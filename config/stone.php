<?php
return [

    'slow_log_time' => 200, // 慢日志记录阈值，单位毫秒

    'domain' => '0.0.0.0',

    'port' => 9101,

    'daemonize' => true,

    'user' => 'www',

    'group' => 'www',

    'chroot' => '',

    'worker_num' => 30,

    'task_worker_num' => 1,

    'max_request' => 10000,

    'pid' => '/run/stone.pid',

    'process_name' => 'stone-server',

    'open_eof_check' => false,

    'package_eof' => "\r\n\r\n",

    'log_file' => storage_path() . '/logs/stone.log',

    'snap_bindings' => [
        'cookie',
        'db',
        'view',
        'session',
        'session.store',
    ],
];

