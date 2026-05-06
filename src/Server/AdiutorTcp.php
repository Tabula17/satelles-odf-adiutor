<?php

declare(strict_types=1);

namespace Tabula17\Satelles\Odf\Adiutor\Server;

use Override;
use Psr\Log\LoggerInterface;
use Swoole\Table;
use Tabula17\Satelles\Nexus\Utilis\Server\Hamum\Basis;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJobStatusEnum;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Service\ConversionManager;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

class AdiutorTcp extends Basis
{
    // 1MB
    private string $uploadDir;
    // Buffer por conexión para mensajes que llegan en partes
    private Table $connectionTable;
    private Table $lockTable;

    // Columnas de la tabla de conexiones
    private const TABLE_COLUMNS = [
        'state' => Table::TYPE_STRING,       // Estado del parser
        'buffer' => Table::TYPE_STRING,       // Buffer de datos (limitado)
        'jsonLength' => Table::TYPE_INT,      // Longitud del JSON
        'fileSize' => Table::TYPE_INT,        // Tamaño del archivo (64 bits)
        'receivedBytes' => Table::TYPE_INT,   // Bytes recibidos
        'metadata' => Table::TYPE_STRING,     // JSON de metadatos
    ];

    // Archivos abiertos por conexión (no se pueden compartir en Table)
    private array $fileHandles = [];

    /**
     * @throws RuntimeException
     */
    public function __construct(
        TCPServerConfig                    $config,
        private readonly ConversionManager $conversionManager,
        public ?LoggerInterface            $logger = null
    )
    {

        // Configurar directorio para archivos subidos
        $this->uploadDir = sys_get_temp_dir() . '/adiutor_uploads';
        if (!is_dir($this->uploadDir) && !mkdir($concurrentDirectory = $this->uploadDir, 0o755, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
        $this->initSharedMemory();
        parent::__construct($config, $logger);
    }

    /**
     * Inicializa las tablas de memoria compartida
     */
    private function initSharedMemory(): void
    {
        // Tabla para buffers de conexión (1000 conexiones simultáneas)
        $this->connectionTable = new Table(1000);
        foreach (self::TABLE_COLUMNS as $column => $type) {
            $size = match ($column) {
                'state' => 32,
                'buffer' => 1024 * 1024, // 1MB por entrada
                'metadata' => 1024 * 10,  // 10KB para metadatos
                default => 8,
            };
            $this->connectionTable->column($column, $type, $size);
        }
        $this->connectionTable->create();

        // Tabla para locks (1000 conexiones)
        $this->lockTable = new Table(1000);
        $this->lockTable->column('locked', Table::TYPE_INT, 1);
        $this->lockTable->create();
    }

    #[Override]
    protected function init(): void
    {
        $this->on('start', fn() => $this->conversionManager->start($this->setting['worker_num'] ?? 1));
        $this->on('close', $this->onConnectionClose(...));
        $this->on('beforeshutdown', fn() => $this->conversionManager->stop());
        $this->logger?->info("Initializing Adiutor server #{$this->getServerId()} | {$this->host}:{$this->port}");
        $this->logger?->info("Upload directory: {$this->uploadDir}");
    }

    #[Override]
    protected function onBeforeStart(): void
    {
        $this->logger?->info("Starting Adiutor server #{$this->getServerId()} | {$this->host}:{$this->port}");
        $this->logger?->info("Adiutor server allowed actions: " . implode(", ", AdiutorActionsEnum::list()));

        $this->registerReceiveHandlers(AdiutorActionsEnum::Submit->path(), $this->handleJobSubmission(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Status->path(), $this->handleJobStatus(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Cancel->path(), $this->handleJobCancellation(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Wait->path(), $this->handleWaitResult(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Convert->path(), $this->handleDirectConversion(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::GetFile->path(), $this->handleGetFile(...));
    }

    //private array $connectionLocks = [];

    protected function onBeforeReceive(mixed $server, int $fd, int $reactorId, $data): bool
    {
        $key = 'fd_' . $fd;

        // Verificar lock
        $lockInfo = $this->lockTable->get($key);

        if ($lockInfo && $lockInfo['locked'] === 1) {
            // Acumular datos
            $connData = $this->connectionTable->get($key);
            if ($connData) {
                $newBuffer = $connData['buffer'] . $data;
                $this->connectionTable->set($key, ['buffer' => $newBuffer]);
                $this->logger?->debug("Buffer acumulado: " . strlen($newBuffer) . " bytes (encolado)");
            }
            return false;
        }

        // Adquirir lock
        $this->lockTable->set($key, ['locked' => 1]);

        try {
            return $this->doProcessReceive($server, $fd, $reactorId, $data);
        } finally {
            $this->lockTable->set($key, ['locked' => 0]);
        }
    }

    private function doProcessReceive(mixed $server, int $fd, int $reactorId, string $data): bool
    {
        $key = 'fd_' . $fd;
        $connData = $this->connectionTable->get($key);

        // ✅ Si no existe, inicializar
        if (!$connData) {
            $this->connectionTable->set($key, [
                'state' => 'init',
                'buffer' => '',
                'jsonLength' => 0,
                'fileSize' => 0,
                'receivedBytes' => 0,
                'metadata' => '',
            ]);
            $connData = $this->connectionTable->get($key);
        }

        // Acumular buffer
        $buffer = $connData['buffer'] . $data;
        $state = $connData['state'];

        $this->logger?->debug("Estado: {$state}, Buffer: " . strlen($buffer) . " bytes");

        // ✅ Si es estado init, leer el primer byte
        if ($state === 'init' && strlen($buffer) >= 1) {
            $firstByte = $buffer[0];
            $buffer = substr($buffer, 1);

            $this->logger?->debug("Primer byte: 0x" . bin2hex($firstByte));

            if ($firstByte === chr(0x01)) {
                $state = 'reading_json_length';
                $this->logger?->debug("→ Transferencia de archivo");
            } elseif ($firstByte === chr(0x00)) {
                $state = 'json';
                $this->logger?->debug("→ Mensaje JSON (0x00)");
            } elseif ($firstByte === '{') {
                $state = 'json';
                $buffer = '{' . $buffer;
                $this->logger?->debug("→ Mensaje JSON (empieza con '{')");
            } else {
                $this->logger?->error("Byte desconocido: 0x" . bin2hex($firstByte));
                throw new \RuntimeException("Protocolo desconocido: 0x" . bin2hex($firstByte));
            }

            // Guardar estado actualizado
            $this->connectionTable->set($key, ['state' => $state, 'buffer' => $buffer]);
            $connData = $this->connectionTable->get($key);
        }

        // Si es JSON, devolver true para que los handlers lo procesen
        if ($state === 'json') {
            $this->connectionTable->del($key);
            return true;
        }

        // Si es archivo, procesar
        if ($state === 'reading_json_length' || $state === 'reading_json' ||
            $state === 'reading_file_size' || $state === 'reading_file_data') {
            $this->processReceivedData($server, $fd);
            return false;
        }

        return true;
    }

    private function processReceivedData($server, int $fd): void
    {
        $key = 'fd_' . $fd;
        $connData = $this->connectionTable->get($key);

        if (!$connData) {
            $this->logger?->error("No hay datos para fd={$fd}");
            return;
        }

        $buffer = $connData['buffer'];
        $state = $connData['state'];

        while (strlen($buffer) > 0 && $state !== 'completed' && $state !== 'error') {
            switch ($state) {
                case 'reading_json_length':
                    if (strlen($buffer) >= 4) {
                        $jsonLength = unpack('N', substr($buffer, 0, 4))[1];
                        $buffer = substr($buffer, 4);
                        $state = 'reading_json';

                        $this->connectionTable->set($key, [
                            'buffer' => $buffer,
                            'state' => $state,
                            'jsonLength' => $jsonLength
                        ]);
                        $this->logger?->debug("JSON length: {$jsonLength}");
                    } else {
                        $this->connectionTable->set($key, ['buffer' => $buffer]);
                        return;
                    }
                    break;

                case 'reading_json':
                    $jsonLength = (int)($connData['jsonLength'] ?? 0);
                    if ($jsonLength > 0 && strlen($buffer) >= $jsonLength) {
                        $jsonData = substr($buffer, 0, $jsonLength);
                        $buffer = substr($buffer, $jsonLength);
                        $state = 'reading_file_size';

                        $this->connectionTable->set($key, [
                            'buffer' => $buffer,
                            'state' => $state,
                            'metadata' => $jsonData
                        ]);
                        $this->logger?->debug("JSON: " . substr($jsonData, 0, 100));
                    } else {
                        $this->connectionTable->set($key, ['buffer' => $buffer]);
                        return;
                    }
                    break;

                case 'reading_file_size':
                    if (strlen($buffer) >= 8) {
                        $fileSize = unpack('J', substr($buffer, 0, 8))[1];
                        $buffer = substr($buffer, 8);

                        $metadata = json_decode($connData['metadata'] ?? '{}', true);
                        $fileName = $metadata['fileName'] ?? uniqid('upload_', true);
                        $safeName = bin2hex(random_bytes(8)); // 16 caracteres hexadecimales aleatorios
                        $filePath = $this->uploadDir . '/' . date('Ymd') . '_' . $safeName . '_' . $fileName;

                        $this->fileHandles[$fd] = [
                            'handle' => fopen($filePath, 'wb'),
                            'filePath' => $filePath,
                            'fileSize' => $fileSize,
                            'metadata' => $metadata,
                        ];

                        $state = 'reading_file_data';
                        $this->connectionTable->set($key, [
                            'buffer' => $buffer,
                            'state' => $state,
                            'fileSize' => $fileSize
                        ]);
                        $this->logger?->debug("File size: {$fileSize}, Path: {$filePath}");
                    } else {
                        $this->connectionTable->set($key, ['buffer' => $buffer]);
                        return;
                    }
                    break;

                case 'reading_file_data':
                    $fileInfo = $this->fileHandles[$fd] ?? null;
                    if (!$fileInfo) {
                        $this->logger?->error("No file handle para fd={$fd}");
                        $state = 'error';
                        break;
                    }

                    $fileSize = (int)($connData['fileSize'] ?? 0);
                    $receivedBytes = (int)($connData['receivedBytes'] ?? 0);
                    $remaining = $fileSize - $receivedBytes;
                    $bufferLen = strlen($buffer);

                    if ($bufferLen > 0) {
                        $writeLen = min($bufferLen, $remaining);
                        fwrite($fileInfo['handle'], substr($buffer, 0, $writeLen));
                        $receivedBytes += $writeLen;
                        $buffer = substr($buffer, $writeLen);

                        $this->connectionTable->set($key, [
                            'buffer' => $buffer,
                            'receivedBytes' => $receivedBytes
                        ]);
                    }

                    $this->logger?->debug("Progreso: {$receivedBytes}/{$fileSize}");

                    if ($receivedBytes >= $fileSize) {
                        fclose($fileInfo['handle']);
                        $state = 'completed';
                        $this->connectionTable->set($key, ['state' => $state]);

                        $this->logger?->info("✅ Archivo recibido: {$fileInfo['filePath']}");

                        $this->processCompleteUpload($server, $fd, [
                            'metadata' => $fileInfo['metadata'],
                            'filePath' => $fileInfo['filePath'],
                            'receivedBytes' => $receivedBytes,
                        ]);

                        unset($this->fileHandles[$fd]);
                        $this->connectionTable->del($key);
                        $this->lockTable->del($key);
                        return;
                    }

                    if ($bufferLen === 0) return;
                    break;
            }

            // Actualizar datos para la siguiente iteración
            $connData = $this->connectionTable->get($key);
            if (!$connData) return;
            $buffer = $connData['buffer'];
            $state = $connData['state'];
        }
    }

    private function cleanupConnection(int $fd, bool $removeFile = false): void
    {
        $key = 'fd_' . $fd;

        // Limpiar handle de archivo
        if (isset($this->fileHandles[$fd])) {
            if (is_resource($this->fileHandles[$fd]['handle'])) {
                fclose($this->fileHandles[$fd]['handle']);
            }
            @unlink($this->fileHandles[$fd]['filePath']);
            unset($this->fileHandles[$fd]);
        }

        // Limpiar tablas
        $this->connectionTable->del($key);
        $this->lockTable->del($key);
    }


    /**
     * Procesa una subida completa
     */
    private function processCompleteUpload($server, int $fd, array $state): void
    {
        $metadata = $state['metadata'];
        $filePath = $state['filePath'];
        $action = $metadata['action'] ?? null;

        $this->logger?->info("Archivo recibido: {$filePath} ({$state['receivedBytes']} bytes). Action: {$action}");

        match ($action) {
            AdiutorActionsEnum::Convert->path() => $this->handleDirectConversionWithFile($server, $fd, $metadata, $filePath),
            AdiutorActionsEnum::Submit->path() => $this->handleJobSubmissionWithFile($server, $fd, $metadata, $filePath),
            default => $server->send($fd, json_encode(['error' => 'Acción desconocida'])),
        };
        // Limpiar después de procesar
        $this->cleanupConnection($fd);
    }

    /**
     * Maneja conversión directa con archivo ya recibido
     */
    private function handleDirectConversionWithFile($server, int $fd, array $metadata, string $filePath): void
    {
        $this->logger?->info("Procesando archivo: {$filePath}");
        try {
            $withProgress = $metadata['withProgress'] ?? false;

            $job = new ConversionJob(
                filePath: $filePath,
                outputFormat: $metadata['outputFormat'] ?? 'pdf',
                mode: 'stream'
            );
            $this->logger?->info("Procesando archivo: {$filePath} (formato: {$job->outputFormat}, job: {$job->jobId})");

            $job->validate();
            $result = $this->conversionManager->processJob($job);
            $this->logger?->debug("Resultado del proceso: " . $result['jobId']);
            if (!$this->streamResult($server, $fd, $result, $withProgress)) {
                throw new RuntimeException("Error al enviar resultado al cliente");
            }

        } catch (\Throwable $e) {
            $this->logger?->error("Error en handleDirectConversionWithFile: " . $e->getMessage());
            $server->send($fd, json_encode(['error' => $e->getMessage()]));
        } finally {

            // Limpiar archivo temporal
            @unlink($filePath);
            $this->cleanupConnection($fd);
            // $server->close($fd);
        }
    }

    /**
     * Maneja envío a cola con archivo ya recibido
     */
    private function handleJobSubmissionWithFile($server, int $fd, array $metadata, string $filePath): void
    {
        try {
            $job = new ConversionJob(
                filePath: $filePath,
                outputFormat: $metadata['outputFormat'] ?? 'pdf'
            );

            $job->validate();
            $jobId = $this->conversionManager->submit($job);

            $server->send($fd, json_encode([
                'status' => ConversionJobStatusEnum::Queued->value,
                'jobId' => $jobId,
                'message' => 'Job queued successfully'
            ]));

        } catch (\Throwable $e) {
            $server->send($fd, json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Limpia los recursos de una conexión
     * private function cleanupConnection(int $fd): void
     * {
     * if (isset($this->connectionBuffers[$fd])) {
     * $state = $this->connectionBuffers[$fd];
     *
     * if (isset($state['handle']) && is_resource($state['handle'])) {
     * fclose($state['handle']);
     * }
     *
     * unset($this->connectionBuffers[$fd]);
     * }
     * }
     */

    /**
     * Evento de cierre de conexión
     */
    public function onConnectionClose($server, int $fd): void
    {
        $this->logger?->debug("Conexión cerrada: fd={$fd}");
        $this->cleanupConnection($fd);
    }

    /**
     * Maneja envío de job a cola - Versión JSON simple (base64 o metadata)
     *
     * @throws InvalidArgumentException
     */
    private function handleJobSubmission(self $server, int $fd, int $reactorId, $data): void
    {
        try {
            $request = json_decode($data, true);

            if (!is_array($request)) {
                throw new InvalidArgumentException('Invalid JSON request');
            }

            // Crear job desde los datos recibidos (puede incluir base64Content)
            $job = ConversionJob::fromArray($request);
            $job->validate();

            $jobId = $this->conversionManager->submit($job);

            $server->send($fd, json_encode([
                'status' => ConversionJobStatusEnum::Queued->value,
                'jobId' => $jobId,
                'message' => 'Job queued successfully'
            ]));

        } catch (\Throwable $e) {
            $this->logger?->error("Error en handleJobSubmission (JSON): " . $e->getMessage());
            $server->send($fd, json_encode([
                'status' => 'error',
                'error' => $e->getMessage()
            ]));
        }
    }

    /**
     * Maneja conversión directa - Versión JSON simple (base64 o metadata)
     *
     * @throws InvalidArgumentException
     */
    private function handleDirectConversion(self $server, int $fd, int $reactorId, $data): void
    {
        try {
            $request = json_decode($data, true);

            if (!is_array($request)) {
                throw new InvalidArgumentException('Invalid JSON request');
            }

            $withProgress = $request['withProgress'] ?? false;

            // Para conversión directa, forzar modo stream
            $request['mode'] = 'stream';

            // Crear job desde los datos recibidos
            $job = ConversionJob::fromArray($request);
            $job->validate();

            // Procesar el job directamente
            $result = $this->conversionManager->processJob($job);

            // Enviar resultado usando el protocolo apropiado
            if (!$this->streamResult($server, $fd, $result, $withProgress)) {
                throw new RuntimeException("Error al enviar resultado al cliente");
            }

        } catch (\Throwable $e) {
            $this->logger?->error("Error en handleDirectConversion (JSON): " . $e->getMessage());
            $server->send($fd, json_encode([
                'status' => 'error',
                'error' => $e->getMessage()
            ]));
        } finally {
            // ✅ Limpiar buffer
            $this->cleanupConnection($fd);
            //$server->close($fd);
        }
    }


    private function jobStatus(string $jobId): ConversionJobStatusEnum
    {
        if ($this->conversionManager->jobExists($jobId)) {
            if ($this->conversionManager->hasResult($jobId)) {
                return ConversionJobStatusEnum::Completed;
            }
            if ($this->conversionManager->hasFailure($jobId)) {
                return ConversionJobStatusEnum::Failed;
            }
            return ConversionJobStatusEnum::Pending;
        }
        return ConversionJobStatusEnum::NotFound;
    }

    private function handleJobStatus($server, int $fd, int $reactorId, $data): void
    {
        $request = json_decode($data, true);
        $jobId = $request['jobId'];

        match ($this->jobStatus($jobId)) {
            ConversionJobStatusEnum::Completed => $server->send($fd, json_encode([
                'status' => ConversionJobStatusEnum::Completed->value,
                'jobId' => $jobId
            ])),
            ConversionJobStatusEnum::Failed => $server->send($fd, json_encode([
                'status' => ConversionJobStatusEnum::Failed->value,
                'jobId' => $jobId,
                'error' => $this->conversionManager->getFailure($jobId)?->getMessage()
            ])),
            ConversionJobStatusEnum::Pending => $server->send($fd, json_encode([
                'status' => ConversionJobStatusEnum::Pending->value,
                'jobId' => $jobId
            ])),
            ConversionJobStatusEnum::Cancelled => $server->send($fd, json_encode([
                'status' => ConversionJobStatusEnum::Cancelled->value,
                'jobId' => $jobId
            ])),
            default => $server->send($fd, json_encode([
                'status' => ConversionJobStatusEnum::NotFound->value
            ])),
        };
    }

    private function handleJobCancellation(self $server, int $fd, int $reactorId, $data): void
    {
        $request = json_decode($data, true);
        $jobId = $request['jobId'];
        $this->conversionManager->cancelJob($jobId);

        $server->send($fd, json_encode([
            'status' => ConversionJobStatusEnum::Cancelled->value,
            'jobId' => $jobId,
            'message' => 'Job cancelled successfully'
        ]));
    }

    /**
     * @throws RuntimeException
     */
    private function handleWaitResult(self $server, int $fd, int $reactorId, $data): void
    {
        $request = json_decode($data, true);
        $jobId = $request['jobId'];
        $withProgress = $request['withProgress'] ?? false;
        $status = $this->jobStatus($jobId);

        switch ($status) {
            case ConversionJobStatusEnum::Completed:
                $result = $this->conversionManager->getResult($jobId);
                if ($result) {
                    if (!$this->streamResult($server, $fd, $result, $withProgress)) {
                        throw new RuntimeException("Error al enviar resultado al cliente");
                    }
                } else {
                    $server->send($fd, json_encode([
                        'status' => 'error',
                        'message' => 'Result not found'
                    ]));
                }
                break;

            case ConversionJobStatusEnum::Failed:
                $server->send($fd, json_encode([
                    'status' => 'failed',
                    'jobId' => $jobId,
                    'error' => $this->conversionManager->getFailure($jobId)?->getMessage()
                ]));
                break;

            case ConversionJobStatusEnum::Pending:
                $result = $this->conversionManager->waitForResult($jobId, 30);
                if ($result) {
                    if (!$this->streamResult($server, $fd, $result, $withProgress)) {
                        throw new RuntimeException("Error al enviar resultado al cliente");
                    }
                } else {
                    $server->send($fd, json_encode([
                        'status' => 'timeout',
                        'jobId' => $jobId
                    ]));
                }
                break;

            default:
                $server->send($fd, json_encode([
                    'status' => 'not_found',
                    'jobId' => $jobId
                ]));
        }
        $this->cleanupConnection($fd);
        // $server->close($fd);
    }


    private function handleGetFile(self $server, int $fd, int $reactorId, $data): void
    {
        $request = json_decode($data, true);
        $jobId = $request['jobId'];
        $withProgress = $request['withProgress'] ?? false;
        $result = $this->conversionManager->getResult($jobId);

        if (!$result) {
            $server->send($fd, json_encode(['error' => 'Resultado no encontrado']));
            return;
        }

        $success = $this->streamResult($server, $fd, $result, $withProgress);

        if (!$success) {
            $server->send($fd, json_encode(['error' => 'Error al enviar archivo']));
        }

        $this->cleanupConnection($fd);
        // $server->close($fd);
    }

    /**
     * Envía el resultado usando el protocolo apropiado
     */
    private function streamResult(mixed $server, int $fd, $result, bool $withProgress): bool
    {
        if ($withProgress) {
            return $result->streamToTcpWithProgress($server, $fd);
        }
        $this->logger?->debug("Enviando resultado: " . $result['jobId']);

        return $result->streamToTcp($server, $fd);
    }
}