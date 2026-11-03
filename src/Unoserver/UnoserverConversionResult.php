<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;

class UnoserverConversionResult extends AbstractDescriptor
{

    public function __construct(
        public readonly ConvertModeEnum $mode,
        public readonly string          $inputPath,
        public readonly ?string         $outputPath = null,
        public readonly ?string         $base64Content = null,
        public readonly ?string         $serverHost = null,
        public readonly ?int            $serverPort = null
    )
    {
        parent::__construct();
    }

    public function isStream(): bool
    {
        return $this->mode->isStreamMode();
    }

    public function isFile(): bool
    {
        return $this->mode->isFileMode();
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
