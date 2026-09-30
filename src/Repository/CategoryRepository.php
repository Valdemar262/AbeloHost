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

    public function findWithLatestPosts(string $publishedBefore): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            WITH ranked_posts AS (
                SELECT pc.category_id, p.id AS post_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY pc.category_id ORDER BY p.published_at DESC, p.id DESC
                    ) AS position
                FROM post_category pc
                JOIN posts p ON p.id = pc.post_id
                WHERE p.published_at <= :published_before
            )
            SELECT c.id AS category_id, c.slug AS category_slug, c.name AS category_name,
                c.description AS category_description,
                p.id, p.slug, p.image_path, p.title, p.description, p.published_at, p.views
            FROM categories c
            JOIN ranked_posts r ON r.category_id = c.id AND r.position <= 3
            JOIN posts p ON p.id = r.post_id
            ORDER BY c.name, c.id, r.position
            SQL);
        $statement->execute(['published_before' => $publishedBefore]);
        $categories = [];

        while ($row = $statement->fetch()) {
            $id = (int) $row['category_id'];

            if (!isset($categories[$id])) {
                $categories[$id] = [
                    'id' => $id,
                    'slug' => $row['category_slug'],
                    'name' => $row['category_name'],
                    'description' => $row['category_description'],
                    'posts' => [],
                ];
            }

            unset($row['category_id'], $row['category_slug'], $row['category_name'], $row['category_description']);
            $categories[$id]['posts'][] = $row;
        }

        return array_values($categories);
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
