<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter;

use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;
use StefanoV1989\ArielRouter\Contracts\RouterInterface;
use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\Http\Url;
use StefanoV1989\ArielRouter\Matching\MatchResult;

/**
 * Static convenience facade backed by one ArielRouter instance.
 *
 * @phpstan-type Handler \Closure|array{class-string|object, string}|string|object
 * @phpstan-type MiddlewareDefinition string|Middleware|MiddlewareFactory
 * @phpstan-type RouteOptions array{
 *     prefix?: string,
 *     namespace?: string,
 *     middleware?: MiddlewareDefinition|list<MiddlewareDefinition>,
 *     as?: string
 * }
 * @phpstan-type Node array{s: array<string, mixed>, d: array<string, mixed>, r: array<string, int>}
 * @phpstan-import-type ExportedDefinition from RouteDefinition
 */
final class Router
{
    private static ?RouterEngine $engine = null;
    private static ?ArielRouter $router = null;

    public static function engine(): RouterEngine
    {
        $engine = self::$engine;
        if ($engine === null) {
            $engine = new RouterEngine();
            self::useEngine($engine);
        }

        return $engine;
    }

    public static function useEngine(RouterEngine $engine): void
    {
        self::$engine = $engine;
        self::$router = new ArielRouter($engine);
    }

    public static function configure(?string $cacheDirectory = null): RouterEngine
    {
        $engine = new RouterEngine($cacheDirectory);
        self::useEngine($engine);

        return $engine;
    }

    public static function reset(): void
    {
        self::instance()->reset();
    }

    public static function setDefaultNamespace(?string $namespace): void
    {
        self::instance()->setDefaultNamespace($namespace);
    }

    /**
     * @param list<string>|string $methods
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function add(array|string $methods, string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->add($methods, $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function get(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->get($path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function post(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->post($path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function put(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->put($path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function patch(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->patch($path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function delete(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->delete($path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function options(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->options($path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function head(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->head($path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function any(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->any($path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function all(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->all($path, $handler, $options);
    }

    /**
     * @param list<string> $methods
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function match(array $methods, string $path, mixed $handler, ?array $options = null): Route
    {
        return self::instance()->match($methods, $path, $handler, $options);
    }

    /**
     * @param RouteOptions $options
     * @param \Closure(): void $routes
     */
    public static function group(array $options, \Closure $routes): RouteGroup
    {
        return self::instance()->group(
            $options,
            static function (RouterInterface $router) use ($routes): void {
                $routes();
            },
        );
    }

    /** @param \Closure(Request, \Throwable): mixed $handler */
    public static function error(\Closure $handler): void
    {
        self::engine()->onException($handler);
    }

    public static function compile(): void
    {
        self::engine()->compile();
    }

    public static function dispatch(?Request $request = null): mixed
    {
        return self::engine()->dispatch($request);
    }

    public static function start(): void
    {
        $result = self::dispatch();
        if ($result !== null) {
            if (!is_scalar($result) && !$result instanceof \Stringable) {
                throw new \UnexpectedValueException(
                    'Router::start() can only emit scalar or Stringable handler results.',
                );
            }
            echo $result;
        }
    }

    public static function request(): Request
    {
        return self::engine()->currentRequest() ?? Request::fromGlobals();
    }

    public static function currentRoute(): ?Route
    {
        return self::engine()->currentRoute();
    }

    public static function currentRequest(): ?Request
    {
        return self::engine()->currentRequest();
    }

    /** @return list<Route> */
    public static function routes(): array
    {
        return self::engine()->routes();
    }

    public static function routeCount(): int
    {
        return count(self::engine()->routes());
    }

    public static function namedRoute(string $name): ?Route
    {
        return self::instance()->namedRoute($name);
    }

    public static function hasNamedRoute(string $name): bool
    {
        return self::instance()->hasNamedRoute($name);
    }

    public static function resolve(string $method, string $path): MatchResult
    {
        return self::engine()->resolve($method, $path);
    }

    public static function hasRoute(string $method, string $path): bool
    {
        return self::resolve($method, $path)->route !== null;
    }

    public static function isCompiled(): bool
    {
        return self::engine()->isCompiled();
    }

    public static function compilationCount(): int
    {
        return self::engine()->compilationCount();
    }

    /**
     * @param array<string, scalar|null>|list<scalar|null> $parameters
     * @param array<string, scalar|null> $query
     */
    public static function url(string $name, array $parameters = [], array $query = []): Url
    {
        return self::instance()->url($name, $parameters, $query);
    }

    /**
     * @param array<string, scalar|null>|list<scalar|null>|scalar|null $parameters
     * @param array<string, scalar|null>|null $query
     */
    public static function getUrl(
        ?string $name = null,
        array|string|int|float|bool|null $parameters = null,
        ?array $query = null,
    ): Url {
        return self::instance()->getUrl($name, $parameters, $query);
    }

    /** @return array{version: int, definitions: list<ExportedDefinition>, tree: Node} */
    public static function compiledPayload(): array
    {
        return self::instance()->compiledPayload();
    }

    /** @param array{version: int, definitions: list<ExportedDefinition>, tree: Node} $payload */
    public static function appendCompiledDefinitions(string $catalog, array $payload): void
    {
        self::instance()->appendCompiledDefinitions($catalog, $payload);
    }

    public static function definitionCatalogLoaded(string $catalog): bool
    {
        return self::instance()->definitionCatalogLoaded($catalog);
    }

    private static function instance(): ArielRouter
    {
        $router = self::$router;
        if ($router === null) {
            $engine = new RouterEngine();
            $router = new ArielRouter($engine);
            self::$engine = $engine;
            self::$router = $router;
        }

        return $router;
    }
}
