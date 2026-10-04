<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Entity;

use Innis\Nostr\Nip46\Domain\Entity\UsedConnectSecrets;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UsedConnectSecretsTest extends TestCase
{
    public function testAnUnusedSecretHasNoUser(): void
    {
        $this->assertNull(new UsedConnectSecrets()->usedBy(ConnectSecret::fromString('a')));
    }

    public function testAUsedSecretNamesTheClientThatUsedIt(): void
    {
        $used = new UsedConnectSecrets();
        $used->recordUse(ConnectSecret::fromString('a'), TestKeys::clientPubkey());

        $this->assertSame(TestKeys::clientPubkey()->toHex(), $used->usedBy(ConnectSecret::fromString('a'))?->toHex());
    }

    public function testASecretIsUsedOnceAndKeepsItsFirstUser(): void
    {
        $used = new UsedConnectSecrets();
        $used->recordUse(ConnectSecret::fromString('a'), TestKeys::clientPubkey());
        $used->recordUse(ConnectSecret::fromString('a'), TestKeys::secondClientPubkey());

        $this->assertSame(TestKeys::clientPubkey()->toHex(), $used->usedBy(ConnectSecret::fromString('a'))?->toHex());
    }

    public function testAnotherSecretIsNotUsedByTheUseOfOne(): void
    {
        $used = new UsedConnectSecrets();
        $used->recordUse(ConnectSecret::fromString('a'), TestKeys::clientPubkey());

        $this->assertNull($used->usedBy(ConnectSecret::fromString('b')));
    }

    public function testEvictsTheOldestSecretPastTheLimit(): void
    {
        $used = new UsedConnectSecrets(2);
        $used->recordUse(ConnectSecret::fromString('a'), TestKeys::clientPubkey());
        $used->recordUse(ConnectSecret::fromString('b'), TestKeys::clientPubkey());
        $used->recordUse(ConnectSecret::fromString('c'), TestKeys::clientPubkey());

        $this->assertNull($used->usedBy(ConnectSecret::fromString('a')));
    }

    public function testRejectsANonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UsedConnectSecrets(0);
    }
}
