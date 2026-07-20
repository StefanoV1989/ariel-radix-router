<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Http\Request;

final class UnsafeMiddleware implements Middleware
{
    public function handle(Request $request): void
    {
    }
}
