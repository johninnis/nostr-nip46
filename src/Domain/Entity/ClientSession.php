<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Entity;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;

final class ClientSession
{
    private EnvelopeCipher $cipher = EnvelopeCipher::Nip44;

    private ?PublicKey $userPublicKey = null;

    private RelayUrlCollection $relays;

    /** @var array<string, true> */
    private array $awaitedRequests = [];

    public function __construct(
        private readonly BunkerUrl $bunker,
        private readonly SeenEventIds $seenEventIds = new SeenEventIds(),
    ) {
        $this->relays = $bunker->getRelays();
    }

    public function rememberSeen(EventId $eventId): bool
    {
        return $this->seenEventIds->remember($eventId);
    }

    public function awaitResponseTo(RequestId $requestId): void
    {
        $this->awaitedRequests[(string) $requestId] = true;
    }

    public function stopAwaiting(RequestId $requestId): void
    {
        unset($this->awaitedRequests[(string) $requestId]);
    }

    public function isAwaiting(RequestId $requestId): bool
    {
        return isset($this->awaitedRequests[(string) $requestId]);
    }

    public function getRemoteSignerPubkey(): PublicKey
    {
        return $this->bunker->getRemoteSignerPubkey();
    }

    public function getRelays(): RelayUrlCollection
    {
        return $this->relays;
    }

    public function adoptRelays(RelayUrlCollection $relays): bool
    {
        $switched = $relays->unique();

        if ($switched->isEmpty() || ($switched->diff($this->relays)->isEmpty() && $this->relays->diff($switched)->isEmpty())) {
            return false;
        }

        $this->relays = $switched;

        return true;
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
