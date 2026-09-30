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
        $segments = explode('/', $path);

        foreach ($segments as &$segment) {
            $segment = preg_match('/^\{([a-z][a-z0-9_]*)\}$/D', $segment, $match) === 1
                ? '(?P<' . $match[1] . '>[^/]+)'
                : preg_quote($segment, '~');
        }
        unset($segment);

        $this->routes[] = ['pattern' => '~^' . implode('/', $segments) . '$~D', 'handler' => $handler];
    }

    public function dispatch(string $method, string $uri): Response
    {
        $path = parse_url($uri, PHP_URL_PATH);

        foreach ($this->routes as $route) {
            if (!is_string($path) || preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
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

            $parameters = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
            $parameters = array_map(rawurldecode(...), $parameters);
            parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);

            return ($route['handler'])($parameters, $query);
        }

        return new Response(
            $this->view->render('errors/error.tpl', [
                'pageTitle' => 'Страница не найдена',
                'status' => 404,
                'message' => 'Такой страницы нет. Вернитесь на главную.',
            ]),
            404,
        );
    }
}
