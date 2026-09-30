<?php

declare(strict_types=1);

namespace App\Database;

use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    public function run(): array
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Migrations cannot run inside a transaction.');
        }

        return (new DatabaseLock($this->pdo))->run(fn (): array => $this->migrate());
    }

    private function migrate(): array
    {
        $files = glob($this->directory . '/*.sql');

        if ($files === false || $files === []) {
            throw new RuntimeException('No SQL migrations found in the configured directory.');
        }

        sort($files, SORT_STRING);
        $migrations = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (preg_match('/^\d{3}_[a-z0-9_]+\.sql$/D', $name) !== 1 || strlen($name) > 190) {
                throw new RuntimeException('Invalid migration filename: ' . $name);
            }

            $sql = file_get_contents($file);

            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Cannot read migration: ' . $name);
            }

            $migrations[$name] = ['sql' => $sql, 'checksum' => hash('sha256', $sql)];
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                applied_at DATETIME NULL,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $history = $this->pdo->query(
            'SELECT migration, checksum, applied_at FROM schema_migrations ORDER BY migration',
        )->fetchAll(PDO::FETCH_UNIQUE);

        foreach ($history as $name => $migration) {
            if ($migration['applied_at'] === null) {
                throw new RuntimeException('Incomplete migration: ' . $name . '. Inspect the schema before retrying.');
            }

            if (!isset($migrations[$name]) || $migrations[$name]['checksum'] !== $migration['checksum']) {
                throw new RuntimeException('Applied migration is missing or modified: ' . $name);
            }
        }

        $applied = [];

        foreach ($migrations as $name => $migration) {
            if (isset($history[$name])) {
                continue;
            }

            if ($history !== [] && strcmp($name, array_key_last($history)) < 0) {
                throw new RuntimeException('New migrations must follow the applied migrations: ' . $name);
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO schema_migrations (migration, checksum) VALUES (:migration, :checksum)',
            );
            $statement->execute(['migration' => $name, 'checksum' => $migration['checksum']]);

            try {
                $this->pdo->exec($migration['sql']);
                $statement = $this->pdo->prepare(
                    'UPDATE schema_migrations SET applied_at = UTC_TIMESTAMP() WHERE migration = :migration',
                );
                $statement->execute(['migration' => $name]);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Migration failed: ' . $name . '. Inspect the schema and schema_migrations before retrying.',
                    0,
                    $exception,
                );
            }

            $applied[] = $name;
        }

        return $applied;
    }
}
