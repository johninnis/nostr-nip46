<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Entity;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Nip46\Domain\Entity\SeenEventIds;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeenEventIdsTest extends TestCase
{
    public function testRemembersAnUnseenEventId(): void
    {
        $this->assertTrue(new SeenEventIds()->remember($this->eventId('a')));
    }

    public function testARememberedEventIdIsNotRememberedTwice(): void
    {
        $seen = new SeenEventIds();
        $seen->remember($this->eventId('a'));

        $this->assertFalse($seen->remember($this->eventId('a')));
    }

    public function testEvictsTheOldestIdPastTheLimit(): void
    {
        $seen = new SeenEventIds(2);
        $seen->remember($this->eventId('a'));
        $seen->remember($this->eventId('b'));
        $seen->remember($this->eventId('c'));

        $this->assertTrue($seen->remember($this->eventId('a')));
    }

    public function testKeepsEveryIdWithinTheLimit(): void
    {
        $seen = new SeenEventIds(2);
        $seen->remember($this->eventId('a'));
        $seen->remember($this->eventId('b'));

        $this->assertFalse($seen->remember($this->eventId('a')));
    }

    public function testRejectsANonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SeenEventIds(0);
    }

    private function eventId(string $seed): EventId
    {
        return EventId::tryFromHex(str_pad($seed, 64, '0')) ?? throw new RuntimeException('Invalid test event id: '.$seed);
    }
}
