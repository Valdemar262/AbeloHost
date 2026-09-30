<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

final class Database
{
    public static function connect(array $config): PDO
    {
        foreach (['host', 'name'] as $key) {
            $value = $config[$key] ?? null;

            if (!is_string($value) || $value === '' || preg_match('/[;\x00-\x20]/', $value) === 1) {
                throw new InvalidArgumentException('Invalid database configuration.');
            }
        }

        $port = filter_var($config['port'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        if ($port === false) {
            throw new InvalidArgumentException('Invalid database port.');
        }

        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'],
                $port,
                $config['name'],
            ),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ],
        );

        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}
