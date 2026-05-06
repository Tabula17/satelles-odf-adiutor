<?php
return [
    'server' => [
        'host' => '0.0.0.0',
        'port' => 9508,
        'options' => [
            'worker_num' => 4,
            'task_worker_num' => 8,
            'package_max_length' => 1024 * 1024 * 100, // 10 MB
            'log_file' => __DIR__ . '/log/exemplar.log'
        ]
    ],
    'client' => [
        'host' => '192.168.0.37',
        'port' => 9508,
    ]
];