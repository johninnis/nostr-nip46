<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Entity;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\Collection\PendingSignRequestCollection;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingSignRequest;

final class BunkerSession
{
    public const int AUTHENTICATED_CLIENT_LIMIT = 10000;
    public const int PENDING_REQUEST_LIMIT = 1000;

    private const int CLIENT_CIPHER_LIMIT = 10000;

    /** @var array<string, PendingSignRequest> */
    private array $pending = [];

    /** @var BoundedMap<AppId> */
    private readonly BoundedMap $authenticatedClients;

    /** @var BoundedMap<EnvelopeCipher> */
    private readonly BoundedMap $clientCiphers;

    public function __construct(
        private readonly RelayUrlCollection $relays,
        private readonly SeenEventIds $seenEventIds = new SeenEventIds(),
    ) {
        $this->authenticatedClients = new BoundedMap(self::AUTHENTICATED_CLIENT_LIMIT);
        $this->clientCiphers = new BoundedMap(self::CLIENT_CIPHER_LIMIT);
    }

    public function getRelays(): RelayUrlCollection
    {
        return $this->relays;
    }

    public function rememberSeen(EventId $eventId): bool
    {
        return $this->seenEventIds->remember($eventId);
    }

    public function authenticate(PublicKey $client, AppId $appId): void
    {
        $this->authenticatedClients->set($client->toHex(), $appId);
    }

    public function deauthenticate(PublicKey $client): void
    {
        $this->authenticatedClients->forget($client->toHex());
    }

    public function isAuthenticated(PublicKey $client): bool
    {
        return $this->authenticatedClients->has($client->toHex());
    }

    public function appIdFor(PublicKey $client): ?AppId
    {
        return $this->authenticatedClients->get($client->toHex());
    }

    public function recordCipher(PublicKey $client, EnvelopeCipher $cipher): void
    {
        $this->clientCiphers->set($client->toHex(), $cipher);
    }

    public function cipherFor(PublicKey $client): EnvelopeCipher
    {
        return $this->clientCiphers->get($client->toHex()) ?? EnvelopeCipher::Nip44;
    }

    // Deliberate: a full queue refuses rather than evicts — a silently dropped request would hang the client awaiting it — see ADR-0001
    public function queue(PendingSignRequest $request): bool
    {
        if (count($this->pending) >= self::PENDING_REQUEST_LIMIT) {
            return false;
        }

        $this->pending[$request->getId()->toHex()] = $request;

        return true;
    }

    public function take(EventId $id): ?PendingSignRequest
    {
        $key = $id->toHex();
        $request = $this->pending[$key] ?? null;
        unset($this->pending[$key]);

        return $request;
    }

    public function pending(): PendingSignRequestCollection
    {
        return new PendingSignRequestCollection(array_values($this->pending));
    }
}
