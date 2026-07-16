<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
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
        $trimmed = trim($raw);

        if (!str_starts_with($trimmed, self::SCHEME)) {
            return null;
        }

        $rest = substr($trimmed, strlen(self::SCHEME));
        $queryStart = strpos($rest, '?');
        $pubkeyPart = false === $queryStart ? $rest : substr($rest, 0, $queryStart);
        $queryPart = false === $queryStart ? '' : substr($rest, $queryStart + 1);

        $remoteSignerPubkey = PublicKey::tryFromHex($pubkeyPart);

        if (null === $remoteSignerPubkey) {
            return null;
        }

        $query = self::parseQuery($queryPart);

        $relays = new RelayUrlCollection(array_values(array_filter(
            array_map(RelayUrl::tryFromString(...), $query['relay'] ?? []),
        )));

        if ($relays->isEmpty()) {
            return null;
        }

        return new self($remoteSignerPubkey, $relays, ConnectSecret::tryFromString($query['secret'][0] ?? ''));
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

    /**
     * @return array<string, list<string>>
     */
    private static function parseQuery(string $query): array
    {
        $parsed = [];

        if ('' === $query) {
            return $parsed;
        }

        foreach (explode('&', $query) as $pair) {
            if ('' === $pair) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parsed[rawurldecode($key)][] = rawurldecode($value);
        }

        return $parsed;
    }
}
