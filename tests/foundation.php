<?php

declare(strict_types=1);

use App\Http\Response;
use App\Database;
use App\Http\Router;

$app = require dirname(__DIR__) . '/config/bootstrap.php';
$view = $app['view'];
$router = new Router($view);
$router->get('/', static fn (): Response => new Response($view->render('pages/home.tpl', [
    'pageTitle' => 'Главная',
    'categories' => [],
])));
$router->get('/category/{slug}', static fn (array $parameters, array $query): Response => new Response(
    json_encode(['slug' => $parameters['slug'], 'query' => $query], JSON_THROW_ON_ERROR),
));
$checks = 0;

$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }

    $checks++;
};

$home = $router->dispatch('GET', '/?page=1');
$check($home->status === 200, 'Home page must accept a query string.');
$check(str_contains($home->body, 'Блог о веб-разработке'), 'Smarty must render the home page.');
$check(!str_contains($home->body, '{block'), 'Template syntax must not reach the response.');
$check($router->dispatch('GET', '/missing')->status === 404, 'Unknown routes must return 404.');
$check($router->dispatch('GET', '/config/app.php')->status === 404, 'Internal files must not be routed.');
$check($router->dispatch('GET', '//')->status === 404, 'Malformed paths must not crash routing.');
$post = $router->dispatch('POST', '/');
$check($post->status === 405 && $post->headers['Allow'] === 'GET, HEAD', 'POST must return 405 and Allow.');
$check($router->dispatch('HEAD', '/')->status === 200, 'HEAD must be supported.');
$category = json_decode($router->dispatch('GET', '/category/my%2Dsql?sort=views&page=2')->body, true);
$check($category['slug'] === 'my-sql', 'Route parameters must be decoded.');
$check($category['query'] === ['sort' => 'views', 'page' => '2'], 'Query parameters must reach the handler.');
$check($router->dispatch('GET', '/category/')->status === 404, 'A slug is required.');
$check($router->dispatch('GET', '/category/php/extra')->status === 404, 'Routes must match the complete path.');
$check($router->dispatch('POST', '/category/php')->status === 405, 'Category routes must reject POST.');


$view->assign('appName', '<script>alert("test")</script>');
$escaped = $router->dispatch('GET', '/')->body;
$check(!str_contains($escaped, '<script>'), 'Template output must be HTML-escaped.');
$check(str_contains($escaped, '&lt;script&gt;'), 'Escaped content must still be displayed.');

$invalidConfigurations = [
    ['host' => ''],
    ['host' => 'localhost;dbname=other'],
    ['name' => ''],
    ['name' => "invalid\0database"],
    ['port' => '0'],
    ['port' => '65536'],
];

foreach ($invalidConfigurations as $invalidConfiguration) {
    try {
        Database::connect(array_replace($app['config']['database'], $invalidConfiguration));
        throw new RuntimeException('Invalid configuration must be rejected before connecting.');
    } catch (InvalidArgumentException) {
        $checks++;
    }
}

$fixture = sys_get_temp_dir() . '/abelohost-error-' . bin2hex(random_bytes(6));
mkdir($fixture . '/public', 0775, true);
mkdir($fixture . '/config', 0775, true);
copy(dirname(__DIR__) . '/public/index.php', $fixture . '/public/index.php');
file_put_contents($fixture . '/config/bootstrap.php', '<?php throw new RuntimeException("PRIVATE_ERROR_MARKER");');
file_put_contents($fixture . '/run.php', <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = $argv[1];
ob_start();
register_shutdown_function(static function (): void {
    $body = ob_get_clean();
    echo json_encode(['status' => http_response_code(), 'body' => $body]);
});
require __DIR__ . '/public/index.php';
PHP);

try {
    foreach (['GET', 'HEAD'] as $method) {
        $process = proc_open(
            [PHP_BINARY, $fixture . '/run.php', $method],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start error-response check.');
        }

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $check($exit === 0 && $errors === '', 'Error response must finish without exposed PHP diagnostics.');
        $check($result['status'] === 500, 'Bootstrap failure must return 500.');
        $check(!str_contains($result['body'], 'PRIVATE_ERROR_MARKER'), '500 must not disclose exception details.');
        $check(
            $method === 'HEAD' ? $result['body'] === '' : str_contains($result['body'], 'Ошибка сервера'),
            'GET/HEAD error bodies must be correct.',
        );
    }

    $check(
        str_contains(file_get_contents($fixture . '/var/log/app.log'), 'PRIVATE_ERROR_MARKER'),
        'Server errors must be logged outside public.',
    );
} finally {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($fixture);
}

fwrite(STDOUT, "Foundation checks passed: {$checks}.\n");
