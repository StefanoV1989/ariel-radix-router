<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Matching;

use StefanoV1989\ArielRouter\Route;

final class RadixNode
{
    /** @var array<string, self> */
    public array $static = [];

    /** @var array<string, array{condition: string|null, priority: int, node: self}> */
    public array $dynamic = [];

    /** @var array<string, Route> */
    public array $routes = [];
}
