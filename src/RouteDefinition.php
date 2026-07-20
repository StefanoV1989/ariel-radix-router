<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter;

use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;

/**
 * Immutable route metadata used while loading compiled route catalogs.
 *
 * @phpstan-type Handler \Closure|array{class-string|object, string}|string|object
 * @phpstan-type MiddlewareDefinition string|Middleware|MiddlewareFactory
 * @phpstan-type ExportedDefinition array{
 *     methods: list<string>,
 *     path: string,
 *     handler: Handler,
 *     middleware: list<MiddlewareDefinition>,
 *     namespace: string|null,
 *     conditions: array<string, string>,
 *     parameters: list<string>,
 *     name: string|null,
 *     regex: string|null
 * }
 */
final readonly class RouteDefinition
{
    /**
     * @param list<string> $methods
     * @param Handler $handler
     * @param list<MiddlewareDefinition> $middleware
     * @param array<string, string> $conditions
     * @param list<string> $parameters
     */
    public function __construct(
        public array $methods,
        public string $path,
        public mixed $handler,
        public array $middleware,
        public ?string $namespace,
        public array $conditions,
        public array $parameters,
        public ?string $name,
        public ?string $regex,
    ) {
    }

    /** @param ExportedDefinition $definition */
    public static function fromArray(array $definition): self
    {
        return new self(
            $definition['methods'],
            $definition['path'],
            $definition['handler'],
            $definition['middleware'],
            $definition['namespace'],
            $definition['conditions'],
            $definition['parameters'],
            $definition['name'],
            $definition['regex'],
        );
    }

    public static function fromRoute(Route $route): self
    {
        return new self(
            $route->methods(),
            $route->path(),
            $route->handler(),
            $route->middlewares(),
            $route->namespace(),
            $route->conditions(),
            $route->parameterNames(),
            $route->getName(),
            $route->getRegex(),
        );
    }

    /** @return ExportedDefinition */
    public function toArray(): array
    {
        return [
            'methods' => $this->methods,
            'path' => $this->path,
            'handler' => $this->handler,
            'middleware' => $this->middleware,
            'namespace' => $this->namespace,
            'conditions' => $this->conditions,
            'parameters' => $this->parameters,
            'name' => $this->name,
            'regex' => $this->regex,
        ];
    }

    /** @param list<MiddlewareDefinition> $middleware */
    public function withContext(string $path, array $middleware, ?string $namespace): self
    {
        return new self(
            $this->methods,
            $path,
            $this->handler,
            $middleware,
            $namespace,
            $this->conditions,
            $this->parameters,
            $this->name,
            $this->regex,
        );
    }
}
