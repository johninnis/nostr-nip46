<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConnectSecretTest extends TestCase
{
    public function testCarriesTheSecretVerbatim(): void
    {
        $this->assertSame('topsecret', (string) ConnectSecret::fromString('topsecret'));
    }

    public function testRejectsAnEmptySecret(): void
    {
        $this->assertNull(ConnectSecret::tryFromString(''));
    }

    public function testFromStringCarriesTheSecretVerbatim(): void
    {
        $this->assertSame('topsecret', (string) ConnectSecret::fromString('topsecret'));
    }

    public function testFromStringRejectsAnEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConnectSecret::fromString('');
    }

    public function testEqualSecretsCompareEqual(): void
    {
        $this->assertTrue(ConnectSecret::fromString('topsecret')->equals(ConnectSecret::fromString('topsecret')));
    }

    public function testDifferentSecretsCompareUnequal(): void
    {
        $this->assertFalse(ConnectSecret::fromString('topsecret')->equals(ConnectSecret::fromString('other')));
    }
}
