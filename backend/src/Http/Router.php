<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Tiny regex-based router supporting path parameters ({id}) and
 * per-route middleware pipelines.
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, handler: callable, middleware: callable[]}> */
    private array $routes = [];

    /** @param callable[] $middleware */
    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $request = $request->withAttribute('routeParams', $params);

            // Compose middleware around the handler (onion style).
            $next = fn (Request $req): Response => $route['handler']($req, $params);
            foreach (array_reverse($route['middleware']) as $mw) {
                $current = $next;
                $next = fn (Request $req): Response => $mw($req, $current);
            }
            return $next($request);
        }

        if ($pathMatched) {
            return Response::error(405, 'METHOD_NOT_ALLOWED', 'HTTP method not allowed for this resource.');
        }
        return Response::error(404, 'NOT_FOUND', 'Resource not found.');
    }
}
