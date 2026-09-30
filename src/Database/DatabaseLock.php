<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class DatabaseLock
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(callable $operation): mixed
    {
        $database = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $name = 'blog:' . substr(hash('sha256', (string) $database), 0, 59);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $statement->execute(['name' => $name]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Another database command is running. Try again after it finishes.');
        }

        try {
            return $operation();
        } finally {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute(['name' => $name]);
        }
    }
}
