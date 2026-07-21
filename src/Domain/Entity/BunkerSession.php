<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Entity;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\Collection\PendingRequestCollection;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequest;

final class BunkerSession
{
    public const int AUTHENTICATED_CLIENT_LIMIT = 10000;
    public const int PENDING_REQUEST_LIMIT = 1000;

    private const int CLIENT_CIPHER_LIMIT = 10000;

    /** @var array<string, PendingRequest> */
    private array $pending = [];

    /** @var BoundedMap<AppId> */
    private readonly BoundedMap $authenticatedClients;

    /** @var BoundedMap<EnvelopeCipher> */
    private readonly BoundedMap $clientCiphers;

    /** @var BoundedMap<RelayUrlCollection> */
    private readonly BoundedMap $clientRelays;

    private RelayUrlCollection $listeningOn;

    public function __construct(
        private readonly RelayUrlCollection $relays,
        private readonly SeenEventIds $seenEventIds = new SeenEventIds(),
    ) {
        $this->authenticatedClients = new BoundedMap(self::AUTHENTICATED_CLIENT_LIMIT);
        $this->clientCiphers = new BoundedMap(self::CLIENT_CIPHER_LIMIT);
        $this->clientRelays = new BoundedMap(self::AUTHENTICATED_CLIENT_LIMIT);
        $this->listeningOn = $relays->unique();
    }

    public function getRelays(): RelayUrlCollection
    {
        return $this->relays;
    }

    public function recordRelays(PublicKey $client, RelayUrlCollection $relays): void
    {
        $this->clientRelays->set($client->toHex(), $relays->unique());
    }

    public function relaysFor(PublicKey $client): RelayUrlCollection
    {
        $clientRelays = $this->clientRelays->get($client->toHex());

        return null === $clientRelays ? $this->relays : $this->relays->merge($clientRelays)->unique();
    }

    public function startListeningOn(RelayUrlCollection $relays): RelayUrlCollection
    {
        $known = $this->listeningOn->toStrings();

        $unlistened = RelayUrlCollection::fromStrings(array_values(array_diff($relays->unique()->toStrings(), $known)));

        $this->listeningOn = $this->listeningOn->merge($unlistened);

        return $unlistened;
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
    public function queue(PendingRequest $request): bool
    {
        if (count($this->pending) >= self::PENDING_REQUEST_LIMIT) {
            return false;
        }

        $this->pending[$request->getId()->toHex()] = $request;

        return true;
    }

    public function take(EventId $id): ?PendingRequest
    {
        $key = $id->toHex();
        $request = $this->pending[$key] ?? null;
        unset($this->pending[$key]);

        return $request;
    }

    public function pending(): PendingRequestCollection
    {
        return new PendingRequestCollection(array_values($this->pending));
    }
}
