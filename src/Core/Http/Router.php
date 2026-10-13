<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * موجّه بسيط: map من "method route" إلى callable. لا حاجة لمكتبة توجيه.
 */
final class Router
{
    /** @var array<string, callable> */
    private array $routes = [];

    public function get(string $name, callable $handler): void
    {
        $this->add('GET', $name, $handler);
    }

    public function post(string $name, callable $handler): void
    {
        $this->add('POST', $name, $handler);
    }

    public function put(string $name, callable $handler): void
    {
        $this->add('PUT', $name, $handler);
    }

    public function patch(string $name, callable $handler): void
    {
        $this->add('PATCH', $name, $handler);
    }

    public function delete(string $name, callable $handler): void
    {
        $this->add('DELETE', $name, $handler);
    }

    public function add(string $method, string $name, callable $handler): void
    {
        $this->routes[strtoupper($method) . ' ' . $name] = $handler;
    }

    public function dispatch(Request $request): void
    {
        $route = (string) ($request->input('r') ?? $request->input('route') ?? '');
        if ($route === '') {
            $path = trim($request->path(), '/');
            // دعم اختياري: /api/index.php/route أو /api/route
            if (str_contains($path, 'api/')) {
                $route = trim(substr($path, (int) strpos($path, 'api/') + 4), '/');
            }
        }
        $route = strtolower(trim($route, '/'));
        $key   = strtoupper($request->method()) . ' ' . $route;

        if (!isset($this->routes[$key])) {
            Response::error('not_found', 'المسار المطلوب غير موجود.', 404);
        }

        ($this->routes[$key])($request);
    }
}