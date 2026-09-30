<?php

declare(strict_types=1);

return [
    'name' => getenv('APP_NAME') ?: 'AbeloHost Blog',
    'database' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_DATABASE') ?: 'abelohost',
        'username' => getenv('DB_USERNAME') ?: 'blog',
        'password' => getenv('DB_PASSWORD') === false ? '' : getenv('DB_PASSWORD'),
    ],
];
