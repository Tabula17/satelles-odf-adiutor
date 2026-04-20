<?php

declare(strict_types=1);

namespace Tabula17\Satelles\Odf\Adiutor\Client;

use JsonException;
use Swoole\Coroutine\Client;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;
use Tabula17\Satelles\Odf\Adiutor\Server\AdiutorActionsEnum;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

class AdiutorClientTcp extends Client
{
    private const int CHUNK_SIZE = 1048576; // 1MB

    // Constantes para el protocolo de framing
    private const FRAME_TYPE_DATA = 0x01;
    private const FRAME_TYPE_PROGRESS = 0x02;
    private const FRAME_TYPE_ERROR = 0x03;
    private const FRAME_TYPE_HEADER = 0x04;
    private const FRAME_TYPE_END = 0x05;

    public function __construct(
        protected TCPServerConfig $serverCfg,
        int                       $sockType = SOCK_STREAM
    )
    {
        parent::__construct($sockType);
    }

    /**
     * Envía un archivo junto con los metadatos del job
     *
     * @param string $filePath Ruta local del archivo a enviar
     * @param array $metadata Metadatos adicionales (action, outputFormat, etc.)
     * @return bool True si se envió correctamente
     * @throws RuntimeException
     */
    private function sendFileWithMetadata(string $filePath, array $metadata): bool
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Archivo no encontrado: {$filePath}");
        }

        $fileSize = filesize($filePath);
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir el archivo: {$filePath}");
        }

        try {
            // Añadir información del archivo a los metadatos
            $metadata['fileName'] = basename($filePath);
            $metadata['fileSize'] = $fileSize;

            $jsonMetadata = json_encode($metadata, JSON_THROW_ON_ERROR);
            $jsonLength = strlen($jsonMetadata);

            // 1. Enviar longitud del JSON (4 bytes)
            if (!$this->send(pack('N', $jsonLength))) {
                throw new RuntimeException('Error al enviar longitud de metadatos');
            }

            // 2. Enviar JSON de metadatos
            if (!$this->send($jsonMetadata)) {
                throw new RuntimeException('Error al enviar metadatos');
            }

            // 3. Enviar longitud del archivo (8 bytes, big-endian)
            if (!$this->send(pack('J', $fileSize))) {
                throw new RuntimeException('Error al enviar longitud de archivo');
            }

            // 4. Enviar archivo en chunks
            $sentBytes = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException('Error al leer archivo');
                }

                if (!$this->send($chunk)) {
                    throw new RuntimeException('Error al enviar chunk de archivo');
                }

                $sentBytes += strlen($chunk);
            }

            return $sentBytes === $fileSize;

        } finally {
            fclose($handle);
        }
    }

    /**
     * Convierte un archivo y lo guarda en disco
     * @throws RuntimeException
     */
    public function convertFile(string $filePath, string $outputPath, string $format = 'pdf'): bool
    {
        $this->ensureConnected();

        $metadata = [
            'action' => AdiutorActionsEnum::Convert->path(),
            'outputFormat' => $format,
        ];

        if (!$this->sendFileWithMetadata($filePath, $metadata)) {
            throw new RuntimeException('Error al enviar archivo');
        }

        return $this->receiveResponse($outputPath);
    }

    /**
     * Convierte un archivo con seguimiento de progreso
     */
    public function convertFileWithProgress(
        string    $filePath,
        string    $outputPath,
        string    $format = 'pdf',
        ?callable $onProgress = null
    ): bool
    {
        $this->ensureConnected();

        $metadata = [
            'action' => AdiutorActionsEnum::Convert->path(),
            'outputFormat' => $format,
            'withProgress' => true,
        ];

        if (!$this->sendFileWithMetadata($filePath, $metadata)) {
            throw new RuntimeException('Error al enviar archivo');
        }

        return $this->receiveResponseWithFraming($outputPath, $onProgress);
    }

    /**
     * Convierte un archivo y devuelve el contenido en memoria
     */
    public function convertFileToMemory(string $filePath, string $format = 'pdf'): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'adiutor_');

        try {
            $this->convertFile($filePath, $tempFile, $format);
            $content = file_get_contents($tempFile);

            if ($content === false) {
                throw new RuntimeException('No se pudo leer el archivo convertido');
            }

            return $content;
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Envía un job a la cola (con archivo)
     */
    public function submitJobWithFile(string $filePath, string $format = 'pdf', array $extraMetadata = []): string
    {
        $this->ensureConnected();

        $metadata = [
            'action' => AdiutorActionsEnum::Submit->path(),
            'outputFormat' => $format,
            ...$extraMetadata
        ];

        if (!$this->sendFileWithMetadata($filePath, $metadata)) {
            throw new RuntimeException('Error al enviar archivo');
        }

        $response = $this->receiveJson();

        if (!isset($response['jobId'])) {
            throw new RuntimeException('No se recibió jobId: ' . json_encode($response));
        }

        return $response['jobId'];
    }

    /**
     * Envía un job a la cola (solo metadatos, sin archivo)
     * @throws RuntimeException|JsonException
     */
    public function submitJob(
        ?string $filePath = null,
        ?string $fileContent = null,
        string  $format = 'pdf',
        array   $extraMetadata = []): string
    {
        $this->ensureConnected();
        if (!isset($fileContent, $filePath)) {
            throw new RuntimeException('Debe enviar un archivo o contenido');
        }
        $request = json_encode([
            'action' => AdiutorActionsEnum::Submit->path(),
            'filePath' => $filePath,
            'mode' => $filePath ? 'file' : 'stream',
            'outputFormat' => $format,
            'fileContent' => $fileContent,
            ...$extraMetadata
        ]);

        $this->send($request);
        $response = $this->receiveJson();

        if (!isset($response['jobId'])) {
            throw new RuntimeException('No se recibió jobId: ' . json_encode($response));
        }

        return $response['jobId'];
    }

    /**
     * Espera a que un job termine y descarga el archivo (protocolo simple)
     */
    public function waitForFile(string $jobId, string $outputPath, int $timeout = 60): bool
    {
        $this->ensureConnected();

        $request = json_encode([
            'action' => AdiutorActionsEnum::Wait->path(),
            'jobId' => $jobId
        ]);

        $this->send($request);
        $this->set(['timeout' => $timeout]);

        return $this->receiveResponse($outputPath);
    }

    /**
     * Espera a que un job termine con seguimiento de progreso
     */
    public function waitForFileWithProgress(
        string    $jobId,
        string    $outputPath,
        ?callable $onProgress = null,
        int       $timeout = 60
    ): bool
    {
        $this->ensureConnected();

        $request = json_encode([
            'action' => AdiutorActionsEnum::Wait->path(),
            'jobId' => $jobId,
            'withProgress' => true
        ]);

        $this->send($request);
        $this->set(['timeout' => $timeout]);

        return $this->receiveResponseWithFraming($outputPath, $onProgress);
    }

    /**
     * Consulta el estado de un job
     */
    public function getJobStatus(string $jobId): array
    {
        $this->ensureConnected();

        $request = json_encode([
            'action' => AdiutorActionsEnum::Status->path(),
            'jobId' => $jobId
        ]);

        $this->send($request);

        return $this->receiveJson();
    }

    /**
     * Cancela un job
     */
    public function cancelJob(string $jobId): array
    {
        $this->ensureConnected();

        $request = json_encode([
            'action' => AdiutorActionsEnum::Cancel->path(),
            'jobId' => $jobId
        ]);

        $this->send($request);

        return $this->receiveJson();
    }

    /**
     * Descarga el archivo de un job ya completado
     */
    public function getFile(string $jobId, string $outputPath): bool
    {
        $this->ensureConnected();

        $request = json_encode([
            'action' => AdiutorActionsEnum::GetFile->path(),
            'jobId' => $jobId
        ]);

        $this->send($request);

        return $this->receiveResponse($outputPath);
    }

    /**
     * Descarga el archivo de un job con progreso
     */
    public function getFileWithProgress(
        string    $jobId,
        string    $outputPath,
        ?callable $onProgress = null
    ): bool
    {
        $this->ensureConnected();

        $request = json_encode([
            'action' => AdiutorActionsEnum::GetFile->path(),
            'jobId' => $jobId,
            'withProgress' => true
        ]);

        $this->send($request);

        return $this->receiveResponseWithFraming($outputPath, $onProgress);
    }

    /**
     * Recibe la respuesta del servidor (detecta si es JSON o streaming simple)
     */
    private function receiveResponse(string $outputPath): bool
    {
        $peek = $this->recv(1, MSG_PEEK);

        if ($peek === false || $peek === '') {
            throw new RuntimeException('Conexión cerrada inesperadamente');
        }

        if ($peek === '{') {
            $json = $this->receiveJson();

            if (isset($json['error'])) {
                throw new RuntimeException('Error del servidor: ' . $json['error']);
            }

            if (($json['status'] ?? '') === 'failed') {
                throw new RuntimeException('Conversión fallida: ' . ($json['message'] ?? 'Unknown error'));
            }

            return false;
        }

        return $this->receiveStreamToFile($outputPath);
    }

    /**
     * Recibe respuesta con protocolo de framing (soporta progreso)
     *
     * @param string $outputPath Ruta donde guardar el archivo
     * @param callable|null $onProgress Callback para progreso
     * @return bool
     * @throws RuntimeException
     */
    private function receiveResponseWithFraming(string $outputPath, ?callable $onProgress = null): bool
    {
        $handle = fopen($outputPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo crear archivo: ' . $outputPath);
        }

        $totalSize = 0;
        $receivedBytes = 0;
        $jobId = null;

        try {
            while (true) {
                // Leer tipo de frame (1 byte)
                $typeByte = $this->recv(1);

                if ($typeByte === false || $typeByte === '') {
                    break;
                }

                $type = ord($typeByte);

                // Leer longitud (4 bytes)
                $lengthBytes = $this->recvAll(4);

                if ($lengthBytes === false || strlen($lengthBytes) < 4) {
                    throw new RuntimeException('Frame incompleto: no se pudo leer longitud');
                }

                $length = unpack('N', $lengthBytes)[1];

                // Leer datos del frame
                $data = $this->recvAll($length);

                if ($data === false || strlen($data) < $length) {
                    throw new RuntimeException('Frame incompleto: datos insuficientes');
                }

                switch ($type) {
                    case self::FRAME_TYPE_HEADER:
                        $header = json_decode($data, true);
                        $totalSize = $header['size'] ?? 0;
                        $jobId = $header['jobId'] ?? null;
                        break;

                    case self::FRAME_TYPE_DATA:
                        fwrite($handle, $data);
                        $receivedBytes += strlen($data);
                        break;

                    case self::FRAME_TYPE_PROGRESS:
                        if ($onProgress) {
                            $progress = json_decode($data, true);
                            $onProgress(
                                $progress['progress'] ?? 0,
                                $progress['sent'] ?? 0,
                                $progress['total'] ?? $totalSize
                            );
                        }
                        break;

                    case self::FRAME_TYPE_ERROR:
                        $error = json_decode($data, true);
                        throw new RuntimeException($error['error'] ?? 'Error desconocido');

                    case self::FRAME_TYPE_END:
                        $endData = json_decode($data, true);
                        return $endData['success'] ?? ($receivedBytes === $totalSize);

                    default:
                        throw new RuntimeException('Tipo de frame desconocido: ' . $type);
                }
            }

            return $receivedBytes === $totalSize;

        } catch (\Throwable $e) {
            throw new RuntimeException('Error recibiendo archivo: ' . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);

            if ($receivedBytes !== $totalSize && $totalSize > 0) {
                @unlink($outputPath);
            }
        }
    }

    /**
     * Recibe un archivo por streaming simple y lo guarda en disco
     */
    private function receiveStreamToFile(string $outputPath): bool
    {
        $header = $this->recvAll(4);

        if ($header === false || strlen($header) < 4) {
            throw new RuntimeException('No se pudo leer el header del archivo');
        }

        $totalSize = unpack('N', $header)[1];

        if ($totalSize === 0) {
            throw new RuntimeException('El servidor reportó error (tamaño 0)');
        }

        $handle = fopen($outputPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo crear archivo: ' . $outputPath);
        }

        try {
            $receivedBytes = 0;

            while ($receivedBytes < $totalSize) {
                $remaining = $totalSize - $receivedBytes;
                $readSize = min(self::CHUNK_SIZE, $remaining);

                $chunk = $this->recv($readSize);

                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Conexión interrumpida durante la transferencia');
                }

                fwrite($handle, $chunk);
                $receivedBytes += strlen($chunk);
            }

            return $receivedBytes === $totalSize;

        } finally {
            fclose($handle);

            if ($receivedBytes !== $totalSize) {
                @unlink($outputPath);
            }
        }
    }

    /**
     * Recibe exactamente N bytes
     */
    private function recvAll(int $length): string|false
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = $this->recv($remaining);

            if ($chunk === false || $chunk === '') {
                return false;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * Recibe una respuesta JSON completa
     */
    private function receiveJson(): array
    {
        $data = '';
        $depth = 0;
        $inString = false;
        $escape = false;

        do {
            $char = $this->recv(1);

            if ($char === false || $char === '') {
                break;
            }

            $data .= $char;

            if (!$inString) {
                if ($char === '{' || $char === '[') {
                    $depth++;
                } elseif ($char === '}' || $char === ']') {
                    $depth--;
                }
            }

            if ($char === '"' && !$escape) {
                $inString = !$inString;
            }

            $escape = $char === '\\' && !$escape;

        } while ($depth > 0);

        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta JSON inválida: ' . $data);
        }

        return $decoded;
    }

    /**
     * Asegura que la conexión está establecida
     */
    private function ensureConnected(): void
    {
        if (!$this->isConnected()) {
            $connected = $this->connect($this->serverCfg->host, $this->serverCfg->port);

            if (!$connected) {
                throw new RuntimeException(
                    sprintf('No se pudo conectar a %s:%s', $this->serverCfg->host, $this->serverCfg->port)
                );
            }
        }
    }

    public function getTargetHost(): string
    {
        return $this->serverCfg->host;
    }
}