<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue;

use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJobResult;
use Throwable;

interface ConversionQueueInterface
{
    public function push(ConversionJob $job): string;

    public function pop(?float $timeout = null): ?ConversionJob;

    public function ack(string $jobId): void;

    public function fail(string $jobId, Throwable $error): void;

    public function retry(string $jobId): void;

    public function storeResult(ConversionJobResult $result): void;

    public function getResult(string $jobId): ?ConversionJobResult;

    public function popResult(?float $timeout = null): ?ConversionJobResult;

    public function getFailure(string $jobId): ?Throwable;

    public function stats(): array;

    public function isEmpty(): bool;

    public function clear(): void;
}