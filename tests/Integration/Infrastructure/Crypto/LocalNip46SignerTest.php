<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Integration\Infrastructure\Crypto;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Infrastructure\Crypto\LocalNip46Signer;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

final class LocalNip46SignerTest extends TestCase
{
    public function testNip44CiphertextRoundTripsBackToPlaintext(): void
    {
        $alice = LocalNip46Signer::create(PrivateKey::generate());
        $bob = LocalNip46Signer::create(PrivateKey::generate());

        $ciphertext = $alice->encrypt($bob->publicKey(), 'gm from a remote device', EnvelopeCipher::Nip44);

        $this->assertSame('gm from a remote device', $bob->decrypt($alice->publicKey(), $ciphertext, EnvelopeCipher::Nip44));
    }

    #[IgnoreDeprecations]
    public function testNip04CiphertextRoundTripsBackToPlaintext(): void
    {
        $alice = LocalNip46Signer::create(PrivateKey::generate());
        $bob = LocalNip46Signer::create(PrivateKey::generate());

        $ciphertext = $alice->encrypt($bob->publicKey(), 'gm from a legacy device', EnvelopeCipher::Nip04);

        $this->assertSame('gm from a legacy device', $bob->decrypt($alice->publicKey(), $ciphertext, EnvelopeCipher::Nip04));
    }

    public function testDecryptReturnsNullOnGarbageCiphertext(): void
    {
        $alice = LocalNip46Signer::create(PrivateKey::generate());
        $bob = LocalNip46Signer::create(PrivateKey::generate());

        $this->assertNull($bob->decrypt($alice->publicKey(), 'not-a-ciphertext', EnvelopeCipher::Nip44));
    }

    public function testSignProducesAnEventThatVerifiesAgainstTheSignerKey(): void
    {
        $signer = LocalNip46Signer::create(PrivateKey::generate());

        $event = $signer->sign(Rumour::draft($signer->publicKey(), EventKind::fromInt(EventKind::TEXT_NOTE), EventContent::fromString('gm')));

        $this->assertTrue($event->getPubkey()->equals($signer->publicKey()));
        $this->assertTrue($event->verify(Secp256k1Signer::create()));
    }
}
