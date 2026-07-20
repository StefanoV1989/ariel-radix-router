<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use PHPUnit\Framework\TestCase;
use StefanoV1989\ArielRouter\Cache\RouteCache;
use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\IndexMode;
use StefanoV1989\ArielRouter\Route;
use StefanoV1989\ArielRouter\Router;
use StefanoV1989\ArielRouter\RouterEngine;

final class CacheTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/radix-router-tests-' . bin2hex(random_bytes(8));
        Router::useEngine(new RouterEngine());
        Router::reset();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->cacheDirectory)) {
            return;
        }
        $files = glob($this->cacheDirectory . '/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->cacheDirectory);
    }

    public function testBuildsStoresAndLoadsCompiledTree(): void
    {
        Router::get('/users/{id}', CacheHandler::class . '::show')->where('id', '[0-9]+');
        $routes = Router::engine()->routes();
        $fingerprint = RouteCache::fingerprint($routes);
        $tree = RouteCache::build($routes);

        $file = RouteCache::store($this->cacheDirectory, $fingerprint, $tree);

        self::assertNotNull($file);
        self::assertFileExists($file);
        self::assertSame($tree, RouteCache::load($this->cacheDirectory, $fingerprint));
    }

    public function testFileIndexDispatchesWithoutChangingResults(): void
    {
        $engine = new RouterEngine($this->cacheDirectory);
        $engine->setIndexMode(IndexMode::File);
        Router::useEngine($engine);
        Router::get('/users/{id}', static fn (string $id): string => $id)->where('id', '[0-9]+');

        self::assertSame('42', Router::dispatch(new Request('GET', '/users/42')));
        self::assertNotEmpty(glob($this->cacheDirectory . '/radix-*.php') ?: []);
    }

    public function testCompiledPayloadRejectsClosures(): void
    {
        Router::get('/', static fn (): string => 'home');
        $this->expectException(\LogicException::class);

        Router::compiledPayload();
    }

    public function testCompiledCatalogCanBeMountedUnderAGroupPrefix(): void
    {
        Router::get('/users/{id}', CacheHandler::class . '::show')->where('id', '[0-9]+');
        $payload = Router::compiledPayload();

        Router::useEngine(new RouterEngine());
        Router::reset();
        Router::group(['prefix' => '/api/v1'], static function () use ($payload): void {
            Router::appendCompiledDefinitions('users', $payload);
            Router::appendCompiledDefinitions('users', $payload);
        });

        self::assertSame('42', Router::dispatch(new Request('GET', '/api/v1/users/42')));
        self::assertCount(1, Router::engine()->routes());
    }

    public function testCompiledCatalogPreservesRegexFallbacks(): void
    {
        Router::get('/legacy', CacheHandler::class . '::show')->regex('~^/legacy/(\d+)$~');
        $payload = Router::compiledPayload();

        Router::useEngine(new RouterEngine());
        Router::reset();
        Router::appendCompiledDefinitions('legacy', $payload);

        self::assertSame('17', Router::dispatch(new Request('GET', '/legacy/17')));
    }

    public function testLegacyArrayDefinitionsRemainAccepted(): void
    {
        Router::get('/users/{id}', CacheHandler::class . '::show')->where('id', '[0-9]+');
        $payload = Router::compiledPayload();

        $route = Route::fromDefinition($payload['definitions'][0], static function (): void {
        });

        self::assertSame('/users/{id}', $route->path());
        self::assertSame(['id' => '[0-9]+'], $route->conditions());
    }
}
