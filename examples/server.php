<?php

use Tabula17\Satelles\Odf\Adiutor\Server\AdiutorTcp;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\SwooleConversionQueue;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Service\ConversionManager;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Worker\ConversionWorker;
use Tabula17\Satelles\Utilis\Collection\ConnectionCollection;
use Tabula17\Satelles\Utilis\Config\ConnectionConfig;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

require __DIR__ . '/../vendor/autoload.php';

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

/*
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisQueueConfig;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisJobStateStore;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisResultStore;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisRetryScheduler;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisRetryDispatcher;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RedisConversionQueue;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Queue\RetryPolicy;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Service\ConversionManager;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Worker\ConversionWorker;

$config = new RedisQueueConfig(prefix: 'adiutor');
$stateStore = new RedisJobStateStore($config);
$resultStore = new RedisResultStore($config);
$retryPolicy = new RetryPolicy(baseDelaySeconds: 2, maxDelaySeconds: 60, jitterRatio: 0.10);
$retryScheduler = new RedisRetryScheduler($config, $retryPolicy);
$queue = new RedisConversionQueue($config, $stateStore, $resultStore, $retryScheduler);

$worker = new ConversionWorker($queue, $loadBalancer, $logger);
$retryDispatcher = new RedisRetryDispatcher($retryScheduler, intervalSeconds: 1, batchSize: 100, logger: $logger);

$manager = new ConversionManager(
    queue: $queue,
    worker: $worker,
    retryDispatcher: $retryDispatcher,
    logger: $logger
);

$manager->start(workers: 2);
 */

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
$queue = new SwooleConversionQueue(
    capacity: 100
);
$worker = new ConversionWorker(
    queue: $queue,
    loadBalancer: $converter

);

$conversionManager = new ConversionManager(
    queue: $queue,
    worker: $worker
);
$serverConfig = new TCPServerConfig(
    [
        'host' => '0.0.0.0',
        'port' => 9508,
        'workers' => 4,
        'task_workers' => 8,
        'package_max_length' => 1024 * 1024 * 100, // 10 MB
        'log_file' => '/var/log/conversion_server.log'
    ],
);
$server = new AdiutorTcp(
    config: $serverConfig,
    conversionManager: $conversionManager
);

$server->start();