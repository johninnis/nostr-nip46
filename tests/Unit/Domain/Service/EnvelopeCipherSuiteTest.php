<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\Service\ConversationCipherInterface;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Service\EnvelopeCipherSuite;
use PHPUnit\Framework\TestCase;

final class EnvelopeCipherSuiteTest extends TestCase
{
    public function testNamesTheNip44CipherForNip44(): void
    {
        $nip44 = $this->createStub(ConversationCipherInterface::class);

        $this->assertSame($nip44, new EnvelopeCipherSuite($nip44, $this->createStub(ConversationCipherInterface::class))->cipherFor(EnvelopeCipher::Nip44));
    }

    public function testNamesTheNip04CipherForNip04(): void
    {
        $nip04 = $this->createStub(ConversationCipherInterface::class);

        $this->assertSame($nip04, new EnvelopeCipherSuite($this->createStub(ConversationCipherInterface::class), $nip04)->cipherFor(EnvelopeCipher::Nip04));
    }
}
