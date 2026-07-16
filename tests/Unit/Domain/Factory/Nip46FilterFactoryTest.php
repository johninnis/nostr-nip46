<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Factory;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Domain\Factory\Nip46FilterFactory;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

final class Nip46FilterFactoryTest extends TestCase
{
    public function testTheFilterSelectsNostrConnectEventsAddressedToTheRecipient(): void
    {
        $filter = Nip46FilterFactory::addressedTo(TestKeys::signerPubkey(), Timestamp::fromInt(1_000));

        $this->assertSame([EventKind::NOSTR_CONNECT], $filter->getKinds()?->toInts());
        $this->assertSame([TestKeys::signerPubkey()->toHex()], $filter->getTags()?->toArray()['#p'] ?? null);
        $this->assertNull($filter->getAuthors());
    }

    public function testTheSinceWindowToleratesClockSkew(): void
    {
        $filter = Nip46FilterFactory::addressedTo(TestKeys::signerPubkey(), Timestamp::fromInt(1_000));

        $this->assertSame(1_000 - Nip46FilterFactory::CLOCK_SKEW_TOLERANCE_SECONDS, $filter->getSince()?->toInt());
    }

    public function testTheSinceWindowNeverGoesBelowZero(): void
    {
        $filter = Nip46FilterFactory::addressedTo(TestKeys::signerPubkey(), Timestamp::fromInt(10));

        $this->assertSame(0, $filter->getSince()?->toInt());
    }

    public function testAKnownSenderConstrainsTheAuthors(): void
    {
        $filter = Nip46FilterFactory::addressedTo(TestKeys::clientPubkey(), Timestamp::fromInt(1_000), TestKeys::signerPubkey());

        $this->assertSame([TestKeys::signerPubkey()->toHex()], $filter->getAuthors()?->toHexes());
    }
}
