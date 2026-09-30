<?php

declare(strict_types=1);

use App\Database;

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/app.php';

try {
    $pdo = Database::connect($config['database']);
    $pdo->query('SELECT 1')->fetchColumn();
    fwrite(STDOUT, "MySQL connection OK (utf8mb4, UTC).\n");
} catch (Throwable) {
    fwrite(STDERR, "MySQL connection failed. Check DB_* variables and database availability.\n");
    exit(1);
}
