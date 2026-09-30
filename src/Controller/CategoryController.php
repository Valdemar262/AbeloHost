<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Repository\CategoryRepository;
use App\Repository\PostRepository;
use App\View;

final class CategoryController
{
    private const PAGE_SIZE = 9;

    public function __construct(
        private readonly View $view,
        private readonly CategoryRepository $categories,
        private readonly PostRepository $posts,
    ) {
    }

    public function index(string $slug, array $query): Response
    {
        $category = $this->categories->findBySlug($slug);

        if ($category === null) {
            return $this->notFound();
        }

        $sort = ($query['sort'] ?? 'date') === 'views' ? 'views' : 'date';
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: 1;
        $publishedBefore = gmdate('Y-m-d H:i:s');
        $total = $this->posts->countPublishedInCategory((int) $category['id'], $publishedBefore);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));

        if ($page > $pageCount) {
            return $this->notFound();
        }

        $posts = $this->posts->findPublishedByCategory(
            (int) $category['id'],
            $publishedBefore,
            $sort,
            self::PAGE_SIZE,
            ($page - 1) * self::PAGE_SIZE,
        );
        $path = '/category/' . rawurlencode($category['slug']);
        $pageUrl = static fn (int $number): string => $path . '?' . http_build_query([
            'sort' => $sort,
            'page' => $number,
        ]);
        $numbers = array_unique([1, ...range(max(1, $page - 2), min($pageCount, $page + 2)), $pageCount]);
        sort($numbers, SORT_NUMERIC);
        $links = [];
        $previous = 0;

        foreach ($numbers as $number) {
            if ($number > $previous + 1) {
                $links[] = ['gap' => true];
            }

            $links[] = ['gap' => false, 'number' => $number, 'url' => $pageUrl($number)];
            $previous = $number;
        }

        return new Response($this->view->render('pages/category.tpl', [
            'pageTitle' => $category['name'],
            'category' => $category,
            'posts' => $posts,
            'sort' => $sort,
            'total' => $total,
            'pagination' => [
                'current' => $page,
                'count' => $pageCount,
                'links' => $links,
                'previousUrl' => $page > 1 ? $pageUrl($page - 1) : null,
                'nextUrl' => $page < $pageCount ? $pageUrl($page + 1) : null,
            ],
        ]));
    }

    private function notFound(): Response
    {
        return new Response(
            $this->view->render('errors/error.tpl', [
                'pageTitle' => 'Страница не найдена',
                'status' => 404,
                'message' => 'Категория или запрошенная страница не найдена.',
            ]),
            404,
        );
    }
}
