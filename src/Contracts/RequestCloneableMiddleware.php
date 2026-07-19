<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Contracts;

interface RequestCloneableMiddleware extends Middleware
{
    public function forRequest(): Middleware;
}
