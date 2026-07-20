<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\RequestCloneableMiddleware;
use StefanoV1989\ArielRouter\Http\Request;

final class CloneableCounter implements RequestCloneableMiddleware
{
    private int $count = 0;

    public function forRequest(): Middleware
    {
        return clone $this;
    }

    public function handle(Request $request): void
    {
        ++$this->count;
        MiddlewareLog::$events[] = 'clone:' . $this->count;
    }
}
