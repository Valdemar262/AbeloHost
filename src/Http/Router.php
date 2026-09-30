<?php

declare(strict_types=1);

namespace App\Http;

use App\View;

final class Router
{
    private array $routes = [];

    public function __construct(private readonly View $view)
    {
    }

    public function get(string $path, callable $handler): void
    {
        $this->routes[$path] = $handler;
    }

    public function dispatch(string $method, string $uri): Response
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || !isset($this->routes[$path])) {
            return new Response(
                $this->view->render('errors/error.tpl', [
                    'pageTitle' => 'Страница не найдена',
                    'status' => 404,
                    'message' => 'Такой страницы нет. Вернитесь на главную.',
                ]),
                404,
            );
        }

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return new Response(
                $this->view->render('errors/error.tpl', [
                    'pageTitle' => 'Метод не поддерживается',
                    'status' => 405,
                    'message' => 'Эта страница доступна только для чтения.',
                ]),
                405,
                ['Allow' => 'GET, HEAD'],
            );
        }

        return ($this->routes[$path])();
    }
}
