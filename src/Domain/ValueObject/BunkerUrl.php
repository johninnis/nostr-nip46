<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use InvalidArgumentException;
use Override;
use Stringable;

final readonly class BunkerUrl implements Stringable
{
    private const string SCHEME = 'bunker://';

    public function __construct(
        private PublicKey $remoteSignerPubkey,
        private RelayUrlCollection $relays,
        private ?ConnectSecret $secret,
    ) {
        if ($relays->isEmpty()) {
            throw new InvalidArgumentException('A bunker URL must carry at least one relay');
        }
    }

    public function getRemoteSignerPubkey(): PublicKey
    {
        return $this->remoteSignerPubkey;
    }

    public function getRelays(): RelayUrlCollection
    {
        return $this->relays;
    }

    public function getSecret(): ?ConnectSecret
    {
        return $this->secret;
    }

    public static function tryFromString(string $raw): ?self
    {
        $uri = PairingUri::tryFromString($raw, self::SCHEME);

        if (null === $uri) {
            return null;
        }

        $remoteSignerPubkey = PublicKey::tryFromHex($uri->getOrigin());

        if (null === $remoteSignerPubkey) {
            return null;
        }

        $relays = RelayUrlCollection::fromStrings($uri->values('relay'));

        if ($relays->isEmpty()) {
            return null;
        }

        return new self($remoteSignerPubkey, $relays, ConnectSecret::tryFromString($uri->first('secret') ?? ''));
    }

    #[Override]
    public function __toString(): string
    {
        $parameters = [];

        foreach ($this->relays as $relay) {
            $parameters[] = 'relay='.rawurlencode((string) $relay);
        }

        if (null !== $this->secret) {
            $parameters[] = 'secret='.rawurlencode((string) $this->secret);
        }

        $query = implode('&', $parameters);

        return self::SCHEME.$this->remoteSignerPubkey->toHex().('' === $query ? '' : '?'.$query);
    }
}
