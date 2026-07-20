<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

final class MiddlewareLog
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }
}
