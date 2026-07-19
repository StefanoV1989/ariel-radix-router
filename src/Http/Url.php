<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Http;

final readonly class Url implements \JsonSerializable, \Stringable
{
    private string $path;

    /** @var array<string, mixed> */
    private array $query;

    public function __construct(private string $original = '/')
    {
        $path = parse_url($original, PHP_URL_PATH);
        $this->path = is_string($path) && $path !== '' ? $path : '/';

        $parsedQuery = [];
        $query = parse_url($original, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parsedQuery);
        }
        $normalizedQuery = [];
        foreach ($parsedQuery as $key => $value) {
            $normalizedQuery[(string) $key] = $value;
        }
        $this->query = $normalizedQuery;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function original(): string
    {
        return $this->original;
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $name, ?string $default = null): ?string
    {
        $value = $this->query[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function __toString(): string
    {
        return $this->original;
    }

    public function jsonSerialize(): string
    {
        return $this->original;
    }
}
