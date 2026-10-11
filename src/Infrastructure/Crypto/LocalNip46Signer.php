<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Infrastructure\Crypto;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Exception\EncryptionException;
use Innis\Nostr\Core\Domain\Service\ConversationCipher;
use Innis\Nostr\Core\Domain\Service\Nip04ConversationCipher;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Infrastructure\Crypto\Nip04Cipher;
use Innis\Nostr\Core\Infrastructure\Crypto\Nip44Cipher;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Ecdh;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Service\EnvelopeCipherSuite;
use Innis\Nostr\Nip46\Domain\Service\EnvelopeCipherSuiteInterface;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Override;

final readonly class LocalNip46Signer implements Nip46SignerInterface
{
    public function __construct(
        private KeyPair $keyPair,
        private SignatureServiceInterface $signatureService,
        private EnvelopeCipherSuiteInterface $ciphers,
    ) {
    }

    public static function create(PrivateKey $privateKey): self
    {
        $signatureService = Secp256k1Signer::create();
        $ecdh = Secp256k1Ecdh::create();

        return new self(
            KeyPair::fromPrivateKey($privateKey, $signatureService),
            $signatureService,
            new EnvelopeCipherSuite(
                new ConversationCipher(new Nip44Cipher(), $ecdh),
                new Nip04ConversationCipher(new Nip04Cipher(), $ecdh),
            ),
        );
    }

    #[Override]
    public function publicKey(): PublicKey
    {
        return $this->keyPair->getPublicKey();
    }

    #[Override]
    public function sign(Rumour $unsigned): Event
    {
        return $unsigned->sign($this->keyPair, $this->signatureService);
    }

    #[Override]
    public function verify(Event $event): bool
    {
        return $event->verify($this->signatureService);
    }

    #[Override]
    public function encrypt(PublicKey $peer, string $plaintext, EnvelopeCipher $cipher): string
    {
        return $this->ciphers->cipherFor($cipher)->encrypt($plaintext, $this->keyPair->getPrivateKey(), $peer);
    }

    // Deliberate: only the cipher's refusal of the peer's ciphertext or key is null — a fault in the signer's own machinery is thrown — see ADR-0029
    #[Override]
    public function decrypt(PublicKey $peer, string $ciphertext, EnvelopeCipher $cipher): ?string
    {
        try {
            return $this->ciphers->cipherFor($cipher)->decrypt($ciphertext, $this->keyPair->getPrivateKey(), $peer);
        } catch (EncryptionException) {
            return null;
        }
    }
}
