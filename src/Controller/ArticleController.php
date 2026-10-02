<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Repository\CategoryRepository;
use App\Repository\PostRepository;
use App\View;
use PDO;

final class ArticleController
{
    public function __construct(
        private readonly View $view,
        private readonly CategoryRepository $categories,
        private readonly PostRepository $posts,
        private readonly PDO $pdo,
    ) {
    }

    public function show(string $slug, bool $countView): Response
    {
        $publishedBefore = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();

        try {
            $post = $this->posts->findPublishedBySlug($slug, $publishedBefore);

            if ($post === null) {
                return new Response(
                    $this->view->render('errors/error.tpl', [
                        'pageTitle' => 'Страница не найдена',
                        'status' => 404,
                        'message' => 'Статья не найдена или ещё не опубликована.',
                    ]),
                    404,
                    ['Cache-Control' => 'no-store'],
                );
            }

            $postId = (int) $post['id'];

            if ($countView) {
                $post['views'] = $this->posts->incrementViews($postId);
            }

            $body = str_replace(["\r\n", "\r"], "\n", $post['body']);
            $response = new Response(
                $this->view->render('pages/article.tpl', [
                    'pageTitle' => $post['title'],
                    'post' => $post,
                    'paragraphs' => preg_split('/\n[\t ]*\n+/', trim($body), -1, PREG_SPLIT_NO_EMPTY),
                    'categories' => $this->categories->findByPostId($postId),
                    'relatedPosts' => $this->posts->findRelated($postId, $publishedBefore),
                ]),
                200,
                ['Cache-Control' => 'no-store'],
            );
            $this->pdo->commit();

            return $response;
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }
}
