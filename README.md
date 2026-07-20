# Ariel Router

[![CI](https://img.shields.io/github/actions/workflow/status/StefanoV1989/ariel-radix-router/ci.yml?branch=main&label=CI&logo=github)](https://github.com/StefanoV1989/ariel-radix-router/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/stefanov1989/ariel-radix-router/v/stable)](https://packagist.org/packages/stefanov1989/ariel-radix-router)
[![License](https://poser.pugx.org/stefanov1989/ariel-radix-router/license)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.4-777BB4.svg)](https://www.php.net/)

Ariel is a fast, dependency-free HTTP router for PHP 8.4+. It uses a radix tree instead of scanning every route, keeps static lookups independent of route position, and includes middleware lifecycle safety, route groups, constraints, named URLs, persistent-worker support, and compiled indexes.

It is a standalone library: no framework, container, or HTTP implementation is required.

## Why Ariel

- Radix-tree matching with static segments taking precedence over parameters.
- Constant-depth lookup: adding routes does not turn dispatch into a linear scan.
- Specialized fast paths for common constraints such as integers and alphanumeric IDs.
- Safe middleware lifecycles for classic PHP requests and long-running workers.
- Optional file-backed compiled indexes designed to benefit from OPcache.
- Explicit `404`/`405` distinction and configurable exception rendering.
- Route groups, optional parameters, regex fallbacks, named URLs, and class handlers.
- Laravel-like static facade plus an isolated, mockable instance API.
- Zero runtime dependencies and strict PHP 8.4 types.
- PHPUnit coverage, PHPStan level max, reproducible benchmarks, and CI on every change.

## Installation

```bash
composer require stefanov1989/ariel-radix-router
```

## Quick start

```php
<?php

declare(strict_types=1);

use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\Router;

require __DIR__ . '/vendor/autoload.php';

Router::get('/', static fn (): string => 'Hello from Ariel');

Router::get('/users/{id}', static function (string $id): string {
    return 'User ' . $id;
})->where('id', '[0-9]+')->name('users.show');

Router::post('/users', [UserController::class, 'store']);

Router::error(static function (Request $request, Throwable $error): string {
    http_response_code($error->getCode() >= 400 ? $error->getCode() : 500);

    return json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
});

Router::start();
```

For tests and workers, pass the request explicitly:

```php
$result = Router::dispatch(new Request('GET', '/users/42'));
```

## Static facade and mockable instances

The static `Router` facade remains the primary, shortest API and is suitable for
normal FPM applications and Ariel. Applications that prefer dependency
injection can instantiate `ArielRouter` instead. Every instance owns a separate
engine, route catalog, group stack, and request context.

```php
use StefanoV1989\ArielRouter\ArielRouter;
use StefanoV1989\ArielRouter\Contracts\RouterInterface;
use StefanoV1989\ArielRouter\Http\Request;

$router = new ArielRouter();

$router->add('GET', '/health', static fn (): string => 'ok');
$router->get('/users/{id}', [UserController::class, 'show'])
    ->where('id', '[0-9]+');

$router->group(['prefix' => '/api'], static function (RouterInterface $routes): void {
    $routes->post('/users', [UserController::class, 'store']);
});

$result = $router->dispatch(new Request('GET', '/users/42'));
```

Both APIs use the same `RouterEngine`, matching logic, middleware lifecycle,
compiled catalogs, and persistent-worker cleanup. The object API therefore
works in FPM and Workerman without a separate adapter or different route
semantics.

Application services can depend on `RouterInterface` rather than the concrete
or static router:

```php
use StefanoV1989\ArielRouter\Contracts\RouterInterface;
use StefanoV1989\ArielRouter\Http\Request;

final class RequestDispatcher
{
    public function __construct(private RouterInterface $router)
    {
    }

    public function dispatch(Request $request): mixed
    {
        return $this->router->dispatch($request);
    }
}
```

This contract can be replaced by a hand-written fake or a PHPUnit mock:

```php
$router = $this->createMock(RouterInterface::class);
$router->method('dispatch')->willReturn('mocked response');

$service = new RequestDispatcher($router);
```

Static calls are not themselves mocked; use `RouterInterface` at boundaries
where test substitution is useful. There is no requirement to replace existing
`Router::get()` or `Router::dispatch()` usage.

## Routes

All standard methods are available, plus `match()`, `any()`, and the `all()` compatibility alias.

```php
Router::get('/articles', $handler);
Router::post('/articles', $handler);
Router::put('/articles/{id}', $handler);
Router::patch('/articles/{id}', $handler);
Router::delete('/articles/{id}', $handler);
Router::options('/articles', $handler);
Router::head('/articles', $handler);
Router::match(['GET', 'HEAD'], '/feed', $handler);
Router::any('/health', $handler);
```

Handlers may be closures, invokable objects, function names, `[Controller::class, 'method']`, or `Controller::class . '::method'`. Non-static controller methods are instantiated without a container.

### Parameters and constraints

```php
Router::get('/posts/{slug}', $handler);
Router::get('/archive/{year?}', $handler);

Router::get('/users/{id}', $handler)
    ->where('id', '[0-9]+');

Router::get('/reports/{year}/{month}', $handler)
    ->where([
        'year' => '[0-9]{4}',
        'month' => '0[1-9]|1[0-2]',
    ]);
```

For overlapping dynamic routes, more specific known constraints win. Static segments always win over dynamic segments. Exact collisions are rejected during compilation instead of silently overwriting a route.

URL parameters originate as strings. Class handlers may declare scalar PHP
types (`int`, `float`, `string`, or `bool`): dispatch applies PHP's native
scalar coercion rules without runtime metadata inspection. Invalid values still
raise `TypeError`; use `where()` to reject them during route matching.

Use `regex()` only for legacy patterns that cannot be represented as path segments. Regex routes are checked after the radix index:

```php
Router::get('/legacy', $handler)->regex('~^/legacy/(\d+)$~');
```

### Groups

Groups nest and compose prefixes, namespaces, and middleware in declaration order.

```php
Router::group([
    'prefix' => '/api',
    'middleware' => [
        RequestIdMiddleware::class,
        CorsMiddleware::class,
    ],
], static function (): void {
    Router::group(['prefix' => '/v1'], static function (): void {
        Router::get('/users/{id}', [UserController::class, 'show']);
    });
});
```

The `middleware` group option accepts either one middleware or an ordered array. Nested-group middleware is cumulative: outer-group middleware runs first, followed by inner-group middleware and then route middleware.

### Named URLs

```php
Router::get('/users/{user}/files/{file?}', $handler)->name('files.show');

$url = Router::url('files.show', [
    'user' => 'jane doe',
    'file' => 'report/1',
], [
    'download' => 1,
]);

// /users/jane%20doe/files/report%2F1?download=1
```

Missing named routes throw an `InvalidArgumentException`; malformed links do not fail silently.

## Middleware

A middleware implements one of the explicit lifecycle contracts. A class name creates a fresh instance for each dispatch:

```php
use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Http\Request;

final class AuthMiddleware implements Middleware
{
    public function handle(Request $request): void
    {
        if ($request->header('authorization') === null) {
            throw new RuntimeException('Unauthenticated', 401);
        }
    }
}

Router::get('/profile', $handler)->middleware(AuthMiddleware::class);
```

Middleware calls are cumulative. Every call appends one middleware and preserves insertion order:

```php
Router::get('/admin/users', $handler)
    ->addMiddleware(AuthMiddleware::class)
    ->addMiddleware(AdminMiddleware::class)
    ->addMiddleware(AuditMiddleware::class);
```

In this example execution order is `AuthMiddleware`, `AdminMiddleware`, `AuditMiddleware`, then the route handler. Group middleware is placed before route middleware. Terminable middleware runs after the handler in reverse order.

`middleware()` and `addMiddleware()` are exact aliases and both are cumulative. `middleware()` offers the concise public API, while `addMiddleware()` keeps declarations explicit and makes migration from existing applications straightforward. They may be mixed without changing ordering:

```php
Router::get('/profile', $handler)
    ->middleware(AuthMiddleware::class)
    ->addMiddleware(AuditMiddleware::class);
```

Object instances must make their lifecycle explicit:

- `StatelessMiddleware` may be safely shared.
- `RequestCloneableMiddleware` returns a request-scoped copy.
- `MiddlewareFactory` creates an instance per request and supports dependency injection.
- `TerminableMiddleware` runs `terminate()` after the handler, in reverse order.

This prevents request state from leaking when the same router lives inside RoadRunner, FrankenPHP, Swoole, or Workerman.

## Requests and errors

`Request::fromGlobals()` is used by `Router::start()`. The constructor makes testing and server adapters straightforward:

```php
$request = new Request(
    method: 'PATCH',
    uri: '/users/42?notify=1',
    headers: ['Authorization' => 'Bearer token'],
);

$request->method();                  // patch
$request->url()->path();             // /users/42
$request->url()->queryParam('notify');
$request->header('authorization');
```

Unmatched paths throw `HttpException` with code `404`; known paths with the wrong method use `405`. Register `Router::error()` to convert any `Throwable` into your application's response format.

## Router information helpers

The facade exposes read-only helpers for debug panels, route listing commands, tests, health checks, and framework integrations:

```php
Router::routes();                         // list<Route>
Router::routeCount();                     // int

Router::namedRoute('users.show');         // Route|null
Router::hasNamedRoute('users.show');      // bool

$match = Router::resolve('GET', '/users/42');
$match->route;                            // Route|null
$match->parameters;                       // ['42']
$match->methodNotAllowed;                 // bool

Router::hasRoute('GET', '/users/42');     // bool
Router::isCompiled();                     // bool
Router::compilationCount();               // int

Router::currentRequest();                 // Request|null
Router::currentRoute();                   // Route|null
```

`resolve()` and `hasRoute()` match the radix index without executing the handler or middleware. The first call may lazily compile and freeze the route catalog, exactly like the first dispatch.

`currentRequest()` and `currentRoute()` return values only while a dispatch is active; outside dispatch they return `null`. Use `request()` when application code intentionally wants the active request or a request created from PHP globals.

Each returned `Route` exposes its path, methods, name, handler, middleware, constraints, parameter names, namespace, and regex through its typed accessors:

```php
$route = Router::namedRoute('users.show');

$route?->path();
$route?->methods();
$route?->middlewares();
$route?->conditions();
$route?->parameterNames();

$definition = $route?->definition(); // immutable RouteDefinition|null
$definition?->path;
$definition?->methods;
```

`RouteDefinition` is a typed, `readonly` snapshot of a route. It replaces large
internal array shapes when compiled catalogs are loaded and composed. The
exported payload returned by `compiledPayload()` remains an array so it can be
stored efficiently with `var_export()` and stays compatible with existing
catalog files.

## Compilation and production cache

The in-memory index compiles lazily on the first dispatch and is reused afterwards. Route definitions become immutable after compilation, catching accidental production mutations.

To store the compact radix topology on disk:

```php
use StefanoV1989\ArielRouter\IndexMode;
use StefanoV1989\ArielRouter\Router;

$engine = Router::configure(__DIR__ . '/storage/cache/router');
$engine->setIndexMode(IndexMode::File);
```

Use a writable deployment cache directory and enable OPcache. Writes are atomic. Closures and runtime middleware objects remain valid for the normal index, but exportable compiled definition catalogs require class/function names or `[class, method]` handlers.

For build-time route catalogs:

```php
$payload = Router::compiledPayload();
file_put_contents($file, '<?php return ' . var_export($payload, true) . ';');

// During application bootstrap:
Router::appendCompiledDefinitions('api-v1', require $file);
```

## Persistent workers

Create one engine, register routes once, and dispatch a new `Request` for every job. Ariel releases its active request and route in a `finally` block, including exceptional paths.

```php
$engine = Router::configure();
require __DIR__ . '/routes.php';
Router::compile();

while ($request = $server->nextRequest()) {
    $result = Router::dispatch($request);
    $server->respond($result);
}
```

The same lifecycle is available without static state:

```php
$router = new ArielRouter();
require __DIR__ . '/routes-instance.php';
$router->compile();

while ($request = $server->nextRequest()) {
    $server->respond($router->dispatch($request));
}
```

Do not share arbitrary middleware objects. Use the lifecycle contracts documented above.

## Benchmarks

Representative local result (Apple M1 Pro, 16 GiB, macOS arm64, PHP 8.4.1, CLI OPcache disabled):

| Workload | Throughput | Latency |
|---|---:|---:|
| Static route, first | 632,654 ops/s | 1,581 ns/op |
| Static route, middle | 633,733 ops/s | 1,578 ns/op |
| Static route, last | 632,447 ops/s | 1,581 ns/op |
| Dynamic constrained route, last | 471,658 ops/s | 2,120 ns/op |

The fixture contains 2,000 routes (1,000 static and 1,000 constrained dynamic routes), runs 5,000 warm-up dispatches per case, then measures 100,000 complete `Router::dispatch()` calls. Median registration took 2.445 ms, median compilation 3.594 ms, and peak memory was 8 MiB across five runs.

A separate parity run with 4,000 routes compared the static facade with a
dedicated `ArielRouter` instance. Median results were 640,528 versus 656,398
ops/s for static dispatch and 516,926 versus 523,420 ops/s for constrained
dynamic dispatch; both used 12 MiB peak memory. The purpose of this comparison
is to verify that instance isolation and mockability add no dispatch penalty,
not to claim that one calling style is inherently faster.

These are internal microbenchmark results, not production capacity promises. Hardware, PHP builds, extensions, handler work, and middleware affect results. The important invariant is visible in the first/middle/last static cases: lookup cost does not grow with declaration position. Measure the complete application on its deployment target before making capacity decisions.

## Quality checks

```bash
composer check       # PSR-12, PHPUnit, then PHPStan level max
composer style       # PSR-12 compliance
composer test
composer stan
composer validate --strict
```

The CI workflow validates Composer metadata, enforces PSR-12, tests the supported PHP baseline, and runs PHPStan at level max without a baseline or ignored errors.

## Versioning and maintenance

Ariel follows Semantic Versioning. Public APIs remain compatible within a major release; deprecations are documented before removal. See [CHANGELOG.md](CHANGELOG.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
