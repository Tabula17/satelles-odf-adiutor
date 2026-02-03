<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Socket;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\InvalidArgumentException;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;

class UnoserverConverterClient
{
    private array $defaultOptions;

    public function __construct(
        private readonly string  $host = 'localhost',
        private readonly int     $port = 2003,
        private ?int             $timeout = 30,
        array                    $defaultOptions = [],
        private readonly ?LoggerInterface $logger = null
    )
    {
        $this->defaultOptions = array_merge([
            'filter_name' => '',
            'filter_options' => [],
        ], $defaultOptions);
    }

    /**
     * Converts a file from one format to another using specified filters and options.
     *
     * @param string $inputPath The path of the input file to be converted.
     * @param string $outputPath The path where the converted file will be saved.
     * @param string $importFilter The name of the filter to be applied during the conversion process. Defaults to 'writer_pdf_Export'.
     * @param array $filterOptions Optional additional options for the filter, e.g., quality settings. Defaults to an empty array.
     * @return bool Returns true if the conversion was successful, false otherwise.
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function convert(
        string $inputPath,
        string $outputPath,
        string $importFilter = 'writer_pdf_Export',
        array  $filterOptions = []//'Quality' => 95
    ): bool
    {
        $this->logger?->debug("Inicio de conversión de archivo {$inputPath} a {$outputPath}");
        $this->validateFilePaths($inputPath, $outputPath);

        $options = array_merge($this->defaultOptions, [
            'filter_name' => $importFilter,
            'filter_options' => $filterOptions,
        ]);

        $methodCall = $this->buildXmlRequest('convert', [
            $inputPath,
            $outputPath,
            $options
        ]);

        $response = $this->sendRequest($methodCall);
        return $this->processXmlResponse($response);
    }

    /**
     * Pings the server to check if it is alive and responsive.
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $methodCall = $this->buildXmlRequest('ping', []);
            $response = $this->sendRequest($methodCall);
            return $this->processXmlResponse($response);
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Returns a list of supported output formats.
     * @return array
     * @throws RuntimeException|InvalidArgumentException
     */
    public function getSupportedFormats(): array
    {
        $methodCall = $this->buildXmlRequest('get_supported_formats', []);
        $response = $this->sendRequest($methodCall);

        return $this->processXmlResponse($response, true);
    }

    /**
     * Builds the XML request to be sent to the server.
     * @param string $methodName The name of the method to be called.
     * @param array $params The parameters to be passed to the method.
     * @return string The XML request.
     * @throws InvalidArgumentException
     */
    private function buildXmlRequest(string $methodName, array $params): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<methodCall>';
        $xml .= '<methodName>' . htmlspecialchars($methodName) . '</methodName>';

        if (!empty($params)) {
            $xml .= '<params>';
            foreach ($params as $param) {
                $xml .= '<param>' . $this->encodeValue($param) . '</param>';
            }
            $xml .= '</params>';
        }

        $xml .= '</methodCall>';
        $this->logger?->debug("XML de solicitud: {$xml}");
        return $xml;
    }

    /**
     * Encodes a PHP value into XML.
     * @param $value
     * @return string
     * @throws InvalidArgumentException
     */
    private function encodeValue($value): string
    {
        if (is_string($value)) {
            return '<value><string>' . htmlspecialchars($value) . '</string></value>';
        }

        if (is_int($value)) {
            return '<value><int>' . $value . '</int></value>';
        }

        if (is_bool($value)) {
            return '<value><boolean>' . ($value ? '1' : '0') . '</boolean></value>';
        }

        if (is_float($value)) {
            return '<value><double>' . $value . '</double></value>';
        }

        if (is_array($value)) {
            // Determinar si es array asociativo (struct) o indexado (array)
            if (array_is_list($value)) {
                // Array indexado
                return '<value><array><data>' .
                    implode('', array_map([$this, 'encodeValue'], $value)) .
                    '</data></array></value>';
            }

// Array asociativo (struct)
            $xml = '<value><struct>';
            foreach ($value as $key => $val) {
                $xml .= '<member>';
                $xml .= '<name>' . htmlspecialchars($key) . '</name>';
                $xml .= $this->encodeValue($val);
                $xml .= '</member>';
            }
            $xml .= '</struct></value>';
            return $xml;
        }

        throw new InvalidArgumentException('Tipo de dato no soportado: ' . gettype($value));
    }

    /**
     * Sends the XML request to the server and returns the response.
     * @param string $xml
     * @return string
     * @throws RuntimeException
     */
    private function sendRequest(string $xml): string
    {
        $this->logger?->debug("Inicio de envío (request) a: {$this->host}:{$this->port}");
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);

        if ($this->timeout) {
            $socket->setOption(SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
            $socket->setOption(SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
        }

        if (!$socket->connect($this->host, $this->port)) {
            $this->logger?->debug("Error al conectar al servidor {$this->host}:{$this->port}");
            throw new RuntimeException(
                "No se pudo conectar al servidor {$this->host}:{$this->port} - Error: {$socket->errCode}"
            );
        }

        $httpRequest = $this->buildHttpRequest($xml);

        if (!$socket->send($httpRequest)) {
            $this->logger?->debug("Error al enviar la solicitud al servidor");
            throw new RuntimeException('Error al enviar la solicitud al servidor');
        }

        $response = $socket->recv(1024 * 1024); // 1MB máximo
        $socket->close();

        if ($response === false || $response === '') {
            $this->logger?->debug('No se recibió respuesta del servidor');
            throw new RuntimeException('No se recibió respuesta del servidor');
        }

        return $this->extractXmlFromHttpResponse($response);
    }

    /**
     * Builds the HTTP request to be sent to the server.
     * @param string $xml
     * @return string
     */
    private function buildHttpRequest(string $xml): string
    {
        $contentLength = strlen($xml);
        $this->logger?->debug("Generando http request ($contentLength bytes)");

        return "POST / HTTP/1.1\r\n" .
            "Host: {$this->host}:{$this->port}\r\n" .
            "Content-Type: text/xml\r\n" .
            "Content-Length: {$contentLength}\r\n" .
            "Connection: close\r\n" .
            "\r\n" .
            $xml;
    }

    /**
     * Extracts the XML response from the HTTP response.
     * @param string $httpResponse
     * @return string
     * @throws RuntimeException
     */
    private function extractXmlFromHttpResponse(string $httpResponse): string
    {
        $this->logger?->debug("Extrayendo XML de respuesta");
        // Buscar el inicio del XML (después de los headers HTTP)
        $xmlStart = strpos($httpResponse, '<?xml');
        if ($xmlStart === false) {
            throw new RuntimeException('Respuesta HTTP no contiene XML válido');
        }

        $xml = substr($httpResponse, $xmlStart);

        // Limpiar posibles caracteres extra al final
        $xmlEnd = strpos($xml, '</methodResponse>');
        if ($xmlEnd !== false) {
            $xml = substr($xml, 0, $xmlEnd + 17); // +17 por la longitud de </methodResponse>
        }

        return $xml;
    }

    /**
     * Processes the XML response and returns the expected value.
     * @param string $xml
     * @param bool $returnValue
     * @return array|bool|float|int|string|null
     * @throws RuntimeException
     */
    private function processXmlResponse(string $xml, bool $returnValue = false): float|array|bool|int|string|null
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();

        if (!$dom->loadXML($xml)) {
            $errors = libxml_get_errors();
            $errorMsg = implode(', ', array_map(fn($error) => $error->message, $errors));
            throw new RuntimeException('XML de respuesta inválido: ' . $errorMsg);
        }

        // Verificar si es una respuesta de fault
        $fault = $dom->getElementsByTagName('fault')->item(0);
        if ($fault) {
            $faultValue = $fault->getElementsByTagName('value')->item(0);
            if ($faultValue) {
                $faultStruct = $this->decodeValue($faultValue);
            }

            $faultCode = $faultStruct['faultCode'] ?? 0;
            $faultString = $faultStruct['faultString'] ?? 'Error desconocido';

            throw new RuntimeException(
                "Error del servidor: {$faultString} (Código: {$faultCode})",
                $faultCode
            );
        }

        if ($returnValue) {
            $params = $dom->getElementsByTagName('param');
            if ($params->length > 0) {
                $valueNode = $params->item(0)?->getElementsByTagName('value')->item(0);
                return $this->decodeValue($valueNode ?? $params->item(0));
            }
            return null;
        }

        return true;
    }

    /**
     * Decodes a XML value into a PHP value.
     * @param \DOMElement $valueElement
     * @return array|bool|float|int|string
     */
    private function decodeValue(\DOMElement $valueElement): float|array|bool|int|string
    {
        $childNodes = $valueElement->childNodes;
        foreach ($childNodes as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                switch ($node->nodeName) {
                    case 'string':
                        return $node->textContent;
                    case 'int':
                    case 'i4':
                        return (int)$node->textContent;
                    case 'double':
                        return (float)$node->textContent;
                    case 'boolean':
                        return $node->textContent === '1';
                    case 'array':
                        $data = $node->getElementsByTagName('data')->item(0);
                        $values = [];
                        foreach ($data->getElementsByTagName('value') as $valueNode) {
                            $values[] = $this->decodeValue($valueNode);
                        }
                        return $values;
                    case 'struct':
                        $struct = [];
                        foreach ($node->getElementsByTagName('member') as $member) {
                            $name = $member->getElementsByTagName('name')->item(0)->textContent;
                            $value = $member->getElementsByTagName('value')->item(0);
                            $struct[$name] = $this->decodeValue($value);
                        }
                        return $struct;
                }
            }
        }

        return $valueElement->textContent;
    }

    /**
     * Validates the provided file paths to ensure the input file exists and is readable,
     * and the output directory is writable.
     *
     * @param string $inputPath The file path of the input file to validate.
     * @param string $outputPath The file path of the output file to validate.
     *
     * @return void
     *
     * @throws InvalidArgumentException If the input file does not exist, is not readable,
     * or the output directory is not writable.
     */
    private function validateFilePaths(string $inputPath, string $outputPath): void
    {
        if (!file_exists($inputPath)) {
            $this->logger?->debug("El archivo de entrada no existe: {$inputPath}");
            throw new InvalidArgumentException("El archivo de entrada no existe: {$inputPath}");
        }

        if (!is_readable($inputPath)) {
            $this->logger?->debug("El archivo de entrada no es legible: {$inputPath}");
            throw new InvalidArgumentException("El archivo de entrada no es legible: {$inputPath}");
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) || !is_writable($outputDir)) {
            $this->logger?->debug("El directorio de salida no es escribible: {$outputDir}");
            throw new InvalidArgumentException("El directorio de salida no es escribible: {$outputDir}");
        }
        $this->logger?->debug("Archivo de entrada: {$inputPath} válido");
        $this->logger?->debug("Archivo de salida $outputDir válido");
    }

    public function setDefaultOptions(array $options): void
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $options);
    }

    public function getDefaultOptions(): array
    {
        return $this->defaultOptions;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setTimeout(?int $timeout): void
    {
        $this->timeout = $timeout;
    }

}