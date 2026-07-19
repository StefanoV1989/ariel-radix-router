<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Matching;

use StefanoV1989\ArielRouter\Exception\RouteConflictException;
use StefanoV1989\ArielRouter\Route;

final class RadixTree implements RouteMatcher
{
    private RadixNode $root;

    public function __construct()
    {
        $this->root = new RadixNode();
    }

    public function add(Route $route): void
    {
        $this->insert($this->root, self::segments($route->path(), true), $route, 0, 0);
    }

    public function match(string $method, string $path): MatchResult
    {
        return $this->walk($this->root, self::segments($path), 0, strtolower($method), []);
    }

    /** @param list<string> $segments */
    private function insert(RadixNode $node, array $segments, Route $route, int $offset, int $parameterIndex): void
    {
        if ($offset === count($segments)) {
            foreach ($route->methods() as $method) {
                if (isset($node->routes[$method]) && $node->routes[$method] !== $route) {
                    throw new RouteConflictException(sprintf(
                        'Route "%s" conflicts with "%s" for method %s.',
                        $route->path(),
                        $node->routes[$method]->path(),
                        strtoupper($method),
                    ));
                }
                $node->routes[$method] = $route;
            }

            return;
        }

        $segment = $segments[$offset];
        if (preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*(\?)?\}$/', $segment, $optional) === 1) {
            $condition = $route->conditionFor($parameterIndex);
            $key = $condition ?? '';
            $node->dynamic[$key] ??= [
                'condition' => $condition,
                'priority' => RouteCondition::priority($condition),
                'node' => new RadixNode(),
            ];
            $this->insert($node->dynamic[$key]['node'], $segments, $route, $offset + 1, $parameterIndex + 1);
            if (($optional[1] ?? '') === '?') {
                $this->insert($node, $segments, $route, $offset + 1, $parameterIndex + 1);
            }

            return;
        }

        $node->static[$segment] ??= new RadixNode();
        $this->insert($node->static[$segment], $segments, $route, $offset + 1, $parameterIndex);
    }

    /**
     * @param list<string> $segments
     * @param list<string> $parameters
     */
    private function walk(RadixNode $node, array $segments, int $offset, string $method, array $parameters): MatchResult
    {
        if ($offset === count($segments)) {
            $route = $node->routes[$method] ?? $node->routes['*'] ?? null;

            return new MatchResult($route, $parameters, $route === null && $node->routes !== []);
        }

        $segment = $segments[$offset];
        $static = $node->static[$segment] ?? null;
        if ($static !== null) {
            $result = $this->walk($static, $segments, $offset + 1, $method, $parameters);
            if ($result->route !== null || $result->methodNotAllowed) {
                return $result;
            }
        }

        $dynamic = array_values($node->dynamic);
        if (count($dynamic) > 1) {
            usort($dynamic, static fn (array $left, array $right): int =>
                ($right['priority'] <=> $left['priority']) ?: strcmp($left['condition'] ?? '', $right['condition'] ?? '')
            );
        }

        $methodNotAllowed = false;
        foreach ($dynamic as $edge) {
            if (!RouteCondition::matches($segment, $edge['condition'])) {
                continue;
            }
            $candidateParameters = [...$parameters, $segment];
            $result = $this->walk($edge['node'], $segments, $offset + 1, $method, $candidateParameters);
            if ($result->route !== null) {
                return $result;
            }
            $methodNotAllowed = $methodNotAllowed || $result->methodNotAllowed;
        }

        return new MatchResult(null, [], $methodNotAllowed);
    }

    /** @return list<string> */
    private static function segments(string $path, bool $routePattern = false): array
    {
        if (!$routePattern) {
            $parsed = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '';
        }
        $path = trim($path, '/');

        return $path === '' ? [] : explode('/', $path);
    }
}
