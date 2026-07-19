<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use PHPUnit\Framework\TestCase;
use StefanoV1989\ArielRouter\Contracts\Middleware;
use StefanoV1989\ArielRouter\Contracts\MiddlewareFactory;
use StefanoV1989\ArielRouter\Contracts\RequestCloneableMiddleware;
use StefanoV1989\ArielRouter\Contracts\StatelessMiddleware;
use StefanoV1989\ArielRouter\Contracts\TerminableMiddleware;
use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\Router;
use StefanoV1989\ArielRouter\RouterEngine;

final class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        MiddlewareLog::reset();
        Router::useEngine(new RouterEngine());
        Router::reset();
    }

    public function testRunsGroupAndRouteMiddlewareInDeclarationOrder(): void
    {
        Router::group(['middleware' => FirstMiddleware::class], static function (): void {
            Router::get('/secure', static function (): string {
                MiddlewareLog::$events[] = 'handler';

                return 'ok';
            })->middleware(new LastMiddleware());
        });

        self::assertSame('ok', Router::dispatch(new Request('GET', '/secure')));
        self::assertSame(['first', 'last', 'handler', 'terminate:ok'], MiddlewareLog::$events);
    }

    public function testFactoryCreatesRequestScopedMiddleware(): void
    {
        Router::get('/', static fn (): string => 'ok')->middleware(new CountingFactory());

        Router::dispatch(new Request('GET', '/'));
        Router::dispatch(new Request('GET', '/'));

        self::assertSame(['factory:1', 'factory:2'], MiddlewareLog::$events);
    }

    public function testCloneableMiddlewareCreatesAnInstancePerRequest(): void
    {
        Router::get('/', static fn (): string => 'ok')->middleware(new CloneableCounter());

        Router::dispatch(new Request('GET', '/'));
        Router::dispatch(new Request('GET', '/'));

        self::assertSame(['clone:1', 'clone:1'], MiddlewareLog::$events);
    }

    public function testRejectsUnsafeSharedMiddlewareObjects(): void
    {
        $this->expectException(\LogicException::class);

        Router::get('/', static fn (): string => 'ok')->middleware(new UnsafeMiddleware());
    }

    public function testAddMiddlewareCallsAreCumulativeAndOrdered(): void
    {
        Router::get('/', static function (): string {
            MiddlewareLog::$events[] = 'handler';

            return 'ok';
        })
            ->addMiddleware(FirstMiddleware::class)
            ->addMiddleware(SecondMiddleware::class)
            ->addMiddleware(new LastMiddleware());

        Router::dispatch(new Request('GET', '/'));

        self::assertSame(['first', 'second', 'last', 'handler', 'terminate:ok'], MiddlewareLog::$events);
    }

    public function testGroupAcceptsAnOrderedMiddlewareArray(): void
    {
        Router::group([
            'middleware' => [FirstMiddleware::class, SecondMiddleware::class],
        ], static function (): void {
            Router::get('/', static function (): string {
                MiddlewareLog::$events[] = 'handler';

                return 'ok';
            })->addMiddleware(new LastMiddleware());
        });

        Router::dispatch(new Request('GET', '/'));

        self::assertSame(['first', 'second', 'last', 'handler', 'terminate:ok'], MiddlewareLog::$events);
    }
}

final class MiddlewareLog
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }
}

final class FirstMiddleware implements StatelessMiddleware
{
    public function handle(Request $request): void
    {
        MiddlewareLog::$events[] = 'first';
    }
}

final class LastMiddleware implements TerminableMiddleware, StatelessMiddleware
{
    public function handle(Request $request): void
    {
        MiddlewareLog::$events[] = 'last';
    }

    public function terminate(Request $request, mixed $result): void
    {
        MiddlewareLog::$events[] = 'terminate:' . (is_scalar($result) ? (string) $result : get_debug_type($result));
    }
}

final class SecondMiddleware implements StatelessMiddleware
{
    public function handle(Request $request): void
    {
        MiddlewareLog::$events[] = 'second';
    }
}

final class CountingFactory implements MiddlewareFactory
{
    private int $count = 0;

    public function create(): Middleware
    {
        ++$this->count;
        $count = $this->count;

        return new class($count) implements Middleware {
            public function __construct(private readonly int $count) {}

            public function handle(Request $request): void
            {
                MiddlewareLog::$events[] = 'factory:' . $this->count;
            }
        };
    }
}

final class CloneableCounter implements RequestCloneableMiddleware
{
    private int $count = 0;

    public function forRequest(): Middleware
    {
        return clone $this;
    }

    public function handle(Request $request): void
    {
        ++$this->count;
        MiddlewareLog::$events[] = 'clone:' . $this->count;
    }
}

final class UnsafeMiddleware implements Middleware
{
    public function handle(Request $request): void {}
}
