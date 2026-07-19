<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter;

use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;

final readonly class RouteGroup
{
    /**
     * @param list<string|Middleware|MiddlewareFactory> $middleware
     */
    public function __construct(
        public string $prefix,
        public array $middleware,
        public ?string $namespace,
    ) {
    }
}
