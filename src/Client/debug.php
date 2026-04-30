<?php

require __DIR__ . '/../../vendor/autoload.php';

use Swoole\Coroutine;
use Tabula17\Satelles\Odf\Adiutor\Client\AdiutorClientTcp;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

$config = new TCPServerConfig(['host' => '192.168.0.37', 'port' => 9508]);
$client = new AdiutorClientTcp($config);

Coroutine\run(function () use ($client) {
    try {
// Enviar solicitud de conversión
        $client->sendFileForConversion(__DIR__ . '/../../examples/Report_8d3ebb0bb585.odt');

// Recibir respuesta manualmente
        $typeByte = $client->recv(1);
        echo "Tipo: 0x" . bin2hex($typeByte) . "\n";

        $sizeBytes = $client->recv(4);
        $size = unpack('N', $sizeBytes)[1];
        echo "Tamaño: {$size}\n";

// Recibir y guardar el archivo
        $handle = fopen('output.pdf', 'wb');
        $received = 0;

        while ($received < $size) {
            $chunk = $client->recv(min(1048576, $size - $received));
            if ($chunk === false || $chunk === '') break;
            fwrite($handle, $chunk);
            $received += strlen($chunk);
            echo "Progreso: {$received}/{$size}\r";
        }

        fclose($handle);
        echo "\nRecibido: {$received} bytes\n";

// Verificar
        echo "Primeros bytes: " . bin2hex(file_get_contents('output.pdf', false, null, 0, 10)) . "\n";
    } catch (Exception $e) {
        echo "❌ Error al conectar: " . $e->getMessage() . "\n";
        return;
    }
});