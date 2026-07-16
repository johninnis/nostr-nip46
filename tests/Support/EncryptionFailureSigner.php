<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Override;
use RuntimeException;

final class EncryptionFailureSigner implements Nip46SignerInterface
{
    public function __construct(
        private readonly FakeSigner $delegate,
        private readonly int $maxPlaintextLength = 65535,
    ) {
    }

    #[Override]
    public function publicKey(): PublicKey
    {
        return $this->delegate->publicKey();
    }

    #[Override]
    public function sign(Rumour $unsigned): Event
    {
        return $this->delegate->sign($unsigned);
    }

    #[Override]
    public function encrypt(PublicKey $peer, string $plaintext, EnvelopeCipher $cipher): string
    {
        $length = strlen($plaintext);

        if ($length < 1 || $length > $this->maxPlaintextLength) {
            throw new RuntimeException('plaintext length must be between 1 and '.$this->maxPlaintextLength.' bytes');
        }

        return $this->delegate->encrypt($peer, $plaintext, $cipher);
    }

    #[Override]
    public function decrypt(PublicKey $peer, string $ciphertext, EnvelopeCipher $cipher): ?string
    {
        return $this->delegate->decrypt($peer, $ciphertext, $cipher);
    }
}
