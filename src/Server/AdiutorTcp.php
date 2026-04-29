<?php

declare(strict_types=1);

namespace Tabula17\Satelles\Odf\Adiutor\Server;

use Override;
use Psr\Log\LoggerInterface;
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
    private array $connectionBuffers = [];

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
        parent::__construct($config, $logger);
    }

    #[Override]
    protected function init(): void
    {
        $this->on('start', fn() => $this->conversionManager->start());
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
    private function onBeforeReceive(mixed $server, int $fd, int $reactorId, $data): bool
    {
        $this->logger?->debug("Received " . strlen($data) . " bytes from fd={$fd}");

        // ✅ CORREGIDO: Inicializar buffer UNA SOLA VEZ por conexión
        if (!isset($this->connectionBuffers[$fd])) {
            $this->connectionBuffers[$fd] = [
                'state' => 'init',
                'buffer' => '',
                'msgType' => null,
                'jsonLength' => 0,
                'metadata' => null,
                'fileSize' => 0,
                'filePath' => null,
                'handle' => null,
                'receivedBytes' => 0,
            ];
            $this->logger?->debug("Nuevo buffer para fd={$fd}");
        }

        $state = &$this->connectionBuffers[$fd];
        $state['buffer'] .= $data;

        // ✅ CORREGIDO: Solo leer el tipo UNA VEZ, cuando el estado es 'init'
        if ($state['state'] === 'init' && strlen($state['buffer']) >= 1) {
            $typeByte = $state['buffer'][0];
            $state['buffer'] = substr($state['buffer'], 1);

            if ($typeByte === chr(0x01)) {
                $state['msgType'] = 'file';
                $state['state'] = 'reading_json_length';
                $this->logger?->debug("Detectado: transferencia de archivo");
            } elseif ($typeByte === chr(0x00) || $typeByte === '{') {
                $state['msgType'] = 'json';
                $this->logger?->debug("Detectado: mensaje JSON");
                return true; // Dejar que los handlers lo procesen
            } else {
                // Si no es ni JSON ni archivo, podría ser parte del archivo
                // que llegó en una llamada posterior
                if ($state['msgType'] === 'file' && $state['state'] === 'reading_file_data') {
                    // Estamos en medio de una transferencia, procesar datos
                    $this->processReceivedData($server, $fd, '');
                    return false;
                }

                $this->logger?->error("Tipo desconocido: 0x" . bin2hex($typeByte) . " (buffer: " . strlen($state['buffer']) . " bytes, state: {$state['state']})");
                $server->send($fd, chr(0x00) . json_encode(['error' => "Protocolo desconocido: 0x" . bin2hex($typeByte)]));
                return false;
            }
        }

        // Si ya sabemos que es archivo, procesar
        if ($state['msgType'] === 'file') {
            $this->processReceivedData($server, $fd, '');
            return false;
        }

        // Si es JSON, dejar pasar
        return true;

    }

    private function processReceivedData($server, int $fd, string $_data): void
    {
        $state = &$this->connectionBuffers[$fd];

        // ✅ CORREGIDO: Procesar en bucle hasta que no haya más datos
        $processed = false;

        while (strlen($state['buffer']) > 0 && $state['state'] !== 'completed') {
            $processed = true;

            switch ($state['state']) {
                case 'reading_json_length':
                    if (strlen($state['buffer']) >= 4) {
                        $lengthBytes = substr($state['buffer'], 0, 4);
                        $state['jsonLength'] = unpack('N', $lengthBytes)[1];
                        $state['buffer'] = substr($state['buffer'], 4);
                        $state['state'] = 'reading_json';

                        $this->logger?->debug("JSON length: {$state['jsonLength']}, Buffer restante: " . strlen($state['buffer']));
                    } else {
                        return;
                    }
                    break;

                case 'reading_json':
                    if (strlen($state['buffer']) >= $state['jsonLength']) {
                        $jsonData = substr($state['buffer'], 0, $state['jsonLength']);
                        $state['buffer'] = substr($state['buffer'], $state['jsonLength']);

                        $this->logger?->debug("JSON crudo: " . substr($jsonData, 0, 200));

                        $decoded = json_decode($jsonData, true);

                        if (!is_array($decoded)) {
                            $error = json_last_error_msg();
                            $this->logger?->error("JSON inválido: {$error}. Datos: " . substr($jsonData, 0, 100));
                            throw new \RuntimeException("Metadatos JSON inválidos: {$error}");
                        }

                        $state['metadata'] = $decoded;
                        $state['state'] = 'reading_file_size';

                        $this->logger?->debug("JSON parseado correctamente: " . json_encode($decoded));
                    } else {
                        return;
                    }
                    break;

                case 'reading_file_size':
                    if (strlen($state['buffer']) >= 8) {
                        $sizeBytes = substr($state['buffer'], 0, 8);
                        $state['fileSize'] = unpack('J', $sizeBytes)[1];
                        $state['buffer'] = substr($state['buffer'], 8);

                        $this->logger?->debug("File size: {$state['fileSize']}, Buffer restante: " . strlen($state['buffer']));

                        if ($state['fileSize'] < 0 || $state['fileSize'] > 10 * 1024 * 1024 * 1024) {
                            throw new \RuntimeException("Tamaño de archivo inválido: {$state['fileSize']}");
                        }

                        $fileName = $state['metadata']['fileName'] ?? uniqid('upload_', true);
                        $state['filePath'] = $this->uploadDir . '/' . date('Ymd') . '_' . $fileName;
                        $state['handle'] = fopen($state['filePath'], 'wb');

                        if ($state['handle'] === false) {
                            throw new \RuntimeException("No se pudo crear archivo: {$state['filePath']}");
                        }

                        $state['state'] = 'reading_file_data';
                    } else {
                        return;
                    }
                    break;

                case 'reading_file_data':
                    $remaining = $state['fileSize'] - $state['receivedBytes'];
                    $bufferLen = strlen($state['buffer']);

                    if ($bufferLen > 0) {
                        $writeLen = min($bufferLen, $remaining);
                        $written = fwrite($state['handle'], substr($state['buffer'], 0, $writeLen));

                        if ($written === false || $written === 0) {
                            throw new \RuntimeException('Error al escribir en archivo');
                        }

                        $state['receivedBytes'] += $written;
                        $state['buffer'] = substr($state['buffer'], $written);
                    }

                    if ($state['receivedBytes'] >= $state['fileSize']) {
                        fclose($state['handle']);
                        $state['state'] = 'completed';

                        $this->logger?->info("✅ Archivo recibido: {$state['filePath']} ({$state['receivedBytes']} bytes)");
                        $this->processCompleteUpload($server, $fd, $state);
                        return;
                    }

                    if ($bufferLen === 0) {
                        return;
                    }
                    break;
            }
        }
    }

    private function cleanupConnection(int $fd, bool $removeFile = false): void
    {
        if (isset($this->connectionBuffers[$fd])) {
            $state = $this->connectionBuffers[$fd];

            if (isset($state['handle']) && is_resource($state['handle'])) {
                fclose($state['handle']);
            }

            if ($removeFile && isset($state['filePath']) && file_exists($state['filePath'])) {
                @unlink($state['filePath']);
            }

            unset($this->connectionBuffers[$fd]);
        }
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
        try {
            $withProgress = $metadata['withProgress'] ?? false;

            $job = new ConversionJob(
                filePath: $filePath,
                outputFormat: $metadata['outputFormat'] ?? 'pdf',
                mode: 'stream'
            );

            $job->validate();
            $result = $this->conversionManager->processJob($job);

            $this->streamResult($server, $fd, $result, $withProgress);

            // Limpiar archivo temporal
            @unlink($filePath);

        } catch (\Throwable $e) {
            $server->send($fd, json_encode(['error' => $e->getMessage()]));
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
    private function cleanupConnection(int $fd): void
    {
        if (isset($this->connectionBuffers[$fd])) {
            $state = $this->connectionBuffers[$fd];

            if (isset($state['handle']) && is_resource($state['handle'])) {
                fclose($state['handle']);
            }

            unset($this->connectionBuffers[$fd]);
        }
    }
*/

    /**
     * Evento de cierre de conexión
     */
    public function onConnectionClose($server, int $fd): void
    {
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
            $this->streamResult($server, $fd, $result, $withProgress);

        } catch (\Throwable $e) {
            $this->logger?->error("Error en handleDirectConversion (JSON): " . $e->getMessage());
            $server->send($fd, json_encode([
                'status' => 'error',
                'error' => $e->getMessage()
            ]));
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
                    $this->streamResult($server, $fd, $result, $withProgress);
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
                    $this->streamResult($server, $fd, $result, $withProgress);
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

        $server->close($fd);
    }

    /**
     * Envía el resultado usando el protocolo apropiado
     */
    private function streamResult(mixed $server, int $fd, $result, bool $withProgress): bool
    {
        if ($withProgress) {
            return $result->streamToTcpWithProgress($server, $fd);
        }

        return $result->streamToTcp($server, $fd);
    }
}