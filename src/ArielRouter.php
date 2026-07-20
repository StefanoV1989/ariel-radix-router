<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter;

use StefanoV1989\ArielRouter\Cache\RouteCache;
use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;
use StefanoV1989\ArielRouter\Contracts\RouterInterface;
use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\Http\Url;
use StefanoV1989\ArielRouter\Matching\MatchResult;

/**
 * Instance-based router for dependency injection and isolated route catalogs.
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
final class ArielRouter implements RouterInterface
{
    private readonly RouterEngine $engine;
    private ?string $defaultNamespace = null;

    /** @var list<RouteGroup> */
    private array $groups = [];

    /** @var array<string, true> */
    private array $catalogs = [];

    public function __construct(?RouterEngine $engine = null)
    {
        $this->engine = $engine ?? new RouterEngine();
    }

    public static function withCacheDirectory(string $cacheDirectory): self
    {
        return new self(new RouterEngine($cacheDirectory));
    }

    public function engine(): RouterEngine
    {
        return $this->engine;
    }

    public function reset(): void
    {
        $this->groups = [];
        $this->catalogs = [];
        $this->defaultNamespace = null;
        $this->engine->reset();
    }

    public function setDefaultNamespace(?string $namespace): void
    {
        $this->defaultNamespace = $namespace === null ? null : trim($namespace, '\\');
    }

    /**
     * @param list<string>|string $methods
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function add(array|string $methods, string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->match(is_string($methods) ? [$methods] : $methods, $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function get(string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->match(['GET'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function post(string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->match(['POST'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function put(string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->match(['PUT'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function patch(string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->match(['PATCH'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function delete(string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->match(['DELETE'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function options(string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->match(['OPTIONS'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function head(string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->match(['HEAD'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function any(string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->match(['*'], $path, $handler, $options);
    }

    /**
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function all(string $path, mixed $handler, ?array $options = null): Route
    {
        return $this->any($path, $handler, $options);
    }

    /**
     * @param list<string> $methods
     * @param Handler $handler
     * @param RouteOptions|null $options
     */
    public function match(array $methods, string $path, mixed $handler, ?array $options = null): Route
    {
        $prefix = '';
        $middleware = [];
        $namespace = $this->defaultNamespace;
        foreach ($this->groups as $group) {
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
            $this->engine->markDirty(...),
        );
        if (isset($options['as'])) {
            $route->name($options['as']);
        }

        return $this->engine->add($route);
    }

    /**
     * @param RouteOptions $options
     * @param \Closure(RouterInterface): void $routes
     */
    public function group(array $options, \Closure $routes): RouteGroup
    {
        $group = new RouteGroup(
            $options['prefix'] ?? '',
            self::middlewareList($options['middleware'] ?? []),
            $options['namespace'] ?? null,
        );
        $this->groups[] = $group;
        try {
            $routes($this);
        } finally {
            array_pop($this->groups);
        }

        return $group;
    }

    /** @param \Closure(Request, \Throwable): mixed $handler */
    public function error(\Closure $handler): void
    {
        $this->engine->onException($handler);
    }

    public function compile(): void
    {
        $this->engine->compile();
    }

    public function dispatch(?Request $request = null): mixed
    {
        return $this->engine->dispatch($request);
    }

    public function start(): void
    {
        $result = $this->dispatch();
        if ($result !== null) {
            if (!is_scalar($result) && !$result instanceof \Stringable) {
                throw new \UnexpectedValueException(
                    'ArielRouter::start() can only emit scalar or Stringable handler results.',
                );
            }
            echo $result;
        }
    }

    public function request(): Request
    {
        return $this->engine->currentRequest() ?? Request::fromGlobals();
    }

    public function currentRoute(): ?Route
    {
        return $this->engine->currentRoute();
    }

    public function currentRequest(): ?Request
    {
        return $this->engine->currentRequest();
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->engine->routes();
    }

    public function routeCount(): int
    {
        return count($this->engine->routes());
    }

    public function namedRoute(string $name): ?Route
    {
        foreach ($this->engine->routes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    public function hasNamedRoute(string $name): bool
    {
        return $this->namedRoute($name) !== null;
    }

    public function resolve(string $method, string $path): MatchResult
    {
        return $this->engine->resolve($method, $path);
    }

    public function hasRoute(string $method, string $path): bool
    {
        return $this->resolve($method, $path)->route !== null;
    }

    public function isCompiled(): bool
    {
        return $this->engine->isCompiled();
    }

    public function compilationCount(): int
    {
        return $this->engine->compilationCount();
    }

    /**
     * @param array<string, scalar|null>|list<scalar|null> $parameters
     * @param array<string, scalar|null> $query
     */
    public function url(string $name, array $parameters = [], array $query = []): Url
    {
        $route = $this->namedRoute($name);
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
    public function getUrl(
        ?string $name = null,
        array|string|int|float|bool|null $parameters = null,
        ?array $query = null,
    ): Url {
        if ($name === null) {
            $name = $this->currentRoute()?->getName();
        }
        if ($name === null) {
            return new Url('/');
        }
        $values = is_array($parameters) ? $parameters : ($parameters === null ? [] : [$parameters]);

        return $this->url($name, $values, $query ?? []);
    }

    /** @return array{version: int, definitions: list<ExportedDefinition>, tree: Node} */
    public function compiledPayload(): array
    {
        return RouteCache::payload($this->engine->routes());
    }

    /** @param array{version: int, definitions: list<ExportedDefinition>, tree: Node} $payload */
    public function appendCompiledDefinitions(string $catalog, array $payload): void
    {
        if (isset($this->catalogs[$catalog])) {
            return;
        }
        if ($payload['version'] !== RouteCache::FORMAT_VERSION) {
            throw new \RuntimeException('Unsupported compiled route format.');
        }

        $definitions = array_map(RouteDefinition::fromArray(...), $payload['definitions']);
        $prefix = '';
        $middleware = [];
        $namespace = $this->defaultNamespace;
        foreach ($this->groups as $group) {
            $prefix .= $group->prefix;
            $middleware = [...$middleware, ...$group->middleware];
            if ($group->namespace !== null) {
                $namespace = self::joinNamespace($namespace, $group->namespace);
            }
        }
        if ($prefix !== '' || $middleware !== [] || $namespace !== $this->defaultNamespace) {
            foreach ($definitions as $index => $definition) {
                $definitionNamespace = $definition->namespace;
                if ($namespace !== null && $namespace !== '') {
                    $definitionNamespace = $definitionNamespace === null
                        ? $namespace
                        : self::joinNamespace($namespace, $definitionNamespace);
                }
                $definitions[$index] = $definition->withContext(
                    self::joinPath($prefix, $definition->path),
                    [...$middleware, ...$definition->middleware],
                    $definitionNamespace,
                );
            }
        }
        $tree = RouteCache::prefix($payload['tree'], $prefix);
        $this->engine->appendCompiled($definitions, $tree);
        $this->catalogs[$catalog] = true;
    }

    public function definitionCatalogLoaded(string $catalog): bool
    {
        return isset($this->catalogs[$catalog]);
    }

    private static function joinPath(string $prefix, string $path): string
    {
        return '/' . trim(trim($prefix, '/') . '/' . trim($path, '/'), '/');
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
