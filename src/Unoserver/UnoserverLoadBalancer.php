<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use Generator;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;

/**
 *
 * Load Balancer para manejar múltiples servidores Unoserver
 * Permite distribuir solicitudes de conversión entre varios servidores
 * y gestionar métricas de rendimiento.
 * @package Tabula17\Satelles\Odf\Adiutor\Unoserver
 * @author Martín Panizzo <code.tabula17@gmail.com>
 * @version 1.0.0
 */
class UnoserverLoadBalancer
{
    private array $serverPool;
    private array $clientPool = [];
    private Channel $taskChannel;
    private bool $running = false;
    private array $metrics = [];
    private int $currentIndex = 0;

    /**
     *
     * @param array $servers
     * @param int $concurrency
     */
    public function __construct(private readonly ServerHealthMonitorInterface $healthMonitor, private readonly int $concurrency = 10, private readonly int $timeout = 10)
    {
        $this->serverPool = $servers = $healthMonitor->servers;
        $this->taskChannel = new Channel($this->concurrency * 2);

        // Inicializar métricas
        foreach ($servers as $index => $server) {
            $this->metrics[$index] = [
                'requests' => 0,
                'errors' => 0,
                'last_response_time' => 0,
                'active_connections' => 0
            ];
        }
    }

    /**
     * Inicia el worker para distribuir solicitudes entre los servidores.
     * Debe llamarse antes de enviar solicitudes.
     * @return void
     */
    public function start(): void
    {
        $this->running = true;
        // Worker para distribuir solicitudes
        Coroutine::create(function () {
            echo "[Worker] Iniciado. Canal abierto? " . $this->taskChannel->capacity . "\n";
            echo "[Worker] Iniciado: " . var_export($this->taskChannel->stats(), true) . "\n";
            echo $this->running ? "[Worker] Cargando..." : "[Worker] No está corriendo\n";
            while ($this->running) {
                $request = $this->taskChannel->pop(2); // Timeout de 15 segundos
                if ($request === false) {
                    if (!$this->running || $this->taskChannel->isEmpty()) {
                        break;
                    }
                    echo "[Worker] Timeout o canal cerrado\n";
                    continue;
                }
                echo "[Worker] Procesando solicitud ID: {$request['id']}\n";
                $serverIndex = $this->selectServer();
                $this->metrics[$serverIndex]['active_connections']++;
                Coroutine::create(function () use ($request, $serverIndex) {
                    try {
                        $start = microtime(true);
                        $response = $this->sendWithRetry($serverIndex, $request);
                        echo "Respuesta recibida del server $serverIndex\n"; // Debug
                        $time = (microtime(true) - $start) * 1000; // ms

                        $this->metrics[$serverIndex]['last_response_time'] = $time;
                        $this->metrics[$serverIndex]['requests']++;

                        $request['promise']->push([
                            'success' => true,
                            'request_id' => $request['id'],
                            'response' => $response,
                            'server' => $serverIndex
                        ]);
                    } catch (\Exception $e) {
                        $this->metrics[$serverIndex]['errors']++;
                        $request['promise']->push([
                            'success' => false,
                            'request_id' => $request['id'],
                            'error' => $e->getMessage(),
                            'server' => $serverIndex
                        ]);
                    } finally {
                        $this->metrics[$serverIndex]['active_connections']--;
                    }
                });
            }
        });
    }

    /**
     * Detiene el worker y cierra el canal de solicitudes.
     * @return void
     */
    public function stop(): void
    {
        $this->running = false;
        $this->taskChannel->close();
    }

    /**
     * Convierte un archivo de forma síncrona utilizando el balanceador de carga.
     *
     * @param string $filePath Ruta del archivo a convertir.
     * @param string|null $fileContent Contenido del archivo (opcional, si se usa modo 'stream').
     * @param string $outputFormat Formato de salida (por defecto 'pdf').
     * @param string|null $outPath Ruta de salida del archivo convertido (opcional).
     * @param string $mode Modo de operación ('stream' o 'file', por defecto 'stream').
     * @return string Ruta del archivo convertido.
     * @throws RuntimeException Si ocurre un error durante la conversión.
     */
    public function convertSync(string $filePath, ?string $fileContent = null, string $outputFormat = 'pdf', ?string $outPath = null, string $mode = 'stream'): string
    {
        $requestId = uniqid('conv_');
        $request = [
            'id' => $requestId,
            'file' => $filePath,
            'format' => $outputFormat,
            'out' => $outPath
        ];
        if ($mode === 'stream' && !empty($fileContent)) {
            $request['fileContent'] = $fileContent; // Agregar contenido del archivo si se proporciona
        }
        $serverIndex = $this->selectServer();
        $this->metrics[$serverIndex]['active_connections']++;

        return $this->sendToServer($serverIndex, $request, $mode);
    }

    /**
     * Convierte un archivo de forma asíncrona utilizando el balanceador de carga.
     *
     * @param string $filePath Ruta del archivo a convertir.
     * @param string|null $fileContent Contenido del archivo (opcional, si se usa modo 'stream').
     * @param string $outputFormat Formato de salida (por defecto 'pdf').
     * @param string|null $outPath Ruta de salida del archivo convertido (opcional).
     * @param string $mode Modo de operación ('stream' o 'file', por defecto 'stream').
     * @return Generator Ruta del archivo convertido o datos en formato base64 (modo 'stream').
     * @throws RuntimeException Si ocurre un error durante la conversión.
     */
    public function convertAsync(string $filePath, ?string $fileContent = null, string $outputFormat = 'pdf', ?string $outPath = null, string $mode = 'stream'): Generator
    {
        echo "[convertAsync] Inicio (Cid: " . Coroutine::getCid() . ")\n";
        $requestId = uniqid('conv_');
        echo "[convertAsync] Iniciamos $requestId a las " . date('Y-m-d H:i:s') . "\n";
        $promise = new Channel(1);
        echo "[convertAsync] Request ID: $requestId\n";
        $requestData = [
            'id' => $requestId,
            'file' => $filePath,
            'format' => $outputFormat,
            'out' => $outPath,
            'mode' => $mode,
            'promise' => $promise
        ];
        if ($mode === 'stream' && !empty($fileContent)) {
            $requestData['fileContent'] = $fileContent; // Agregar contenido del archivo si se proporciona
        }
        // Verifica estado del canal ANTES del push
        echo "[convertAsync] requestChannel stats: " . json_encode($this->taskChannel->stats()) . "\n";

        if ($this->taskChannel->push($requestData, 1.0) === false) {
            throw new RuntimeException("Error: Canal lleno o cerrado");
        }
        echo "[convertAsync] Request enviado al canal\n";
        $response = $promise->pop($this->timeout);

        if ($response === false) {
            throw new RuntimeException("Tiempo de espera agotado");
        }

        if (!$response['success']) {
            throw new RuntimeException($response['error']);
        }

        yield $response['response'];
    }

    /**
     * Selecciona un servidor del pool de forma equitativa.
     * Utiliza una estrategia de Round Robin con preferencia a servidores con menor carga.
     * @return int Índice del servidor seleccionado.
     */
    private function selectServer(): int
    {
        // Estrategia: Round Robin con fallback a menor carga
        $attempts = 0;
        $maxAttempts = count($this->serverPool) * 2;
        $healthyServers = $this->healthMonitor->getHealthyServers();
        echo "[selectServer] Intentando seleccionar servidor (Intentos: $attempts, Máximos: $maxAttempts)\n";
        echo "[selectServer] Servidores saludables: " . count($healthyServers) . "\n";
        while ($attempts++ < $maxAttempts) {
            $this->currentIndex = ($this->currentIndex + 1) % count($this->serverPool);
            $serverIndex = $this->currentIndex;
            if ($this->metrics[$serverIndex]['errors'] > 5 &&
                time() - $this->metrics[$serverIndex]['last_error_time'] < 300) {
                continue; // Saltar servidores con muchos errores recientes
            }
            // Preferir servidores con menos conexiones activas
            if ($this->metrics[$serverIndex]['active_connections'] < $this->concurrency) {
                $server = $this->serverPool[$serverIndex];
                if (!in_array($server, $healthyServers)) {
                    continue; // Saltar servidores no saludables
                }
                echo '[selectServer] Servidor seleccionado: ' . $server['host'] . ':' . $server['port'] . "\n"; // Debug
                return $serverIndex;
            }
        }

        $this->logHealthStatus('No healthy servers available, using fallback');
        // Fallback: seleccionar el servidor con mejor métrica
        return $this->selectBestServer();
    }

    /**
     * Selecciona el mejor servidor basado en métricas de rendimiento.
     * Utiliza una fórmula ponderada para calcular un puntaje:
     * - Conexiones activas (multiplicadas por 10)
     * - Tiempo de respuesta (en ms)
     * - Errores (multiplicados por 100)
     * @return int Índice del servidor con mejor puntaje.
     */
    private function selectBestServer(): int
    {
        $bestScore = PHP_FLOAT_MAX;
        $bestIndex = 0;

        foreach ($this->metrics as $index => $metric) {
            $score = $metric['active_connections'] * 10
                + $metric['last_response_time']
                + ($metric['errors'] * 100);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private function getXmlRpcClient(array $server): UnoserverXmlRpcClient
    {
        $key = "{$server['host']}:{$server['port']}";
        return $this->clientPool[$key] ??= new UnoserverXmlRpcClient($server);
    }

    /**
     * Envía una solicitud de conversión a un servidor Unoserver.
     *
     * @param int $serverIndex Índice del servidor en el pool.
     * @param array $request Datos de la solicitud.
     * @param string $mode Modo de operación ('stream' o 'file').
     * @return string Ruta del archivo convertido o datos en formato base64 (modo 'stream').
     * @throws RuntimeException Si ocurre un error al enviar o recibir datos.
     */
    private function doSendToServer(int $serverIndex, array $request): string
    {
        $server = $this->serverPool[$serverIndex];
        $client = $this->getXmlRpcClient($server);
        echo "[sendToServer] Conectando a {$server['host']}:{$server['port']}\n"; // Debug
        try {
            $fileContent = $request['fileContent'] ?? null;
            return $client->convert(
                filePath: $request['file'],
                outputFormat: $request['format'],
                fileContent: $fileContent,
                outPath: $request['out'],
                mode: $request['mode'] ?? 'stream'
            );
        } catch (RuntimeException $e) {
            // Limpieza en caso de error
            if (isset($request['out'])) {
                @unlink($request['out']);
            }
            throw $e;
        }
    }

    private function sendToServer(int $serverIndex, array $request): string
    {
        try {
            $result = $this->doSendToServer($serverIndex, $request);
            $this->healthMonitor->markServerSuccess($serverIndex);
            return $result;
        } catch (RuntimeException $e) {
            $this->healthMonitor->markServerFailed($serverIndex);
            throw $e;
        }
    }

    private function sendWithRetry(int $serverIndex, array $request, int $maxRetries = 3): string
    {
        $retryDelay = [100, 500, 1000]; // ms
        $lastError = null;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            echo "[sendWithRetry] Intento $attempt para servidor $serverIndex\n"; // Debug
            try {
                return $this->sendToServer($serverIndex, $request);
            } catch (\Exception $e) {
                $lastError = $e;
                $this->metrics[$serverIndex]['errors']++;

                // Delay exponencial solo si no es el último intento
                if ($attempt < $maxRetries - 1) {
                    Coroutine::sleep($retryDelay[$attempt] / 1000);
                    $this->selectServer(); // Cambiar de servidor para el reintento
                }
            }
        }

        throw new RuntimeException("Falló después de $maxRetries intentos: " . $lastError->getMessage());
    }

    /**
     * Obtiene las métricas de rendimiento de los servidores.
     * Incluye número de solicitudes, errores, tiempo de respuesta y conexiones activas.
     *
     * @return array Array con las métricas de cada servidor.
     */
    public function getServerMetrics(): array
    {
        return $this->metrics;
    }

    public function logHealthStatus(string $message, array $context = []): void
    {
        // Integrar con tu sistema de logging
        file_put_contents(
            __DIR__ . '/unoserver_health.log',
            date('[Y-m-d H:i:s]') . ' ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}