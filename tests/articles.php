<?php

declare(strict_types=1);

use App\Controller\ArticleController;
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
    $categories = new CategoryRepository($pdo);
    $posts = new PostRepository($pdo);
    $view = new View($root . '/templates', $root . '/var/smarty');
    $view->assign('appName', 'AbeloHost Blog');
    $controller = new ArticleController($view, $categories, $posts, $pdo);
    $categoryIds = [];
    $postIds = [];
    $workers = [];
    $cutoff = gmdate('Y-m-d H:i:s');
    $createPost = static function (string $slug, array $links, array $overrides = []) use (
        $posts,
        $data,
        &$postIds,
    ): int {
        $id = $posts->create(array_replace($data['posts'][0], [
            'slug' => 'article-check-' . $slug,
            'published_at' => '2025-01-01 12:00:00',
            'views' => 0,
        ], $overrides), $links);
        $postIds[] = $id;

        return $id;
    };
    $viewsIn = static function (string $html): int {
        if (preg_match('/data-article-views>(\d+)</', $html, $match) !== 1) {
            throw new RuntimeException('Article view count is missing.');
        }

        return (int) $match[1];
    };

    try {
        foreach (['alpha', 'beta', 'single', 'unrelated', 'concurrent'] as $slug) {
            $categoryIds[$slug] = $categories->create('article-check-' . $slug, $slug, 'Article tests');
        }

        $a = $categoryIds['alpha'];
        $b = $categoryIds['beta'];
        $target = $createPost('target', [$a, $b], [
            'title' => '<script>title</script>',
            'description' => '<img src=x onerror=alert(1)>',
            'body' => "Первый абзац <script>alert(2)</script>\r\nВторая строка\r\n\r\nВторой абзац & текст",
        ]);
        $oldBoth = $createPost('both-old', [$a, $b]);
        $newBoth = $createPost('both-new', [$a, $b], ['published_at' => '2025-02-01 12:00:00']);
        $tieBoth = $createPost('both-tie', [$a, $b], ['published_at' => '2025-02-01 12:00:00']);
        $oneShared = $createPost('one-shared', [$a], ['published_at' => '2025-03-01 12:00:00']);
        $future = $createPost('future', [$a, $b], ['published_at' => '2099-01-01 12:00:00']);
        $unrelated = $createPost('unrelated', [$categoryIds['unrelated']]);
        $single = $createPost('single', [$categoryIds['single']]);
        $concurrent = $createPost('concurrent', [$categoryIds['concurrent']]);

        $check($posts->findPublishedBySlug('article-check-target', $cutoff)['id'] === $target, 'Published articles must be found.');
        $check($posts->findPublishedBySlug('article-check-future', $cutoff) === null, 'Scheduled articles must stay private.');
        $check($posts->findPublishedBySlug("' OR 1=1 --", $cutoff) === null, 'Article slugs must be bound parameters.');
        $check(
            $posts->findPublishedBySlug('article-check-target', '2025-01-01 12:00:00') !== null,
            'Articles become public exactly at their publication time.',
        );
        $check(
            $posts->findPublishedBySlug('article-check-target', '2025-01-01 11:59:59') === null,
            'Articles must remain hidden before their publication time.',
        );
        $check(array_column($categories->findByPostId($target), 'id') === [$a, $b], 'All article categories must be ordered by name.');
        $related = $posts->findRelated($target, $cutoff);
        $check(
            array_column($related, 'id') === [$tieBoth, $newBoth, $oldBoth],
            'Related articles must rank shared categories, then date and ID, without duplicates.',
        );
        $check(!array_key_exists('body', $related[0]), 'Related cards must not load full article bodies.');
        $check($posts->findRelated($single, $cutoff) === [], 'Unrelated articles must not fill recommendations.');

        $head = $controller->show('article-check-target', false);
        $check($head->status === 200 && $viewsIn($head->body) === 0, 'Read-only rendering must preserve the counter.');
        $check($head->headers['Cache-Control'] === 'no-store', 'Article responses must not be cached.');
        $check(!$pdo->inTransaction(), 'Successful rendering must close its transaction.');
        $first = $controller->show('article-check-target', true);
        $second = $controller->show('article-check-target', true);
        $check($viewsIn($first->body) === 1 && $viewsIn($second->body) === 2, 'Each article GET must add exactly one view.');
        $check((int) $posts->findBySlug('article-check-target')['views'] === 2, 'The displayed counter must be persisted.');
        $check(!str_contains($first->body, '<script>') && !str_contains($first->body, '<img src=x'), 'Article content must not execute HTML.');
        $check(str_contains($first->body, '&lt;script&gt;alert(2)&lt;/script&gt;'), 'Body text must be escaped once.');
        $check(str_contains($first->body, '<p>Второй абзац &amp; текст</p>'), 'Blank lines must create paragraphs.');
        $check(str_contains($first->body, "\nВторая строка"), 'Line breaks within paragraphs must be retained.');
        $check(str_contains($first->body, 'href="/category/article-check-alpha"'), 'Category links must be rendered.');
        $check(str_contains($first->body, 'href="/category/article-check-beta"'), 'All categories must be linked.');
        $check(str_contains($first->body, 'class="article-image"'), 'The article image must be rendered.');
        $check(str_contains($first->body, '2025-01-01T12:00:00Z'), 'The publication date must declare UTC.');
        $check(substr_count($first->body, 'data-post-id=') === 3, 'Three related cards must be rendered.');
        $check(!str_contains($first->body, 'data-post-id="' . $target . '"'), 'The current article must not recommend itself.');

        foreach (['missing', 'article-check-future'] as $slug) {
            foreach ([true, false] as $countView) {
                $check($controller->show($slug, $countView)->status === 404, 'Unknown and future articles must return 404.');
                $check(!$pdo->inTransaction(), '404 responses must close their transaction.');
            }
        }

        $check((int) $posts->findBySlug('article-check-future')['views'] === 0, '404 must not count scheduled views.');
        $empty = $controller->show('article-check-single', false);
        $check(!str_contains($empty->body, 'id="related-heading"'), 'An empty related section must be hidden.');
        $peer = $createPost('single-peer', [$categoryIds['single']]);
        $check(array_column($posts->findRelated($single, $cutoff), 'id') === [$peer], 'One available peer must be shown.');
        $peer2 = $createPost('single-peer-2', [$categoryIds['single']]);
        $check(array_column($posts->findRelated($single, $cutoff), 'id') === [$peer2, $peer], 'Two available peers must be shown.');

        $brokenView = new View($root . '/templates/missing', $root . '/var/smarty');
        $brokenController = new ArticleController($brokenView, $categories, $posts, $pdo);
        $failed = false;

        try {
            $brokenController->show('article-check-target', true);
        } catch (Smarty\Exception) {
            $failed = true;
        }

        $check($failed, 'A missing article template must fail rendering.');
        $check(!$pdo->inTransaction(), 'Render failure must close its transaction.');
        $check((int) $posts->findBySlug('article-check-target')['views'] === 2, 'Render failure must roll back the view increment.');

        for ($index = 0; $index < 4; $index++) {
            $process = proc_open([PHP_BINARY, $root . '/tests/article-worker.php'], [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start concurrent article checks.');
            }

            $workers[] = [$process, $pipes];
        }

        $observed = [];

        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $check(proc_close($process) === 0 && $errors === '', 'Concurrent requests must succeed: ' . $errors);
            $observed = [...$observed, ...json_decode($output, true, 512, JSON_THROW_ON_ERROR)];
        }

        sort($observed, SORT_NUMERIC);
        $check($observed === range(1, 32), 'Concurrent responses must display each committed increment exactly once.');
        $check((int) $posts->findBySlug('article-check-concurrent')['views'] === 32, 'Concurrent requests must not lose increments.');
    } finally {
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process);

                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                proc_close($process);
            }
        }

        $deletePost = $pdo->prepare('DELETE FROM posts WHERE id = :id');

        foreach ($postIds as $id) {
            $deletePost->execute(['id' => $id]);
        }

        $deleteCategory = $pdo->prepare('DELETE FROM categories WHERE id = :id');

        foreach ($categoryIds as $id) {
            $deleteCategory->execute(['id' => $id]);
        }
    }

    return $checks;
};
