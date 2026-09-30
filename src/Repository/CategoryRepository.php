<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, slug, name, description FROM categories WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);

        return $statement->fetch() ?: null;
    }

    public function create(string $slug, string $name, string $description): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO categories (slug, name, description) VALUES (:slug, :name, :description)',
        );
        $statement->execute(['slug' => $slug, 'name' => $name, 'description' => $description]);

        return (int) $this->pdo->lastInsertId();
    }
}
