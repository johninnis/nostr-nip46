<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Enum;

use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use PHPUnit\Framework\TestCase;

final class EnvelopeCipherTest extends TestCase
{
    public function testNip44FallsBackToNip04(): void
    {
        $this->assertSame(EnvelopeCipher::Nip04, EnvelopeCipher::Nip44->fallback());
    }

    public function testNip04FallsBackToNip44(): void
    {
        $this->assertSame(EnvelopeCipher::Nip44, EnvelopeCipher::Nip04->fallback());
    }
}
