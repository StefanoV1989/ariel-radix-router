<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Matching;

use StefanoV1989\ArielRouter\Route;

/** @phpstan-type Node array{s: array<string, mixed>, d: array<string, mixed>, r: array<string, int>} */
final class CompiledRadixTree implements RouteMatcher
{
    /**
     * @param Node $root
     * @param list<Route> $routes
     */
    public function __construct(
        array $root,
        private readonly array $routes,
    ) {
        $this->root = self::normalizeNode($root);
    }

    /** @var Node */
    private readonly array $root;

    public function match(string $method, string $path): MatchResult
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        $path = trim(is_string($parsed) ? $parsed : '', '/');
        $segments = $path === '' ? [] : explode('/', $path);

        return $this->walk($this->root, $segments, 0, strtolower($method), []);
    }

    /**
     * @param Node $node
     * @param list<string> $segments
     * @param list<string> $parameters
     */
    private function walk(array $node, array $segments, int $offset, string $method, array $parameters): MatchResult
    {
        if ($offset === count($segments)) {
            $index = $node['r'][$method] ?? $node['r']['*'] ?? null;
            $route = $index !== null ? ($this->routes[$index] ?? null) : null;

            return new MatchResult($route, $parameters, $route === null && $node['r'] !== []);
        }

        $segment = $segments[$offset];
        $static = $node['s'][$segment] ?? null;
        if ($static !== null) {
            $result = $this->walk(self::normalizeNode($static), $segments, $offset + 1, $method, $parameters);
            if ($result->route !== null || $result->methodNotAllowed) {
                return $result;
            }
        }

        $methodNotAllowed = false;
        foreach ($node['d'] as $condition => $candidate) {
            if (!RouteCondition::matches($segment, $condition)) {
                continue;
            }
            $result = $this->walk(
                self::normalizeNode($candidate),
                $segments,
                $offset + 1,
                $method,
                [...$parameters, $segment],
            );
            if ($result->route !== null) {
                return $result;
            }
            $methodNotAllowed = $methodNotAllowed || $result->methodNotAllowed;
        }

        return new MatchResult(null, [], $methodNotAllowed);
    }

    /** @return Node */
    private static function normalizeNode(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Invalid compiled radix node.');
        }
        $staticValue = $value['s'] ?? null;
        $dynamicValue = $value['d'] ?? null;
        $routesValue = $value['r'] ?? null;
        if (!is_array($staticValue) || !is_array($dynamicValue) || !is_array($routesValue)) {
            throw new \UnexpectedValueException('Invalid compiled radix node shape.');
        }

        $static = [];
        foreach ($staticValue as $key => $child) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Invalid static radix edge.');
            }
            $static[$key] = $child;
        }
        $dynamic = [];
        foreach ($dynamicValue as $key => $child) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Invalid dynamic radix edge.');
            }
            $dynamic[$key] = $child;
        }
        $routes = [];
        foreach ($routesValue as $key => $index) {
            if (!is_string($key) || !is_int($index)) {
                throw new \UnexpectedValueException('Invalid compiled route target.');
            }
            $routes[$key] = $index;
        }

        return ['s' => $static, 'd' => $dynamic, 'r' => $routes];
    }
}
