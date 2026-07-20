<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use StefanoV1989\ArielRouter\Contracts\StatelessMiddleware;
use StefanoV1989\ArielRouter\Contracts\TerminableMiddleware;
use StefanoV1989\ArielRouter\Http\Request;

final class LastMiddleware implements TerminableMiddleware, StatelessMiddleware
{
    public function handle(Request $request): void
    {
        MiddlewareLog::$events[] = 'last';
    }

    public function terminate(Request $request, mixed $result): void
    {
        $description = is_scalar($result) ? (string) $result : get_debug_type($result);
        MiddlewareLog::$events[] = 'terminate:' . $description;
    }
}
