<?php

require __DIR__ . '/../vendor/autoload.php';

use Swoole\Coroutine;
use Tabula17\Satelles\Odf\Adiutor\Client\AdiutorClientTcp;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

$config = new TCPServerConfig(['host' => '192.168.0.37', 'port' => 9508]);
$client = new AdiutorClientTcp($config);

$fileList = glob(__DIR__ . '/*.odt') ?: [];

Coroutine\run(function () use ($client, $fileList) {
    foreach ($fileList as $file) {
        echo "📄 Archivo: " . basename($file) . " (" . formatBytes(filesize($file)) . ")\n";
        $output = __DIR__ . '/output/Report_' . uniqid('', false) . '_converted.pdf';
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