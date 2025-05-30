<?php
require __DIR__ . '/../vendor/autoload.php';

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
$servers = [
    ['host' => '127.0.0.1', 'port' => 2005],
    ['host' => '127.0.0.1', 'port' => 2004],
    ['host' => '127.0.0.1', 'port' => 2003]
];
$healthMonitor = new ServerHealthMonitor(
    $servers,
    30,     // Check cada 30 segundos
    3,      // 3 fallos consecutivos marcan como no saludable
    60      // Reintentar después de 60 segundos
);
$converter = new UnoserverLoadBalancer($servers, $healthMonitor, 20, 15);
$fileList = glob(__DIR__ . DIRECTORY_SEPARATOR . "*.odt");
function atomicWrite(string $path, string $data, int $retries = 3): bool {
    $tempPath = $path . '.tmp.' . uniqid();

    for ($i = 0; $i < $retries; $i++) {
        try {
            $written = Coroutine\System::writeFile($tempPath, $data);
            if ($written !== strlen($data)) {
                throw new RuntimeException("Escritura incompleta");
            }

            if (!rename($tempPath, $path)) {
                throw new RuntimeException("Fallo en rename");
            }

            return true;
        } catch (\Throwable $e) {
            if ($i === $retries - 1) {
                @unlink($tempPath);
                throw $e;
            }
            Coroutine::sleep(0.1 * ($i + 1));
        }
    }

    return false;
}
Coroutine\run(function () use ($converter, $fileList, $healthMonitor) {
    $healthMonitor->startMonitoring();
    $converter->start();
    $results = new Channel(count($fileList));

    foreach ($fileList as $file) {

        Coroutine::create(function () use ($file, $results, $converter) {
            $format = 'pdf';
            $mode = 'stream'; // o 'filePath' según tu necesidad
            $outputFile = __DIR__ . '/output/Converted_rpt_' . substr(md5(uniqid()), 0, 8) . '.' . $format;
            try {


                $generator = $converter->convertAsync(
                    filePath: $file,
                    outputFormat: $format,
                    outPath: $outputFile,
                    mode: $mode
                );

                foreach ($generator as $data) {
                    if ($mode === 'stream') {
                        //atomicWrite($outputFile, base64_decode($data));
                      /*  $file = fopen($outputFile, 'wb');
                        fwrite($file, base64_decode($data));
                        fflush($file); // Forzar escritura inmediata
                        fclose($file);*/
                        $writeChannel = new Channel(1);

                        Coroutine::create(function() use ($writeChannel, $outputFile) {
                            while (true) {
                                $data = $writeChannel->pop();
                                if ($data === false) {
                                    break;
                                }
                                file_put_contents($outputFile, base64_decode($data));
                                clearstatcache(true, $outputFile);
                            }
                        });

// En tu código:
                        $writeChannel->push($data);
                    }
                    $results->push(['file' => $outputFile, 'mode' => $mode, 'status' => 'success']);
                }
            } catch (\Throwable $e) {
                $results->push([
                    'file' => $file,
                    'status' => 'error',
                    'mode' => $mode,
                    'error' => $e->getMessage()
                ]);
            }
        });
    }

    // Procesar resultados
    $success = $errors = 0;
    for ($i = 0, $iMax = count($fileList); $i < $iMax; $i++) {
        $result = $results->pop(30);
        if ($result['status'] === 'success') {
            $success++;
            echo "✅ {$result['file']}\n";
        } else {
            $errors++;
            echo "❌ {$result['file']}: {$result['error']}\n";
        }
    }

    echo "\nResumen: $success éxitos, $errors errores\n";
    echo "Métricas: " . var_export($converter->getServerMetrics(), true) . "\n";
});