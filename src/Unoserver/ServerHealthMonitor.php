<?php
declare(strict_types=1);

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;
use Tabula17\Satelles\Utilis\Config\ConnectionConfig;

class ServerHealthMonitor implements ServerHealthMonitorInterface
{
    protected(set) ConnectionCollection $servers;

    private array $serverStates = [];
    private int $checkInterval;
    private bool $monitoring = false;
    private int $failureThreshold;
    private int $retryTimeout;
    private ?LoggerInterface $logger;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        ConnectionCollection|array $servers,
        int $checkInterval = 60,
        int $failureThreshold = 5,
        int $retryTimeout = 300,
        ?LoggerInterface $logger = null
    ) {
        $this->servers = $this->normalizeServers($servers);
        $this->checkInterval = $checkInterval;
        $this->failureThreshold = $failureThreshold;
        $this->retryTimeout = $retryTimeout;
        $this->logger = $logger;

        $this->initializeServerStates();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function normalizeServers(ConnectionCollection|array $servers): ConnectionCollection
    {
        if ($servers instanceof ConnectionCollection) {
            if ($servers->isEmpty()) {
                throw new InvalidArgumentException('At least one valid server must be provided.');
            }

            return $servers;
        }

        $collection = new ConnectionCollection();

        foreach ($servers as $server) {
            if ($server instanceof ConnectionConfig) {
                $collection->add($server);
            }
        }

        if ($collection->isEmpty()) {
            throw new InvalidArgumentException('At least one valid server must be provided.');
        }

        return $collection;
    }

    private function initializeServerStates(): void
    {
        foreach ($this->servers as $index => $server) {
            $this->serverStates[$index] = [
                'status' => 'healthy',
                'failure_count' => 0,
                'last_failure' => null,
                'last_check' => null,
                'response_time' => null,
            ];
        }
    }

    public function startMonitoring(): void
    {
        if ($this->monitoring) {
            return;
        }

        $this->monitoring = true;

        Coroutine::create(function (): void {
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
            Coroutine::create(function () use ($index, $server): void {
                try {
                    $start = microtime(true);
                    $client = new UnoserverXmlRpcClient($server, $this->logger);
                    $healthy = $client->ping();

                    $this->updateServerState(
                        $index,
                        $healthy,
                        microtime(true) - $start
                    );
                } catch (\Throwable $e) {
                    $this->logger?->error(
                        'Health check failed',
                        [
                            'server' => $server->getSafeData(),
                            'error' => $e->getMessage(),
                        ]
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

        if (
            $state['last_failure'] !== null
            && (time() - $state['last_failure']) > $this->retryTimeout
        ) {
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
        }
    }

    public function markServerSuccess(int $serverIndex): void
    {
        if (!isset($this->serverStates[$serverIndex])) {
            return;
        }

        if ($this->serverStates[$serverIndex]['failure_count'] > 0) {
            $this->serverStates[$serverIndex]['failure_count'] = 0;
            $this->serverStates[$serverIndex]['status'] = 'healthy';
        }
    }

    public function getHealthyServers(): ConnectionCollection
    {
        return $this->servers->filter(
            fn(ConnectionConfig $server, int $index) => $this->isServerAvailable($index)
        );
    }

    public function getServerState(int $serverIndex): array
    {
        return $this->serverStates[$serverIndex] ?? [
            'status' => 'unknown',
            'failure_count' => 0,
            'last_failure' => null,
            'last_check' => null,
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
}