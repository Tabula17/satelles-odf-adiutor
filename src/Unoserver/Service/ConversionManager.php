<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Service;

use Psr\Log\LoggerInterface;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJobResult;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Worker\ConversionWorker;
use Tabula17\Satelles\Utilis\Job\AbstractJob;
use Tabula17\Satelles\Utilis\Job\JobQueueInterface;
use Tabula17\Satelles\Utilis\Job\Service\JobManagerInterface;
use Tabula17\Satelles\Utilis\Queue\QueueBackgroundServiceInterface;
use Throwable;

readonly class ConversionManager implements JobManagerInterface
{
    public function __construct(
        private JobQueueInterface                $queue,
        private ConversionWorker                 $worker,
        private ?QueueBackgroundServiceInterface $queueBackgroundService = null,
        private ?LoggerInterface                 $logger = null
    )
    {
    }

    public function start(int $workers = 1): void
    {
        $this->worker->start($workers);

        $this->queueBackgroundService?->start();

        $this->logger?->debug('[ConversionManager] Started', [
            'workers' => $workers,
            'queueBackgroundService' => $this->queueBackgroundService !== null,
        ]);
    }

    public function stop(): void
    {
        $this->queueBackgroundService?->stop();

        $this->worker->stop();
        $this->logger?->debug('[ConversionManager] Stopped');
    }

    /**
     * @throws InvalidArgumentException
     */
    public function submit(AbstractJob $job): string
    {
        $jobId = $this->queue->push($job);
        $this->logger?->info('[ConversionManager] Job submitted', [
            'jobId' => $jobId,
            'mode' => $job->mode,
            'outputFormat' => $job->outputFormat,
        ]);

        return $jobId;
    }

    public function getResult(string $jobId): ?ConversionJobResult
    {
        return $this->queue->getResult($jobId);
    }

    public function waitForResult(string $jobId, int $timeoutSeconds = 30, int $pollIntervalMs = 200): ?ConversionJobResult
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $result = $this->getResult($jobId);

            if ($result !== null) {
                return $result;
            }

            usleep($pollIntervalMs * 1000);
        }

        return null;
    }

    public function processJob(ConversionJob|AbstractJob $job): ?ConversionJobResult
    {
        return $this->worker->process($job);
    }

    public function hasResult(string $jobId): bool
    {
        return $this->getResult($jobId) !== null;
    }

    public function hasFailure(string $jobId): bool
    {
        return $this->queue->getFailure($jobId) !== null;
    }

    public function getFailure(string $jobId): ?Throwable
    {
        return $this->queue->getFailure($jobId);
    }

    public function cancelJob(string $jobId): void
    {
        $this->queue->cancel($jobId);
    }

    public function jobExists(string $jobId): bool
    {
        return $this->queue->exists($jobId);
    }

    public function stats(): array
    {
        return [
            'queue' => $this->queue->stats(),
            'worker_running' => $this->worker->isRunning(),
            'retry_dispatcher_running' => $this->queueBackgroundService?->isRunning() ?? false,
        ];
    }
}