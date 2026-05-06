<?php

declare(strict_types=1);

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Job;

use DateTimeImmutable;
use Tabula17\Satelles\Nexus\Utilis\Protocol\AbstractFileJobResult;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;

class ConversionJobResult extends AbstractFileJobResult
{
    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string  $jobId,
        public readonly bool    $success,
        public readonly ?string $outputPath = null,
        public readonly ?string $base64Content = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $serverHost = null,
        public readonly ?int    $serverPort = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
        public readonly ?float  $durationMs = null
    )
    {
        parent::__construct();
        $this->validate();
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $config): static
    {
        return new static(
            jobId: $config['jobId'] ?? '',
            success: (bool)($config['success'] ?? false),
            outputPath: $config['outputPath'] ?? null,
            base64Content: $config['base64Content'] ?? null,
            errorMessage: $config['errorMessage'] ?? null,
            serverHost: $config['serverHost'] ?? null,
            serverPort: isset($config['serverPort']) ? (int)$config['serverPort'] : null,
            startedAt: $config['startedAt'] ?? null,
            finishedAt: $config['finishedAt'] ?? null,
            durationMs: isset($config['durationMs']) ? (float)$config['durationMs'] : null
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function success(
        string             $jobId,
        ?string            $outputPath = null,
        ?string            $base64Content = null,
        ?string            $serverHost = null,
        ?int               $serverPort = null,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        ?float             $durationMs = null
    ): self
    {
        return new self(
            jobId: $jobId,
            success: true,
            outputPath: $outputPath,
            base64Content: $base64Content,
            serverHost: $serverHost,
            serverPort: $serverPort,
            startedAt: $startedAt?->format(DATE_ATOM),
            finishedAt: $finishedAt?->format(DATE_ATOM),
            durationMs: $durationMs
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function failure(
        string             $jobId,
        string             $errorMessage,
        ?string            $serverHost = null,
        ?int               $serverPort = null,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        ?float             $durationMs = null
    ): self
    {
        return new self(
            jobId: $jobId,
            success: false,
            errorMessage: $errorMessage,
            serverHost: $serverHost,
            serverPort: $serverPort,
            startedAt: $startedAt?->format(DATE_ATOM),
            finishedAt: $finishedAt?->format(DATE_ATOM),
            durationMs: $durationMs
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validate(): void
    {
        if ($this->jobId === '') {
            throw new InvalidArgumentException('jobId no puede estar vacío');
        }
    }
}