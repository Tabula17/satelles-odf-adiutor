<?php

require __DIR__ . '/../../vendor/autoload.php';

use Swoole\Coroutine;
use Tabula17\Satelles\Odf\Adiutor\Client\AdiutorClientTcp;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

$config = new TCPServerConfig(['host' => '192.168.0.37', 'port' => 9508]);
$client = new AdiutorClientTcp($config);
$file = __DIR__ . '/../../examples/Report_8d3ebb0bb585.odt';
$output = __DIR__ . '/../../examples/output/Report_'.uniqid('', false).'_converted.pdf';

Coroutine\run(function () use ($client, $file, $output) {
    try {
        echo "✅ Conectado al servidor de conversión\n";
// Conversión con barra de progreso
        $client->convertFile(
            filePath: $file,
            outputPath: $output,
            format: 'pdf'
        );
    } catch (Exception $e) {
        echo "❌ Error al conectar: " . $e->getMessage() . "\n";
        return;
    }
});

echo "\n✅ Conversión completada\n";

// Función auxiliar para formatear bytes
function formatBytes($bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}