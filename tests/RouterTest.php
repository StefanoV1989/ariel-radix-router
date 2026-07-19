<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use StefanoV1989\ArielRouter\Exception\HttpException;
use StefanoV1989\ArielRouter\Exception\RouteConflictException;
use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\Router;
use StefanoV1989\ArielRouter\RouterEngine;

#[CoversClass(Router::class)]
#[CoversClass(RouterEngine::class)]
final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        Router::useEngine(new RouterEngine());
        Router::reset();
    }

    public function testDispatchesStaticAndDynamicRoutes(): void
    {
        Router::get('/health', static fn (): string => 'ok');
        Router::get('/users/{id}', static fn (string $id): string => 'user:' . $id);

        self::assertSame('ok', Router::dispatch(new Request('GET', '/health')));
        self::assertSame('user:42', Router::dispatch(new Request('GET', '/users/42')));
    }

    public function testStaticRouteWinsOverDynamicRoute(): void
    {
        Router::get('/posts/{slug}', static fn (string $slug): string => 'dynamic:' . $slug);
        Router::get('/posts/latest', static fn (): string => 'static');

        self::assertSame('static', Router::dispatch(new Request('GET', '/posts/latest')));
    }

    public function testConditionsAreOrderedBySpecificity(): void
    {
        Router::get('/items/{value}', static fn (string $value): string => 'generic:' . $value);
        Router::get('/items/{id}', static fn (string $id): string => 'integer:' . $id)->where('id', '[0-9]+');

        self::assertSame('integer:123', Router::dispatch(new Request('GET', '/items/123')));
        self::assertSame('generic:blue', Router::dispatch(new Request('GET', '/items/blue')));
    }

    public function testSupportsOptionalParameters(): void
    {
        Router::get('/archive/{year?}', static fn (?string $year = null): string => $year ?? 'all');

        self::assertSame('all', Router::dispatch(new Request('GET', '/archive')));
        self::assertSame('2026', Router::dispatch(new Request('GET', '/archive/2026')));
    }

    public function testGroupsComposePrefixMiddlewareAndNamespaceOptions(): void
    {
        Router::group(['prefix' => '/api'], static function (): void {
            Router::group(['prefix' => '/v1'], static function (): void {
                Router::get('/status', static fn (): string => 'ready')->name('status');
            });
        });

        self::assertSame('ready', Router::dispatch(new Request('GET', '/api/v1/status')));
        self::assertSame('/api/v1/status?verbose=1', (string) Router::url('status', query: ['verbose' => 1]));
    }

    public function testGeneratesEncodedNamedUrls(): void
    {
        Router::get('/users/{user}/files/{file?}', static fn (): null => null)->name('file');

        self::assertSame('/users/jane%20doe/files/report%2F1', (string) Router::url('file', [
            'user' => 'jane doe',
            'file' => 'report/1',
        ]));
        self::assertSame('/users/jane/files', (string) Router::url('file', ['user' => 'jane']));
    }

    public function testDistinguishesNotFoundFromMethodNotAllowed(): void
    {
        Router::post('/users', static fn (): string => 'created');

        try {
            Router::dispatch(new Request('GET', '/users'));
            self::fail('Expected a method-not-allowed exception.');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->getCode());
        }

        try {
            Router::dispatch(new Request('GET', '/missing'));
            self::fail('Expected a not-found exception.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getCode());
        }
    }

    public function testCustomErrorHandlerCanRenderExceptions(): void
    {
        Router::error(static fn (Request $request, \Throwable $error): string => $request->url()->path() . ':' . $error->getCode());

        self::assertSame('/missing:404', Router::dispatch(new Request('GET', '/missing')));
    }

    public function testRegexFallbackRoutesRemainAvailable(): void
    {
        Router::get('/legacy', static fn (string $id): string => $id)->regex('~^/legacy/(\d+)$~');

        self::assertSame('91', Router::dispatch(new Request('GET', '/legacy/91')));
    }

    public function testCompilesOnlyOnceAcrossDispatches(): void
    {
        Router::get('/', static fn (): string => 'home');

        Router::dispatch(new Request('GET', '/'));
        Router::dispatch(new Request('GET', '/'));

        self::assertSame(1, Router::engine()->compilationCount());
    }

    public function testRejectsRoutesThatCompileToTheSameRadixLeaf(): void
    {
        Router::get('/users/{id}', static fn (string $id): string => $id);
        Router::get('/users/{name}', static fn (string $name): string => $name);

        $this->expectException(RouteConflictException::class);
        Router::compile();
    }

    public function testAllowsTheSamePathForDifferentMethods(): void
    {
        Router::get('/resource', static fn (): string => 'read');
        Router::post('/resource', static fn (): string => 'create');

        self::assertSame('read', Router::dispatch(new Request('GET', '/resource')));
        self::assertSame('create', Router::dispatch(new Request('POST', '/resource')));
    }

    public function testRoutesAreImmutableAfterCompilationUnlessExplicitlyEnabled(): void
    {
        $route = Router::get('/', static fn (): string => 'home');
        Router::compile();

        try {
            $route->name('home');
            self::fail('Expected immutable routes after compilation.');
        } catch (\LogicException) {
            self::assertNull($route->getName());
        }

        Router::engine()->allowRouteMutation();
        $route->name('home');
        self::assertSame('home', $route->getName());
    }

    public function testReadOnlyIntrospectionHelpers(): void
    {
        Router::get('/users/{id}', static fn (string $id): string => $id)
            ->where('id', '[0-9]+')
            ->name('users.show');
        Router::post('/users', static fn (): string => 'created');

        self::assertCount(2, Router::routes());
        self::assertSame(2, Router::routeCount());
        self::assertTrue(Router::hasNamedRoute('users.show'));
        self::assertFalse(Router::hasNamedRoute('missing'));
        self::assertSame('/users/{id}', Router::namedRoute('users.show')?->path());
        self::assertFalse(Router::isCompiled());

        $match = Router::resolve('GET', '/users/42');

        self::assertSame('/users/{id}', $match->route?->path());
        self::assertSame(['42'], $match->parameters);
        self::assertTrue(Router::hasRoute('POST', '/users'));
        self::assertFalse(Router::hasRoute('DELETE', '/users'));
        self::assertTrue(Router::isCompiled());
        self::assertSame(1, Router::compilationCount());
        self::assertNull(Router::currentRequest());
        self::assertNull(Router::currentRoute());
    }

    public function testStaticAndInstanceClassHandlers(): void
    {
        Router::get('/static/{id}', [TestController::class, 'staticShow']);
        Router::get('/instance/{id}', [TestController::class, 'show']);

        self::assertSame('static:7', Router::dispatch(new Request('GET', '/static/7')));
        self::assertSame('instance:8', Router::dispatch(new Request('GET', '/instance/8')));
    }

    public function testCoercesUrlSegmentsForScalarTypedHandlers(): void
    {
        Router::get('/typed/{id}/{ratio}/{enabled}', [TestController::class, 'typedScalars']);
        Router::get('/typed-controller/{id}', [TestController::class, 'typedShow']);

        self::assertSame([42, 1.5, false], Router::dispatch(new Request('GET', '/typed/42/1.5/0')));
        self::assertSame('typed:7', Router::dispatch(new Request('GET', '/typed-controller/7')));
    }
}

final class TestController
{
    public static function staticShow(string $id): string
    {
        return 'static:' . $id;
    }

    public function show(string $id): string
    {
        return 'instance:' . $id;
    }

    public function typedShow(int $id): string
    {
        return 'typed:' . $id;
    }

    /** @return array{int, float, bool} */
    public static function typedScalars(int $id, float $ratio, bool $enabled): array
    {
        return [$id, $ratio, $enabled];
    }
}
