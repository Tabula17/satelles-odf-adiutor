<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

interface ServerHealthMonitorInterface
{
    public array $servers {
        get;
    }

    public function startMonitoring(): void;

    public function stopMonitoring(): void;

    public function runHealthChecks(): void;

    public function isServerAvailable(int $serverIndex): bool;

    public function markServerFailed(int $serverIndex): void;

    public function markServerSuccess(int $serverIndex): void;

    public function getHealthyServers(): array;

    public function getServerState(int $serverIndex): array;

    public function getAllServerStates(): array;
}