<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter;

use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;
use StefanoV1989\ArielRouter\Contracts\RequestCloneableMiddleware;
use StefanoV1989\ArielRouter\Contracts\StatelessMiddleware;

/**
 * @phpstan-type Handler \Closure|array{class-string|object, string}|string|object
 * @phpstan-type MiddlewareDefinition string|Middleware|MiddlewareFactory
 * @phpstan-import-type ExportedDefinition from RouteDefinition
 */
final class Route
{
    /** @var list<string> */
    private array $methods;

    /** @var Handler */
    private mixed $handler;

    /** @var array<string, string> */
    private array $conditions = [];

    /** @var list<MiddlewareDefinition> */
    private array $middleware;

    /** @var list<string> */
    private array $parameterNames;

    /** @var array<string, string|null>|null */
    private ?array $parameters = null;

    private ?string $name = null;
    private ?string $regex = null;
    private bool $frozen = false;
    private bool $requestScoped = false;

    /** @var \Closure(): void */
    private \Closure $onMutation;

    /**
     * @param list<string> $methods
     * @param Handler $handler
     * @param list<MiddlewareDefinition> $middleware
     * @param \Closure(): void $onMutation
     * @param array{
     *     conditions: array<string, string>,
     *     parameters: list<string>,
     *     name: string|null,
     *     regex: string|null
     * }|RouteDefinition|null $compiled
     */
    public function __construct(
        array $methods,
        private readonly string $path,
        mixed $handler,
        array $middleware,
        private readonly ?string $namespace,
        \Closure $onMutation,
        array|RouteDefinition|null $compiled = null,
    ) {
        $this->methods = array_values(array_unique(array_map(strtolower(...), $methods)));
        $this->handler = $handler;
        $this->middleware = $middleware;
        $this->onMutation = $onMutation;

        if ($compiled instanceof RouteDefinition) {
            $this->conditions = $compiled->conditions;
            $this->parameterNames = $compiled->parameters;
            $this->name = $compiled->name;
            $this->regex = $compiled->regex;

            return;
        }
        if ($compiled !== null) {
            $this->conditions = $compiled['conditions'];
            $this->parameterNames = $compiled['parameters'];
            $this->name = $compiled['name'];
            $this->regex = $compiled['regex'];

            return;
        }

        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\??\}/', $path, $matches);
        $this->parameterNames = $matches[1];
    }

    /**
     * @param ExportedDefinition|RouteDefinition $definition
     * @param \Closure(): void $onMutation
     */
    public static function fromDefinition(array|RouteDefinition $definition, \Closure $onMutation): self
    {
        if (is_array($definition)) {
            $definition = RouteDefinition::fromArray($definition);
        }

        return new self(
            $definition->methods,
            $definition->path,
            $definition->handler,
            $definition->middleware,
            $definition->namespace,
            $onMutation,
            $definition,
        );
    }

    public function definition(): RouteDefinition
    {
        return RouteDefinition::fromRoute($this);
    }

    /** @param array<string, string>|string $conditions */
    public function where(array|string $conditions, ?string $expression = null): self
    {
        $this->beforeMutation();
        if (is_string($conditions)) {
            if ($expression === null) {
                throw new \InvalidArgumentException('A route condition cannot be null.');
            }
            $conditions = [$conditions => $expression];
        }

        foreach ($conditions as $parameter => $condition) {
            if (!in_array($parameter, $this->parameterNames, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown route parameter "%s".', $parameter));
            }
            $this->conditions[$parameter] = $condition;
        }

        return $this;
    }

    public function name(string $name): self
    {
        $this->beforeMutation();
        $this->name = $name;

        return $this;
    }

    /** @param MiddlewareDefinition $middleware */
    public function middleware(string|Middleware|MiddlewareFactory $middleware): self
    {
        $this->beforeMutation();
        self::assertMiddleware($middleware);
        $this->middleware[] = $middleware;

        return $this;
    }

    /** @param MiddlewareDefinition $middleware */
    public function addMiddleware(string|Middleware|MiddlewareFactory $middleware): self
    {
        return $this->middleware($middleware);
    }

    public function regex(string $regex): self
    {
        $this->beforeMutation();
        if (@preg_match($regex, '') === false) {
            throw new \InvalidArgumentException('Invalid route regular expression.');
        }
        $this->regex = $regex;

        return $this;
    }

    public function setMatch(string $regex): self
    {
        return $this->regex($regex);
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    /** @return list<string> */
    public function getRequestMethods(): array
    {
        return $this->methods();
    }

    /** @return Handler */
    public function handler(): mixed
    {
        return $this->handler;
    }

    /** @return Handler */
    public function getCallback(): mixed
    {
        return $this->handler();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function getUrl(): string
    {
        return $this->path();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getRegex(): ?string
    {
        return $this->regex;
    }

    public function getMatch(): ?string
    {
        return $this->getRegex();
    }

    /** @return array<string, string> */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /** @return array<string, string> */
    public function getConditions(): array
    {
        return $this->conditions();
    }

    /** @return list<MiddlewareDefinition> */
    public function middlewares(): array
    {
        return $this->middleware;
    }

    /** @return list<MiddlewareDefinition> */
    public function getMiddlewares(): array
    {
        return $this->middlewares();
    }

    /** @return list<string> */
    public function parameterNames(): array
    {
        return $this->parameterNames;
    }

    /** @return array<string, string|null> */
    public function parameters(): array
    {
        return $this->parameters ?? array_fill_keys($this->parameterNames, null);
    }

    /** @return array<string, string|null> */
    public function getParameters(): array
    {
        return $this->parameters();
    }

    public function conditionFor(int $index): ?string
    {
        $name = $this->parameterNames[$index] ?? null;

        return $name === null ? null : ($this->conditions[$name] ?? null);
    }

    public function namespace(): ?string
    {
        return $this->namespace;
    }

    /** @param list<string> $values */
    public function forRequest(array $values): self
    {
        $route = clone $this;
        $route->requestScoped = true;
        $values = array_pad(array_slice($values, 0, count($this->parameterNames)), count($this->parameterNames), null);
        $route->parameters = array_combine($this->parameterNames, $values) ?: [];

        return $route;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function unfreeze(): void
    {
        $this->frozen = false;
    }

    private function beforeMutation(): void
    {
        if ($this->requestScoped) {
            throw new \LogicException('A request-scoped route cannot be mutated.');
        }
        if ($this->frozen) {
            ($this->onMutation)();
        }
    }

    /** @param MiddlewareDefinition $middleware */
    public static function assertMiddleware(string|Middleware|MiddlewareFactory $middleware): void
    {
        if (is_string($middleware)) {
            if (!is_a($middleware, Middleware::class, true) && !is_a($middleware, MiddlewareFactory::class, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Middleware class "%s" must implement Middleware or MiddlewareFactory.',
                    $middleware,
                ));
            }
            return;
        }

        if (
            $middleware instanceof MiddlewareFactory
            || $middleware instanceof StatelessMiddleware
            || $middleware instanceof RequestCloneableMiddleware
        ) {
            return;
        }

        throw new \LogicException(sprintf(
            'Middleware object "%s" must implement StatelessMiddleware, '
                . 'RequestCloneableMiddleware or MiddlewareFactory.',
            $middleware::class,
        ));
    }
}
