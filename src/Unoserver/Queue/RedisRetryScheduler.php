<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue;

use Redis;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;

class RedisRetryScheduler
{
    private Redis $redis;

    public function __construct(
        private readonly RedisQueueConfig $config,
        private readonly RetryPolicy $retryPolicy
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

    public function schedule(ConversionJob $job): void
    {
        $job->markRetrying();

        $payload = json_encode($job->toArray(), JSON_THROW_ON_ERROR);
        $nextRetryAt = $this->retryPolicy->calculateNextRetryAt($job->attempts);

        $this->redis->zAdd(
            $this->config->retryKey(),
            $nextRetryAt,
            $payload
        );

        $this->redis->setex(
            $this->config->stateKey($job->id),
            $this->config->jobTtl,
            $payload
        );
    }

    /**
     * Promueve jobs cuyo retry ya está vencido a la cola principal.
     *
     * @return int Cantidad de jobs promovidos
     */
    public function promoteDueJobs(?int $now = null, int $batchSize = 100): int
    {
        $now ??= time();

        $dueJobs = $this->redis->zRangeByScore(
            $this->config->retryKey(),
            '-inf',
            (string) $now,
            ['limit' => [0, $batchSize]]
        );

        if ($dueJobs === false || $dueJobs === []) {
            return 0;
        }

        $promoted = 0;

        foreach ($dueJobs as $payload) {
            if (!is_string($payload) || $payload === '') {
                continue;
            }

            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                continue;
            }

            $job = ConversionJob::fromArray($data);
            $job->markQueued();

            $queuedPayload = json_encode($job->toArray(), JSON_THROW_ON_ERROR);

            $this->redis->multi();
            $this->redis->zRem($this->config->retryKey(), $payload);
            $this->redis->lPush($this->config->queueKey(), $queuedPayload);
            $this->redis->setex($this->config->stateKey($job->id), $this->config->jobTtl, $queuedPayload);
            $this->redis->exec();

            $promoted++;
        }

        return $promoted;
    }

    public function countDue(?int $now = null): int
    {
        $now ??= time();

        return (int) $this->redis->zCount(
            $this->config->retryKey(),
            '-inf',
            (string) $now
        );
    }

    public function stats(): array
    {
        return [
            'retry_length' => $this->redis->zCard($this->config->retryKey()),
            'due_count' => $this->countDue(),
        ];
    }

    public function clear(): void
    {
        $this->redis->del($this->config->retryKey());
    }
}
