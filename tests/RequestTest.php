<?php

declare(strict_types=1);

namespace StefanoV1989\ArielRouter\Tests;

use PHPUnit\Framework\TestCase;
use StefanoV1989\ArielRouter\Http\Request;
use StefanoV1989\ArielRouter\Http\Url;

final class RequestTest extends TestCase
{
    public function testNormalizesMethodHeadersPathAndQuery(): void
    {
        $request = new Request('PATCH', '/users/42?expand=roles', [
            'X_REQUEST_ID' => 123,
            'Host' => 'example.test',
        ]);

        self::assertSame('patch', $request->method());
        self::assertSame('/users/42', $request->url()->path());
        self::assertSame('roles', $request->url()->queryParam('expand'));
        self::assertSame('123', $request->header('x-request-id'));
        self::assertSame('example.test', $request->host());
    }

    public function testRequestIsEasyToCloneWithAnotherMethod(): void
    {
        $request = new Request('POST', '/resource');
        $clone = $request->withMethod('DELETE');

        self::assertSame('post', $request->method());
        self::assertSame('delete', $clone->method());
    }

    public function testUrlIsStringableAndJsonSerializable(): void
    {
        $url = new Url('/search?q=router');

        self::assertSame('/search?q=router', (string) $url);
        self::assertSame('"/search?q=router"', json_encode($url, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
