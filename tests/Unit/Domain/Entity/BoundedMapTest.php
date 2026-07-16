<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Entity;

use Innis\Nostr\Nip46\Domain\Entity\BoundedMap;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BoundedMapTest extends TestCase
{
    public function testStoresAndReturnsAValue(): void
    {
        $map = new BoundedMap(2);
        $map->set('a', 'first');

        $this->assertTrue($map->has('a'));
        $this->assertSame('first', $map->get('a'));
    }

    public function testAbsentKeyReturnsNull(): void
    {
        $this->assertNull(new BoundedMap(2)->get('missing'));
    }

    public function testForgetDiscardsAKey(): void
    {
        $map = new BoundedMap(2);
        $map->set('a', 'first');
        $map->forget('a');

        $this->assertFalse($map->has('a'));
    }

    public function testUpdatingAKeyDoesNotChangeItsEvictionOrder(): void
    {
        $map = new BoundedMap(2);
        $map->set('a', 'first');
        $map->set('b', 'second');
        $map->set('a', 'updated');
        $map->set('c', 'third');

        $this->assertFalse($map->has('a'));
        $this->assertTrue($map->has('b'));
        $this->assertTrue($map->has('c'));
    }

    public function testEvictsTheOldestEntryPastTheLimit(): void
    {
        $map = new BoundedMap(2);
        $map->set('a', 'first');
        $map->set('b', 'second');
        $map->set('c', 'third');

        $this->assertFalse($map->has('a'));
        $this->assertTrue($map->has('b'));
        $this->assertTrue($map->has('c'));
    }

    public function testRejectsANonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BoundedMap(0);
    }
}
