<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use Tabula17\Satelles\Utilis\File\MimeTypes;

interface UnoserverXmlRpcClientInterface
{
    public function convert(
        string $filePath,
        MimeTypes $outputFormat,
        ?string $fileContent,
        ?string $outputPath = null,
        ConvertModeEnum $mode = ConvertModeEnum::FILE
    ): UnoserverConversionResult;

    public function ping(): bool;

    public function getHost(): string;

    public function getPort(): int;

    public function getTimeout(): int;

    public function setTimeout(int $timeout): void;
}
