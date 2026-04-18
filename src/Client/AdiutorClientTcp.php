<?php

namespace Tabula17\Satelles\Odf\Adiutor\Client;

use Swoole\Coroutine\Client;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;
use Tabula17\Satelles\Odf\Adiutor\Server\AdiutorActionsEnum;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

class AdiutorClientTcp extends Client
{
    private const CHUNK_SIZE = 1048576; // 1MB

    public function __construct(
        protected TCPServerConfig $serverCfg,
        int $sockType = SOCK_STREAM
    ) {
        parent::__construct($sockType);
    }

    /**
     * Convierte un archivo y lo guarda en disco (modo streaming)
     *
     * @param string $filePath Ruta del archivo a convertir
     * @param string $outputPath Ruta donde guardar el archivo convertido
     * @param string $format Formato de salida (pdf, docx, etc.)
     * @return bool True si se convirtió y guardó correctamente
     * @throws RuntimeException Si hay error en la conversión
     */
    public function convertFile(string $filePath, string $outputPath, string $format = 'pdf'): bool
    {
        $this->ensureConnected();

        // Crear job con el contenido del archivo
        $job = new ConversionJob(
            filePath: $filePath,
            fileContent: ConversionJob::getContentFile($filePath),
            outputFormat: $format
        );

        // Enviar solicitud de conversión directa
        $request = json_encode([
            'action' => AdiutorActionsEnum::Convert->path(),
            ...$job->toArray()
        ]);

        if (!$this->send($request)) {
            throw new RuntimeException('Error al enviar solicitud: ' . $this->errCode);
        }

        // Recibir respuesta (puede ser JSON de error o streaming de archivo)
        return $this->receiveResponse($outputPath);
    }

    /**
     * Convierte un archivo y devuelve el contenido en memoria (para archivos pequeños)
     *
     * @param string $filePath Ruta del archivo a convertir
     * @param string $format Formato de salida
     * @return string Contenido del archivo convertido
     * @throws RuntimeException Si hay error
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
     * Espera a que un job termine y descarga el archivo
     */
    public function waitForFile(string $jobId, string $outputPath, int $timeout = 60): bool
    {
        $this->ensureConnected();

        $request = json_encode([
            'action' => AdiutorActionsEnum::Wait->path(),
            'jobId' => $jobId
        ]);

        $this->send($request);

        // Establecer timeout de lectura
        $this->set(['timeout' => $timeout]);

        return $this->receiveResponse($outputPath);
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
     * Recibe la respuesta del servidor (detecta si es JSON o streaming)
     * @throws RuntimeException
     */
    private function receiveResponse(string $outputPath): bool
    {
        // Leer los primeros bytes para detectar el tipo de respuesta
        $peek = $this->recv(1, MSG_PEEK);

        if ($peek === false || $peek === '') {
            throw new RuntimeException('Conexión cerrada inesperadamente');
        }

        // Si empieza con '{', es JSON (error o respuesta de estado)
        if ($peek === '{') {
            $json = $this->receiveJson();

            // Verificar si es un error
            if (isset($json['error'])) {
                throw new RuntimeException('Error del servidor: ' . $json['error']);
            }

            // Si tiene status failed, lanzar excepción
            if (($json['status'] ?? '') === 'failed') {
                throw new RuntimeException('Conversión fallida: ' . ($json['message'] ?? 'Unknown error'));
            }

            // Si no es error pero tampoco es archivo, devolver false
            return false;
        }

        // Si no es JSON, asumimos que es streaming de archivo
        return $this->receiveStreamToFile($outputPath);
    }

    /**
     * Recibe un archivo por streaming y lo guarda en disco
     */
    private function receiveStreamToFile(string $outputPath): bool
    {
        // 1. Leer header (4 bytes = tamaño total)
        $header = $this->recvAll(4);

        if ($header === false || strlen($header) < 4) {
            throw new RuntimeException('No se pudo leer el header del archivo');
        }

        $totalSize = unpack('N', $header)[1];

        // Si el tamaño es 0, el servidor indica error
        if ($totalSize === 0) {
            throw new RuntimeException('El servidor reportó error (tamaño 0)');
        }

        // 2. Crear archivo de destino
        $handle = fopen($outputPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo crear archivo: ' . $outputPath);
        }

        try {
            $receivedBytes = 0;

            // 3. Recibir chunks hasta completar
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

            // Si no se recibió completo, eliminar archivo parcial
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
     * @throws RuntimeException
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

            // Detectar inicio/fin de strings JSON
            if ($char === '"' && !$escape) {
                $inString = !$inString;
            }

            $escape = $char === '\\' && !$escape;

        } while ($depth > 0);

        $decoded = json_decode($data, true);

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