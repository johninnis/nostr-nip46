<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip46ResponseTest extends TestCase
{
    public function testResultCarriesIdAndResultOnly(): void
    {
        $response = Nip46Response::result(self::requestId('1'), 'ack');

        $this->assertSame(['id' => '1', 'result' => 'ack'], $response->toArray());
        $this->assertSame('{"id":"1","result":"ack"}', $response->toJson());
    }

    public function testFailureCarriesIdAndErrorOnly(): void
    {
        $response = Nip46Response::failure(self::requestId('1'), 'user rejected');

        $this->assertSame(['id' => '1', 'error' => 'user rejected'], $response->toArray());
    }

    public function testParsesAResultResponse(): void
    {
        $response = Nip46Response::tryFromWire(['id' => '1', 'result' => 'pong']);

        self::assertNotNull($response);
        $this->assertSame('1', (string) $response->getId());
        $this->assertSame('pong', $response->getResult());
        $this->assertNull($response->getError());
    }

    public function testParsesAnErrorResponse(): void
    {
        $response = Nip46Response::tryFromWire(['id' => '1', 'error' => 'user rejected']);

        self::assertNotNull($response);
        $this->assertSame('user rejected', $response->getError());
        $this->assertNull($response->getResult());
    }

    public function testRejectsMissingId(): void
    {
        $this->assertNull(Nip46Response::tryFromWire(['result' => 'pong']));
    }

    public function testRejectsNonStringResult(): void
    {
        $this->assertNull(Nip46Response::tryFromWire(['id' => '1', 'result' => 42]));
    }

    public function testRejectsNonArrayInput(): void
    {
        $this->assertNull(Nip46Response::tryFromWire('nope'));
    }

    public function testAParsedAuthUrlChallengeIsRecognised(): void
    {
        $response = Nip46Response::tryFromWire(['id' => '1', 'result' => 'auth_url', 'error' => 'https://bunker.example/authorise']);

        self::assertNotNull($response);
        $this->assertTrue($response->isAuthUrlChallenge());
        $this->assertSame('https://bunker.example/authorise', (string) $response->getAuthUrl());
    }

    public function testAnAuthUrlChallengeCarryingANonWebUrlYieldsNoUrl(): void
    {
        $response = Nip46Response::tryFromWire(['id' => '1', 'result' => 'auth_url', 'error' => 'javascript:alert(1)']);

        self::assertNotNull($response);
        $this->assertTrue($response->isAuthUrlChallenge());
        $this->assertNull($response->getAuthUrl());
    }

    public function testAnOrdinaryResponseIsNotAnAuthUrlChallenge(): void
    {
        $response = Nip46Response::result(self::requestId('1'), 'pong');

        $this->assertFalse($response->isAuthUrlChallenge());
        $this->assertNull($response->getAuthUrl());
    }

    private static function requestId(string $id): RequestId
    {
        return RequestId::tryFromString($id) ?? throw new RuntimeException('Invalid fixture request id: '.$id);
    }
}
