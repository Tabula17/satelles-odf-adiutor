<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Job;

use DateTimeImmutable;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class ConversionJobResult extends AbstractDescriptor
{
    public string $jobId;
    public bool $success;
    public ?string $outputPath = null;
    public ?string $base64Content = null;
    public ?string $errorMessage = null;
    public ?string $serverHost = null;
    public ?int $serverPort = null;
    public ?string $startedAt = null;
    public ?string $finishedAt = null;
    public ?float $durationMs = null;

    public function __construct(
        string $jobId,
        bool $success,
        ?string $outputPath = null,
        ?string $base64Content = null,
        ?string $errorMessage = null,
        ?string $serverHost = null,
        ?int $serverPort = null,
        ?string $startedAt = null,
        ?string $finishedAt = null,
        ?float $durationMs = null
    ) {
        parent::__construct();
        $this->jobId = $jobId;
        $this->success = $success;
        $this->outputPath = $outputPath;
        $this->base64Content = $base64Content;
        $this->errorMessage = $errorMessage;
        $this->serverHost = $serverHost;
        $this->serverPort = $serverPort;
        $this->startedAt = $startedAt;
        $this->finishedAt = $finishedAt;
        $this->durationMs = $durationMs;

        $this->validate();
    }

    public static function fromArray(array $data): self
    {
        return new self(
            jobId: $data['jobId'] ?? '',
            success: (bool)($data['success'] ?? false),
            outputPath: $data['outputPath'] ?? null,
            base64Content: $data['base64Content'] ?? null,
            errorMessage: $data['errorMessage'] ?? null,
            serverHost: $data['serverHost'] ?? null,
            serverPort: isset($data['serverPort']) ? (int)$data['serverPort'] : null,
            startedAt: $data['startedAt'] ?? null,
            finishedAt: $data['finishedAt'] ?? null,
            durationMs: isset($data['durationMs']) ? (float)$data['durationMs'] : null
        );
    }

    public static function success(
        string $jobId,
        ?string $outputPath = null,
        ?string $base64Content = null,
        ?string $serverHost = null,
        ?int $serverPort = null,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        ?float $durationMs = null
    ): self {
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

    public static function failure(
        string $jobId,
        string $errorMessage,
        ?string $serverHost = null,
        ?int $serverPort = null,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        ?float $durationMs = null
    ): self {
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

    public function isStream(): bool
    {
        return $this->base64Content !== null && $this->base64Content !== '';
    }

    public function isFile(): bool
    {
        return $this->outputPath !== null && $this->outputPath !== '';
    }

    public function hasError(): bool
    {
        return !$this->success && $this->errorMessage !== null && $this->errorMessage !== '';
    }

    public function validate(): void
    {
        if ($this->jobId === '') {
            throw new InvalidArgumentException('jobId no puede estar vacío');
        }
    }
}