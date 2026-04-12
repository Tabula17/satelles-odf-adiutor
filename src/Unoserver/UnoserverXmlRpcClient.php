<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use DOMDocument;
use DOMElement;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Socket;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\Unoserver\UnoserverTransportException;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\Unoserver\UnoserverValidationException;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\Unoserver\UnoserverXmlRpcException;
use Tabula17\Satelles\Utilis\Config\ConnectionConfig;
use Throwable;

readonly class UnoserverXmlRpcClient implements UnoserverXmlRpcClientInterface
{
    public function __construct(
        private ConnectionConfig $connection,
        private ?LoggerInterface $logger = null
    )
    {
        if (!$this->connection->canConnect()) {
            $safe = $this->connection->getSafeData();
            throw new UnoserverValidationException(
                'La configuración de conexión no es válida o no está disponible: ' . json_encode($safe)
            );
        }
    }

    public function convert(
        string  $filePath,
        string  $outputFormat,
        ?string $fileContent,
        ?string $outPath = null,
        string  $mode = 'stream'
    ): UnoserverConversionResult
    {
        $this->validateMode($mode);

        if ($mode === 'file') {
            $this->validateFilePaths($filePath, $outPath);
        } elseif ($fileContent === null) {
            $this->validateReadableFile($filePath);
        }

        $requestXml = $this->buildConvertRequest(
            filePath: $filePath,
            outputFormat: $outputFormat,
            fileContent: $fileContent,
            outPath: $outPath,
            mode: $mode
        );

        $responseXml = $this->sendXmlRpcRequest($requestXml);
        $this->assertValidXmlRpcResponse($responseXml);

        if ($mode === 'file') {
            return new UnoserverConversionResult(
                mode: $mode,
                inputPath: $filePath,
                outputPath: $outPath,
                serverHost: $this->connection->host,
                serverPort: $this->connection->port
            );
        }

        return new UnoserverConversionResult(
            mode: $mode,
            inputPath: $filePath,
            base64Content: $this->extractBase64Content($responseXml),
            serverHost: $this->connection->host,
            serverPort: $this->connection->port
        );
    }

    public function ping(): bool
    {
        try {
            $responseXml = $this->sendXmlRpcRequest($this->buildMethodRequest('info', []));
            $this->assertValidXmlRpcResponse($responseXml);

            return true;
        } catch (Throwable $e) {
            $this->logger?->warning('[UnoserverXmlRpcClient] Ping failed', [
                'connection' => $this->connection->getSafeData(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getHost(): string
    {
        return $this->connection->host;
    }

    public function getPort(): int
    {
        return $this->connection->port ?? 0;
    }

    public function getTimeout(): int
    {
        return $this->connection->get('delay') > 0 ? (int)ceil($this->connection->get('delay')) : 5;
    }

    public function setTimeout(int $timeout): void
    {
        if ($timeout <= 0) {
            throw new UnoserverValidationException('El timeout debe ser mayor que cero');
        }

        $this->connection->set('delay', $timeout);
    }

    private function buildConvertRequest(
        string  $filePath,
        string  $outputFormat,
        ?string $fileContent,
        ?string $outPath,
        string  $mode
    ): string
    {
        if ($mode === 'stream') {
            $binary = $fileContent ?? $this->readFileContents($filePath);
            $fileValue = $this->xmlValueNil();
            $dataValue = $this->xmlValueBase64($binary);
            $outValue = $this->xmlValueNil();
        } else {
            if ($outPath === null || $outPath === '') {
                throw new UnoserverValidationException('outPath es obligatorio en modo file');
            }

            $fileValue = $this->xmlValueString($filePath);
            $dataValue = $this->xmlValueNil();
            $outValue = $this->xmlValueString($outPath);
        }

        return $this->buildMethodRequest('convert', [
            $fileValue,
            $dataValue,
            $outValue,
            $this->xmlValueString($outputFormat),
            $this->xmlValueNil(),
            $this->xmlValueArray([]),
            $this->xmlValueBoolean(true),
            $this->xmlValueNil(),
        ]);
    }

    private function buildMethodRequest(string $methodName, array $params): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<methodCall>';
        $xml .= '<methodName>' . $this->xmlEscape($methodName) . '</methodName>';

        if ($params !== []) {
            $xml .= '<params>';
            foreach ($params as $param) {
                $xml .= '<param><value>' . $param . '</value></param>';
            }
            $xml .= '</params>';
        }

        $xml .= '</methodCall>';

        return $xml;
    }

    private function sendXmlRpcRequest(string $xml): string
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);

        try {
            $this->configureSocketTimeouts($socket);

            if (!$socket->connect($this->connection->host, $this->connection->port ?? 2003, 5)) {
                throw new UnoserverTransportException(
                    sprintf(
                        'No se pudo conectar con %s:%d - %s',
                        $this->connection->host,
                        $this->connection->port ?? 2003,
                        $socket->errMsg ?: 'error desconocido'
                    )
                );
            }

            $httpRequest = $this->buildHttpRequest($xml);

            if ($socket->send($httpRequest) === false) {
                throw new UnoserverTransportException(
                    sprintf(
                        'No se pudo enviar la solicitud a %s:%d - %s',
                        $this->connection->host,
                        $this->connection->port ?? 2003,
                        $socket->errMsg ?: 'error desconocido'
                    )
                );
            }

            $httpResponse = $this->receiveHttpResponse($socket);

            if ($httpResponse === '') {
                throw new UnoserverTransportException('La respuesta del servidor está vacía');
            }

            [$statusCode, , $body] = $this->splitHttpResponse($httpResponse);

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new UnoserverTransportException("HTTP error from Unoserver: {$statusCode}");
            }

            return $body;
        } finally {
            $socket->close();
        }
    }

    private function receiveHttpResponse(Socket $socket): string
    {
        $response = '';

        while (true) {
            $chunk = $socket->recv(8192, 5);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $response .= $chunk;
        }

        return $response;
    }

    private function splitHttpResponse(string $response): array
    {
        $separatorPosition = strpos($response, "\r\n\r\n");

        if ($separatorPosition === false) {
            throw new UnoserverTransportException('Respuesta HTTP inválida: no se encontraron headers');
        }

        $headers = substr($response, 0, $separatorPosition);
        $body = substr($response, $separatorPosition + 4);

        if (!preg_match('/^HTTP\/\d\.\d\s+(\d{3})/m', $headers, $matches)) {
            throw new UnoserverTransportException('Respuesta HTTP inválida: no se pudo determinar el código de estado');
        }

        return [(int)$matches[1], $headers, $body];
    }

    private function assertValidXmlRpcResponse(string $responseXml): void
    {
        $dom = $this->loadXmlDocument($responseXml);
        $this->assertNoFault($dom);
    }

    private function extractBase64Content(string $responseXml): string
    {
        $dom = $this->loadXmlDocument($responseXml);
        $this->assertNoFault($dom);

        $base64Node = $dom->getElementsByTagName('base64')->item(0);

        if ($base64Node === null) {
            throw new UnoserverXmlRpcException('La respuesta XML-RPC no contiene datos base64');
        }

        $base64 = trim($base64Node->textContent);

        if ($base64 === '') {
            throw new UnoserverXmlRpcException('La respuesta XML-RPC contiene base64 vacío');
        }

        return $base64;
    }

    private function loadXmlDocument(string $xml): DOMDocument
    {
        $previousState = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;

            if (!$dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING)) {
                $errors = libxml_get_errors();
                libxml_clear_errors();

                $errorMessages = array_map(
                    static fn($error) => trim($error->message),
                    $errors
                );

                throw new UnoserverXmlRpcException(
                    'XML de respuesta inválido: ' . implode(' | ', $errorMessages)
                );
            }

            return $dom;
        } finally {
            libxml_use_internal_errors($previousState);
        }
    }

    private function assertNoFault(DOMDocument $dom): void
    {
        $faultNode = $dom->getElementsByTagName('fault')->item(0);

        if ($faultNode === null) {
            return;
        }

        $faultData = $this->decodeFault($faultNode);
        $faultCode = $faultData['faultCode'] ?? 0;
        $faultString = $faultData['faultString'] ?? 'Error desconocido';

        throw new UnoserverXmlRpcException(
            sprintf('XML-RPC Fault: %s - %s', (string)$faultCode, $faultString),
            (int)$faultCode
        );
    }

    private function decodeFault(DOMElement $faultNode): array
    {
        $valueNode = $faultNode->getElementsByTagName('value')->item(0);

        if ($valueNode instanceof DOMElement) {
            $decoded = $this->decodeValue($valueNode);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function decodeValue(DOMElement $valueElement): float|array|bool|int|string|null
    {
        foreach ($valueElement->childNodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            return match ($node->nodeName) {
                'string' => $node->textContent,
                'int', 'i4' => (int)$node->textContent,
                'double' => (float)$node->textContent,
                'boolean' => $node->textContent === '1',
                'base64' => trim($node->textContent),
                'array' => $this->decodeArrayNode($node),
                'struct' => $this->decodeStructNode($node),
                default => $node->textContent,
            };
        }

        return $valueElement->textContent !== '' ? $valueElement->textContent : null;
    }

    private function decodeArrayNode(\DOMNode $arrayNode): array
    {
        $dataNode = null;

        foreach ($arrayNode->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'data') {
                $dataNode = $child;
                break;
            }
        }

        if (!$dataNode instanceof \DOMNode) {
            return [];
        }

        $result = [];

        foreach ($dataNode->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && $child->nodeName === 'value' && $child instanceof DOMElement) {
                $result[] = $this->decodeValue($child);
            }
        }

        return $result;
    }

    private function decodeStructNode(\DOMNode $structNode): array
    {
        $result = [];

        foreach ($structNode->childNodes as $member) {
            if ($member->nodeType !== XML_ELEMENT_NODE || $member->nodeName !== 'member') {
                continue;
            }

            $name = null;
            $value = null;

            foreach ($member->childNodes as $memberChild) {
                if ($memberChild->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }

                if ($memberChild->nodeName === 'name') {
                    $name = $memberChild->textContent;
                }

                if ($memberChild->nodeName === 'value' && $memberChild instanceof DOMElement) {
                    $value = $this->decodeValue($memberChild);
                }
            }

            if ($name !== null) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    private function buildHttpRequest(string $xml): string
    {
        $contentLength = strlen($xml);

        return "POST / HTTP/1.1\r\n"
            . "Host: {$this->connection->host}\r\n"
            . "Content-Type: text/xml; charset=UTF-8\r\n"
            . "Content-Length: {$contentLength}\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $xml;
    }

    private function readFileContents(string $filePath): string
    {
        $this->validateReadableFile($filePath);

        $contents = file_get_contents($filePath);

        if ($contents === false) {
            throw new UnoserverTransportException("No se pudo leer el archivo: {$filePath}");
        }

        return $contents;
    }

    private function validateMode(string $mode): void
    {
        if (!in_array($mode, ['stream', 'file'], true)) {
            throw new UnoserverValidationException("Modo no soportado: {$mode}");
        }
    }

    private function validateFilePaths(string $inputPath, ?string $outputPath): void
    {
        $this->validateReadableFile($inputPath);

        if ($outputPath === null || $outputPath === '') {
            throw new UnoserverValidationException('La ruta de salida es obligatoria en modo file');
        }

        $outputDir = dirname($outputPath);

        if (!is_dir($outputDir)) {
            throw new UnoserverValidationException("El directorio de salida no existe: {$outputDir}");
        }

        if (!is_writable($outputDir)) {
            throw new UnoserverValidationException("El directorio de salida no es escribible: {$outputDir}");
        }
    }

    private function validateReadableFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new UnoserverValidationException("El archivo no existe: {$path}");
        }

        if (!is_file($path)) {
            throw new UnoserverValidationException("La ruta no es un archivo válido: {$path}");
        }

        if (!is_readable($path)) {
            throw new UnoserverValidationException("El archivo no es legible: {$path}");
        }
    }

    private function configureSocketTimeouts(Socket $socket): void
    {
        $socket->setOption(SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);
        $socket->setOption(SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xmlValueString(string $value): string
    {
        return '<string>' . $this->xmlEscape($value) . '</string>';
    }

    private function xmlValueBoolean(bool $value): string
    {
        return '<boolean>' . ($value ? '1' : '0') . '</boolean>';
    }

    private function xmlValueNil(): string
    {
        return '<nil/>';
    }

    private function xmlValueBase64(string $value): string
    {
        return '<base64>' . base64_encode($value) . '</base64>';
    }

    private function xmlValueArray(array $values): string
    {
        $xml = '<array><data>';

        foreach ($values as $value) {
            $xml .= '<value>' . $value . '</value>';
        }

        $xml .= '</data></array>';

        return $xml;
    }
}