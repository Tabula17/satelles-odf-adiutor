<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue;

use Redis;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;

class RedisJobStateStore
{
    private Redis $redis;

    public function __construct(
        private readonly RedisQueueConfig $config
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

    public function put(string $jobId, array $state): void
    {
        $this->redis->setex(
            $this->config->stateKey($jobId),
            $this->config->jobTtl,
            json_encode($state, JSON_THROW_ON_ERROR)
        );
    }

    public function get(string $jobId): ?array
    {
        $payload = $this->redis->get($this->config->stateKey($jobId));

        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : null;
    }

    public function delete(string $jobId): void
    {
        $this->redis->del($this->config->stateKey($jobId));
    }

    public function exists(string $jobId): bool
    {
        return $this->redis->exists($this->config->stateKey($jobId)) > 0;
    }
}
