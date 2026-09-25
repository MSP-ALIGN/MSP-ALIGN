<?php
declare(strict_types=1);

namespace Align;

final class Router
{
    /** @var array<int, array{string, string, callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $path = '/' . trim($path, '/');
        $allowed = false;
        foreach ($this->routes as [$m, $pattern, $handler]) {
            // {id} matches digits (passed as int); {name:str} matches [A-Za-z0-9_.-] (passed as string)
            $regex = '#^' . preg_replace(
                ['#\{(\w+):str\}#', '#\{(\w+)\}#'],
                ['(?P<$1>[A-Za-z0-9_.-]+)', '(?P<$1>[0-9]+)'],
                $pattern
            ) . '$#';
            if (!preg_match($regex, $path, $match)) {
                continue;
            }
            $allowed = true;
            if ($m !== $method) {
                continue;
            }
            if ($method === 'POST') {
                csrf_check();
            }
            $params = [];
            foreach (array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY) as $k => $v) {
                $params[$k] = str_contains($pattern, '{' . $k . ':str}') ? $v : (int) $v;
            }
            $handler(...$params);
            return;
        }
        http_response_code($allowed ? 405 : 404);
        View::render('error', [
            'title' => $allowed ? 'Method not allowed' : 'Page not found',
            'message' => $allowed ? 'That action is not supported here.' : 'There is nothing at this address.',
        ]);
    }
}
