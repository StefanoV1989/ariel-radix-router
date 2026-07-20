<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use PHPUnit\Framework\TestCase;
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
