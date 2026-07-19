<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Matching;

interface RouteMatcher
{
    public function match(string $method, string $path): MatchResult;
}
