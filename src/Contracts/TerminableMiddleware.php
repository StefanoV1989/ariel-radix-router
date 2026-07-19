<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Contracts;

use StefanoV1989\ArielRouter\Http\Request;

interface TerminableMiddleware extends Middleware
{
    public function terminate(Request $request, mixed $result): void;
}
