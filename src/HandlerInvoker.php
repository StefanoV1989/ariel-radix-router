<?php

namespace StefanoV1989\ArielRouter;

/**
 * Invokes route handlers from PHP's default scalar-coercion context.
 *
 * URL segments are strings by definition. Calling from this file lets PHP
 * safely adapt numeric and boolean segments to typed controller parameters,
 * while objects, enums and other non-scalar types still fail normally.
 */
final class HandlerInvoker
{
    /**
     * @param callable(mixed ...$parameters): mixed $handler
     * @param list<string> $parameters
     */
    public static function invoke(callable $handler, array $parameters): mixed
    {
        return $handler(...$parameters);
    }
}
