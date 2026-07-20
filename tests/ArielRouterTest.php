<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use StefanoV1989\ArielRouter\ArielRouter;
use StefanoV1989\ArielRouter\Contracts\RouterInterface;
use StefanoV1989\ArielRouter\Http\Request;

#[CoversClass(ArielRouter::class)]
final class ArielRouterTest extends TestCase
{
    public function testProvidesTheFluentApiWithoutStaticState(): void
    {
        $router = new ArielRouter();

        $router->add('GET', '/health', static fn (): string => 'ok');

        $router->group(['prefix' => '/api'], static function (RouterInterface $routes): void {
            $routes->get('/users/{id}', [TestController::class, 'typedShow'])
                ->where('id', '[0-9]+')
                ->name('users.show');
        });

        self::assertSame('ok', $router->dispatch(new Request('GET', '/health')));
        self::assertSame('typed:42', $router->dispatch(new Request('GET', '/api/users/42')));
        self::assertSame('/api/users/7', (string) $router->url('users.show', ['id' => 7]));
    }

    public function testKeepsMultipleRouterInstancesIsolated(): void
    {
        $first = new ArielRouter();
        $second = new ArielRouter();

        $first->get('/status', static fn (): string => 'first');
        $second->get('/status', static fn (): string => 'second');

        self::assertSame('first', $first->dispatch(new Request('GET', '/status')));
        self::assertSame('second', $second->dispatch(new Request('GET', '/status')));
        self::assertCount(1, $first->routes());
        self::assertCount(1, $second->routes());
        self::assertNotSame($first->engine(), $second->engine());
    }

    public function testCanBeReplacedByAnInterfaceMock(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $request = new Request('GET', '/mocked');

        $router->expects(self::once())
            ->method('dispatch')
            ->with($request)
            ->willReturn('mocked');

        $consumer = static fn (RouterInterface $dependency): mixed => $dependency->dispatch($request);

        self::assertSame('mocked', $consumer($router));
    }

    public function testReusesOneInstanceAcrossPersistentRequestsWithoutRetainingContext(): void
    {
        $router = new ArielRouter();
        $router->get('/worker/{id}', static fn (string $id): string => $id)->where('id', '[0-9]+');
        $router->compile();

        for ($request = 1; $request <= 2_000; ++$request) {
            $router->dispatch(new Request('GET', '/worker/' . $request));
        }
        gc_collect_cycles();
        $memoryBefore = memory_get_usage(true);

        $result = null;
        for ($request = 1; $request <= 100_000; ++$request) {
            $result = $router->dispatch(new Request('GET', '/worker/' . $request));
        }
        gc_collect_cycles();
        $memoryGrowth = memory_get_usage(true) - $memoryBefore;

        self::assertSame('100000', $result);
        self::assertNull($router->currentRequest());
        self::assertNull($router->currentRoute());
        self::assertSame(1, $router->compilationCount());
        self::assertLessThanOrEqual(2 * 1_024 * 1_024, $memoryGrowth);
    }

    public function testIsolatesRoutesWithAndWithoutMiddlewareAcrossRequests(): void
    {
        MiddlewareLog::reset();
        $router = new ArielRouter();
        $seen = [];
        $requestReference = null;
        $definition = null;

        $router->group(
            ['middleware' => FirstMiddleware::class],
            static function (RouterInterface $routes) use (
                $router,
                &$seen,
                &$requestReference,
                &$definition,
            ): void {
                $definition = $routes->get(
                    '/secure/{id}',
                    static function (string $id) use ($router, &$seen, &$requestReference): string {
                        $requestReference = \WeakReference::create($router->request());
                        $seen[] = [
                            'id' => $id,
                            'token' => $router->request()->header('x-request-token'),
                            'parameters' => $router->currentRoute()?->parameters(),
                        ];
                        MiddlewareLog::$events[] = 'handler';

                        return $id;
                    },
                )->addMiddleware(new LastMiddleware());
            },
        );
        $router->get(
            '/plain',
            static fn (): string => $router->request()->header('x-request-token', 'missing') ?? 'missing',
        );
        $router->compile();

        self::assertSame(
            '101',
            $router->dispatch(new Request('GET', '/secure/101', ['X-Request-Token' => 'first'])),
        );
        self::assertSame(
            'second',
            $router->dispatch(new Request('GET', '/plain', ['X-Request-Token' => 'second'])),
        );

        self::assertSame([[
            'id' => '101',
            'token' => 'first',
            'parameters' => ['id' => '101'],
        ]], $seen);
        self::assertSame(['first', 'last', 'handler', 'terminate:101'], MiddlewareLog::$events);
        self::assertNull($router->currentRequest());
        self::assertNull($router->currentRoute());
        self::assertNull($requestReference?->get());
        self::assertInstanceOf(\StefanoV1989\ArielRouter\Route::class, $definition);
        self::assertSame(['id' => null], $definition->parameters());
    }

    public function testClearsObjectContextAfterHandledAndUnhandledFailures(): void
    {
        $router = new ArielRouter();
        $router->get('/failure', static function (): never {
            throw new \RuntimeException('failure');
        });
        $router->get('/recovery', static fn (): string => 'recovered');
        $router->error(static fn (Request $request, \Throwable $error): string => $error->getMessage());
        $router->compile();

        self::assertSame('failure', $router->dispatch(new Request('GET', '/failure')));
        self::assertNull($router->currentRequest());
        self::assertNull($router->currentRoute());
        self::assertSame('recovered', $router->dispatch(new Request('GET', '/recovery')));

        $unhandled = new ArielRouter();
        $unhandled->get('/failure', static function (): never {
            throw new \RuntimeException('unhandled');
        });

        try {
            $unhandled->dispatch(new Request('GET', '/failure'));
            self::fail('The route should throw.');
        } catch (\RuntimeException $exception) {
            self::assertSame('unhandled', $exception->getMessage());
        }

        self::assertNull($unhandled->currentRequest());
        self::assertNull($unhandled->currentRoute());
    }

    public function testLoadsCompiledCatalogsIndependently(): void
    {
        $source = new ArielRouter();
        $source->get('/cached/{id}', CacheHandler::class . '::show')->where('id', '[0-9]+');
        $payload = $source->compiledPayload();

        $mounted = new ArielRouter();
        $mounted->appendCompiledDefinitions('cache', $payload);
        $mounted->appendCompiledDefinitions('cache', $payload);

        self::assertSame('99', $mounted->dispatch(new Request('GET', '/cached/99')));
        self::assertSame(1, $mounted->routeCount());
    }
}
