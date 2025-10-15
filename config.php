<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'zeitwerk',
        'user' => 'vera',
        'pass' => 'secret',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'debug' => true,
        'base_url' => '/zeitwerk/public',   // <— wichtig bei Apache/Nginx unter /zeitwerk
        'timezone' => 'Europe/Berlin',
    ],
];
