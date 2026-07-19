<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Matching;

use StefanoV1989\ArielRouter\Route;

final readonly class MatchResult
{
    /** @param list<string> $parameters */
    public function __construct(
        public ?Route $route,
        public array $parameters,
        public bool $methodNotAllowed,
    ) {
    }
}
