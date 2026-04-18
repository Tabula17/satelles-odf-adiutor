<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue;

use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class RedisQueueConfig extends AbstractDescriptor
{
    public string $host = '127.0.0.1';
    public int $port = 6379;
    public ?string $password = null;
    public int $database = 0;
    public string $prefix = 'adiutor';
    public int $timeout = 2;
    public int $readTimeout = 2;
    public int $jobTtl = 86400;
    public int $resultTtl = 86400;
    public int $deadLetterTtl = 604800;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        ?string $password = null,
        int $database = 0,
        string $prefix = 'adiutor',
        int $timeout = 2,
        int $readTimeout = 2,
        int $jobTtl = 86400,
        int $resultTtl = 86400,
        int $deadLetterTtl = 604800
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->database = $database;
        $this->prefix = $prefix;
        $this->timeout = $timeout;
        $this->readTimeout = $readTimeout;
        $this->jobTtl = $jobTtl;
        $this->resultTtl = $resultTtl;
        $this->deadLetterTtl = $deadLetterTtl;

        parent::__construct();
        $this->validate();
    }

    public function validate(): void
    {
        if ($this->host === '') {
            throw new InvalidArgumentException('El host de Redis no puede estar vacío');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('El puerto de Redis debe estar entre 1 y 65535');
        }

        if ($this->database < 0) {
            throw new InvalidArgumentException('La base de datos de Redis no puede ser negativa');
        }

        if ($this->prefix === '') {
            throw new InvalidArgumentException('El prefijo de Redis no puede estar vacío');
        }
    }

    public function queueKey(): string
    {
        return $this->prefix . ':jobs:queue';
    }

    public function retryKey(): string
    {
        return $this->prefix . ':jobs:retry';
    }

    public function processingKey(): string
    {
        return $this->prefix . ':jobs:processing';
    }

    public function stateKey(string $jobId): string
    {
        return $this->prefix . ':jobs:state:' . $jobId;
    }

    public function resultKey(string $jobId): string
    {
        return $this->prefix . ':jobs:result:' . $jobId;
    }

    public function failedKey(): string
    {
        return $this->prefix . ':jobs:failed';
    }

    public function deadLetterKey(): string
    {
        return $this->prefix . ':jobs:dead';
    }
}