<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use InvalidArgumentException;

final readonly class NostrConnectUrl
{
    private const string SCHEME = 'nostrconnect://';

    public function __construct(
        private PublicKey $clientPubkey,
        private RelayUrlCollection $relays,
        private ConnectSecret $secret,
        private ClientMetadata $metadata,
    ) {
        if ($relays->isEmpty()) {
            throw new InvalidArgumentException('A nostrconnect url must carry at least one relay');
        }
    }

    public function getClientPubkey(): PublicKey
    {
        return $this->clientPubkey;
    }

    public function getRelays(): RelayUrlCollection
    {
        return $this->relays;
    }

    public function getSecret(): ConnectSecret
    {
        return $this->secret;
    }

    public function getMetadata(): ClientMetadata
    {
        return $this->metadata;
    }

    public static function tryFromString(string $raw): ?self
    {
        $uri = PairingUri::tryFromString($raw, self::SCHEME);

        if (null === $uri) {
            return null;
        }

        $clientPubkey = PublicKey::tryFromHex($uri->getOrigin());
        $secret = ConnectSecret::tryFromString($uri->first('secret') ?? '');
        $relays = RelayUrlCollection::fromStrings($uri->values('relay'));

        if (null === $clientPubkey || null === $secret || $relays->isEmpty()) {
            return null;
        }

        return new self($clientPubkey, $relays, $secret, ClientMetadata::fromPairingUri($uri));
    }
}
