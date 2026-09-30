<?php

declare(strict_types=1);

use App\Controller\CategoryController;
use App\Controller\HomeController;
use App\Repository\CategoryRepository;
use App\Repository\PostRepository;
use App\View;

return static function (PDO $pdo, string $root, array $data): int {
    $checks = 0;
    $check = static function (bool $condition, string $message) use (&$checks): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }

        $checks++;
    };
    $cardIds = static function (string $html): array {
        preg_match_all('/data-post-id="(\d+)"/', $html, $matches);

        return array_map(intval(...), $matches[1]);
    };
    $categories = new CategoryRepository($pdo);
    $posts = new PostRepository($pdo);
    $view = new View($root . '/templates', $root . '/var/smarty');
    $view->assign('appName', 'AbeloHost Blog');
    $homeController = new HomeController($view, $categories);
    $categoryController = new CategoryController($view, $categories, $posts);
    $cutoff = gmdate('Y-m-d H:i:s');
    $selectCount = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];

    $pdo->beginTransaction();

    try {
        $before = $selectCount();
        $groups = $categories->findWithLatestPosts($cutoff);
        $check($selectCount() - $before === 1, 'Home must use one SELECT for all categories.');
        $check(count($groups) === 4, 'Home must include all four non-empty categories.');
        $orderedSlugs = $pdo->query('SELECT slug FROM categories ORDER BY name, id')->fetchAll(PDO::FETCH_COLUMN);
        $check(
            array_column($groups, 'slug') === array_values(array_diff($orderedSlugs, ['notes'])),
            'Home categories must be ordered by name.',
        );

        foreach ($groups as $group) {
            $expected = [];

            foreach ($data['posts'] as $post) {
                if (in_array($group['slug'], $post['categories'], true) && $post['published_at'] <= $cutoff) {
                    $expected[] = $posts->findBySlug($post['slug']);
                }
            }

            usort($expected, static fn (array $a, array $b): int =>
                [$b['published_at'], (int) $b['id']] <=> [$a['published_at'], (int) $a['id']]);
            $check(
                array_column($group['posts'], 'slug') === array_column(array_slice($expected, 0, 3), 'slug'),
                'Home must show the latest three posts for ' . $group['slug'],
            );
            $check(!array_key_exists('body', $group['posts'][0]), 'Card queries must not load full article bodies.');
        }

        $home = $homeController->index();
        $check($home->status === 200 && count($cardIds($home->body)) === 11, 'Home must render eleven seeded cards.');
        $check(!str_contains($home->body, 'future-blog-release'), 'Home must exclude future posts.');
        $check(substr_count($home->body, 'class="button button-secondary"') === 4, 'Each category needs an all-posts link.');
        $phpId = (int) $categories->findBySlug('php')['id'];
        $check($posts->countPublishedInCategory($phpId, $cutoff) === 14, 'Counts must exclude the scheduled PHP post.');

        foreach (['date', 'views'] as $sort) {
            $expected = [];

            foreach ($data['posts'] as $post) {
                if (in_array('php', $post['categories'], true) && $post['published_at'] <= $cutoff) {
                    $expected[] = $posts->findBySlug($post['slug']);
                }
            }

            usort($expected, static function (array $a, array $b) use ($sort): int {
                if ($sort === 'views' && (int) $a['views'] !== (int) $b['views']) {
                    return (int) $b['views'] <=> (int) $a['views'];
                }

                return [$b['published_at'], (int) $b['id']] <=> [$a['published_at'], (int) $a['id']];
            });
            $first = $posts->findPublishedByCategory($phpId, $cutoff, $sort, 9, 0);
            $second = $posts->findPublishedByCategory($phpId, $cutoff, $sort, 9, 9);
            $check(count($first) === 9 && count($second) === 5, 'Pagination must split fourteen posts into nine and five.');
            $check(
                array_column([...$first, ...$second], 'slug') === array_column($expected, 'slug'),
                'SQL ordering must match the complete reference list: ' . $sort,
            );
            $check(array_intersect(array_column($first, 'id'), array_column($second, 'id')) === [], 'Pages must not overlap.');
            $response = $categoryController->index('php', ['sort' => $sort, 'page' => '2']);
            $check($response->status === 200, 'The second category page must render.');
            $check($cardIds($response->body) === array_map(intval(...), array_column($second, 'id')), 'Cards must preserve SQL order.');
            $check(str_contains($response->body, 'sort=' . $sort . '&amp;page=1'), 'Pagination must preserve its sort mode.');
            $check(!str_contains($response->body, 'name="page"'), 'Changing sorting must reset the page.');
        }

        $firstPage = $categoryController->index('php', []);
        $check(str_contains($firstPage->body, 'rel="next"'), 'The first page needs a next link.');
        $check(!str_contains($firstPage->body, 'rel="prev"'), 'The first page must not link backwards.');
        $lastPage = $categoryController->index('php', ['page' => '2']);
        $check(!str_contains($lastPage->body, 'rel="next"'), 'The last page must not link forwards.');

        foreach (['0', '-1', 'abc', '1.5', '1e2', '', str_repeat('9', 50), ['2']] as $invalidPage) {
            $response = $categoryController->index('php', ['page' => $invalidPage]);
            $check($response->status === 200 && $cardIds($response->body) === $cardIds($firstPage->body), 'Invalid page must fall back to page one.');
        }

        foreach (['unknown', 'views; DROP TABLE posts', ['views']] as $invalidSort) {
            $response = $categoryController->index('php', ['sort' => $invalidSort]);
            $check($cardIds($response->body) === $cardIds($firstPage->body), 'Invalid sort must fall back to date.');
        }

        foreach (['3', (string) PHP_INT_MAX] as $outOfRange) {
            $check($categoryController->index('php', ['page' => $outOfRange])->status === 404, 'Out-of-range pages must return 404.');
        }

        $empty = $categoryController->index('notes', []);
        $check($empty->status === 200 && $cardIds($empty->body) === [], 'An empty category must be available.');
        $check(str_contains($empty->body, 'пока нет опубликованных статей'), 'An empty category must explain the empty list.');
        $check($categoryController->index('notes', ['page' => '2'])->status === 404, 'Empty categories have no second page.');
        $check($categoryController->index('missing', [])->status === 404, 'Unknown categories must return 404.');
        $check(count($cardIds($categoryController->index('frontend', [])->body)) === 2, 'Short lists must show all available posts.');

        $futureCategory = $categories->create('future-only', 'Future', 'Future');
        $posts->create(array_replace($data['posts'][0], [
            'slug' => 'future-only-post',
            'published_at' => '2099-01-01 12:00:00',
        ]), [$futureCategory]);
        $check(!in_array('future-only', array_column($categories->findWithLatestPosts($cutoff), 'slug'), true), 'Future-only categories must be hidden on home.');
        $check($cardIds($categoryController->index('future-only', [])->body) === [], 'Future-only categories must show an empty list.');

        $unsafeCategory = $categories->create('special&category', '<script>category</script>', '<img src=x onerror=alert(1)>');
        $unsafePost = $posts->create(array_replace($data['posts'][0], [
            'slug' => 'special&post',
            'title' => '<script>post</script>',
            'description' => '<img src=x onerror=alert(2)>',
        ]), [$unsafeCategory]);
        $escaped = $categoryController->index('special&category', [])->body;
        $check(!str_contains($escaped, '<script>') && !str_contains($escaped, '<img src=x'), 'Category and card content must be escaped.');
        $check(str_contains($escaped, '&lt;script&gt;post&lt;/script&gt;'), 'Escaping must preserve displayable text.');
        $check(str_contains($escaped, '/article/special%26post'), 'Article slugs must be URL-encoded.');
        $check(str_contains($escaped, '/category/special%26category'), 'Category slugs must be URL-encoded.');
        $check($cardIds($escaped) === [$unsafePost], 'A category with a single post must render one card.');

        $manyId = $categories->create('many-pages', 'Many pages', 'Pagination window');

        for ($index = 0; $index < 82; $index++) {
            $posts->create(array_replace($data['posts'][0], ['slug' => 'page-test-' . $index]), [$manyId]);
        }

        $middle = $categoryController->index('many-pages', ['page' => '6', 'sort' => 'views'])->body;
        $check(str_contains($middle, 'sort=views&amp;page=1'), 'Pagination must link to the first page.');
        $check(str_contains($middle, 'sort=views&amp;page=10'), 'Pagination must link to the last page.');
        $check(substr_count($middle, 'class="pagination-gap"') === 2, 'Long pagination must collapse distant pages.');
        $check(substr_count($middle, 'class="page-link') <= 9, 'The navigation size must stay bounded.');

        $pdo->exec("UPDATE posts SET published_at = '2099-01-01 12:00:00'");
        $emptyHome = $homeController->index();
        $check($emptyHome->status === 200 && $cardIds($emptyHome->body) === [], 'Home must support no published posts.');
        $check(str_contains($emptyHome->body, 'Пока нет опубликованных статей'), 'Home must display an empty state.');
    } finally {
        $pdo->rollBack();
    }

    return $checks;
};
