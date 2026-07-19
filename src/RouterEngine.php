<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter;

use StefanoV1989\ArielRouter\Cache\RouteCache;
use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;
use StefanoV1989\ArielRouter\Contracts\RequestCloneableMiddleware;
use StefanoV1989\ArielRouter\Contracts\StatelessMiddleware;
use StefanoV1989\ArielRouter\Contracts\TerminableMiddleware;
use StefanoV1989\ArielRouter\Exception\HttpException;
use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\Matching\CompiledRadixTree;
use StefanoV1989\ArielRouter\Matching\CompositeRouteMatcher;
use StefanoV1989\ArielRouter\Matching\MatchResult;
use StefanoV1989\ArielRouter\Matching\RadixTree;
use StefanoV1989\ArielRouter\Matching\RouteMatcher;

/**
 * @phpstan-type Handler \Closure|array{class-string|object, string}|string|object
 * @phpstan-type MiddlewareDefinition string|Middleware|MiddlewareFactory
 * @phpstan-type Definition array{methods: list<string>, path: string, handler: Handler, middleware: list<MiddlewareDefinition>, namespace: string|null, conditions: array<string, string>, parameters: list<string>, name: string|null, regex: string|null}
 * @phpstan-type Node array{s: array<string, mixed>, d: array<string, mixed>, r: array<string, int>}
 */
final class RouterEngine
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var list<Route> */
    private array $dynamicRoutes = [];

    /** @var list<Route> */
    private array $regexRoutes = [];

    /** @var list<RouteMatcher> */
    private array $compiledCatalogs = [];

    /** @var list<Route> */
    private array $compiledRegexRoutes = [];

    private ?RouteMatcher $matcher = null;
    private bool $dirty = true;
    private bool $compiled = false;
    private bool $routeMutationAllowed = false;
    private int $compilationCount = 0;
    private IndexMode $indexMode = IndexMode::Auto;
    private ?Request $activeRequest = null;
    private ?Route $activeRoute = null;

    /** @var (\Closure(Request, \Throwable): mixed)|null */
    private ?\Closure $exceptionHandler = null;

    public function __construct(private ?string $cacheDirectory = null)
    {
    }

    public function add(Route $route): Route
    {
        $this->prepareMutation();
        $this->routes[] = $route;
        $this->dynamicRoutes[] = $route;
        $this->dirty = true;

        return $route;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function currentRoute(): ?Route
    {
        return $this->activeRoute;
    }

    public function currentRequest(): ?Request
    {
        return $this->activeRequest;
    }

    public function compilationCount(): int
    {
        return $this->compilationCount;
    }

    public function isCompiled(): bool
    {
        return $this->compiled;
    }

    public function resolve(string $method, string $path): MatchResult
    {
        $this->compile();
        if ($this->matcher === null) {
            throw new \LogicException('The router index has not been compiled.');
        }

        $result = $this->matcher->match($method, $path);
        if ($result->route !== null) {
            return $result;
        }

        return $this->matchRegex($method, $path, $result->methodNotAllowed);
    }

    public function setIndexMode(IndexMode $mode): void
    {
        if ($this->compiled) {
            throw new \LogicException('The index mode cannot change after compile().');
        }
        $this->indexMode = $mode;
    }

    public function indexMode(): IndexMode
    {
        return $this->indexMode;
    }

    public function allowRouteMutation(bool $allow = true): void
    {
        $this->routeMutationAllowed = $allow;
    }

    /** @param \Closure(Request, \Throwable): mixed $handler */
    public function onException(\Closure $handler): void
    {
        $this->prepareMutation();
        $this->exceptionHandler = $handler;
    }

    public function reset(): void
    {
        $this->routes = [];
        $this->dynamicRoutes = [];
        $this->regexRoutes = [];
        $this->compiledCatalogs = [];
        $this->compiledRegexRoutes = [];
        $this->matcher = null;
        $this->dirty = true;
        $this->compiled = false;
        $this->routeMutationAllowed = false;
        $this->compilationCount = 0;
        $this->indexMode = IndexMode::Auto;
        $this->activeRequest = null;
        $this->activeRoute = null;
        $this->exceptionHandler = null;
    }

    public function compile(): void
    {
        if (!$this->dirty) {
            return;
        }

        $this->regexRoutes = [...array_values(array_filter(
            $this->dynamicRoutes,
            static fn (Route $route): bool => $route->getRegex() !== null,
        )), ...$this->compiledRegexRoutes];

        $indexable = array_values(array_filter(
            $this->dynamicRoutes,
            static fn (Route $route): bool => $route->getRegex() === null,
        ));

        if ($this->shouldUseFileCache()) {
            $fingerprint = RouteCache::fingerprint($indexable);
            $tree = RouteCache::load($this->cacheDirectory ?? '', $fingerprint);
            if ($tree === null) {
                $tree = RouteCache::build($indexable);
                RouteCache::store($this->cacheDirectory ?? '', $fingerprint, $tree);
            }
            $this->matcher = new CompiledRadixTree($tree, $indexable);
        } else {
            $tree = new RadixTree();
            foreach ($indexable as $route) {
                $tree->add($route);
            }
            $this->matcher = $tree;
        }

        if ($this->compiledCatalogs !== []) {
            $this->matcher = new CompositeRouteMatcher([$this->matcher, ...$this->compiledCatalogs]);
        }

        foreach ($this->routes as $route) {
            $route->freeze();
        }
        $this->dirty = false;
        $this->compiled = true;
        ++$this->compilationCount;
    }

    public function dispatch(?Request $request = null): mixed
    {
        $this->compile();
        if ($this->activeRequest !== null) {
            throw new \LogicException('Nested dispatch is not supported by the same router instance.');
        }

        $request ??= Request::fromGlobals();
        $this->activeRequest = $request;

        try {
            $result = $this->resolve($request->method(), $request->url()->path());
            if ($result->route === null) {
                $status = $result->methodNotAllowed ? 405 : 404;
                throw new HttpException(
                    $result->methodNotAllowed
                        ? sprintf('Method "%s" is not allowed for route "%s".', strtoupper($request->method()), $request->url()->path())
                        : sprintf('Route "%s" was not found.', $request->url()->path()),
                    $status,
                );
            }

            $route = $result->route->forRequest($result->parameters);
            $this->activeRoute = $route;
            $request->addLoadedRoute($route);

            $terminable = [];
            foreach ($route->middlewares() as $definition) {
                $middleware = $this->resolveMiddleware($definition);
                $middleware->handle($request);
                if ($middleware instanceof TerminableMiddleware) {
                    $terminable[] = $middleware;
                }
            }

            $output = $this->invoke($route, $result->parameters);
            foreach (array_reverse($terminable) as $middleware) {
                $middleware->terminate($request, $output);
            }

            return $output;
        } catch (\Throwable $exception) {
            if ($this->exceptionHandler === null) {
                throw $exception;
            }

            return ($this->exceptionHandler)($request, $exception);
        } finally {
            $request->clearLoadedRoutes();
            $this->activeRoute = null;
            $this->activeRequest = null;
        }
    }

    /**
     * @param list<Definition> $definitions
     * @param Node $tree
     */
    public function appendCompiled(array $definitions, array $tree): void
    {
        $this->prepareMutation();
        $catalog = [];
        foreach ($definitions as $definition) {
            $route = Route::fromDefinition($definition, $this->markDirty(...));
            $route->freeze();
            $catalog[] = $route;
            $this->routes[] = $route;
            if ($route->getRegex() !== null) {
                $this->compiledRegexRoutes[] = $route;
            }
        }
        $this->compiledCatalogs[] = new CompiledRadixTree($tree, $catalog);
        $this->dirty = true;
    }

    public function markDirty(): void
    {
        $this->prepareMutation();
        $this->dirty = true;
    }

    private function matchRegex(string $method, string $path, bool $methodNotAllowed): MatchResult
    {
        foreach ($this->regexRoutes as $route) {
            $regex = $route->getRegex();
            if ($regex === null || preg_match($regex, $path, $matches) !== 1) {
                continue;
            }
            if (!in_array(strtolower($method), $route->methods(), true) && !in_array('*', $route->methods(), true)) {
                $methodNotAllowed = true;
                continue;
            }
            array_shift($matches);

            return new MatchResult($route, array_values($matches), false);
        }

        return new MatchResult(null, [], $methodNotAllowed);
    }

    /** @param list<string> $parameters */
    private function invoke(Route $route, array $parameters): mixed
    {
        $handler = $route->handler();
        if (is_array($handler)) {
            [$target, $method] = $handler;
            if (is_object($target)) {
                if (!is_callable([$target, $method])) {
                    throw new \RuntimeException(sprintf('The handler for route "%s" is not callable.', $route->path()));
                }

                if ($parameters === []) {
                    return $target->{$method}();
                }

                return HandlerInvoker::invoke([$target, $method], $parameters);
            }
            $class = $target;
            if (!class_exists($class) && $route->namespace() !== null) {
                $class = trim($route->namespace(), '\\') . '\\' . ltrim($class, '\\');
            }
            if (is_callable([$class, $method])) {
                $handler = [$class, $method];
            } else {
                $instance = new $class();
                $handler = [$instance, $method];
            }
            if (!is_callable($handler)) {
                throw new \RuntimeException(sprintf('The handler for route "%s" is not callable.', $route->path()));
            }

            return $parameters === []
                ? $handler()
                : HandlerInvoker::invoke($handler, $parameters);
        } elseif (is_string($handler) && !is_callable($handler) && $route->namespace() !== null) {
            $handler = trim($route->namespace(), '\\') . '\\' . ltrim($handler, '\\');
        }
        if (!is_callable($handler)) {
            throw new \RuntimeException(sprintf('The handler for route "%s" is not callable.', $route->path()));
        }

        return $handler(...$parameters);
    }

    /** @param MiddlewareDefinition $definition */
    private function resolveMiddleware(string|Middleware|MiddlewareFactory $definition): Middleware
    {
        if (is_string($definition)) {
            $resolved = new $definition();
            if ($resolved instanceof MiddlewareFactory) {
                return $resolved->create();
            }
            if (!$resolved instanceof Middleware) {
                throw new \LogicException(sprintf('Middleware class "%s" does not implement Middleware.', $definition));
            }

            return $resolved;
        }
        if ($definition instanceof MiddlewareFactory) {
            return $definition->create();
        }
        if ($definition instanceof StatelessMiddleware) {
            return $definition;
        }
        if ($definition instanceof RequestCloneableMiddleware) {
            return $definition->forRequest();
        }

        throw new \LogicException(sprintf('Middleware "%s" has an unsafe lifecycle.', $definition::class));
    }

    private function shouldUseFileCache(): bool
    {
        return $this->cacheDirectory !== null
            && $this->cacheDirectory !== ''
            && ($this->indexMode === IndexMode::File || ($this->indexMode === IndexMode::Auto && PHP_SAPI !== 'cli'));
    }

    private function prepareMutation(): void
    {
        if (!$this->compiled) {
            return;
        }
        if (!$this->routeMutationAllowed) {
            throw new \LogicException('Routes are immutable after compile(). Enable route mutation explicitly for development.');
        }
        foreach ($this->routes as $route) {
            $route->unfreeze();
        }
        $this->matcher = null;
        $this->regexRoutes = [];
        $this->compiledCatalogs = [];
        $this->compiledRegexRoutes = [];
        $this->compiled = false;
        $this->dirty = true;
    }
}
