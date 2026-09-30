<?php

declare(strict_types=1);

use App\Database;
use App\Database\Seeder;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config = require dirname(__DIR__) . '/config/app.php';
    $pdo = Database::connect($config['database']);
    $data = require dirname(__DIR__) . '/database/seeds/blog.php';
    $created = (new Seeder($pdo, dirname(__DIR__) . '/public'))->run($data);

    fwrite(STDOUT, sprintf(
        "Seeding completed: %d categories and %d posts created. Existing records were preserved.\n",
        $created['categories'],
        $created['posts'],
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seed error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
