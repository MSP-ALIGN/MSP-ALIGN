<?php
declare(strict_types=1);

namespace Align;

/**
 * Maps a method and path to a controller method (routes are listed in src/routes.php).
 *
 * Security assumptions: the router checks the CSRF token on every POST before the handler runs, and nothing else.
 * It does no sign-in or role check: each handler must start with Auth::require()/requireRole() (staff),
 * PortalAuth::require() (portal) or its own token check (public links). Path parameters are limited to digits
 * ({id}, passed as int) or [A-Za-z0-9_.-] ({name:str}), so they can't carry a slash, but a handler must still check
 * a string parameter against what it expects (it may be "..").
 */
final class Router
{
    /** @var array<int, array{string, string, callable}> */
    private array $routes = [];

    /** Adds a GET route. $pattern is a path with {id} / {name:str} placeholders. */
    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    /** Adds a POST route; dispatch() checks its CSRF token before calling $handler. */
    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    /**
     * The regex for a route pattern. The text between placeholders is matched literally (2.2.1: a "." in
     * "/settings/api/openapi.json" no longer matches any character), and \z (not $) ends it, so a trailing new
     * line can't match.
     */
    private static function regex(string $pattern): string
    {
        $parts = preg_split('#(\{\w+(?::str)?\})#', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $re = '';
        foreach ($parts as $p) {
            if (preg_match('#^\{(\w+):str\}$#', $p, $m)) {
                $re .= '(?P<' . $m[1] . '>[A-Za-z0-9_.-]+)';
            } elseif (preg_match('#^\{(\w+)\}$#', $p, $m)) {
                $re .= '(?P<' . $m[1] . '>[0-9]+)';
            } else {
                $re .= preg_quote($p, '#');
            }
        }
        return '#^' . $re . '\z#';
    }

    /**
     * Runs the first route that matches $method and $path. A path that matches only other methods gets 405 with
     * an Allow header; no match at all gets 404. Leading/trailing slashes are ignored ("/clients/" is "/clients").
     * HEAD isn't routed (405): GET handlers can record views, and nothing here needs HEAD.
     */
    public function dispatch(string $method, string $path): void
    {
        $path = '/' . trim($path, '/');
        $allowed = [];
        foreach ($this->routes as [$m, $pattern, $handler]) {
            // {id} matches digits (passed as int); {name:str} matches [A-Za-z0-9_.-] (passed as string)
            if (!preg_match(self::regex($pattern), $path, $match)) {
                continue;
            }
            if ($m !== $method) {
                $allowed[$m] = true;
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
        if ($allowed) {
            header('Allow: ' . implode(', ', array_keys($allowed)));
        }
        http_response_code($allowed ? 405 : 404);
        View::render('error', [
            'title' => $allowed ? 'Method not allowed' : 'Page not found',
            'message' => $allowed ? 'That action is not supported here.' : 'There is nothing at this address.',
        ]);
    }
}
