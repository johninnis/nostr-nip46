<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Entity;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;

final class ClientSession
{
    private EnvelopeCipher $cipher = EnvelopeCipher::Nip44;

    private ?PublicKey $userPublicKey = null;

    public function __construct(
        private readonly BunkerUrl $bunker,
        private readonly SeenEventIds $seenEventIds = new SeenEventIds(),
    ) {
    }

    public function rememberSeen(EventId $eventId): bool
    {
        return $this->seenEventIds->remember($eventId);
    }

    public function getRemoteSignerPubkey(): PublicKey
    {
        return $this->bunker->getRemoteSignerPubkey();
    }

    public function getRelays(): RelayUrlCollection
    {
        return $this->bunker->getRelays();
    }

    public function getSecret(): ?ConnectSecret
    {
        return $this->bunker->getSecret();
    }

    public function recordCipher(EnvelopeCipher $cipher): void
    {
        $this->cipher = $cipher;
    }

    public function getCipher(): EnvelopeCipher
    {
        return $this->cipher;
    }

    public function identify(PublicKey $userPublicKey): void
    {
        $this->userPublicKey = $userPublicKey;
    }

    public function getUserPublicKey(): ?PublicKey
    {
        return $this->userPublicKey;
    }
}
