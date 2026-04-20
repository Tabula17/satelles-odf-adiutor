<?php

namespace Tabula17\Satelles\Odf\Adiutor\Server;

use Override;
use Psr\Log\LoggerInterface;
use Tabula17\Satelles\Nexus\Utilis\Server\Hamum\Basis;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJobStatusEnum;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Service\ConversionManager;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

class AdiutorTcp extends Basis
{
    public function __construct(
        TCPServerConfig                    $config,
        private readonly ConversionManager $conversionManager,
        public ?LoggerInterface            $logger = null
    )
    {
        parent::__construct($config, $logger);
    }

    #[Override]
    protected function init(): void
    {
        $this->on('start', fn() => $this->conversionManager->start());
        $this->on('beforeshutdown', fn() => $this->conversionManager->stop());
        $this->logger?->info("Initializing Adiutor server #{$this->getServerId()} | {$this->host}:{$this->port}}");
    }

    #[Override]
    protected function onBeforeStart(): void
    {
        $this->logger?->info("Starting Adiutor server #{$this->getServerId()} | {$this->host}:{$this->port}}");
        $this->logger?->info("Adiutor server allowed actions: " . implode(", ", AdiutorActionsEnum::list()));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Submit->path(), $this->handleJobSubmission(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Status->path(), $this->handleJobStatus(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Cancel->path(), $this->handleJobCancellation(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Wait->path(), $this->handleWaitResult(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::Convert->path(), $this->handleDirectConversion(...));
        $this->registerReceiveHandlers(AdiutorActionsEnum::GetFile->path(), $this->handleGetFile(...));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function handleJobSubmission($server, $fd, $request): void
    {
        $job = ConversionJob::fromArray($request);
        $job->validate();
        $jobId = $this->conversionManager->submit($job);
        $server->send($fd, json_encode([
            'status' => ConversionJobStatusEnum::Queued->value,
            'jobId' => $jobId,
            'message' => 'Job queued successfully'
        ]));
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
            ConversionJobStatusEnum::Completed => $server->send($fd, json_encode(['status' => ConversionJobStatusEnum::Completed->value, 'jobId' => $jobId])),
            ConversionJobStatusEnum::Failed => $server->send($fd, json_encode(['status' => ConversionJobStatusEnum::Failed->value, 'jobId' => $jobId])),
            ConversionJobStatusEnum::Pending => $server->send($fd, json_encode(['status' => ConversionJobStatusEnum::Pending->value, 'jobId' => $jobId])),
            ConversionJobStatusEnum::Cancelled => $server->send($fd, json_encode(['status' => ConversionJobStatusEnum::Cancelled->value, 'jobId' => $jobId])),
            default => $server->send($fd, json_encode(['status' => ConversionJobStatusEnum::NotFound->value])),
        };
    }

    private function handleJobCancellation(self $server, int $fd, int $reactorId, $data): void
    {
        $request = json_decode($data, true);
        $jobId = $request['jobId'];
        $this->conversionManager->cancelJob($jobId);
        $server->send($fd, json_encode(['status' => ConversionJobStatusEnum::Cancelled->value, 'jobId' => $jobId, 'message' => 'Job cancelled successfully']));

    }

    private function handleWaitResult(self $server, int $fd, int $reactorId, $data): void
    {
        $request = json_decode($data, true);
        $jobId = $request['jobId'];
        $status = $this->jobStatus($jobId);

        switch ($status) {
            case ConversionJobStatusEnum::Completed:
                $result = $this->conversionManager->getResult($jobId);
                $result?->streamToTcp($server, $fd);
                break;

            case ConversionJobStatusEnum::Failed:
                $server->send($fd, json_encode([
                    'status' => 'failed',
                    'jobId' => $jobId,
                    'error' => $this->conversionManager->getFailure($jobId)?->getMessage()
                ]));
                break;

            case ConversionJobStatusEnum::Pending:
                // Esperar activamente con timeout
                $result = $this->conversionManager->waitForResult($jobId, 30); // 30 segundos
                if ($result) {
                    $result->streamToTcp($server, $fd);
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

    /**
     * @throws InvalidArgumentException
     */
    private function handleDirectConversion(self $server, int $fd, int $reactorId, $data): void
    {
        $request = json_decode($data, true);
        $request['mode'] = 'stream'; // for direct conversion response with base64 encoded file
        $job = ConversionJob::fromArray($request);
        $job->validate();
        $result = $this->conversionManager->processJob($job);
        /* $server->send($fd, json_encode([
                 'status' => ConversionJobStatusEnum::Completed->value,
                 'result' => $result
             ]
         ));*/
        $result->streamToTcp($server, $fd);
    }

    private function handleGetFile(self $server, int $fd, int $reactorId, $data): void
    {
        $request = json_decode($data, true);
        $jobId = $request['jobId'];
        $result = $this->conversionManager->getResult($jobId);
        // Enviar el archivo por TCP usando streaming
        $success = $result?->streamToTcp($server, $fd);

        if (!$success) {
            $server->send($fd, json_encode(['error' => 'Error al enviar archivo']));
        }
        $server->close($fd);
    }
}