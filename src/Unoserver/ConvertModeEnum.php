<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

enum ConvertModeEnum
{
    case FILE;
    case STREAM;

    public function toString(): string
    {
        return match ($this) {
            self::FILE => 'file',
            self::STREAM => 'stream',
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::FILE => 'File mode: The input file is sent to the server, and the output is saved to a specified path.',
            self::STREAM => 'Stream mode: The input file is sent to the server as a base64-encoded string, and the output is returned as a stream.',
        };
    }

    public function isFileMode(): bool
    {
        return $this === self::FILE;
    }

    public function isStreamMode(): bool
    {
        return $this === self::STREAM;
    }

    public static function fromString(string $mode): self
    {
        return match (strtolower($mode)) {
            'file' => self::FILE,
            'stream' => self::STREAM,
            default => throw new \InvalidArgumentException("Invalid mode: {$mode}. Supported modes are 'file' and 'stream'."),
        };
    }
}
