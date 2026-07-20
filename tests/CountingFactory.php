<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;
use StefanoV1989\ArielRouter\Http\Request;

final class CountingFactory implements MiddlewareFactory
{
    private int $count = 0;

    public function create(): Middleware
    {
        ++$this->count;
        $count = $this->count;

        return new class ($count) implements Middleware {
            public function __construct(private readonly int $count)
            {
            }

            public function handle(Request $request): void
            {
                MiddlewareLog::$events[] = 'factory:' . $this->count;
            }
        };
    }
}
