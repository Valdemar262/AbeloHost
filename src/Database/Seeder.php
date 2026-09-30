<?php

declare(strict_types=1);

namespace App\Database;

use App\Repository\CategoryRepository;
use App\Repository\PostRepository;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

final class Seeder
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $publicDirectory,
    ) {
    }

    public function run(array $data): array
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Seeding must manage its own transaction.');
        }

        return (new DatabaseLock($this->pdo))->run(fn (): array => $this->seed($data));
    }

    private function seed(array $data): array
    {
        $categories = new CategoryRepository($this->pdo);
        $posts = new PostRepository($this->pdo);
        $categoryIds = [];
        $created = ['categories' => 0, 'posts' => 0];
        $this->pdo->beginTransaction();

        try {
            foreach ($data['categories'] as $category) {
                $existing = $categories->findBySlug($category['slug']);

                if ($existing !== null) {
                    $categoryIds[$category['slug']] = (int) $existing['id'];
                    continue;
                }

                $categoryIds[$category['slug']] = $categories->create(
                    $category['slug'],
                    $category['name'],
                    $category['description'],
                );
                $created['categories']++;
            }

            foreach ($data['posts'] as $post) {
                if ($posts->findBySlug($post['slug']) !== null) {
                    continue;
                }

                $image = realpath($this->publicDirectory . $post['image_path']);
                $imageDirectory = realpath($this->publicDirectory . '/assets/images');

                if (
                    $image === false
                    || $imageDirectory === false
                    || !str_starts_with($image, $imageDirectory . DIRECTORY_SEPARATOR)
                    || !is_file($image)
                ) {
                    throw new RuntimeException('Seed image is missing or outside assets/images: ' . $post['slug']);
                }

                $ids = [];

                foreach ($post['categories'] as $slug) {
                    if (!isset($categoryIds[$slug])) {
                        throw new RuntimeException('Unknown seed category: ' . $slug);
                    }

                    $ids[] = $categoryIds[$slug];
                }

                $posts->create($post, $ids);
                $created['posts']++;
            }

            $this->pdo->commit();

            return $created;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
