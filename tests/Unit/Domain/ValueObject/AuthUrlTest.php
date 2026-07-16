<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Nip46\Domain\ValueObject\AuthUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthUrlTest extends TestCase
{
    public function testAcceptsAnHttpsUrl(): void
    {
        $this->assertSame(
            'https://bunker.example/authorise?request=1',
            (string) AuthUrl::tryFromString('https://bunker.example/authorise?request=1'),
        );
    }

    public function testAcceptsAnHttpUrl(): void
    {
        $this->assertSame('http://bunker.example/', (string) AuthUrl::tryFromString('http://bunker.example/'));
    }

    public function testAcceptsAnUppercaseScheme(): void
    {
        $this->assertNotNull(AuthUrl::tryFromString('HTTPS://bunker.example/authorise'));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('https://bunker.example/', (string) AuthUrl::tryFromString("  https://bunker.example/\n"));
    }

    #[DataProvider('rejectedValues')]
    public function testRejectsANonWebUrl(string $raw): void
    {
        $this->assertNull(AuthUrl::tryFromString($raw));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'empty' => [''];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'websocket scheme' => ['wss://relay.example.com'];
        yield 'scheme-less' => ['bunker.example/authorise'];
        yield 'scheme without host' => ['https://'];
        yield 'not a url' => ['open sesame'];
    }
}
