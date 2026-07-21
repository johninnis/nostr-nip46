<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\PairingUri;
use PHPUnit\Framework\TestCase;

final class PairingUriTest extends TestCase
{
    public function testReadsTheOrigin(): void
    {
        $uri = PairingUri::tryFromString('bunker://abc123?relay=wss://relay.example.com', 'bunker://');

        $this->assertSame('abc123', ($uri ?? self::fail('Expected a uri'))->getOrigin());
    }

    public function testReadsAnOriginWithoutAQuery(): void
    {
        $uri = PairingUri::tryFromString('bunker://abc123', 'bunker://');

        $this->assertSame('abc123', ($uri ?? self::fail('Expected a uri'))->getOrigin());
    }

    public function testRejectsAnotherScheme(): void
    {
        $this->assertNull(PairingUri::tryFromString('nostrconnect://abc123', 'bunker://'));
    }

    public function testToleratesSurroundingWhitespace(): void
    {
        $uri = PairingUri::tryFromString('  bunker://abc123  ', 'bunker://');

        $this->assertSame('abc123', ($uri ?? self::fail('Expected a uri'))->getOrigin());
    }

    public function testCollectsEveryValueOfARepeatedParameter(): void
    {
        $uri = PairingUri::tryFromString('bunker://abc?relay=wss://one.example&relay=wss://two.example', 'bunker://');

        $this->assertSame(
            ['wss://one.example', 'wss://two.example'],
            ($uri ?? self::fail('Expected a uri'))->values('relay'),
        );
    }

    public function testDecodesPercentEncodedValues(): void
    {
        $uri = PairingUri::tryFromString('nostrconnect://abc?relay=wss%3A%2F%2Fnos.lol&name=My+Client', 'nostrconnect://');

        self::assertNotNull($uri);
        $this->assertSame(['wss://nos.lol'], $uri->values('relay'));
        $this->assertSame('My Client', $uri->first('name'));
    }

    public function testAMissingParameterHasNoValues(): void
    {
        $uri = PairingUri::tryFromString('bunker://abc?relay=wss://one.example', 'bunker://');

        self::assertNotNull($uri);
        $this->assertSame([], $uri->values('secret'));
        $this->assertNull($uri->first('secret'));
    }

    public function testAParameterWithoutAValueReadsAsEmpty(): void
    {
        $uri = PairingUri::tryFromString('bunker://abc?secret', 'bunker://');

        $this->assertSame('', ($uri ?? self::fail('Expected a uri'))->first('secret'));
    }
}
