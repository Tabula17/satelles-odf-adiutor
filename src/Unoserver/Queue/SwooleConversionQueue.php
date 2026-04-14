<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue;

use Swoole\Coroutine\Channel;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJobResult;
use Throwable;

class SwooleConversionQueue implements ConversionQueueInterface
{
    private Channel $queue;
    private Channel $results;

    /**
     * @var array<string, ConversionJob>
     */
    private array $pendingJobs = [];

    /**
     * @var array<string, ConversionJobResult>
     */
    private array $storedResults = [];

    /**
     * @var array<string, Throwable>
     */
    private array $failures = [];

    public function __construct(
        private readonly int $capacity = 1000
    ) {
        $this->queue = new Channel($capacity);
        $this->results = new Channel($capacity);
    }

    public function push(ConversionJob $job): string
    {
        $job->markQueued();
        $this->pendingJobs[$job->id] = $job;

        if ($this->queue->push($job) === false) {
            unset($this->pendingJobs[$job->id]);
            throw new \RuntimeException('No se pudo insertar el job en la cola');
        }

        return $job->id;
    }

    public function pop(?float $timeout = null): ?ConversionJob
    {
        $job = $this->queue->pop($timeout ?? -1);

        if ($job === false || !$job instanceof ConversionJob) {
            return null;
        }

        return $job;
    }

    public function ack(string $jobId): void
    {
        if (isset($this->pendingJobs[$jobId])) {
            $this->pendingJobs[$jobId]->markCompleted();
            unset($this->pendingJobs[$jobId]);
        }

        unset($this->failures[$jobId]);
    }

    public function fail(string $jobId, Throwable $error): void
    {
        if (isset($this->pendingJobs[$jobId])) {
            $this->pendingJobs[$jobId]->markFailed();
        }

        $this->failures[$jobId] = $error;
    }

    public function retry(string $jobId): void
    {
        if (!isset($this->pendingJobs[$jobId])) {
            return;
        }

        $job = $this->pendingJobs[$jobId];

        if (!$job->canRetry()) {
            $job->markFailed();
            return;
        }

        $job->markRetrying();

        if ($this->queue->push($job) === false) {
            throw new \RuntimeException('No se pudo reinsertar el job en la cola');
        }
    }

    public function storeResult(ConversionJobResult $result): void
    {
        $this->storedResults[$result->jobId] = $result;

        if ($this->results->push($result) === false) {
            throw new \RuntimeException('No se pudo almacenar el resultado del job');
        }
    }

    public function getResult(string $jobId): ?ConversionJobResult
    {
        return $this->storedResults[$jobId] ?? null;
    }

    public function popResult(?float $timeout = null): ?ConversionJobResult
    {
        $result = $this->results->pop($timeout ?? -1);

        if ($result === false || !$result instanceof ConversionJobResult) {
            return null;
        }

        return $result;
    }

    public function getFailure(string $jobId): ?Throwable
    {
        return $this->failures[$jobId] ?? null;
    }

    public function stats(): array
    {
        return [
            'capacity' => $this->capacity,
            'queue_size' => $this->queue->length(),
            'results_size' => $this->results->length(),
            'pending_jobs' => count($this->pendingJobs),
            'stored_results' => count($this->storedResults),
            'failures' => count($this->failures),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    public function clear(): void
    {
        $this->pendingJobs = [];
        $this->storedResults = [];
        $this->failures = [];
    }
}