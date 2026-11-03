<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

interface UnoserverXmlRpcClientInterface
{
    public function convert(
        string $filePath,
        string $outputFormat,
        ?string $fileContent,
        ?string $outPath = null,
        ConvertModeEnum $mode = ConvertModeEnum::FILE
    ): UnoserverConversionResult;

    public function ping(): bool;

    public function getHost(): string;

    public function getPort(): int;

    public function getTimeout(): int;

    public function setTimeout(int $timeout): void;
}
