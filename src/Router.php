<?php

declare(strict_types=1);

namespace App;

class Router
{
    /** @var array<string, array<int, array{pattern: string, handler: callable}>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][] = [
            'pattern' => $this->normalize($path),
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = $this->normalize($path);
        $path = $this->stripBasePath($path);

        foreach ($this->routes[$method] ?? [] as $route) {
            $params = $this->matchRoute($route['pattern'], $path);
            if ($params !== null) {
                ($route['handler'])($params);
                return;
            }
        }

        error_page(404);
    }

    /**
     * @return array<string, string>|null
     */
    private function matchRoute(string $pattern, string $path): ?array
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts = explode('/', trim($path, '/'));

        if ($pattern === '/') {
            return $path === '/' ? [] : null;
        }

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];

        foreach ($patternParts as $i => $part) {
            if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/', $part, $matches)) {
                $params[$matches[1]] = $pathParts[$i];
                continue;
            }

            if ($part !== $pathParts[$i]) {
                return null;
            }
        }

        return $params;
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function stripBasePath(string $path): string
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = str_replace('\\', '/', dirname($scriptName));

        if ($basePath !== '' && $basePath !== '/') {
            if (str_starts_with($path, $basePath)) {
                $path = substr($path, strlen($basePath));
                $path = $path === '' ? '/' : $path;
            }
        }

        return $this->normalize($path);
    }
}
