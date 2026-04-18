<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Job;

use DateTimeImmutable;
use Swoole\Coroutine;
use Swoole\Coroutine\System;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class ConversionJob extends AbstractDescriptor
{
    public readonly string $id;
    public readonly string $filePath;
    public readonly string $outputFormat;
    public readonly string $mode;
    public readonly ?string $fileContent;
    public readonly ?string $outPath;
    public readonly array $metadata;
    public string $status;
    public int $attempts = 0;
    public int $maxAttempts;
    public int $priority = 0;
    public readonly string $createdAt;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        string  $filePath,
        string  $outputFormat,
        string  $mode = 'stream',
        ?string $fileContent = null,
        ?string $outPath = null,
        array   $metadata = [],
        ?string $id = null,
        ?string $status = null,
        int     $attempts = 0,
        int     $maxAttempts = 3,
        int     $priority = 0,
        ?string $createdAt = null
    )
    {
        $this->filePath = $filePath;
        $this->outputFormat = $outputFormat;
        $this->mode = $mode;
        $this->fileContent = $fileContent;
        $this->outPath = $outPath;
        $this->metadata = $metadata;
        $this->id = $id ?? $this->generateId();
        $this->status = $status ?? ConversionJobStatusEnum::Pending->value;
        $this->attempts = $attempts;
        $this->maxAttempts = $maxAttempts;
        $this->priority = $priority;
        $this->createdAt = $createdAt ?? new DateTimeImmutable()->format(DATE_ATOM);
        parent::__construct();

        $this->validate();
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): static
    {
        return new static(
            filePath: $data['filePath'] ?? '',
            outputFormat: $data['outputFormat'] ?? '',
            mode: $data['mode'] ?? 'stream',
            fileContent: $data['fileContent'] ?? null,
            outPath: $data['outPath'] ?? null,
            metadata: $data['metadata'] ?? [],
            id: $data['id'] ?? null,
            status: $data['status'] ?? null,
            attempts: (int)($data['attempts'] ?? 0),
            maxAttempts: (int)($data['maxAttempts'] ?? 3),
            priority: (int)($data['priority'] ?? 0),
            createdAt: $data['createdAt'] ?? null
        );
    }

    public function markQueued(): void
    {
        $this->status = ConversionJobStatusEnum::Queued->value;
    }

    public function markRunning(): void
    {
        $this->status = ConversionJobStatusEnum::Running->value;
    }

    public function markCompleted(): void
    {
        $this->status = ConversionJobStatusEnum::Completed->value;
    }

    public function markFailed(): void
    {
        $this->status = ConversionJobStatusEnum::Failed->value;
    }

    public function markRetrying(): void
    {
        $this->status = ConversionJobStatusEnum::Retrying->value;
        $this->attempts++;
    }

    public function cancel(): void
    {
        $this->status = ConversionJobStatusEnum::Cancelled->value;
    }

    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function withPriority(int $priority): self
    {
        $clone = clone $this;
        $clone->priority = $priority;

        return $clone;
    }

    public function withMaxAttempts(int $maxAttempts): self
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts debe ser mayor o igual a 1');
        }

        $clone = clone $this;
        $clone->maxAttempts = $maxAttempts;

        return $clone;
    }

    public function getStatusEnum(): ConversionJobStatusEnum
    {
        return ConversionJobStatusEnum::from($this->status);
    }
    public static function getContentFile(string $filePath): ?string
    {
        $inCoroutine = Coroutine::getCid() > 0;

        // Verificar tamaño del archivo (advertencia si es muy grande)
        $fileSize = filesize($filePath);
        if ($fileSize > 50 * 1024 * 1024) { // 50MB
            trigger_error(
                "Archivo grande ({$fileSize} bytes). Considera usar getFileStream() o streamToFile()",
                E_USER_WARNING
            );
        }

        if ( $inCoroutine && class_exists(System::class)) {
            $content = Coroutine\System::readFile($filePath);
            return $content !== false ? $content : null;
        }

        // Fallback síncrono
        if (file_exists($filePath)) {
            return file_get_contents($filePath);
        }
        return null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validate(): void
    {
        if ($this->filePath === '') {
            throw new InvalidArgumentException('filePath no puede estar vacío');
        }

        if ($this->outputFormat === '') {
            throw new InvalidArgumentException('outputFormat no puede estar vacío');
        }

        if (!in_array($this->mode, ['stream', 'file'], true)) {
            throw new InvalidArgumentException('mode debe ser "stream" o "file"');
        }

        if ($this->mode === 'file' && ($this->outPath === null || $this->outPath === '')) {
            throw new InvalidArgumentException('outPath es obligatorio en modo file');
        }

        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts debe ser mayor o igual a 1');
        }
    }

    private function generateId(): string
    {
        return 'job_' . bin2hex(random_bytes(8));
    }
}