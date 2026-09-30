<?php

declare(strict_types=1);

use App\Database;
use App\Database\MigrationRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config = require dirname(__DIR__) . '/config/app.php';
    $pdo = Database::connect($config['database']);
    $migrations = (new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations'))->run();

    foreach ($migrations as $migration) {
        fwrite(STDOUT, 'Applied: ' . $migration . PHP_EOL);
    }

    fwrite(STDOUT, $migrations === [] ? "Database is up to date.\n" : "Migrations completed.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
