<?php
declare(strict_types=1);

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use Swoole\Coroutine;

class ServerHealthMonitor
{
    private array $servers;
    private array $serverStates = [];
    private int $checkInterval;
    private bool $monitoring = false;
    private int $failureThreshold;
    private int $retryTimeout;
    private $logger;

    public function __construct(
        array     $servers,
        int       $checkInterval = 60,
        int       $failureThreshold = 5,
        int       $retryTimeout = 300,
        ?callable $logger = null
    )
    {
        $this->servers = $servers;
        $this->checkInterval = $checkInterval;
        $this->failureThreshold = $failureThreshold;
        $this->retryTimeout = $retryTimeout;
        $this->logger = $logger ?? function ($message, $context = []) {
            echo date('[Y-m-d H:i:s]') . ' ServerHealthMonitor.php' . $message . PHP_EOL;
            if (!empty($context)) {
                echo  var_export($context, true) . PHP_EOL;
            }
        };

        $this->initializeServerStates();
    }

    private function initializeServerStates(): void
    {
        foreach ($this->servers as $index => $server) {
            $this->serverStates[$index] = [
                'status' => 'healthy',
                'failure_count' => 0,
                'last_failure' => null,
                'last_check' => null,
                'response_time' => null
            ];
        }
    }

    public function startMonitoring(): void
    {
        if ($this->monitoring) {
            return;
        }

        $this->monitoring = true;

        Coroutine::create(function () {
            while ($this->monitoring) {
                $this->runHealthChecks();
                Coroutine::sleep($this->checkInterval);
            }
        });
    }

    public function stopMonitoring(): void
    {
        $this->monitoring = false;
    }

    public function runHealthChecks(): void
    {
        foreach ($this->servers as $index => $server) {
            Coroutine::create(function () use ($index, $server) {
                try {
                    $start = microtime(true);

                    // Implementar check de salud real con UnoserverXmlRpcClient
                    $client = new UnoserverXmlRpcClient($server);
                    $healthy = $client->ping(); // Necesitarás implementar ping()

                    $this->updateServerState(
                        $index,
                        $healthy,
                        microtime(true) - $start
                    );
                } catch (\Throwable $e) {
                    $this->log(
                        'error',
                        "Health check failed for server {$server['host']}:{$server['port']}",
                        ['error' => $e->getMessage()]
                    );
                    $this->markServerFailed($index);
                }
            });
        }
    }

    public function isServerAvailable(int $serverIndex): bool
    {
        if (!isset($this->serverStates[$serverIndex])) {
            return false;
        }

        $state = $this->serverStates[$serverIndex];

        if ($state['status'] === 'healthy') {
            return true;
        }

        // Verificar si el tiempo de retry ha expirado
        if ($state['last_failure'] &&
            (time() - $state['last_failure']) > $this->retryTimeout) {
            $this->serverStates[$serverIndex]['status'] = 'healthy';
            $this->serverStates[$serverIndex]['failure_count'] = 0;
            return true;
        }

        return false;
    }

    public function markServerFailed(int $serverIndex): void
    {
        if (!isset($this->serverStates[$serverIndex])) {
            return;
        }

        $this->serverStates[$serverIndex]['failure_count']++;
        $this->serverStates[$serverIndex]['last_failure'] = time();

        if ($this->serverStates[$serverIndex]['failure_count'] >= $this->failureThreshold) {
            $this->serverStates[$serverIndex]['status'] = 'unhealthy';
            $this->log(
                'warning',
                "Server marked as unhealthy",
                [
                    'server' => $this->servers[$serverIndex],
                    'state' => $this->serverStates[$serverIndex]
                ]
            );
        }
    }

    public function markServerSuccess(int $serverIndex): void
    {
        if (!isset($this->serverStates[$serverIndex])) {
            return;
        }

        // Solo resetear el contador si estaba en estado problemático
        if ($this->serverStates[$serverIndex]['failure_count'] > 0) {
            $this->serverStates[$serverIndex]['failure_count'] = 0;
            $this->serverStates[$serverIndex]['status'] = 'healthy';
            $this->log(
                'info',
                "Server recovered",
                ['server' => $this->servers[$serverIndex]]
            );
        }
    }

    public function getHealthyServers(): array
    {
        return array_filter($this->servers, function ($server, $index) {
            return $this->isServerAvailable($index);
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function getServerState(int $serverIndex): array
    {
        return $this->serverStates[$serverIndex] ?? [
            'status' => 'unknown',
            'failure_count' => 0,
            'last_failure' => null,
            'last_check' => null
        ];
    }

    public function getAllServerStates(): array
    {
        return $this->serverStates;
    }

    private function updateServerState(int $index, bool $healthy, float $responseTime): void
    {
        $this->serverStates[$index]['last_check'] = time();
        $this->serverStates[$index]['response_time'] = $responseTime;

        if ($healthy) {
            $this->markServerSuccess($index);
        } else {
            $this->markServerFailed($index);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        ($this->logger)($message, array_merge([
            'level' => $level,
            'timestamp' => microtime(true)
        ], $context));
    }
}