<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Matching;

final readonly class CompositeRouteMatcher implements RouteMatcher
{
    /** @param list<RouteMatcher> $matchers */
    public function __construct(private array $matchers)
    {
    }

    public function match(string $method, string $path): MatchResult
    {
        $methodNotAllowed = false;
        foreach ($this->matchers as $matcher) {
            $result = $matcher->match($method, $path);
            if ($result->route !== null) {
                return $result;
            }
            $methodNotAllowed = $methodNotAllowed || $result->methodNotAllowed;
        }

        return new MatchResult(null, [], $methodNotAllowed);
    }
}
