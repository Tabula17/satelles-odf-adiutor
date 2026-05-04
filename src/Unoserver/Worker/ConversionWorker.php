<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Worker;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\Unoserver\UnoserverTransportException;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\Unoserver\UnoserverValidationException;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\Unoserver\UnoserverXmlRpcException;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJobResult;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;
use Tabula17\Satelles\Utilis\Job\AbstractJob;
use Tabula17\Satelles\Utilis\Job\JobQueueInterface;
use Tabula17\Satelles\Utilis\Job\Worker\JobWorkerInterface;
use Throwable;

class ConversionWorker implements JobWorkerInterface
{
    private bool $running = false;

    public function __construct(
        private readonly JobQueueInterface     $queue,
        private readonly UnoserverLoadBalancer $loadBalancer,
        private readonly ?LoggerInterface      $logger = null,
        private readonly int                   $pollTimeout = 1,
        private readonly int                   $sleepWhenEmpty = 100000
    )
    {
    }

    public function start(int $workers = 1): void
    {
        if ($this->running) {
            return;
        }
        $this->loadBalancer->start();
        $this->running = true;

        for ($i = 0; $i < $workers; $i++) {
            Coroutine::create(function () use ($i): void {
                $this->logger?->debug('[ConversionWorker] Worker started', [
                    'worker' => $i,
                ]);

                while ($this->running) {
                    $job = $this->queue->pop((float)$this->pollTimeout);

                    if ($job === null) {
                        if ($this->queue->isEmpty()) {
                            Coroutine::sleep($this->sleepWhenEmpty / 1000000);
                        }

                        continue;
                    }

                    $this->processJob($job);
                }
            });
        }
    }

    public function stop(): void
    {
        $this->loadBalancer->stop();
        $this->running = false;
        $this->logger?->debug('[ConversionWorker] Worker stopped');
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function process(ConversionJob|AbstractJob $job): ConversionJobResult
    {
        $job->markRunning();
        $startedAt = new DateTimeImmutable();
        $startedAtFloat = microtime(true);

        try {
            $result = $this->loadBalancer->convertSync(
                filePath: $job->filePath,
                fileContent: $job->fileContent,
                outputFormat: $job->outputFormat,
                outPath: $job->outPath,
                mode: $job->mode
            );

            $finishedAt = new DateTimeImmutable();
            $durationMs = (microtime(true) - $startedAtFloat) * 1000;

            $job->markCompleted();
            return ConversionJobResult::success(
                jobId: $job->jobId,
                outputPath: $result->outputPath,
                base64Content: $result->base64Content,
                serverHost: $result->serverHost,
                serverPort: $result->serverPort,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                durationMs: $durationMs
            );
        } catch (UnoserverValidationException|UnoserverTransportException|UnoserverXmlRpcException|Throwable $e) {
            return ConversionJobResult::failure(
                jobId: $job->jobId,
                errorMessage: $e->getMessage(),
                startedAt: $startedAt,
                finishedAt: new DateTimeImmutable(),
                durationMs: (microtime(true) - $startedAtFloat) * 1000

            );
        }
    }

    private function processJob(ConversionJob $job): void
    {
        $job->markRunning();
        $startedAt = new DateTimeImmutable();
        $startedAtFloat = microtime(true);

        $this->logger?->debug('[ConversionWorker] Processing job', [
            'jobId' => $job->jobId,
            'attempts' => $job->attempts,
            'mode' => $job->mode,
            'outputFormat' => $job->outputFormat,
        ]);

        try {
            $result = $this->loadBalancer->convertAsync(
                filePath: $job->filePath,
                fileContent: $job->fileContent,
                outputFormat: $job->outputFormat,
                outPath: $job->outPath,
                mode: $job->mode
            );

            $finishedAt = new DateTimeImmutable();
            $durationMs = (microtime(true) - $startedAtFloat) * 1000;

            $job->markCompleted();

            $jobResult = ConversionJobResult::success(
                jobId: $job->jobId,
                outputPath: $result->outputPath,
                base64Content: $result->base64Content,
                serverHost: $result->serverHost,
                serverPort: $result->serverPort,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                durationMs: $durationMs
            );

            $this->queue->storeResult($jobResult);
            $this->queue->ack($job->jobId);

            $this->logger?->info('[ConversionWorker] Job completed', [
                'jobId' => $job->jobId,
                'durationMs' => $durationMs,
                'serverHost' => $result->serverHost,
                'serverPort' => $result->serverPort,
            ]);
        } catch (UnoserverValidationException|UnoserverTransportException|UnoserverXmlRpcException|Throwable $e) {
            $this->handleFailedJob($job, $e, $startedAt, $startedAtFloat);
        }
    }

    private function handleFailedJob(
        ConversionJob     $job,
        Throwable         $error,
        DateTimeImmutable $startedAt,
        float             $startedAtFloat
    ): void
    {
        $finishedAt = new DateTimeImmutable();
        $durationMs = (microtime(true) - $startedAtFloat) * 1000;

        $this->logger?->warning('[ConversionWorker] Job failed', [
            'jobId' => $job->jobId,
            'attempts' => $job->attempts,
            'error' => $error->getMessage(),
            'durationMs' => $durationMs,
        ]);

        $jobResult = ConversionJobResult::failure(
            jobId: $job->jobId,
            errorMessage: $error->getMessage(),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            durationMs: $durationMs
        );

        $this->queue->storeResult($jobResult);
        $this->queue->fail($job->jobId, $error);

        if ($job->canRetry()) {
            $this->queue->retry($job->jobId);
            return;
        }

        $job->markFailed();
    }
}