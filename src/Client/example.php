<?php

use Tabula17\Satelles\Odf\Adiutor\Client\AdiutorClientTcp;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

$config = new TCPServerConfig(host: '127.0.0.1', port: 9503);
$client = new AdiutorClientTcp($config);

// Conversión con barra de progreso
$client->convertFileWithProgress(
    filePath: '/path/to/large-document.odt',
    outputPath: '/path/to/output.pdf',
    format: 'pdf',
    onProgress: function($percent, $sent, $total) {
        printf("\rProgreso: %d%% (%s / %s)",
            $percent,
            formatBytes($sent),
            formatBytes($total)
        );
    }
);

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