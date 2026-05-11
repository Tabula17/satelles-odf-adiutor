<?php
$unoservers = [
    [
        'name' => 'unoserver-2004',
        'host' => '127.0.0.1',
        'port' => 2004,
    ],
    [
        'name' => 'unoserver-2005',
        'host' => '127.0.0.1',
        'port' => 2003,
    ]
];
return [
    'server' => [
        'host' => '0.0.0.0',
        'port' => 9508,
        'options' => [
            'worker_num' => count($unoservers),
            'task_worker_num' => 8,
            'package_max_length' => 1024 * 1024 * 100, // 10 MB
            'log_file' => __DIR__ . '/log/exemplar.log'
        ]
    ],
    'client' => [
        'host' => '127.0.0.1', //192.168.0.37
        'port' => 9508,
    ],
    'unoservers' => $unoservers
];