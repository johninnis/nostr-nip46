<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BunkerUrlTest extends TestCase
{
    public function testParsesPubkeyRelaysAndSecret(): void
    {
        $hex = TestKeys::signerPubkey()->toHex();

        $url = BunkerUrl::tryFromString('bunker://'.$hex.'?relay=wss://relay.example.com&secret=hunter2');

        self::assertNotNull($url);
        $this->assertTrue($url->getRemoteSignerPubkey()->equals(TestKeys::signerPubkey()));
        $this->assertSame('hunter2', (string) $url->getSecret());
        $this->assertSame(1, $url->getRelays()->count());
    }

    public function testFormatIsInverseOfParse(): void
    {
        $url = new BunkerUrl(
            TestKeys::signerPubkey(),
            new RelayUrlCollection([$this->relay('wss://relay.example.com'), $this->relay('wss://nos.lol')]),
            ConnectSecret::fromString('hunter2'),
        );

        $reparsed = BunkerUrl::tryFromString((string) $url);

        self::assertNotNull($reparsed);
        $this->assertSame((string) $url, (string) $reparsed);
    }

    public function testSecretIsOptional(): void
    {
        $hex = TestKeys::signerPubkey()->toHex();

        $url = BunkerUrl::tryFromString('bunker://'.$hex.'?relay=wss://relay.example.com');

        self::assertNotNull($url);
        $this->assertNull($url->getSecret());
    }

    public function testRejectsWrongScheme(): void
    {
        $this->assertNull(BunkerUrl::tryFromString('https://relay.example.com'));
    }

    public function testRejectsWhenNoValidRelay(): void
    {
        $hex = TestKeys::signerPubkey()->toHex();

        $this->assertNull(BunkerUrl::tryFromString('bunker://'.$hex.'?secret=hunter2'));
    }

    public function testConstructionWithAnEmptyRelaySetIsAFault(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BunkerUrl(TestKeys::signerPubkey(), new RelayUrlCollection(), ConnectSecret::fromString('hunter2'));
    }

    private function relay(string $url): RelayUrl
    {
        $relay = RelayUrl::tryFromString($url);
        self::assertNotNull($relay);

        return $relay;
    }
}
