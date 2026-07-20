<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

final class TestController
{
    public static function staticShow(string $id): string
    {
        return 'static:' . $id;
    }

    public function show(string $id): string
    {
        return 'instance:' . $id;
    }

    public function typedShow(int $id): string
    {
        return 'typed:' . $id;
    }

    /** @return array{int, float, bool} */
    public static function typedScalars(int $id, float $ratio, bool $enabled): array
    {
        return [$id, $ratio, $enabled];
    }
}
