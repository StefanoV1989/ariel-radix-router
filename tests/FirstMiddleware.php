<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use StefanoV1989\ArielRouter\Contracts\StatelessMiddleware;
use StefanoV1989\ArielRouter\Http\Request;

final class FirstMiddleware implements StatelessMiddleware
{
    public function handle(Request $request): void
    {
        MiddlewareLog::$events[] = 'first';
    }
}
