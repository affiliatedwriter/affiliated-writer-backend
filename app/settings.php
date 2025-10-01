<?php
return [
    'displayErrorDetails' => true, // dev only
    'db' => [
        'driver'   => 'mysql',
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'database' => getenv('DB_DATABASE') ?: 'affiliated_writer',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset'  => 'utf8mb4',
    ],
];
