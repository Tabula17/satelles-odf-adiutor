<?php
require __DIR__ . '/../vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;
use Tabula17\Satelles\Utilis\Config\ConnectionConfig;

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);


$servers= new ConnectionCollection(
    new ConnectionConfig([
        'name' => 'unoserver-2004',
        'host' => '127.0.0.1',
        'port' => 2004,
    ]),
    new ConnectionConfig([
        'name' => 'unoserver-2003',
        'host' => '127.0.0.1',
        'port' => 2003,
    ])
);

$healthMonitor = new ServerHealthMonitor(
    servers: $servers,
    checkInterval: 30,
    failureThreshold: 3,
    retryTimeout: 60
);

$converter = new UnoserverLoadBalancer(
    healthMonitor: $healthMonitor,
    servers: $servers,
    concurrency: 20,
    timeout: 15
);

$fileList = glob(__DIR__ . DIRECTORY_SEPARATOR . '*.odt') ?: [];

Coroutine\run(function () use ($converter, $fileList, $healthMonitor): void {
    $healthMonitor->startMonitoring();
    $converter->start();

    $results = new Channel(max(1, count($fileList)));

    foreach ($fileList as $file) {
        Coroutine::create(function () use ($file, $results, $converter): void {
            $format = 'pdf';
            $mode = 'stream';
            $outputFile = __DIR__ . '/output/Converted_rpt_' . substr(md5((string) microtime(true)), 0, 8) . '.' . $format;

            try {
                $result = $converter->convertAsync(
                    filePath: $file,
                    outputFormat: $format,
                    mode: $mode
                );

                if ($result->isStream() && $result->hasBase64Content()) {
                    file_put_contents($outputFile, base64_decode($result->base64Content));
                    $results->push([
                        'file' => $file,
                        'output' => $outputFile,
                        'status' => 'success',
                        'mode' => $mode,
                    ]);
                    return;
                }

                if ($result->isFile()) {
                    $results->push([
                        'file' => $file,
                        'output' => $result->outputPath,
                        'status' => 'success',
                        'mode' => $mode,
                    ]);
                    return;
                }

                $results->push([
                    'file' => $file,
                    'status' => 'error',
                    'mode' => $mode,
                    'error' => 'La conversión no devolvió un resultado válido',
                ]);
            } catch (\Throwable $e) {
                $results->push([
                    'file' => $file,
                    'status' => 'error',
                    'mode' => $mode,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    $success = 0;
    $errors = 0;

    for ($i = 0, $iMax = count($fileList); $i < $iMax; $i++) {
        $result = $results->pop(30);

        if ($result === false || !is_array($result)) {
            $errors++;
            echo "❌ Resultado inválido o timeout\n";
            continue;
        }

        if (($result['status'] ?? '') === 'success') {
            $success++;
            echo "✅ {$result['file']} -> {$result['output']}\n";
        } else {
            $errors++;
            echo "❌ {$result['file']}: {$result['error']}\n";
        }
    }

    echo "\nResumen: {$success} éxitos, {$errors} errores\n";
    echo "Métricas: " . var_export($converter->getServerMetrics(), true) . "\n";

    $converter->stop();
    $healthMonitor->stopMonitoring();
});