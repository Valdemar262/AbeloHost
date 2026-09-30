<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use PDO;
use Throwable;

final class PostRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, slug, image_path, title, description, body, published_at, views
            FROM posts
            WHERE slug = :slug
            SQL);
        $statement->execute(['slug' => $slug]);

        return $statement->fetch() ?: null;
    }

    public function create(array $post, array $categoryIds): int
    {
        if ($categoryIds === []) {
            throw new InvalidArgumentException('A post must belong to at least one category.');
        }

        foreach ($categoryIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Category IDs must be positive integers.');
            }
        }

        $ownsTransaction = !$this->pdo->inTransaction();

        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO posts (slug, image_path, title, description, body, published_at, views)
                VALUES (:slug, :image_path, :title, :description, :body, :published_at, :views)
                SQL);
            $statement->execute([
                'slug' => $post['slug'],
                'image_path' => $post['image_path'],
                'title' => $post['title'],
                'description' => $post['description'],
                'body' => $post['body'],
                'published_at' => $post['published_at'],
                'views' => $post['views'] ?? 0,
            ]);
            $postId = (int) $this->pdo->lastInsertId();
            $statement = $this->pdo->prepare(
                'INSERT INTO post_category (post_id, category_id) VALUES (:post_id, :category_id)',
            );

            foreach (array_unique($categoryIds) as $categoryId) {
                $statement->execute(['post_id' => $postId, 'category_id' => $categoryId]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $postId;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
