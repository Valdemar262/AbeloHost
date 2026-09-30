<?php

declare(strict_types=1);

use App\Controller\HomeController;
use App\Controller\CategoryController;
use App\Database;
use App\Http\Response;
use App\Http\Router;
use App\Repository\CategoryRepository;
use App\Repository\PostRepository;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$logDirectory = dirname(__DIR__) . '/var/log';

if (is_dir($logDirectory) || @mkdir($logDirectory, 0775, true)) {
    ini_set('error_log', $logDirectory . '/app.log');
}

$headOnly = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
$response = null;

try {
    $app = require dirname(__DIR__) . '/config/bootstrap.php';
    $router = new Router($app['view']);
    $router->get('/', static function () use ($app): Response {
        $pdo = Database::connect($app['config']['database']);

        return (new HomeController($app['view'], new CategoryRepository($pdo)))->index();
    });
    $router->get('/category/{slug}', static function (array $parameters, array $query) use ($app): Response {
        $pdo = Database::connect($app['config']['database']);
        $controller = new CategoryController($app['view'], new CategoryRepository($pdo), new PostRepository($pdo));

        return $controller->index($parameters['slug'], $query);
    });
    $response = $router->dispatch(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $_SERVER['REQUEST_URI'] ?? '/',
    );
} catch (Throwable $exception) {
    error_log((string) $exception);

    if (isset($app)) {
        try {
            $response = new Response(
                $app['view']->render('errors/error.tpl', [
                    'pageTitle' => 'Ошибка сервера',
                    'status' => 500,
                    'message' => 'Не удалось открыть страницу. Попробуйте позже.',
                ]),
                500,
                ['Cache-Control' => 'no-store'],
            );
        } catch (Throwable $renderException) {
            error_log((string) $renderException);
        }
    }
}

if ($response === null) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (!$headOnly) {
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<title>Ошибка сервера</title></head><body><h1>500 — Ошибка сервера</h1>'
            . '<p>Попробуйте открыть страницу позже.</p></body></html>';
    }

    exit;
}

$response->send($headOnly);
