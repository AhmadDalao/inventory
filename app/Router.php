<?php
declare(strict_types=1);

final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            $params = $this->match($route['pattern'], $path);

            if ($params === null) {
                continue;
            }

            call_user_func($route['handler'], $params);
            return;
        }

        abort(404, 'Page not found.');
    }

    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $matches): string => '(?P<' . $matches[1] . '>[^/]+)',
            $pattern
        );

        $regex = '#^' . rtrim($regex, '/') . '$#';

        if ($pattern === '/') {
            $regex = '#^/$#';
        }

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $params = [];

        foreach ($matches as $key => $value) {
            if (!is_int($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
