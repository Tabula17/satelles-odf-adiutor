<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use Closure;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\Unoserver\UnoserverTransportException;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\Unoserver\UnoserverValidationException;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\Unoserver\UnoserverXmlRpcException;
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;
use Tabula17\Satelles\Utilis\Config\ConnectionConfig;
use Throwable;

class UnoserverLoadBalancer
{
    /**
     * @var ConnectionCollection
     */
    private ConnectionCollection $serverPool;

    /**
     * @var array<string, UnoserverXmlRpcClientInterface>
     */
    private array $clientPool = [];

    private array $metrics = [];
    private int $currentIndex = -1;
    private bool $running = false;

    public function __construct(
        private readonly ServerHealthMonitorInterface $healthMonitor,
        ConnectionCollection|array                    $servers,
        private readonly int                          $concurrency = 10,
        private int                                   $timeout = 10,
        private readonly ?LoggerInterface             $logger = null,
        private readonly int                          $maxRetries = 3,
        private readonly ?Closure                     $clientFactory = null
    )
    {
        $this->serverPool = $this->normalizeServerPool($servers);

        if ($this->serverPool->isEmpty()) {
            throw new UnoserverValidationException('El pool de servidores no puede estar vacío');
        }

        foreach ($this->serverPool as $index => $server) {
            $this->metrics[$index] = [
                'requests' => 0,
                'errors' => 0,
                'last_response_time' => 0.0,
                'active_connections' => 0,
                'last_error_time' => 0,
            ];
        }
    }

    public function start(): void
    {
        $this->healthMonitor->startMonitoring();
        $this->running = true;
    }

    public function stop(): void
    {
        $this->healthMonitor->stopMonitoring();
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function convertSync(
        string  $filePath,
        ?string $fileContent = null,
        string  $outputFormat = 'pdf',
        ?string $outPath = null,
        string  $mode = 'stream'
    ): UnoserverConversionResult
    {
        $serverIndex = $this->selectServer();
        $this->metrics[$serverIndex]['active_connections']++;

        try {
            return $this->sendWithRetry(
                serverIndex: $serverIndex,
                filePath: $filePath,
                fileContent: $fileContent,
                outputFormat: $outputFormat,
                outPath: $outPath,
                mode: $mode,
                maxRetries: $this->maxRetries
            );
        } finally {
            $this->metrics[$serverIndex]['active_connections']--;
        }
    }

    /**
     * @throws Throwable
     */
    public function convertAsync(
        string  $filePath,
        ?string $fileContent = null,
        string  $outputFormat = 'pdf',
        ?string $outPath = null,
        string  $mode = 'stream',
        ?int    $timeout = null
    ): UnoserverConversionResult
    {
        $timeout ??= $this->timeout;
        $resultChannel = new Channel(1);

        Coroutine::create(function () use (
            $resultChannel,
            $filePath,
            $fileContent,
            $outputFormat,
            $outPath,
            $mode
        ): void {
            try {
                $resultChannel->push([
                    'success' => true,
                    'result' => $this->convertSync(
                        filePath: $filePath,
                        fileContent: $fileContent,
                        outputFormat: $outputFormat,
                        outPath: $outPath,
                        mode: $mode
                    ),
                ]);
            } catch (Throwable $e) {
                $resultChannel->push([
                    'success' => false,
                    'error' => $e,
                ]);
            }
        });

        $response = $resultChannel->pop($timeout);

        if ($response === false) {
            throw new UnoserverTransportException('Tiempo de espera agotado esperando el resultado asíncrono');
        }

        if (!is_array($response) || !array_key_exists('success', $response)) {
            throw new UnoserverTransportException('Respuesta inválida del canal asíncrono');
        }

        if ($response['success'] === false) {
            /** @var Throwable $error */
            $error = $response['error'];

            if ($error instanceof UnoserverValidationException) {
                throw $error;
            }

            if ($error instanceof UnoserverTransportException) {
                throw $error;
            }

            if ($error instanceof UnoserverXmlRpcException) {
                throw $error;
            }

            throw new UnoserverTransportException(
                'Falló la conversión asíncrona: ' . $error->getMessage(),
                previous: $error
            );
        }

        /** @var UnoserverConversionResult $result */
        $result = $response['result'];

        return $result;
    }

    public function getServerMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * @return ConnectionCollection
     */
    public function getServerPool(): ConnectionCollection
    {
        return $this->serverPool;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTimeout(int $timeout): void
    {
        if ($timeout <= 0) {
            throw new UnoserverValidationException('El timeout debe ser mayor que cero');
        }

        $this->timeout = $timeout;
    }

    private function sendWithRetry(
        int     $serverIndex,
        string  $filePath,
        ?string $fileContent,
        string  $outputFormat,
        ?string $outPath,
        string  $mode,
        int     $maxRetries = 3
    ): UnoserverConversionResult
    {
        $lastException = null;
        $retryDelaysMs = [100, 500, 1000];

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $result = $this->sendToServer(
                    serverIndex: $serverIndex,
                    filePath: $filePath,
                    fileContent: $fileContent,
                    outputFormat: $outputFormat,
                    outPath: $outPath,
                    mode: $mode
                );

                $this->metrics[$serverIndex]['requests']++;
                $this->metrics[$serverIndex]['last_response_time'] = 0.0;

                return $result;
            } catch (UnoserverTransportException|UnoserverXmlRpcException $e) {
                $lastException = $e;
                $this->metrics[$serverIndex]['errors']++;
                $this->metrics[$serverIndex]['last_error_time'] = time();

                if ($attempt < $maxRetries - 1) {
                    Coroutine::sleep($retryDelaysMs[$attempt] / 1000);
                    $serverIndex = $this->selectServer();
                }
            }
        }

        throw new UnoserverTransportException(
            'Falló la conversión después de ' . $maxRetries . ' intentos: ' . $lastException?->getMessage(),
            previous: $lastException
        );
    }

    private function sendToServer(
        int     $serverIndex,
        string  $filePath,
        ?string $fileContent,
        string  $outputFormat,
        ?string $outPath,
        string  $mode
    ): UnoserverConversionResult
    {
        $server = $this->serverPool[$serverIndex];
        $client = $this->getXmlRpcClient($server);

        $start = microtime(true);

        try {
            $result = $client->convert(
                filePath: $filePath,
                outputFormat: $outputFormat,
                fileContent: $fileContent,
                outPath: $outPath,
                mode: $mode
            );

            $this->metrics[$serverIndex]['last_response_time'] = (microtime(true) - $start) * 1000;
            $this->healthMonitor->markServerSuccess($serverIndex);

            return $result;
        } catch (UnoserverTransportException|UnoserverXmlRpcException $e) {
            $this->metrics[$serverIndex]['last_response_time'] = (microtime(true) - $start) * 1000;
            $this->healthMonitor->markServerFailed($serverIndex);
            throw $e;
        }
    }

    private function selectServer(): int
    {
        $healthyServers = $this->healthMonitor->getHealthyServers();
        $serverCount = count($this->serverPool);

        if ($serverCount === 0) {
            throw new UnoserverValidationException('No hay servidores disponibles');
        }

        for ($attempt = 0; $attempt < $serverCount; $attempt++) {
            $this->currentIndex = ($this->currentIndex + 1) % $serverCount;
            $serverIndex = $this->currentIndex;
            $server = $this->serverPool[$serverIndex];

            if ($healthyServers?->contains($server) === false) {
                continue;
            }

            if ($this->metrics[$serverIndex]['active_connections'] >= $this->concurrency) {
                continue;
            }

            if (
                $this->metrics[$serverIndex]['errors'] > 5
                && (time() - $this->metrics[$serverIndex]['last_error_time']) < 300
            ) {
                continue;
            }

            return $serverIndex;
        }

        return $this->selectBestServer();
    }

    private function selectBestServer(): int
    {
        $bestScore = PHP_FLOAT_MAX;
        $bestIndex = 0;

        foreach ($this->metrics as $index => $metric) {
            $score = ($metric['active_connections'] * 10)
                + $metric['last_response_time']
                + ($metric['errors'] * 100);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private function getXmlRpcClient(ConnectionConfig $server): UnoserverXmlRpcClientInterface
    {
        $key = $server->host . ':' . $server->port;

        if (!isset($this->clientPool[$key])) {
            $this->clientPool[$key] = $this->createClient($server);
        }

        return $this->clientPool[$key];
    }

    private function createClient(ConnectionConfig $server): UnoserverXmlRpcClientInterface
    {
        if ($this->clientFactory !== null) {
            $client = ($this->clientFactory)($server, $this->timeout, $this->logger);

            if (!$client instanceof UnoserverXmlRpcClientInterface) {
                throw new UnoserverValidationException(
                    'La factory de cliente debe retornar una instancia de UnoserverXmlRpcClientInterface'
                );
            }

            return $client;
        }

        return new UnoserverXmlRpcClient(
            connection: $server,
            logger: $this->logger
        );
    }

    /**
     * @param ConnectionConfig[] $servers
     */
    private function normalizeServerPool(ConnectionCollection|array $servers): ConnectionCollection
    {
        if ($servers instanceof ConnectionCollection) {
            return $servers;
        }

        $collection = new ConnectionCollection();

        foreach ($servers as $server) {
            if ($server instanceof ConnectionConfig) {
                $collection->add($server);
            }
        }

        return $collection;
    }
}