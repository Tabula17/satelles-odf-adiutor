<?php

require __DIR__ . '/../../vendor/autoload.php';
use Tabula17\Satelles\Odf\Adiutor\Client\AdiutorClientTcp;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

$config = new TCPServerConfig(['host' => '192.168.0.37', 'port' => 9508]);
$client = new AdiutorClientTcp($config);


\Swoole\Coroutine::create(function () use ($client) {
    try {
        echo "✅ Conectado al servidor de conversión\n";
// Conversión con barra de progreso
        $client->convertFileWithProgress(
            filePath: __DIR__ . '/../../examples/Report_8d3ebb0bb585.odt',
            outputPath: __DIR__ . '/../../examples/output/Report_8d3ebb0bb585_converted.pdf',
            format: 'pdf',
            onProgress: function ($percent, $sent, $total) {
                printf("\rProgreso: %d%% (%s / %s)",
                    $percent,
                    formatBytes($sent),
                    formatBytes($total)
                );
            }
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