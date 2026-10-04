<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Entity;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;

final class UsedConnectSecrets
{
    private const int DEFAULT_LIMIT = 10000;

    /** @var BoundedMap<PublicKey> */
    private readonly BoundedMap $users;

    public function __construct(int $limit = self::DEFAULT_LIMIT)
    {
        $this->users = new BoundedMap($limit);
    }

    public function recordUse(ConnectSecret $secret, PublicKey $client): void
    {
        $key = self::keyOf($secret);

        if (!$this->users->has($key)) {
            $this->users->set($key, $client);
        }
    }

    public function usedBy(ConnectSecret $secret): ?PublicKey
    {
        return $this->users->get(self::keyOf($secret));
    }

    private static function keyOf(ConnectSecret $secret): string
    {
        return hash('sha256', (string) $secret);
    }
}
