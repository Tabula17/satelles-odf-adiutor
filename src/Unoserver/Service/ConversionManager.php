<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Service;

use Psr\Log\LoggerInterface;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJobResult;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\ConversionQueueInterface;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Worker\ConversionWorker;

readonly class ConversionManager
{
    public function __construct(
        private ConversionQueueInterface $queue,
        private ConversionWorker         $worker,
        private ?LoggerInterface         $logger = null
    ) {
    }

    public function start(int $workers = 1): void
    {
        $this->worker->start($workers);

        $this->logger?->debug('[ConversionManager] Started', [
            'workers' => $workers,
        ]);
    }

    public function stop(): void
    {
        $this->worker->stop();
        $this->logger?->debug('[ConversionManager] Stopped');
    }

    public function submit(
        string $filePath,
        string $outputFormat,
        string $mode = 'stream',
        ?string $fileContent = null,
        ?string $outPath = null,
        ?array $metadata = null,
        int $maxAttempts = 3,
        int $priority = 0
    ): string {
        $job = new ConversionJob(
            filePath: $filePath,
            outputFormat: $outputFormat,
            mode: $mode,
            fileContent: $fileContent,
            outPath: $outPath,
            metadata: $metadata ?? [],
            maxAttempts: $maxAttempts,
            priority: $priority
        );

        $jobId = $this->queue->push($job);

        $this->logger?->info('[ConversionManager] Job submitted', [
            'jobId' => $jobId,
            'mode' => $mode,
            'outputFormat' => $outputFormat,
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

    public function hasResult(string $jobId): bool
    {
        return $this->getResult($jobId) !== null;
    }

    public function stats(): array
    {
        return [
            'queue' => $this->queue->stats(),
            'worker_running' => $this->worker->isRunning(),
        ];
    }
}