<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Cache;

use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;
use StefanoV1989\ArielRouter\Exception\RouteConflictException;
use StefanoV1989\ArielRouter\Matching\RouteCondition;
use StefanoV1989\ArielRouter\Route;

/**
 * @phpstan-type Node array{s: array<string, mixed>, d: array<string, mixed>, r: array<string, int>}
 * @phpstan-type Handler \Closure|array{class-string|object, string}|string|object
 */
final class RouteCache
{
    public const FORMAT_VERSION = 1;

    /** @param list<Route> $routes */
    public static function fingerprint(array $routes): string
    {
        $hash = hash_init('xxh128');
        hash_update($hash, self::FORMAT_VERSION . "\0");
        foreach ($routes as $route) {
            hash_update($hash, $route->path() . "\0" . implode(',', $route->methods()) . "\0");
            foreach ($route->conditions() as $name => $condition) {
                hash_update($hash, $name . '=' . $condition . "\0");
            }
            hash_update($hash, ($route->getRegex() ?? '') . "\1");
        }

        return hash_final($hash);
    }

    /**
     * @param list<Route> $routes
     * @return Node
     */
    public static function build(array $routes): array
    {
        $tree = new CacheNode();
        foreach ($routes as $index => $route) {
            if ($route->getRegex() !== null) {
                continue;
            }
            $path = trim($route->path(), '/');
            $segments = $path === '' ? [] : explode('/', $path);
            self::insert($tree, $segments, $route, $index, 0, 0);
        }
        self::sortDynamic($tree);

        return $tree->export();
    }

    /**
     * @param list<Route> $routes
     * @return list<array{methods: list<string>, path: string, handler: Handler, middleware: list<string|Middleware|MiddlewareFactory>, namespace: string|null, conditions: array<string, string>, parameters: list<string>, name: string|null, regex: string|null}>
     */
    public static function definitions(array $routes): array
    {
        $definitions = [];
        foreach ($routes as $route) {
            self::assertExportable($route->handler(), 'handler');
            foreach ($route->middlewares() as $middleware) {
                self::assertExportable($middleware, 'middleware');
            }
            $definitions[] = [
                'methods' => $route->methods(),
                'path' => $route->path(),
                'handler' => $route->handler(),
                'middleware' => $route->middlewares(),
                'namespace' => $route->namespace(),
                'conditions' => $route->conditions(),
                'parameters' => $route->parameterNames(),
                'name' => $route->getName(),
                'regex' => $route->getRegex(),
            ];
        }

        return $definitions;
    }

    /**
     * @param list<Route> $routes
     * @return array{version: int, definitions: list<array{methods: list<string>, path: string, handler: Handler, middleware: list<string|Middleware|MiddlewareFactory>, namespace: string|null, conditions: array<string, string>, parameters: list<string>, name: string|null, regex: string|null}>, tree: Node}
     */
    public static function payload(array $routes): array
    {
        return ['version' => self::FORMAT_VERSION, 'definitions' => self::definitions($routes), 'tree' => self::build($routes)];
    }

    /**
     * @param Node $tree
     * @return Node
     */
    public static function prefix(array $tree, string $prefix): array
    {
        $parsed = parse_url($prefix, PHP_URL_PATH);
        $path = trim(is_string($parsed) ? $parsed : '', '/');
        if ($path === '') {
            return $tree;
        }
        foreach (array_reverse(explode('/', $path)) as $segment) {
            $tree = ['s' => [$segment => $tree], 'd' => [], 'r' => []];
        }

        return $tree;
    }

    /** @param Node $tree */
    public static function store(string $directory, string $fingerprint, array $tree): ?string
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }
        $file = rtrim($directory, '/\\') . '/radix-v' . self::FORMAT_VERSION . '-' . $fingerprint . '.php';
        if (is_file($file)) {
            return $file;
        }
        $payload = '<?php return ' . var_export(['version' => self::FORMAT_VERSION, 'fingerprint' => $fingerprint, 'tree' => $tree], true) . ';';
        $temporary = tempnam($directory, 'radix-');
        if ($temporary === false) {
            return null;
        }
        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
                return null;
            }
            chmod($temporary, 0664);
            if (!rename($temporary, $file)) {
                return null;
            }

            return $file;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return Node|null */
    public static function load(string $directory, string $fingerprint): ?array
    {
        $file = rtrim($directory, '/\\') . '/radix-v' . self::FORMAT_VERSION . '-' . $fingerprint . '.php';
        if (!is_file($file)) {
            return null;
        }
        $payload = require $file;
        if (!is_array($payload)
            || ($payload['version'] ?? null) !== self::FORMAT_VERSION
            || ($payload['fingerprint'] ?? null) !== $fingerprint
            || !isset($payload['tree'])
            || !is_array($payload['tree'])) {
            return null;
        }

        return self::normalizeNode($payload['tree']);
    }

    /** @param list<string> $segments */
    private static function insert(CacheNode $node, array $segments, Route $route, int $routeIndex, int $offset, int $parameterIndex): void
    {
        if ($offset === count($segments)) {
            foreach ($route->methods() as $method) {
                if (isset($node->routes[$method]) && $node->routes[$method] !== $routeIndex) {
                    throw new RouteConflictException(sprintf(
                        'Route "%s" conflicts with another route for method %s.',
                        $route->path(),
                        strtoupper($method),
                    ));
                }
                $node->routes[$method] = $routeIndex;
            }

            return;
        }
        $segment = $segments[$offset];
        if (preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*(\?)?\}$/', $segment, $optional) === 1) {
            $condition = $route->conditionFor($parameterIndex) ?? '';
            $node->dynamic[$condition] ??= new CacheNode();
            self::insert($node->dynamic[$condition], $segments, $route, $routeIndex, $offset + 1, $parameterIndex + 1);
            if (($optional[1] ?? '') === '?') {
                self::insert($node, $segments, $route, $routeIndex, $offset + 1, $parameterIndex + 1);
            }

            return;
        }
        $node->static[$segment] ??= new CacheNode();
        self::insert($node->static[$segment], $segments, $route, $routeIndex, $offset + 1, $parameterIndex);
    }

    private static function sortDynamic(CacheNode $node): void
    {
        if (count($node->dynamic) > 1) {
            uksort($node->dynamic, static fn (string $left, string $right): int =>
                (RouteCondition::priority($right) <=> RouteCondition::priority($left)) ?: strcmp($left, $right)
            );
        }
        foreach ($node->static as $child) {
            self::sortDynamic($child);
        }
        foreach ($node->dynamic as $child) {
            self::sortDynamic($child);
        }
    }

    /** @return Node */
    private static function normalizeNode(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('The compiled route cache contains an invalid node.');
        }
        $staticValue = $value['s'] ?? null;
        $dynamicValue = $value['d'] ?? null;
        $routesValue = $value['r'] ?? null;
        if (!is_array($staticValue) || !is_array($dynamicValue) || !is_array($routesValue)) {
            throw new \UnexpectedValueException('The compiled route cache contains an invalid node shape.');
        }

        $static = [];
        foreach ($staticValue as $segment => $child) {
            if (!is_string($segment)) {
                throw new \UnexpectedValueException('A static route segment must be a string.');
            }
            $static[$segment] = self::normalizeNode($child);
        }
        $dynamic = [];
        foreach ($dynamicValue as $condition => $child) {
            if (!is_string($condition)) {
                throw new \UnexpectedValueException('A dynamic route condition must be a string.');
            }
            $dynamic[$condition] = self::normalizeNode($child);
        }
        $routes = [];
        foreach ($routesValue as $method => $index) {
            if (!is_string($method) || !is_int($index)) {
                throw new \UnexpectedValueException('A compiled route target must contain a method and integer index.');
            }
            $routes[$method] = $index;
        }

        return ['s' => $static, 'd' => $dynamic, 'r' => $routes];
    }

    private static function assertExportable(mixed $value, string $kind): void
    {
        if (is_string($value) || (is_array($value) && count($value) === 2 && is_string($value[0]) && is_string($value[1]))) {
            return;
        }
        throw new \LogicException(sprintf('Compiled definitions require %s values exportable as a class name or [class, method].', $kind));
    }
}
