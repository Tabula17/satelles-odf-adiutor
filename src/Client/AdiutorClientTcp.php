<?php

declare(strict_types=1);

namespace Tabula17\Satelles\Odf\Adiutor\Client;

use Swoole\Coroutine\Client;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
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
        int $sockType = SOCK_STREAM
    ) {
        parent::__construct($sockType);
    }

    /**
     * Convierte un archivo y lo guarda en disco (modo streaming simple)
     *
     * @param string $filePath Ruta del archivo a convertir
     * @param string $outputPath Ruta donde guardar el archivo convertido
     * @param string $format Formato de salida (pdf, docx, etc.)
     * @return bool True si se convirtió y guardó correctamente
     * @throws RuntimeException|InvalidArgumentException
     */
    public function convertFile(string $filePath, string $outputPath, string $format = 'pdf'): bool
    {
        $this->ensureConnected();

        $job = new ConversionJob(
            filePath: $filePath,
            outputFormat: $format,
            fileContent: ConversionJob::getContentFile($filePath)
        );

        $request = json_encode([
            'action' => AdiutorActionsEnum::Convert->path(),
            ...$job->toArray()
        ]);

        if (!$this->send($request)) {
            throw new RuntimeException('Error al enviar solicitud: ' . $this->errCode);
        }

        return $this->receiveResponse($outputPath);
    }

    /**
     * Convierte un archivo con seguimiento de progreso
     *
     * @param string $filePath Ruta del archivo a convertir
     * @param string $outputPath Ruta donde guardar el archivo convertido
     * @param string $format Formato de salida
     * @param callable|null $onProgress Callback para progreso: function($percent, $sent, $total)
     * @return bool True si se convirtió y guardó correctamente
     * @throws RuntimeException
     */
    public function convertFileWithProgress(
        string $filePath,
        string $outputPath,
        string $format = 'pdf',
        ?callable $onProgress = null
    ): bool {
        $this->ensureConnected();

        $job = new ConversionJob(
            filePath: $filePath,
            outputFormat: $format,
            fileContent: ConversionJob::getContentFile($filePath)
        );

        // Añadir flag para solicitar progreso
        $requestData = $job->toArray();
        $requestData['action'] = AdiutorActionsEnum::Convert->path();
        $requestData['withProgress'] = true;

        $request = json_encode($requestData);

        if (!$this->send($request)) {
            throw new RuntimeException('Error al enviar solicitud: ' . $this->errCode);
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
     * Envía un job a la cola y devuelve el ID
     */
    public function submitJob(ConversionJob $job): string
    {
        $this->ensureConnected();

        $request = json_encode([
            'action' => AdiutorActionsEnum::Submit->path(),
            ...$job->toArray()
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
     *
     * @param string $jobId ID del trabajo
     * @param string $outputPath Ruta de salida
     * @param callable|null $onProgress Callback de progreso
     * @param int $timeout Timeout en segundos
     * @return bool
     */
    public function waitForFileWithProgress(
        string $jobId,
        string $outputPath,
        ?callable $onProgress = null,
        int $timeout = 60
    ): bool {
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
     * Descarga el archivo de un job ya completado (protocolo simple)
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
        string $jobId,
        string $outputPath,
        ?callable $onProgress = null
    ): bool {
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
}