<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Job;

use DateTimeImmutable;
use Swoole\Coroutine;
use Swoole\Http\Response;
use Swoole\Server;
use Tabula17\Satelles\Nexus\Utilis\Server\MimeTypes;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
use Tabula17\Satelles\Utilis\Config\AbstractDescriptor;
use Swoole\Coroutine\System;

class ConversionJobResult extends AbstractDescriptor
{/*
    public readonly string $jobId;
    public bool $success;
    public ?string $outputPath = null;
    public ?string $base64Content = null;
    public ?string $errorMessage = null;
    public ?string $serverHost = null;
    public ?int $serverPort = null;
    public ?string $startedAt = null;
    public ?string $finishedAt = null;
    public ?float $durationMs = null;*/

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string  $jobId,
        public readonly bool    $success,
        public readonly ?string $outputPath = null,
        public readonly ?string $base64Content = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $serverHost = null,
        public readonly ?int    $serverPort = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $finishedAt = null,
        public readonly ?float  $durationMs = null
    )
    {
        parent::__construct();/*
        $this->jobId = $jobId;
        $this->success = $success;
        $this->outputPath = $outputPath;
        $this->base64Content = $base64Content;
        $this->errorMessage = $errorMessage;
        $this->serverHost = $serverHost;
        $this->serverPort = $serverPort;
        $this->startedAt = $startedAt;
        $this->finishedAt = $finishedAt;
        $this->durationMs = $durationMs;*/

        $this->validate();
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobId: $data['jobId'] ?? '',
            success: (bool)($data['success'] ?? false),
            outputPath: $data['outputPath'] ?? null,
            base64Content: $data['base64Content'] ?? null,
            errorMessage: $data['errorMessage'] ?? null,
            serverHost: $data['serverHost'] ?? null,
            serverPort: isset($data['serverPort']) ? (int)$data['serverPort'] : null,
            startedAt: $data['startedAt'] ?? null,
            finishedAt: $data['finishedAt'] ?? null,
            durationMs: isset($data['durationMs']) ? (float)$data['durationMs'] : null
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function success(
        string             $jobId,
        ?string            $outputPath = null,
        ?string            $base64Content = null,
        ?string            $serverHost = null,
        ?int               $serverPort = null,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        ?float             $durationMs = null
    ): self
    {
        return new self(
            jobId: $jobId,
            success: true,
            outputPath: $outputPath,
            base64Content: $base64Content,
            serverHost: $serverHost,
            serverPort: $serverPort,
            startedAt: $startedAt?->format(DATE_ATOM),
            finishedAt: $finishedAt?->format(DATE_ATOM),
            durationMs: $durationMs
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function failure(
        string             $jobId,
        string             $errorMessage,
        ?string            $serverHost = null,
        ?int               $serverPort = null,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        ?float             $durationMs = null
    ): self
    {
        return new self(
            jobId: $jobId,
            success: false,
            errorMessage: $errorMessage,
            serverHost: $serverHost,
            serverPort: $serverPort,
            startedAt: $startedAt?->format(DATE_ATOM),
            finishedAt: $finishedAt?->format(DATE_ATOM),
            durationMs: $durationMs
        );
    }

    public function isStream(): bool
    {
        return $this->base64Content !== null && $this->base64Content !== '';
    }

    public function isFile(): bool
    {
        return $this->outputPath !== null && $this->outputPath !== '';
    }

    public function hasError(): bool
    {
        return !$this->success && $this->errorMessage !== null && $this->errorMessage !== '';
    }

    /**
     * Obtiene el contenido del archivo (carga completa en memoria)
     * ADVERTENCIA: Para archivos grandes, usar getFileStream() o streamToFile()
     *
     * @param bool $useCoroutine Si es true, usa Swoole\Coroutine\System::readFile()
     * @return string|null
     * @deprecated Para archivos > 50MB, usar getFileStream() o streamToFile()
     */
    public function getFileContent(bool $useCoroutine = true): ?string
    {
        // Si ya tenemos contenido en base64, devolver decodificado
        if ($this->base64Content !== null && !$this->isFile()) {
            return base64_decode($this->base64Content);
        }

        if ($this->isFile()) {
            $inCoroutine = Coroutine::getCid() > 0;

            // Verificar tamaño del archivo (advertencia si es muy grande)
            $fileSize = filesize($this->outputPath);
            if ($fileSize > 50 * 1024 * 1024) { // 50MB
                trigger_error(
                    "Archivo grande ({$fileSize} bytes). Considera usar getFileStream() o streamToFile()",
                    E_USER_WARNING
                );
            }

            if ($useCoroutine && $inCoroutine && class_exists(System::class)) {
                $content = Coroutine\System::readFile($this->outputPath);
                return $content !== false ? $content : null;
            }

            // Fallback síncrono
            if (file_exists($this->outputPath)) {
                // Usar file_get_contents con verificación de memoria
                $memoryLimit = $this->getMemoryLimit();
                if ($fileSize > $memoryLimit * 0.5) {
                    trigger_error(
                        "Archivo ({$fileSize} bytes) excede el 50% del memory_limit ({$memoryLimit} bytes)",
                        E_USER_WARNING
                    );
                }
                return file_get_contents($this->outputPath);
            }
        }

        return null;
    }

    /**
     * Obtiene el memory_limit en bytes
     */
    private function getMemoryLimit(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtoupper(substr($limit, -1));
        $value = (int)substr($limit, 0, -1);

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int)$limit,
        };
    }

    /**
     * Obtiene el contenido en base64 de forma eficiente
     *
     * @param bool $useCoroutine Si es true, usa lectura asíncrona
     * @return string|null
     */
    public function getStream(bool $useCoroutine = true): ?string
    {
        if ($this->base64Content !== null) {
            return $this->base64Content;
        }

        $content = $this->getFileContent($useCoroutine);
        return $content !== null ? base64_encode($content) : null;
    }

    /**
     * Escribe el contenido en un archivo de forma NO bloqueante
     *
     * @param string $path Ruta del archivo de destino
     * @param bool $useCoroutine Si es true, usa Swoole\Coroutine\System::writeFile()
     * @return int|false Número de bytes escritos o false en error
     */
    public function writeFile(string $path, bool $useCoroutine = true): int|false
    {
        $content = $this->getFileContent($useCoroutine);

        if ($content === null) {
            return false;
        }

        // Detectar automáticamente si estamos en corrutina
        $inCoroutine = Coroutine::getCid() > 0;

        if ($useCoroutine && $inCoroutine && class_exists(System::class)) {
            // Usar escritura asíncrona de Swoole (no bloquea)
            return Coroutine\System::writeFile($path, $content);
        }

        // Fallback síncrono
        return file_put_contents($path, $content) !== false ? strlen($content) : false;
    }

    /**
     * Escribe el contenido en un archivo usando streaming (chunks)
     * No carga el archivo completo en memoria
     *
     * @param string $path Ruta del archivo de destino
     * @param int $chunkSize Tamaño del chunk en bytes
     * @return int|false Número total de bytes escritos o false en error
     */
    public function streamToFile(string $path, int $chunkSize = 1048576): int|false
    {
        $destination = fopen($path, 'wb');

        if ($destination === false) {
            return false;
        }

        $totalBytes = 0;

        try {
            // Si ya tenemos base64, escribir directamente
            if ($this->base64Content !== null && !$this->isFile()) {
                $decoded = base64_decode($this->base64Content);
                $totalBytes = fwrite($destination, $decoded);
                return $totalBytes;
            }

            // Si tenemos archivo, hacer streaming chunk a chunk
            if ($this->isFile()) {
                $source = fopen($this->outputPath, 'rb');

                if ($source === false) {
                    return false;
                }

                try {
                    while (!feof($source)) {
                        $chunk = fread($source, $chunkSize);
                        $written = fwrite($destination, $chunk);

                        if ($written === false) {
                            return false;
                        }

                        $totalBytes += $written;

                        // En corrutina, dar oportunidad a otras tareas
                        if (Coroutine::getCid() > 0) {
                            Coroutine::sleep(0.001); // 1ms de pausa
                        }
                    }
                } finally {
                    fclose($source);
                }
            }

            return $totalBytes;
        } finally {
            fclose($destination);
        }
        return false;
    }

    /**
     * Obtiene el contenido del archivo como un recurso de stream
     * Útil para archivos grandes
     *
     * @return resource|null
     */
    public function getFileStream(int $chunkSize = 1048576): ?\Generator
    {
        if ($this->base64Content !== null && !$this->isFile()) {
            // Para contenido base64, decodificar por chunks
            $stream = fopen('php://temp', 'rb+');
            fwrite($stream, base64_decode($this->base64Content));
            rewind($stream);

            while (!feof($stream)) {
                yield fread($stream, $chunkSize);
            }
            fclose($stream);
            return;
        }

        if ($this->isFile() && $this->outputPath !== null) {
            $handle = fopen($this->outputPath, 'rb');

            if ($handle === false) {
                return null;
            }

            try {
                while (!feof($handle)) {
                    yield fread($handle, $chunkSize);
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * Envía el archivo directamente a una respuesta HTTP de Swoole (streaming)
     * Ideal para descargar archivos grandes sin consumir memoria
     *
     * @param Response $response
     * @param string|null $fileName Nombre del archivo para el cliente
     * @param int $chunkSize Tamaño del chunk
     */
    public function streamToHttpResponse(
        Response $response,
        ?string  $fileName = null,
        int      $chunkSize = 1048576
    ): void
    {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $mime = MimeTypes::fromExtension($ext)->mime() ?? 'application/octet-stream';

        $response->header('Content-Type', $mime);

        if ($fileName) {
            $response->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        }

        if ($this->isFile()) {
            // Usar sendfile para máximo rendimiento (zero-copy)
            if (function_exists('swoole_sendfile') && Coroutine::getCid() > 0) {
                $response->sendfile($this->outputPath);
                return;
            }

            // Fallback: streaming manual
            $handle = fopen($this->outputPath, 'rb');

            if ($handle === false) {
                $response->status(500);
                $response->end('Error al abrir archivo');
                return;
            }

            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                $response->write($chunk);
            }

            fclose($handle);
            $response->end();
            return;
        }

        if ($this->base64Content !== null) {
            // Para contenido base64, decodificar y enviar
            $response->end(base64_decode($this->base64Content));
            return;
        }

        $response->status(404);
        $response->end('No hay contenido disponible');
    }

    /**
     * Envía el archivo a través de una conexión TCP usando streaming
     *
     * Formato del protocolo:
     * - Header: 4 bytes con el tamaño total del archivo (N - network order)
     * - Body: chunks del archivo
     *
     * @param Server $server Instancia del servidor Swoole
     * @param int $fd File descriptor de la conexión
     * @param int $chunkSize Tamaño del chunk en bytes (default 1MB)
     * @return bool True si se envió correctamente
     */
    public function streamToTcp(Server $server, int $fd, int $chunkSize = 1048576): bool
    {
        // Caso 1: Tenemos archivo físico
        if ($this->isFile() && $this->outputPath !== null) {
            return $this->streamFileToTcp($server, $fd, $this->outputPath, $chunkSize);
        }

        // Caso 2: Tenemos contenido base64
        if ($this->base64Content !== null) {
            return $this->streamBase64ToTcp($server, $fd, $this->base64Content, $chunkSize);
        }

        // Enviar header de error (tamaño 0 indica error)
        $server->send($fd, pack('N', 0));
        return false;
    }

    /**
     * Envía un archivo físico por TCP usando streaming optimizado
     */
    private function streamFileToTcp(Server $server, int $fd, string $filePath, int $chunkSize): bool
    {
        if (!file_exists($filePath)) {
            $server->send($fd, pack('N', 0));
            return false;
        }

        $fileSize = filesize($filePath);
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            $server->send($fd, pack('N', 0));
            return false;
        }

        try {
            // 1. Enviar header con el tamaño total (4 bytes, big-endian)
            $server->send($fd, pack('N', $fileSize));

            // 2. Enviar el contenido en chunks
            $sentBytes = 0;
            $inCoroutine = Coroutine::getCid() > 0;

            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);

                if ($chunk === false) {
                    return false;
                }

                $server->send($fd, $chunk);
                $sentBytes += strlen($chunk);

                // En corrutina, dar oportunidad a otras tareas
                if ($inCoroutine && $sentBytes % ($chunkSize * 10) === 0) {
                    Coroutine::sleep(0.001); // 1ms de pausa cada 10 chunks
                }
            }

            return $sentBytes === $fileSize;

        } finally {
            fclose($handle);
        }
    }

    /**
     * Envía contenido base64 por TCP
     */
    private function streamBase64ToTcp(Server $server, int $fd, string $base64Content, int $chunkSize): bool
    {
        $decoded = base64_decode($base64Content);
        $totalSize = strlen($decoded);

        // Enviar header con el tamaño total
        $server->send($fd, pack('N', $totalSize));

        // Enviar contenido en chunks
        $offset = 0;
        $inCoroutine = Coroutine::getCid() > 0;

        while ($offset < $totalSize) {
            $chunk = substr($decoded, $offset, $chunkSize);
            $server->send($fd, $chunk);
            $offset += $chunkSize;

            if ($inCoroutine && $offset % ($chunkSize * 10) === 0) {
                Coroutine::sleep(0.001);
            }
        }

        return true;
    }

    /**
     * Versión con progreso (envía actualizaciones de progreso por TCP)
     *
     * Protocolo extendido:
     * - Header inicial: 4 bytes con tamaño total
     * - Por cada 10% de progreso: 1 byte (0xFF) + 1 byte (porcentaje)
     * - Chunks de datos
     *
     * @param Server $server
     * @param int $fd
     * @param bool $sendProgress Si es true, envía actualizaciones de progreso
     */
    public function streamToTcpWithProgress(
        Server $server,
        int    $fd,
        bool   $sendProgress = true,
        int    $chunkSize = 1048576
    ): bool
    {
        if (!$this->isFile()) {
            return $this->streamToTcp($server, $fd, $chunkSize);
        }

        if (!file_exists($this->outputPath)) {
            $server->send($fd, pack('N', 0));
            return false;
        }

        $fileSize = filesize($this->outputPath);
        $handle = fopen($this->outputPath, 'rb');

        if ($handle === false) {
            $server->send($fd, pack('N', 0));
            return false;
        }

        try {
            // Enviar header con tamaño total
            $server->send($fd, pack('N', $fileSize));

            $sentBytes = 0;
            $lastProgress = 0;
            $inCoroutine = Coroutine::getCid() > 0;

            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);

                if ($chunk === false) {
                    return false;
                }

                // Enviar progreso si es necesario
                if ($sendProgress) {
                    $currentProgress = (int)(($sentBytes / $fileSize) * 100);

                    if ($currentProgress > $lastProgress && $currentProgress % 10 === 0) {
                        // Enviar marcador de progreso: 0xFF + porcentaje
                        $server->send($fd, chr(0xFF) . chr($currentProgress));
                        $lastProgress = $currentProgress;
                    }
                }

                // Enviar chunk de datos
                $server->send($fd, $chunk);
                $sentBytes += strlen($chunk);

                if ($inCoroutine && $sentBytes % ($chunkSize * 10) === 0) {
                    Coroutine::sleep(0.001);
                }
            }

            // Enviar marcador de finalización
            if ($sendProgress) {
                $server->send($fd, chr(0xFF) . chr(100));
            }

            return $sentBytes === $fileSize;

        } finally {
            fclose($handle);
        }
    }

    /**
     * Valida el resultado
     *
     * @throws InvalidArgumentException
     */
    public function validate(): void
    {
        if ($this->jobId === '') {
            throw new InvalidArgumentException('jobId no puede estar vacío');
        }
    }
}