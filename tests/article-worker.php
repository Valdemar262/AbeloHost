<?php

declare(strict_types=1);

use App\Controller\ArticleController;
use App\Database;
use App\Repository\CategoryRepository;
use App\Repository\PostRepository;

$app = require dirname(__DIR__) . '/config/bootstrap.php';

if ($app['config']['database']['name'] !== 'abelohost_test') {
    throw new RuntimeException('Concurrent article checks require the isolated test database.');
}

$pdo = Database::connect($app['config']['database']);
$controller = new ArticleController(
    $app['view'],
    new CategoryRepository($pdo),
    new PostRepository($pdo),
    $pdo,
);
$views = [];

for ($index = 0; $index < 8; $index++) {
    $response = $controller->show('article-check-concurrent', true);

    if ($response->status !== 200 || preg_match('/data-article-views>(\d+)</', $response->body, $match) !== 1) {
        throw new RuntimeException('Concurrent article response is invalid.');
    }

    $views[] = (int) $match[1];
}

fwrite(STDOUT, json_encode($views, JSON_THROW_ON_ERROR));
