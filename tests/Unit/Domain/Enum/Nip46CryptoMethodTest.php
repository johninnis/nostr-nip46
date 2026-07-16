<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Enum;

use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Enum\Nip46CryptoMethod;
use PHPUnit\Framework\TestCase;

final class Nip46CryptoMethodTest extends TestCase
{
    public function testNip44MethodsCarryTheNip44Cipher(): void
    {
        $this->assertSame(EnvelopeCipher::Nip44, Nip46CryptoMethod::Nip44Encrypt->cipher());
        $this->assertSame(EnvelopeCipher::Nip44, Nip46CryptoMethod::Nip44Decrypt->cipher());
    }

    public function testNip04MethodsCarryTheNip04Cipher(): void
    {
        $this->assertSame(EnvelopeCipher::Nip04, Nip46CryptoMethod::Nip04Encrypt->cipher());
        $this->assertSame(EnvelopeCipher::Nip04, Nip46CryptoMethod::Nip04Decrypt->cipher());
    }

    public function testEncryptMethodsAreEncrypt(): void
    {
        $this->assertTrue(Nip46CryptoMethod::Nip44Encrypt->isEncrypt());
        $this->assertTrue(Nip46CryptoMethod::Nip04Encrypt->isEncrypt());
    }

    public function testDecryptMethodsAreNotEncrypt(): void
    {
        $this->assertFalse(Nip46CryptoMethod::Nip44Decrypt->isEncrypt());
        $this->assertFalse(Nip46CryptoMethod::Nip04Decrypt->isEncrypt());
    }
}
