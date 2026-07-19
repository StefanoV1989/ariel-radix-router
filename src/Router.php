<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter;

use StefanoV1989\ArielRouter\Cache\RouteCache;
use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;
use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\Http\Url;
use StefanoV1989\ArielRouter\Matching\MatchResult;

/**
 * @phpstan-type Handler \Closure|array{class-string|object, string}|string|object
 * @phpstan-type MiddlewareDefinition string|Middleware|MiddlewareFactory
 * @phpstan-type RouteOptions array{prefix?: string, namespace?: string, middleware?: MiddlewareDefinition|list<MiddlewareDefinition>, as?: string}
 * @phpstan-type Definition array{methods: list<string>, path: string, handler: Handler, middleware: list<MiddlewareDefinition>, namespace: string|null, conditions: array<string, string>, parameters: list<string>, name: string|null, regex: string|null}
 * @phpstan-type Node array{s: array<string, mixed>, d: array<string, mixed>, r: array<string, int>}
 */
final class Router
{
    private static ?RouterEngine $engine = null;
    private static ?string $defaultNamespace = null;

    /** @var list<RouteGroup> */
    private static array $groups = [];

    /** @var array<string, true> */
    private static array $catalogs = [];

    public static function engine(): RouterEngine
    {
        return self::$engine ??= new RouterEngine();
    }

    public static function useEngine(RouterEngine $engine): void
    {
        self::$engine = $engine;
        self::$groups = [];
        self::$catalogs = [];
    }

    public static function configure(?string $cacheDirectory = null): RouterEngine
    {
        $engine = new RouterEngine($cacheDirectory);
        self::useEngine($engine);

        return $engine;
    }

    public static function reset(): void
    {
        self::$groups = [];
        self::$catalogs = [];
        self::$defaultNamespace = null;
        self::engine()->reset();
    }

    public static function setDefaultNamespace(?string $namespace): void
    {
        self::$defaultNamespace = $namespace === null ? null : trim($namespace, '\\');
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function get(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::match(['GET'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function post(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::match(['POST'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function put(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::match(['PUT'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function patch(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::match(['PATCH'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function delete(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::match(['DELETE'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function options(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::match(['OPTIONS'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function head(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::match(['HEAD'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function any(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::match(['*'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function all(string $path, mixed $handler, ?array $options = null): Route
    {
        return self::any($path, $handler, $options);
    }

    /**
     * @param list<string> $methods
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public static function match(array $methods, string $path, mixed $handler, ?array $options = null): Route
    {
        $prefix = '';
        $middleware = [];
        $namespace = self::$defaultNamespace;
        foreach (self::$groups as $group) {
            $prefix .= $group->prefix;
            $middleware = [...$middleware, ...$group->middleware];
            if ($group->namespace !== null) {
                $namespace = self::joinNamespace($namespace, $group->namespace);
            }
        }

        if (isset($options['prefix'])) {
            $prefix .= $options['prefix'];
        }
        if (isset($options['namespace'])) {
            $namespace = self::joinNamespace($namespace, $options['namespace']);
        }
        if (isset($options['middleware'])) {
            $middleware = [...$middleware, ...self::middlewareList($options['middleware'])];
        }

        $route = new Route(
            $methods,
            self::joinPath($prefix, $path),
            $handler,
            $middleware,
            $namespace,
            self::engine()->markDirty(...),
        );
        if (isset($options['as'])) {
            $route->name($options['as']);
        }

        return self::engine()->add($route);
    }

    /**
     * @param RouteOptions $options
     * @param \Closure(): void $routes
     */
    public static function group(array $options, \Closure $routes): RouteGroup
    {
        $group = new RouteGroup(
            $options['prefix'] ?? '',
            self::middlewareList($options['middleware'] ?? []),
            $options['namespace'] ?? null,
        );
        self::$groups[] = $group;
        try {
            $routes();
        } finally {
            array_pop(self::$groups);
        }

        return $group;
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
                throw new \UnexpectedValueException('Router::start() can only emit scalar or Stringable handler results.');
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
        foreach (self::engine()->routes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    public static function hasNamedRoute(string $name): bool
    {
        return self::namedRoute($name) !== null;
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
        $route = self::namedRoute($name);
        if ($route === null) {
            throw new \InvalidArgumentException(sprintf('Named route "%s" does not exist.', $name));
        }

        $position = 0;
        $path = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\??\}/',
            static function (array $matches) use ($parameters, &$position): string {
                $value = $parameters[$matches[1]] ?? $parameters[$position] ?? null;
                ++$position;
                if ($value === null) {
                    return '';
                }

                return rawurlencode((string) $value);
            },
            $route->path(),
        ) ?? $route->path();
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return new Url($path);
    }

    /**
     * @param array<string, scalar|null>|list<scalar|null>|scalar|null $parameters
     * @param array<string, scalar|null>|null $query
     */
    public static function getUrl(?string $name = null, array|string|int|float|bool|null $parameters = null, ?array $query = null): Url
    {
        if ($name === null) {
            $name = self::currentRoute()?->getName();
        }
        if ($name === null) {
            return new Url('/');
        }
        $values = is_array($parameters) ? $parameters : ($parameters === null ? [] : [$parameters]);

        return self::url($name, $values, $query ?? []);
    }

    /** @return array{version: int, definitions: list<Definition>, tree: Node} */
    public static function compiledPayload(): array
    {
        return RouteCache::payload(self::engine()->routes());
    }

    /** @param array{version: int, definitions: list<Definition>, tree: Node} $payload */
    public static function appendCompiledDefinitions(string $catalog, array $payload): void
    {
        if (isset(self::$catalogs[$catalog])) {
            return;
        }
        if ($payload['version'] !== RouteCache::FORMAT_VERSION) {
            throw new \RuntimeException('Unsupported compiled route format.');
        }

        $definitions = $payload['definitions'];
        $prefix = '';
        $middleware = [];
        $namespace = self::$defaultNamespace;
        foreach (self::$groups as $group) {
            $prefix .= $group->prefix;
            $middleware = [...$middleware, ...$group->middleware];
            if ($group->namespace !== null) {
                $namespace = self::joinNamespace($namespace, $group->namespace);
            }
        }
        if ($prefix !== '' || $middleware !== [] || $namespace !== self::$defaultNamespace) {
            foreach ($definitions as &$definition) {
                $definition['path'] = self::joinPath($prefix, $definition['path']);
                $definition['middleware'] = [...$middleware, ...$definition['middleware']];
                if ($namespace !== null && $namespace !== '') {
                    $definition['namespace'] = $definition['namespace'] === null
                        ? $namespace
                        : self::joinNamespace($namespace, $definition['namespace']);
                }
            }
            unset($definition);
        }
        $tree = RouteCache::prefix($payload['tree'], $prefix);
        self::engine()->appendCompiled($definitions, $tree);
        self::$catalogs[$catalog] = true;
    }

    public static function definitionCatalogLoaded(string $catalog): bool
    {
        return isset(self::$catalogs[$catalog]);
    }

    private static function joinPath(string $prefix, string $path): string
    {
        $joined = '/' . trim(trim($prefix, '/') . '/' . trim($path, '/'), '/');

        return $joined;
    }

    private static function joinNamespace(?string $base, string $namespace): string
    {
        $namespace = trim($namespace, '\\');
        if ($base === null || $base === '') {
            return $namespace;
        }

        return trim($base, '\\') . '\\' . $namespace;
    }

    /** @return list<MiddlewareDefinition> */
    private static function middlewareList(mixed $middleware): array
    {
        if ($middleware === null || $middleware === '') {
            return [];
        }
        $items = array_values(is_array($middleware) ? $middleware : [$middleware]);
        $result = [];
        foreach ($items as $item) {
            if (!is_string($item) && !$item instanceof Middleware && !$item instanceof MiddlewareFactory) {
                throw new \InvalidArgumentException('Middleware must be a middleware class name or object.');
            }
            Route::assertMiddleware($item);
            $result[] = $item;
        }

        return $result;
    }
}
