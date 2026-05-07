<?php

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Level;
use Tabula17\Satelles\Odf\Adiutor\Server\AdiutorTcp;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisJobQueue;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisJobStateStore;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisQueueConfig;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisResultStore;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisRetryScheduler;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RetryPolicy;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Service\ConversionManager;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Worker\ConversionWorker;
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;
use Tabula17\Satelles\Utilis\Config\ConnectionConfig;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

require __DIR__ . '/../vendor/autoload.php';

$tcp_config = include __DIR__ . '/tcp_config.php';

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$logger = new Monolog\Logger('test');
$handler = new Monolog\Handler\StreamHandler('php://stdout', Level::Debug);
$handler->setFormatter(new ColoredLineFormatter(
    format: "%datetime% [%level_name%]: %message% \n\t%context% \n\t%extra%\n",
    dateFormat: "Y.m.d H:i:s",
    allowInlineLineBreaks: true,
    ignoreEmptyContextAndExtra: true,
    includeStacktraces: true
));
$logger->pushHandler($handler);

$servers = new ConnectionCollection(
    new ConnectionConfig([
        'name' => 'unoserver-2004',
        'host' => '192.168.0.37',
        'port' => 2004,
    ]),
    new ConnectionConfig([
        'name' => 'unoserver-2003',
        'host' => '192.168.0.37',
        'port' => 2003,
    ])
);
foreach ($servers as $server) {
    if (!$server->canConnect()) {
        $logger->error("Cannot connect to server {$server->name} at {$server->host}:{$server->port}");
        continue;
    }
    $logger->info("Server {$server->name} is available at {$server->host}:{$server->port}");
}

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
$config = new RedisQueueConfig(prefix: 'adiutor');
$stateStore = new RedisJobStateStore($config);
$resultStore = new RedisResultStore($config);
$retryPolicy = new RetryPolicy(baseDelaySeconds: 2, maxDelaySeconds: 60, jitterRatio: 0.10);
$retryScheduler = new RedisRetryScheduler($config, $retryPolicy);
$queue = new RedisJobQueue(
    config: $config,
    stateStore: $stateStore,
    resultStore: $resultStore,
    retryScheduler: $retryScheduler
);

$worker = new ConversionWorker(
    queue: $queue,
    loadBalancer: $converter,
    logger: $logger

);

$conversionManager = new ConversionManager(
    queue: $queue,
    worker: $worker,
    logger: $logger
);
$serverConfig = new TCPServerConfig(...$tcp_config['server']);
$server = new AdiutorTcp(
    config: $serverConfig,
    conversionManager: $conversionManager,
    logger: $logger
);

$server->on('beforestart', function ($server) {
    $server->logger->info("🛫 AdiutorTcp file conversion server started on  {$server->host}:{$server->port} PID " . getmypid() . " | Workers: {$server->setting['worker_num']} | Task Workers: {$server->setting['task_worker_num']}");

});
//$server->on('start', fn() => echo 'Server started' . PHP_EOL);
$server->start();