<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Infrastructure\Crypto;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\EcdhServiceInterface;
use Innis\Nostr\Core\Domain\Service\Nip04EncryptionInterface;
use Innis\Nostr\Core\Domain\Service\Nip44EncryptionInterface;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\ConversationKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\SecretKeyMaterial;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Infrastructure\Crypto\Nip04Cipher;
use Innis\Nostr\Core\Infrastructure\Crypto\Nip44Cipher;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Ecdh;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Override;
use Throwable;

final readonly class LocalNip46Signer implements Nip46SignerInterface
{
    public function __construct(
        private KeyPair $keyPair,
        private SignatureServiceInterface $signatureService,
        private Nip44EncryptionInterface $nip44,
        private Nip04EncryptionInterface $nip04,
        private EcdhServiceInterface $ecdh,
    ) {
    }

    public static function create(PrivateKey $privateKey): self
    {
        $signatureService = Secp256k1Signer::create();

        return new self(
            KeyPair::fromPrivateKey($privateKey, $signatureService),
            $signatureService,
            new Nip44Cipher(),
            new Nip04Cipher(),
            Secp256k1Ecdh::create(),
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
    public function encrypt(PublicKey $peer, string $plaintext, EnvelopeCipher $cipher): string
    {
        return EnvelopeCipher::Nip44 === $cipher
            ? $this->nip44->encrypt($plaintext, ConversationKey::derive($this->keyPair->getPrivateKey(), $peer, $this->ecdh))
            : $this->nip04->encrypt($plaintext, new SecretKeyMaterial($this->ecdh->computeSharedX($this->keyPair->getPrivateKey(), $peer)));
    }

    #[Override]
    public function decrypt(PublicKey $peer, string $ciphertext, EnvelopeCipher $cipher): ?string
    {
        try {
            return EnvelopeCipher::Nip44 === $cipher
                ? $this->nip44->decrypt($ciphertext, ConversationKey::derive($this->keyPair->getPrivateKey(), $peer, $this->ecdh))
                : $this->nip04->decrypt($ciphertext, new SecretKeyMaterial($this->ecdh->computeSharedX($this->keyPair->getPrivateKey(), $peer)));
        } catch (Throwable) {
            return null;
        }
    }
}
