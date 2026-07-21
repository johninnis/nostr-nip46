<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\NostrConnectUrl;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

final class NostrConnectUrlTest extends TestCase
{
    public function testParsesTheClientPubkeyFromTheOrigin(): void
    {
        $url = self::parse('?relay=wss%3A%2F%2Fnos.lol&secret=hunter2');

        $this->assertTrue($url->getClientPubkey()->equals(TestKeys::clientPubkey()));
    }

    public function testParsesTheSecret(): void
    {
        $url = self::parse('?relay=wss%3A%2F%2Fnos.lol&secret=hunter2');

        $this->assertSame('hunter2', (string) $url->getSecret());
    }

    public function testParsesEveryRelay(): void
    {
        $url = self::parse('?relay=wss%3A%2F%2Fnos.lol&relay=wss%3A%2F%2Fnostr.mom&secret=hunter2');

        $this->assertSame(['wss://nos.lol', 'wss://nostr.mom'], $url->getRelays()->toStrings());
    }

    public function testParsesTheClientMetadata(): void
    {
        $url = self::parse('?relay=wss%3A%2F%2Fnos.lol&secret=hunter2&name=Emanator&url=https%3A%2F%2Femanator.cypherpunk.today&image=https%3A%2F%2Femanator.cypherpunk.today%2Ficon.png');

        $metadata = $url->getMetadata();

        $this->assertSame('Emanator', $metadata->getName());
        $this->assertSame('https://emanator.cypherpunk.today', $metadata->getUrl());
        $this->assertSame('https://emanator.cypherpunk.today/icon.png', $metadata->getImage());
    }

    public function testParsesTheRequestedPermissions(): void
    {
        $url = self::parse('?relay=wss%3A%2F%2Fnos.lol&secret=hunter2&perms=get_public_key%2Csign_event%3A1%2Csign_event%3A24242');

        $this->assertSame('get_public_key,sign_event:1,sign_event:24242', $url->getMetadata()->getRequested()->toPermsString());
    }

    public function testAbsentMetadataIsNull(): void
    {
        $metadata = self::parse('?relay=wss%3A%2F%2Fnos.lol&secret=hunter2')->getMetadata();

        $this->assertNull($metadata->getName());
        $this->assertNull($metadata->getUrl());
        $this->assertNull($metadata->getImage());
        $this->assertTrue($metadata->getRequested()->isEmpty());
    }

    public function testRejectsAnotherScheme(): void
    {
        $this->assertNull(NostrConnectUrl::tryFromString('bunker://'.TestKeys::clientPubkey()->toHex().'?relay=wss://nos.lol&secret=hunter2'));
    }

    public function testRejectsAnInvalidClientPubkey(): void
    {
        $this->assertNull(NostrConnectUrl::tryFromString('nostrconnect://not-a-pubkey?relay=wss://nos.lol&secret=hunter2'));
    }

    public function testRejectsAUrlWithoutARelay(): void
    {
        $this->assertNull(NostrConnectUrl::tryFromString('nostrconnect://'.TestKeys::clientPubkey()->toHex().'?secret=hunter2'));
    }

    public function testRejectsAUrlWithoutASecret(): void
    {
        $this->assertNull(NostrConnectUrl::tryFromString('nostrconnect://'.TestKeys::clientPubkey()->toHex().'?relay=wss://nos.lol'));
    }

    public function testRejectsAUrlWhoseOnlyRelayIsUnusable(): void
    {
        $this->assertNull(NostrConnectUrl::tryFromString('nostrconnect://'.TestKeys::clientPubkey()->toHex().'?relay=http%3A%2F%2Fnos.lol&secret=hunter2'));
    }

    private static function parse(string $query): NostrConnectUrl
    {
        return NostrConnectUrl::tryFromString('nostrconnect://'.TestKeys::clientPubkey()->toHex().$query)
            ?? self::fail('Expected a nostrconnect url');
    }
}
