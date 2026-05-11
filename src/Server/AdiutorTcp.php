<?php

declare(strict_types=1);

namespace Tabula17\Satelles\Odf\Adiutor\Server;

use Override;
use Psr\Log\LoggerInterface;
use Tabula17\Satelles\Nexus\Utilis\Server\Hamum\Basis;
use Tabula17\Satelles\Nexus\Utilis\Server\Protocol\FileTransfer\AnonymousWrapper;
use Tabula17\Satelles\Nexus\Utilis\Server\Protocol\FileTransfer\FileTransferProtocol;
use Tabula17\Satelles\Nexus\Utilis\Server\Protocol\ServiceProtocol;
use Tabula17\Satelles\Nexus\Utilis\Service\FileTransferClient;
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
    private FileTransferClient $fileTransferClient;

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
        $this->uploadDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adiutor_uploads';
        if (!is_dir($this->uploadDir) && !mkdir($concurrentDirectory = $this->uploadDir, 0o755, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }
        parent::__construct($config, $logger);
    }

    /**
     * @throws \Tabula17\Satelles\Nexus\Utilis\Exception\RuntimeException
     */
    #[Override]
    protected function init(): void
    {
        $fileTransferManager = new FileTransferProtocol(
            storagePath: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adiutor_file_transfers',
            logger: $this->logger
        );
        $this->fileTransferClient = new FileTransferClient($this->logger);

        $this->addProtocolManager($fileTransferManager->getProtocolName(), $fileTransferManager);

        $this->on('start', fn() => $this->conversionManager->start($this->setting['worker_num']));
        $this->on('close', $this->onConnectionClose(...));
        $this->on('beforeshutdown', fn() => $this->conversionManager->stop());
        $this->logger?->info("Initializing Adiutor server #{$this->getServerId()} | {$this->host}:{$this->port}");
        $this->logger?->info("Upload directory: {$this->uploadDir}");


    }

    #[Override]
    protected function onBeforeStart(): void
    {
        $this->fileTransferClient->initializeWithServer($this);
        $this->logger?->info("Starting Adiutor server #{$this->getServerId()} | {$this->host}:{$this->port}");
        $this->logger?->info("Adiutor server allowed actions: " . implode(", ", AdiutorActionsEnum::list()));

        $this->registerReceiveHandlers(AdiutorActionsEnum::Submit->path(), $this->handleJobSubmission(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Convert->path(), $this->handleConversion(...));
        // $this->registerReceiveHandlers(AdiutorActionsEnum::Status->path(), $this->handleJobStatus(...));
        // $this->registerReceiveHandlers(AdiutorActionsEnum::Cancel->path(), $this->handleJobCancellation(...));
        // $this->registerReceiveHandlers(AdiutorActionsEnum::Wait->path(), $this->handleWaitResult(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::GetFile->path(), $this->handleGetFile(...));
    }

    /**
     * Evento de cierre de conexión
     */
    public function onConnectionClose($server, int $fd): void
    {
        $this->logger?->debug("Conexión cerrada: fd={$fd}");
        $this->cleanupConnection($fd);
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
            $this->logger?->info("💼 Job submitted: " . json_encode($job->toArray()));
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
    private function handleConversion(self $server, int $fd, int $reactorId, $data): void
    {
        try {
            $request = json_decode($data, true);

            if (!is_array($request)) {
                throw new InvalidArgumentException('Invalid JSON request');
            }

            //$withProgress = $request['withProgress'] ?? false;

            // Para conversión directa, forzar modo stream
            $request['mode'] = 'stream';

            // Crear job desde los datos recibidos
            $job = ConversionJob::fromArray($request);
            $job->validate();

            // Procesar el job directamente
            $result = $this->conversionManager->processJob($job);
            /** @var $fileManager FileTransferProtocol */
            $fileManager = $this->getProtocolManager(ServiceProtocol::TCPFILE->shortName());
            $onCompleted = fn() => $this->cleanupConnection($fd, true);
            $fileManager->sendFile(
                server: $server,
                fd: $fd,
                filePath: $result->outputPath,
                onComplete: new AnonymousWrapper($onCompleted),

            );
            // Enviar resultado usando el protocolo apropiado
            /*
            if (!$this->streamResult($server, $fd, $result, $withProgress)) {
                throw new RuntimeException("Error al enviar resultado al cliente");
            }*/

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

        /*$success = $this->streamResult($server, $fd, $result, $withProgress);

        if (!$success) {
            $server->send($fd, json_encode(['error' => 'Error al enviar archivo']));
        }

        $this->cleanupConnection($fd);
        */

        $fileManager = $this->getProtocolManager(ServiceProtocol::TCPFILE->shortName());
        $onCompleted = fn() => $this->cleanupConnection($fd, true);
        $fileManager->sendFile(
            server: $server,
            fd: $fd,
            filePath: $result->outputPath,
            onComplete: new AnonymousWrapper($onCompleted),

        );
        // $server->close($fd);
    }
}