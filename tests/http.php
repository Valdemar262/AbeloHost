<?php

declare(strict_types=1);

$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$checks = 0;

function request(string $baseUrl, string $path, string $method = 'GET'): array
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ],
    ]);
    $stream = fopen($baseUrl . $path, 'r', false, $context);

    if ($stream === false) {
        throw new RuntimeException('HTTP server is unavailable: ' . $baseUrl);
    }

    $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
    $body = stream_get_contents($stream);
    fclose($stream);

    if ($body === false || preg_match('/^HTTP\/\S+ (\d{3})/', $headers[0] ?? '', $match) !== 1) {
        throw new RuntimeException('Invalid HTTP response: ' . $baseUrl . $path);
    }

    return [
        'status' => (int) $match[1],
        'headers' => implode("\n", $headers),
        'body' => $body,
    ];
}

$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }

    $checks++;
};

$home = request($baseUrl, '/');
$check(
    $home['status'] === 200 && str_contains($home['body'], 'Блог о веб-разработке'),
    'Home must render through HTTP.',
);
$check(
    str_contains(strtolower($home['headers']), 'content-type: text/html; charset=utf-8'),
    'HTML must declare UTF-8.',
);
$css = request($baseUrl, '/assets/css/app.css');
$check($css['status'] === 200 && str_contains($css['body'], '.site-header'), 'Static CSS must be served.');

$inaccessiblePaths = [
    '/.env',
    '/composer.json',
    '/composer.lock',
    '/config/app.php',
    '/vendor/autoload.php',
    '/var/log/app.log',
    '/templates/layout.tpl',
    '/missing',
];

foreach ($inaccessiblePaths as $path) {
    $result = request($baseUrl, $path);
    $check(in_array($result['status'], [403, 404], true), 'Private/unknown path must be inaccessible: ' . $path);
}

$head = request($baseUrl, '/', 'HEAD');
$check($head['status'] === 200 && $head['body'] === '', 'HEAD must have an empty body.');
$post = request($baseUrl, '/', 'POST');
$check(
    $post['status'] === 405 && str_contains($post['headers'], 'Allow: GET, HEAD'),
    'POST must return 405 and Allow.',
);

$category = request($baseUrl, '/category/php');
$check($category['status'] === 200, 'Seeded categories must be reachable.');
$check(substr_count($category['body'], 'data-post-id=') === 9, 'The first category page must contain nine posts.');
$check(!str_contains($category['body'], 'future-blog-release'), 'Future posts must not appear.');
$check(!str_contains($home['body'], 'data-category="notes"'), 'Empty categories must not appear on home.');
$sorted = request($baseUrl, '/category/php?sort=views&page=2');
$check($sorted['status'] === 200, 'The second sorted page must be reachable.');
$check(str_contains($sorted['body'], 'sort=views&amp;page=1'), 'Pagination must preserve sorting.');
$check(request($baseUrl, '/category/notes')['status'] === 200, 'An empty category must return 200.');
$check(request($baseUrl, '/category/notes?page=2')['status'] === 404, 'An empty category has only one page.');
$check(request($baseUrl, '/category/missing')['status'] === 404, 'An unknown category must return 404.');
$check(request($baseUrl, '/category/php?page=999999')['status'] === 404, 'Out-of-range pages must return 404.');
$check(request($baseUrl, '/category/php?page[]=2&sort[]=views')['status'] === 200, 'Array parameters must not crash.');
$categoryHead = request($baseUrl, '/category/php', 'HEAD');
$check($categoryHead['status'] === 200 && $categoryHead['body'] === '', 'Category HEAD must have no body.');
$check(request($baseUrl, '/category/php', 'POST')['status'] === 405, 'Category POST must return 405.');

$article = request($baseUrl, '/article/strict-types');
$check($article['status'] === 200, 'Published articles must be reachable.');
$check(str_contains($article['headers'], 'Cache-Control: no-store'), 'Article responses must prevent caching.');
$check(preg_match('/data-article-views>(\d+)</', $article['body'], $initialViews) === 1, 'The view count must be visible.');
$articleHead = request($baseUrl, '/article/strict-types', 'HEAD');
$check($articleHead['status'] === 200 && $articleHead['body'] === '', 'Article HEAD must have no body.');
$articlePost = request($baseUrl, '/article/strict-types', 'POST');
$check($articlePost['status'] === 405 && str_contains($articlePost['headers'], 'Allow: GET, HEAD'), 'Article POST must return 405.');

foreach (['GET', 'HEAD'] as $method) {
    $check(request($baseUrl, '/article/missing', $method)['status'] === 404, 'Missing articles must return 404.');
    $check(request($baseUrl, '/article/future-blog-release', $method)['status'] === 404, 'Scheduled articles must return 404.');
}

$articleAgain = request($baseUrl, '/article/strict-types');
$check(preg_match('/data-article-views>(\d+)</', $articleAgain['body'], $nextViews) === 1, 'Reload must show the counter.');
$check((int) $nextViews[1] === (int) $initialViews[1] + 1, 'Only the second GET may add a view.');
$check(substr_count($article['body'], 'data-post-id=') === 3, 'Article HTTP responses must include related cards.');

fwrite(STDOUT, "HTTP checks passed: {$checks}.\n");
