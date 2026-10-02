<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use PDO;
use RuntimeException;
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

    public function countPublishedInCategory(int $categoryId, string $publishedBefore): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM posts p
            JOIN post_category pc ON pc.post_id = p.id
            WHERE pc.category_id = :category_id AND p.published_at <= :published_before
            SQL);
        $statement->execute(['category_id' => $categoryId, 'published_before' => $publishedBefore]);

        return (int) $statement->fetchColumn();
    }

    public function findPublishedBySlug(string $slug, string $publishedBefore): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, slug, image_path, title, description, body, published_at, views
            FROM posts
            WHERE slug = :slug AND published_at <= :published_before
            SQL);
        $statement->execute(['slug' => $slug, 'published_before' => $publishedBefore]);

        return $statement->fetch() ?: null;
    }

    public function incrementViews(int $postId): int
    {
        $statement = $this->pdo->prepare('UPDATE posts SET views = views + 1 WHERE id = :id');
        $statement->execute(['id' => $postId]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The article no longer exists.');
        }

        $statement = $this->pdo->prepare('SELECT views FROM posts WHERE id = :id');
        $statement->execute(['id' => $postId]);

        return (int) $statement->fetchColumn();
    }

    public function findRelated(int $postId, string $publishedBefore): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT p.id, p.slug, p.image_path, p.title, p.description, p.published_at, p.views
            FROM posts p
            JOIN (
                SELECT candidate.post_id, COUNT(*) AS shared_categories
                FROM post_category source
                JOIN post_category candidate ON candidate.category_id = source.category_id
                WHERE source.post_id = :source_id AND candidate.post_id <> :excluded_id
                GROUP BY candidate.post_id
            ) related ON related.post_id = p.id
            WHERE p.published_at <= :published_before
            ORDER BY related.shared_categories DESC, p.published_at DESC, p.id DESC
            LIMIT 3
            SQL);
        $statement->execute([
            'source_id' => $postId,
            'excluded_id' => $postId,
            'published_before' => $publishedBefore,
        ]);

        return $statement->fetchAll();
    }

    public function findPublishedByCategory(
        int $categoryId,
        string $publishedBefore,
        string $sort,
        int $limit,
        int $offset,
    ): array {
        if ($limit < 1 || $limit > 100 || $offset < 0) {
            throw new InvalidArgumentException('Invalid pagination bounds.');
        }

        $orderBy = match ($sort) {
            'date' => 'p.published_at DESC, p.id DESC',
            'views' => 'p.views DESC, p.published_at DESC, p.id DESC',
            default => throw new InvalidArgumentException('Unsupported post sorting.'),
        };
        $statement = $this->pdo->prepare(<<<SQL
            SELECT p.id, p.slug, p.image_path, p.title, p.description, p.published_at, p.views
            FROM posts p
            JOIN post_category pc ON pc.post_id = p.id
            WHERE pc.category_id = :category_id AND p.published_at <= :published_before
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
            SQL);
        $statement->bindValue('category_id', $categoryId, PDO::PARAM_INT);
        $statement->bindValue('published_before', $publishedBefore);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
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
