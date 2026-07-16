<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Request;
use PHPUnit\Framework\TestCase;

final class Nip46RequestTest extends TestCase
{
    public function testParsesIdMethodAndParams(): void
    {
        $request = Nip46Request::tryFromWire(['id' => 'abc', 'method' => 'connect', 'params' => ['', 'secret']]);

        self::assertNotNull($request);
        $this->assertSame('abc', (string) $request->getId());
        $this->assertSame('connect', $request->getMethod());
        $this->assertSame(['', 'secret'], $request->toArray()['params']);
    }

    public function testDefaultsToEmptyParamsWhenAbsent(): void
    {
        $request = Nip46Request::tryFromWire(['id' => 'abc', 'method' => 'ping']);

        self::assertNotNull($request);
        $this->assertSame([], $request->toArray()['params']);
    }

    public function testNormalisesNonStringParamToJson(): void
    {
        $request = Nip46Request::tryFromWire(['id' => 'abc', 'method' => 'sign_event', 'params' => [['kind' => 1]]]);

        self::assertNotNull($request);
        $this->assertSame('{"kind":1}', $request->param(0));
    }

    public function testNormalisesFalsyNumericParamWithoutLosingIt(): void
    {
        $request = Nip46Request::tryFromWire(['id' => 'abc', 'method' => 'sign_event', 'params' => [0]]);

        self::assertNotNull($request);
        $this->assertSame('0', $request->param(0));
    }

    public function testRejectsMissingId(): void
    {
        $this->assertNull(Nip46Request::tryFromWire(['method' => 'ping']));
    }

    public function testRejectsMissingMethod(): void
    {
        $this->assertNull(Nip46Request::tryFromWire(['id' => 'abc']));
    }

    public function testRejectsNonArrayParams(): void
    {
        $this->assertNull(Nip46Request::tryFromWire(['id' => 'abc', 'method' => 'ping', 'params' => 'nope']));
    }

    public function testRejectsNonArrayInput(): void
    {
        $this->assertNull(Nip46Request::tryFromWire('not-an-object'));
    }

    public function testGenerateMintsADistinctNonEmptyIdPerRequest(): void
    {
        $first = Nip46Request::generate('ping', []);
        $second = Nip46Request::generate('ping', []);

        $this->assertNotSame('', (string) $first->getId());
        $this->assertNotSame((string) $first->getId(), (string) $second->getId());
    }

    public function testSerialisesAndParsesBackToTheSameRequest(): void
    {
        $request = Nip46Request::generate('connect', ['', 'secret']);

        $parsed = Nip46Request::tryFromWire(json_decode($request->toJson(), true));

        self::assertNotNull($parsed);
        $this->assertSame($request->toArray(), $parsed->toArray());
    }
}
