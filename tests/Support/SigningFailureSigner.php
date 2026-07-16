<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Override;
use RuntimeException;

final class SigningFailureSigner implements Nip46SignerInterface
{
    public function __construct(private readonly FakeSigner $delegate)
    {
    }

    #[Override]
    public function publicKey(): PublicKey
    {
        return $this->delegate->publicKey();
    }

    #[Override]
    public function sign(Rumour $unsigned): Event
    {
        if (!$unsigned->getKind()->is(EventKind::NOSTR_CONNECT)) {
            throw new RuntimeException('hardware signer unavailable');
        }

        return $this->delegate->sign($unsigned);
    }

    #[Override]
    public function encrypt(PublicKey $peer, string $plaintext, EnvelopeCipher $cipher): string
    {
        return $this->delegate->encrypt($peer, $plaintext, $cipher);
    }

    #[Override]
    public function decrypt(PublicKey $peer, string $ciphertext, EnvelopeCipher $cipher): ?string
    {
        return $this->delegate->decrypt($peer, $ciphertext, $cipher);
    }
}
