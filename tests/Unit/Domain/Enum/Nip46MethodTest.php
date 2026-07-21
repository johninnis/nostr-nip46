<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Enum;

use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Nip46MethodTest extends TestCase
{
    #[DataProvider('wireValues')]
    public function testWireValuesMatchTheNip46Specification(Nip46Method $method, string $wire): void
    {
        $this->assertSame($wire, $method->value);
    }

    /**
     * @return iterable<string, array{Nip46Method, string}>
     */
    public static function wireValues(): iterable
    {
        yield 'connect' => [Nip46Method::Connect, 'connect'];
        yield 'ping' => [Nip46Method::Ping, 'ping'];
        yield 'get_public_key' => [Nip46Method::GetPublicKey, 'get_public_key'];
        yield 'switch_relays' => [Nip46Method::SwitchRelays, 'switch_relays'];
        yield 'logout' => [Nip46Method::Logout, 'logout'];
        yield 'sign_event' => [Nip46Method::SignEvent, 'sign_event'];
        yield 'nip44_encrypt' => [Nip46Method::Nip44Encrypt, 'nip44_encrypt'];
        yield 'nip44_decrypt' => [Nip46Method::Nip44Decrypt, 'nip44_decrypt'];
        yield 'nip04_encrypt' => [Nip46Method::Nip04Encrypt, 'nip04_encrypt'];
        yield 'nip04_decrypt' => [Nip46Method::Nip04Decrypt, 'nip04_decrypt'];
    }

    public function testConnectAndPingAllowUnauthenticatedClients(): void
    {
        $this->assertTrue(Nip46Method::Connect->allowsUnauthenticated());
        $this->assertTrue(Nip46Method::Ping->allowsUnauthenticated());
    }

    public function testAllOtherMethodsRequireAuthentication(): void
    {
        $this->assertFalse(Nip46Method::GetPublicKey->allowsUnauthenticated());
        $this->assertFalse(Nip46Method::SwitchRelays->allowsUnauthenticated());
        $this->assertFalse(Nip46Method::Logout->allowsUnauthenticated());
        $this->assertFalse(Nip46Method::SignEvent->allowsUnauthenticated());
        $this->assertFalse(Nip46Method::Nip44Decrypt->allowsUnauthenticated());
    }

    public function testCapabilityMethodsRequireAuthorisation(): void
    {
        $this->assertTrue(Nip46Method::GetPublicKey->requiresAuthorisation());
        $this->assertTrue(Nip46Method::SignEvent->requiresAuthorisation());
        $this->assertTrue(Nip46Method::Nip44Encrypt->requiresAuthorisation());
        $this->assertTrue(Nip46Method::Nip44Decrypt->requiresAuthorisation());
        $this->assertTrue(Nip46Method::Nip04Encrypt->requiresAuthorisation());
        $this->assertTrue(Nip46Method::Nip04Decrypt->requiresAuthorisation());
    }

    public function testHousekeepingMethodsRequireNoAuthorisation(): void
    {
        $this->assertFalse(Nip46Method::Connect->requiresAuthorisation());
        $this->assertFalse(Nip46Method::Ping->requiresAuthorisation());
        $this->assertFalse(Nip46Method::SwitchRelays->requiresAuthorisation());
        $this->assertFalse(Nip46Method::Logout->requiresAuthorisation());
    }

    public function testNip44MethodsCarryTheNip44Cipher(): void
    {
        $this->assertSame(EnvelopeCipher::Nip44, Nip46Method::Nip44Encrypt->cipher());
        $this->assertSame(EnvelopeCipher::Nip44, Nip46Method::Nip44Decrypt->cipher());
    }

    public function testNip04MethodsCarryTheNip04Cipher(): void
    {
        $this->assertSame(EnvelopeCipher::Nip04, Nip46Method::Nip04Encrypt->cipher());
        $this->assertSame(EnvelopeCipher::Nip04, Nip46Method::Nip04Decrypt->cipher());
    }

    public function testAMethodThatIsNotACipherOperationHasNoCipher(): void
    {
        $this->assertNull(Nip46Method::SignEvent->cipher());
        $this->assertNull(Nip46Method::Ping->cipher());
    }

    public function testEncryptMethodsAreEncrypt(): void
    {
        $this->assertTrue(Nip46Method::Nip44Encrypt->isEncrypt());
        $this->assertTrue(Nip46Method::Nip04Encrypt->isEncrypt());
    }

    public function testDecryptMethodsAreNotEncrypt(): void
    {
        $this->assertFalse(Nip46Method::Nip44Decrypt->isEncrypt());
        $this->assertFalse(Nip46Method::Nip04Decrypt->isEncrypt());
    }
}
