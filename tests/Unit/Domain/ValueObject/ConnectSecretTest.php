<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectSecretTest extends TestCase
{
    public function testCarriesTheSecretVerbatim(): void
    {
        $this->assertSame('topsecret', (string) self::secret('topsecret'));
    }

    public function testRejectsAnEmptySecret(): void
    {
        $this->assertNull(ConnectSecret::tryFromString(''));
    }

    public function testEqualSecretsCompareEqual(): void
    {
        $this->assertTrue(self::secret('topsecret')->equals(self::secret('topsecret')));
    }

    public function testDifferentSecretsCompareUnequal(): void
    {
        $this->assertFalse(self::secret('topsecret')->equals(self::secret('other')));
    }

    private static function secret(string $raw): ConnectSecret
    {
        return ConnectSecret::tryFromString($raw) ?? throw new RuntimeException('Invalid fixture secret: '.$raw);
    }
}
