<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Contracts;

use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\Http\Url;
use StefanoV1989\ArielRouter\Matching\MatchResult;
use StefanoV1989\ArielRouter\Route;
use StefanoV1989\ArielRouter\RouteGroup;

/**
 * Mockable contract for dependency injection and application boundaries.
 *
 * @phpstan-type Handler \Closure|array{class-string|object, string}|string|object
 * @phpstan-type MiddlewareDefinition string|Middleware|MiddlewareFactory
 * @phpstan-type RouteOptions array{
 *     prefix?: string,
 *     namespace?: string,
 *     middleware?: MiddlewareDefinition|list<MiddlewareDefinition>,
 *     as?: string
 * }
 */
interface RouterInterface
{
    /**
     * @param list<string>|string $methods
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function add(array|string $methods, string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function get(string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function post(string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function put(string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function patch(string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function delete(string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function options(string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function head(string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function any(string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function all(string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param list<string> $methods
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function match(array $methods, string $path, mixed $handler, ?array $options = null): Route;

    /**
     * @param RouteOptions $options
     * @param \Closure(RouterInterface): void $routes
     */
    public function group(array $options, \Closure $routes): RouteGroup;

    public function compile(): void;

    public function dispatch(?Request $request = null): mixed;

    public function resolve(string $method, string $path): MatchResult;

    /** @return list<Route> */
    public function routes(): array;

    /**
     * @param array<string, scalar|null>|list<scalar|null> $parameters
     * @param array<string, scalar|null> $query
     */
    public function url(string $name, array $parameters = [], array $query = []): Url;
}
