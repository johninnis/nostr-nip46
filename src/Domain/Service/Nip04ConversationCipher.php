<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Service;

use Innis\Nostr\Core\Domain\Service\ConversationCipherInterface;
use Innis\Nostr\Core\Domain\Service\EcdhServiceInterface;
use Innis\Nostr\Core\Domain\Service\Nip04EncryptionInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Override;

final readonly class Nip04ConversationCipher implements ConversationCipherInterface
{
    public function __construct(
        private Nip04EncryptionInterface $encryption,
        private EcdhServiceInterface $ecdh,
    ) {
    }

    #[Override]
    public function encrypt(string $plaintext, PrivateKey $ownKey, PublicKey $peer): string
    {
        $sharedSecret = $this->ecdh->computeSharedX($ownKey, $peer);

        try {
            return $this->encryption->encrypt($plaintext, $sharedSecret);
        } finally {
            $sharedSecret->zero();
        }
    }

    #[Override]
    public function decrypt(string $ciphertext, PrivateKey $ownKey, PublicKey $peer): string
    {
        $sharedSecret = $this->ecdh->computeSharedX($ownKey, $peer);

        try {
            return $this->encryption->decrypt($ciphertext, $sharedSecret);
        } finally {
            $sharedSecret->zero();
        }
    }
}
