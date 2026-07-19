<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Cache;

final class CacheNode
{
    /** @var array<string, self> */
    public array $static = [];

    /** @var array<string, self> */
    public array $dynamic = [];

    /** @var array<string, int> */
    public array $routes = [];

    /** @return array{s: array<string, mixed>, d: array<string, mixed>, r: array<string, int>} */
    public function export(): array
    {
        $static = [];
        foreach ($this->static as $segment => $node) {
            $static[$segment] = $node->export();
        }
        $dynamic = [];
        foreach ($this->dynamic as $condition => $node) {
            $dynamic[$condition] = $node->export();
        }

        return ['s' => $static, 'd' => $dynamic, 'r' => $this->routes];
    }

    public function sort(): void
    {
        foreach ($this->static as $node) {
            $node->sort();
        }
        foreach ($this->dynamic as $node) {
            $node->sort();
        }
    }
}
