<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Matching;

final class RouteCondition
{
    public static function priority(?string $condition): int
    {
        return match ($condition) {
            null, '' => 0,
            '[01]' => 500,
            '[0-9]+', '\\d+' => 300,
            '[0-9]+(\\.[0-9]+)?' => 250,
            '[A-Za-z ]+', '[a-zA-Z ]+' => 225,
            '[a-zA-Z0-9_]+', '[A-Za-z0-9_]+' => 200,
            default => 100,
        };
    }

    public static function matches(string $value, ?string $condition): bool
    {
        return match ($condition) {
            null, '' => true,
            '[01]' => $value === '0' || $value === '1',
            '[0-9]+', '\\d+' => $value !== '' && ctype_digit($value),
            '[0-9]+(\\.[0-9]+)?' => self::isUnsignedDecimal($value),
            '[A-Za-z ]+', '[a-zA-Z ]+' => $value !== ''
                && strspn($value, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ ') === strlen($value),
            '[a-zA-Z0-9_]+', '[A-Za-z0-9_]+' => $value !== '' && ctype_alnum(str_replace('_', '', $value)),
            default => preg_match('~^(?:' . str_replace('~', '\\~', $condition) . ')$~D', $value) === 1,
        };
    }

    private static function isUnsignedDecimal(string $value): bool
    {
        $parts = explode('.', $value);

        return count($parts) <= 2
            && $parts[0] !== ''
            && ctype_digit($parts[0])
            && (!isset($parts[1]) || ($parts[1] !== '' && ctype_digit($parts[1])));
    }
}
