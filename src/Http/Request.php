<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Http;

use StefanoV1989\ArielRouter\Route;

final class Request
{
    /** @var array<string, string> */
    private array $headers;

    /** @var list<Route> */
    private array $loadedRoutes = [];

    /**
     * @param array<string, scalar> $headers
     */
    public function __construct(
        private string $method = 'GET',
        string $uri = '/',
        array $headers = [],
    ) {
        $this->method = strtolower($method);
        $this->url = new Url(urldecode($uri));
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[self::normalizeHeader($name)] = (string) $value;
        }
    }

    private Url $url;

    public static function fromGlobals(): self
    {
        $methodValue = $_POST['_method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uriValue = $_SERVER['REQUEST_URI'] ?? '/';
        $method = is_scalar($methodValue) ? (string) $methodValue : 'GET';
        $uri = is_scalar($uriValue) ? (string) $uriValue : '/';
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            if (str_starts_with($name, 'HTTP_')) {
                $headers[substr($name, 5)] = $value;
            } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[$name] = $value;
            }
        }

        return new self($method, $uri, $headers);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): self
    {
        $clone = clone $this;
        $clone->method = strtolower($method);

        return $clone;
    }

    public function url(): Url
    {
        return $this->url;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[self::normalizeHeader($name)] ?? $default;
    }

    public function host(): ?string
    {
        return $this->header('host');
    }

    /** @return list<Route> */
    public function loadedRoutes(): array
    {
        return $this->loadedRoutes;
    }

    public function addLoadedRoute(Route $route): void
    {
        $this->loadedRoutes[] = $route;
    }

    public function clearLoadedRoutes(): void
    {
        $this->loadedRoutes = [];
    }

    private static function normalizeHeader(string $name): string
    {
        return strtolower(str_replace('_', '-', $name));
    }
}
