<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Service;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;

interface Nip46SignerInterface
{
    public function publicKey(): PublicKey;

    public function sign(Rumour $unsigned): Event;

    public function encrypt(PublicKey $peer, string $plaintext, EnvelopeCipher $cipher): string;

    public function decrypt(PublicKey $peer, string $ciphertext, EnvelopeCipher $cipher): ?string;
}
