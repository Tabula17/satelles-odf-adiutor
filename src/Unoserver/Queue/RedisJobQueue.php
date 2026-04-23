<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue;

use Redis;
use Tabula17\Satelles\Utilis\Job\AbstractJob;
use Tabula17\Satelles\Utilis\Job\AbstractJobResult;
use Tabula17\Satelles\Utilis\Job\JobQueueInterface;
use Throwable;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJobResult;

class RedisJobQueue implements JobQueueInterface
{
    private Redis $redis;

    /**
     * @var array<string, Throwable>
     */
    private array $failures = [];

    public function __construct(
        private readonly RedisQueueConfig $config,
        private readonly RedisJobStateStore $stateStore,
        private readonly RedisResultStore $resultStore,
        private readonly RedisRetryScheduler $retryScheduler
    ) {
        $this->redis = new Redis();
        $this->connect();
    }

    private function connect(): void
    {
        if (!$this->redis->connect($this->config->host, $this->config->port, $this->config->timeout)) {
            throw new RuntimeException('No se pudo conectar a Redis');
        }

        if ($this->config->password !== null && $this->config->password !== '') {
            if (!$this->redis->auth($this->config->password)) {
                throw new RuntimeException('No se pudo autenticar en Redis');
            }
        }

        if (!$this->redis->select($this->config->database)) {
            throw new RuntimeException('No se pudo seleccionar la base de datos Redis');
        }
    }

    public function push(AbstractJob $job): string
    {
        $job->markQueued();

        $payload = json_encode($job->toArray(), JSON_THROW_ON_ERROR);
        $this->stateStore->put($job->jobId, $job->toArray());
        $this->redis->lPush($this->config->queueKey(), $payload);

        return $job->jobId;
    }

    public function pop(?float $timeout = null): ?ConversionJob
    {
        $timeout = $timeout ?? (float) $this->config->readTimeout;

        $result = $this->redis->brPop([$this->config->queueKey()], (int) max(1, ceil($timeout)));

        if ($result === null || $result === false) {
            return null;
        }

        $payload = $result[1] ?? null;

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            return null;
        }

        $job = ConversionJob::fromArray($data);
        $job->markRunning();

        $this->stateStore->put($job->jobId, $job->toArray());
        $this->redis->lPush($this->config->processingKey(), $job->jobId);

        return $job;
    }

    public function ack(string $jobId): void
    {
        $this->redis->lRem($this->config->processingKey(), $jobId, 0);
        $this->stateStore->delete($jobId);
        unset($this->failures[$jobId]);
    }

    public function cancel(string $jobId): void
    {
        $state = $this->stateStore->get($jobId);

        if ($state === null) {
            return;
        }

        $state['status'] = 'cancelled';
        $this->stateStore->put($jobId, $state);

        $this->redis->lRem($this->config->processingKey(), $jobId, 0);
        $this->redis->lPush($this->config->deadLetterKey(), json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function fail(string $jobId, Throwable $error): void
    {
        $this->failures[$jobId] = $error;

        $state = $this->stateStore->get($jobId);

        if ($state !== null) {
            $state['status'] = 'failed';
            $state['lastError'] = $error->getMessage();
            $this->stateStore->put($jobId, $state);
        }

        $this->redis->lPush(
            $this->config->failedKey(),
            json_encode([
                'jobId' => $jobId,
                'error' => $error->getMessage(),
                'time' => date(DATE_ATOM),
            ], JSON_THROW_ON_ERROR)
        );
    }

    public function retry(string $jobId): void
    {
        $state = $this->stateStore->get($jobId);

        if ($state === null) {
            return;
        }

        $job = ConversionJob::fromArray($state);

        if (!$job->canRetry()) {
            $job->markFailed();
            $this->stateStore->put($jobId, $job->toArray());
            $this->redis->lPush($this->config->deadLetterKey(), json_encode($job->toArray(), JSON_THROW_ON_ERROR));
            return;
        }

        $this->retryScheduler->schedule($job);
    }

    public function storeResult(AbstractJobResult $result): void
    {
        $this->resultStore->put($result);
        $this->stateStore->delete($result->jobId);
    }

    public function pullResult(string $jobId): ?ConversionJobResult
    {
        return $this->resultStore->get($jobId);
    }

    public function getResult(string $jobId): ?ConversionJobResult
    {
        return $this->resultStore->get($jobId);
    }

    public function popResult(?float $timeout = null): ?ConversionJobResult
    {
        $timeout = $timeout ?? (float) $this->config->readTimeout;

        $result = $this->redis->brPop(
            [$this->config->resultKey('')],
            (int) max(1, ceil($timeout))
        );

        if ($result === null) {
            return null;
        }

        $jobId = $result[1];
        return $this->resultStore->get($jobId);
    }

    public function getFailure(string $jobId): ?Throwable
    {
        return $this->failures[$jobId] ?? null;
    }

    public function stats(): array
    {
        return [
            'queue_length' => $this->redis->lLen($this->config->queueKey()),
            'retry_length' => $this->redis->zCard($this->config->retryKey()),
            'processing_length' => $this->redis->lLen($this->config->processingKey()),
            'failed_length' => $this->redis->lLen($this->config->failedKey()),
            'dead_length' => $this->redis->lLen($this->config->deadLetterKey()),
            'connected' => true,
            'database' => $this->config->database,
            'prefix' => $this->config->prefix,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->redis->lLen($this->config->queueKey()) === 0;
    }

    public function clear(): void
    {
        $this->redis->del(
            $this->config->queueKey(),
            $this->config->retryKey(),
            $this->config->processingKey(),
            $this->config->failedKey(),
            $this->config->deadLetterKey()
        );
    }

    public function exists(string $jobId): bool
    {
        return $this->stateStore->exists($jobId) || $this->resultStore->exists($jobId);
    }
}
