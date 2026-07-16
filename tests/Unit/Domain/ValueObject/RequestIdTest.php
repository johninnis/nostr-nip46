<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestIdTest extends TestCase
{
    public function testCarriesTheIdVerbatim(): void
    {
        $this->assertSame('abc123', (string) self::id('abc123'));
    }

    public function testRejectsAnEmptyId(): void
    {
        $this->assertNull(RequestId::tryFromString(''));
    }

    public function testRejectsANonStringId(): void
    {
        $this->assertNull(RequestId::tryFromString(42));
    }

    public function testGeneratedIdsAreNonEmptyAndDistinct(): void
    {
        $first = RequestId::generate();
        $second = RequestId::generate();

        $this->assertNotSame('', (string) $first);
        $this->assertFalse($first->equals($second));
    }

    public function testEqualIdsCompareEqual(): void
    {
        $this->assertTrue(self::id('r1')->equals(self::id('r1')));
    }

    public function testDifferentIdsCompareUnequal(): void
    {
        $this->assertFalse(self::id('r1')->equals(self::id('r2')));
    }

    private static function id(string $raw): RequestId
    {
        return RequestId::tryFromString($raw) ?? throw new RuntimeException('Invalid fixture request id: '.$raw);
    }
}
