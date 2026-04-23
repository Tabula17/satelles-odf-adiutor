<?php

declare(strict_types=1);

namespace Tabula17\Satelles\Odf\Adiutor\Client;

use JsonException;
use Tabula17\Satelles\Nexus\Utilis\Client\BasisFileClientTcp;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;
use Tabula17\Satelles\Odf\Adiutor\Server\AdiutorActionsEnum;

class AdiutorClientTcp extends BasisFileClientTcp
{

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
     * @throws RuntimeException
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
     * Usa mode='stream' para recibir el contenido directamente en base64
     */
    public function convertFileToMemory(string $filePath, string $format = 'pdf'): string
    {
        $this->ensureConnected();

        // Solicitar conversión con modo stream (devuelve base64)
        $metadata = [
            'action' => AdiutorActionsEnum::Convert->path(),
            'outputFormat' => $format,
            'mode' => 'stream',  // ✅ Pedir base64 en lugar de archivo
        ];

        if (!$this->sendFileWithMetadata($filePath, $metadata)) {
            throw new RuntimeException('Error al enviar archivo');
        }

        // Recibir respuesta (puede ser JSON con base64 o streaming)
        return $this->receiveResponseToMemory();
    }

    /**
     * Convierte un archivo a memoria usando JSON simple (base64 en el request)
     * Útil para archivos pequeños que ya están en memoria
     */
    public function convertBase64ToMemory(string $base64Content, string $format = 'pdf'): string
    {
        $this->ensureConnected();

        $request = json_encode([
            'action' => AdiutorActionsEnum::Convert->path(),
            'outputFormat' => $format,
            'mode' => 'stream',
            'base64Content' => $base64Content,
        ]);

        $this->send($request);

        $response = $this->receiveJson();

        if (isset($response['error'])) {
            throw new RuntimeException('Error del servidor: ' . $response['error']);
        }

        if (isset($response['base64Content'])) {
            return base64_decode($response['base64Content']);
        }

        throw new RuntimeException('Respuesta inválida del servidor');
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

    public function getTargetHost(): string
    {
        return $this->serverCfg->host;
    }
}