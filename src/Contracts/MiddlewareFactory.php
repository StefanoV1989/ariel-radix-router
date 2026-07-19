<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Contracts;

interface MiddlewareFactory
{
    public function create(): Middleware;
}
