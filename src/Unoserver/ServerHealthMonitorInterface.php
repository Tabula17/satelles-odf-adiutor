<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;

interface ServerHealthMonitorInterface
{
    public ConnectionCollection $servers {
        get;
    }

    public function startMonitoring(): void;

    public function stopMonitoring(): void;

    public function runHealthChecks(): void;

    public function isServerAvailable(int $serverIndex): bool;

    public function markServerFailed(int $serverIndex): void;

    public function markServerSuccess(int $serverIndex): void;

    public function getHealthyServers(): ConnectionCollection;

    public function getServerState(int $serverIndex): array;

    public function getAllServerStates(): array;
}