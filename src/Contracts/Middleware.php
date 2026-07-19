<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Contracts;

use StefanoV1989\ArielRouter\Http\Request;

interface Middleware
{
    public function handle(Request $request): void;
}
