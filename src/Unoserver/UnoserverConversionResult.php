<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

final readonly class UnoserverConversionResult
{
    public function __construct(
        public string $mode,
        public string $inputPath,
        public ?string $outputPath = null,
        public ?string $base64Content = null,
        public ?string $serverHost = null,
        public ?int $serverPort = null
    ) {
    }

    public function isStream(): bool
    {
        return $this->mode === 'stream';
    }

    public function isFile(): bool
    {
        return $this->mode === 'file';
    }

    public function hasBase64Content(): bool
    {
        return $this->base64Content !== null && $this->base64Content !== '';
    }

    public function hasOutputPath(): bool
    {
        return $this->outputPath !== null && $this->outputPath !== '';
    }
}
