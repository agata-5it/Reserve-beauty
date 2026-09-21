<?php

$config = require __DIR__ . '/../config/local.php';

$dsn = 'mysql:host=' . $config['host']
    . ';dbname=' . $config['database']
    . ';charset=utf8mb4';

$pdo = new PDO(
    $dsn,
    $config['username'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);