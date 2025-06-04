<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver;

use Swoole\Coroutine\Socket;
use Tabula17\Satelles\Odf\Adiutor\Exceptions\RuntimeException;
use Tabula17\Satelles\Utilis\Console\VerboseTrait;

/**
 * UnoserverXmlRpcClient
 *
 * Cliente XML-RPC para interactuar con un servidor Unoserver.
 *
 * Esta clase permite enviar solicitudes de conversión de archivos a Unoserver
 * y manejar las respuestas, incluyendo la gestión de errores y la conversión
 * de datos en diferentes modos (stream o archivo).
 *
 * @package Tabula17\Satelles\Odf\Adiutor\Unoserver
 * @version 1.0.0
 * @since 1.0.0
 * @author Martín Panizzo <code.tabula17@gmail.com>
 */
class UnoserverXmlRpcClient
{
    use VerboseTrait;

    private array $server;
    private int $timeout;

    public function __construct(array $server, int $timeout = 5, private readonly int $verbose = 4)
    {
        $this->server = $server;
        $this->timeout = $timeout;
    }

    /**
     * Convierte un archivo o datos en un formato específico utilizando Unoserver.
     *
     * @param string $filePath Ruta del archivo a convertir (si se usa el modo 'stream', esta ruta se ignora).
     * @param string $outputFormat Formato de salida deseado (ej. 'pdf', 'odt').
     * @param string|null $fileContent Contenido del archivo a convertir (en modo 'stream').
     * @param string|null $outPath Ruta de salida para guardar el archivo convertido (solo si no se usa el modo 'stream').
     * @param string $mode Modo de conversión: 'stream' o 'file'.
     * @return string Ruta del archivo convertido o datos en formato base64 (modo 'stream').
     * @throws RuntimeException Si ocurre un error durante la conversión.
     * */
    public function convert(string $filePath, string $outputFormat, ?string $fileContent, ?string $outPath = null, string $mode = 'stream'): string
    {
        $requestXml = $this->buildXmlRequest(
            filePath: $filePath,
            outputFormat: $outputFormat,
            fileContent: $fileContent,
            outPath: $outPath,
            mode: $mode
        );
        $this->debug("[XML] Request: " . $requestXml); // Debug: muestra el XML de la solicitud
        $response = $this->sendRequest($requestXml);
        return $this->parseXmlResponse(
            httpResponse: $response,
            outPath: $outPath,
            mode: $mode
        );
    }

    public function ping(): bool
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);

        if (!$socket->connect($this->server['host'], $this->server['port'], $this->timeout)) {
            throw new RuntimeException("Connection failed: {$socket->errMsg}");
        }

        //$request = "POST /info HTTP/1.1\r\nHost: {$this->server['host']}\r\n\r\n";
        $xml = <<<XML
<?xml version="1.0"?>
<methodCall>
    <methodName>info</methodName>
</methodCall>
XML;
        $request = "POST / HTTP/1.1\r\n"
            . "Host: {$this->server['host']}\r\n"
            . "Content-Type: text/xml\r\n"
            . "Content-Length: " . strlen($xml) . "\r\n\r\n"
            . $xml;

        $socket->send($request);
        $response = $socket->recv(1024, $this->timeout);
        $socket->close();

        return strpos($response ?? '', '200 OK') !== false;
    }

    /**
     * Construye la solicitud XML-RPC para enviar al servidor Unoserver.
     *
     * @param string $filePath Ruta del archivo a convertir (si se usa el modo 'stream', esta ruta se ignora).
     * @param string $outputFormat Formato de salida deseado.
     * @param string|null $fileContent Contenido del archivo (en modo 'stream').
     * @param string|null $outPath Ruta de salida para guardar el archivo convertido (solo si no se usa el modo 'stream').
     * @param string $mode Modo de conversión: 'stream' o 'file'.
     * @return string XML completo para la solicitud.
     */
    private function buildXmlRequest(string $filePath, string $outputFormat, ?string $fileContent, ?string $outPath = null, string $mode = 'stream'): string
    {
        if ($mode === 'stream' && !empty($fileContent)) {
            $fileContent = '<base64>' . base64_encode($fileContent) . '</base64>';
        } else {
            $fileContent = $mode === 'stream' ? '<base64>' . base64_encode(file_get_contents($filePath)) . '</base64>' : '<nil/>';
        }
        $filePath = $mode === 'stream' ? '<nil/>' : '<string>' . $filePath . '</string>';
        //$outPath = $outPath ?? '<nil/>';
        $outPath = $mode === 'stream' ? '<nil/>' : '<string>' . $outPath . '</string>';

        return <<<XML
<?xml version="1.0"?>
<methodCall>
    <methodName>convert</methodName>
    <params>
        <param><value>{$filePath}</value></param> <!-- inpath -->
        <param><value>{$fileContent}</value></param> <!-- indata -->
        <param><value>{$outPath}</value></param> <!-- outpath -->
        <param><value><string>{$outputFormat}</string></value></param> <!-- convert_to -->
        <param><value><nil/></value></param>
        <param><value><array><data></data></array></value></param>
        <param><value><boolean>1</boolean></value></param>
        <param><value><nil/></value></param>
    </params>
</methodCall>
XML;
    }

    /**
     * Envía una solicitud XML-RPC al servidor Unoserver y recibe la respuesta.
     *
     * @param string $xml XML completo para la solicitud.
     * @return string Respuesta del servidor Unoserver.
     * @throws RuntimeException Si ocurre un error al enviar o recibir datos.
     */
    private function sendRequest(string $xml): string
    {
        $server = $this->server; // Implementa balanceo de carga aquí
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);

        // Conexión con timeout
        if (!$socket->connect($server['host'], $server['port'], $this->timeout)) {
            throw new RuntimeException("Connection failed: {$socket->errMsg}");
        }

        // Envía el request HTTP+XML
        $httpRequest = "POST / HTTP/1.1\r\n"
            . "Host: {$server['host']}\r\n"
            . "Content-Type: text/xml\r\n"
            . "Content-Length: " . strlen($xml) . "\r\n\r\n"
            . $xml;

        if ($socket->send($httpRequest) === false) {
            throw new RuntimeException("Send failed: {$socket->errMsg}");
        }

        // Recibe la respuesta (con timeout)
        $response = '';
        while (true) {
            $chunk = $socket->recv(8192, $this->timeout);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }

        $socket->close();
        return $response;
    }

    /**
     * Procesa la respuesta XML-RPC del servidor Unoserver.
     *
     * @param string $httpResponse Respuesta HTTP completa del servidor.
     * @param string|null $outPath Ruta de salida para guardar el archivo convertido (solo si no se usa el modo 'stream').
     * @param string $mode Modo de conversión: 'stream' o 'file'.
     * @return string Ruta del archivo convertido o datos en formato base64 (modo 'stream').
     * @throws RuntimeException Si ocurre un error al procesar la respuesta.
     */
    private function parseXmlResponse(string $httpResponse, ?string $outPath, string $mode): string
    {
        // Extrae el cuerpo HTTP (omitir headers)
        $xmlPos = strpos($httpResponse, "\r\n\r\n");
        $xml = $xmlPos !== false ? substr($httpResponse, $xmlPos + 4) : $httpResponse;
        $this->debug("[XML] mode Response: " . $mode); // Debug
        $xmlResponse = new \DOMDocument();
        $xmlResponse->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $faultNode = $xmlResponse->getElementsByTagName('fault')->item(0);
        if ($faultNode) {
            $faultCode = $faultNode->getElementsByTagName('value')->item(0)?->getElementsByTagName('int')->item(0)->nodeValue ?? '';
            $faultString = $faultNode->getElementsByTagName('value')->item(0)?->getElementsByTagName('string')->item(0)->nodeValue ?? '';
            $this->error("[XML] Fault Code: {$faultCode}, Fault String: {$faultString}"); // Debug
            throw new RuntimeException("XML-RPC Fault: {$faultCode} - {$faultString}");
        }
        $data = $outPath;
        $this->debug("[XML] Data inicial: " . var_export($data, true)); // Debug
        if ($mode === 'stream') {
            $this->debug("[XML] Data stream, buscamos el base64");
            $nodes = $xmlResponse->getElementsByTagName('base64');
            if ($nodes->length === 0) {
                $this->error('[XML] Invalid XML response: ' . $xml); // Debug
                throw new RuntimeException("Invalid XML-RPC response");
            }
            $data = $nodes->item(0)->nodeValue;
            if (empty($data)) {
                $this->error('[XML] Empty base64 data in response'); // Debug
                throw new RuntimeException("Empty base64 data in XML-RPC response");
            }
        }
        //$this->debug( "[XML] Data: " . var_export($data, true) ); // Debug
        return $data; // Ruta del archivo convertido o stream de datos
    }

    private function isVerbose(int $level): bool
    {
        $this->verboseIcon = '🛰️';
        return $level >= $this->verbose;
    }
}