<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Enum;

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
    }
}
