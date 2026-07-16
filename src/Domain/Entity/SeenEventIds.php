<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Entity;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;

final class SeenEventIds
{
    private const int DEFAULT_LIMIT = 10000;

    /** @var BoundedMap<true> */
    private readonly BoundedMap $ids;

    public function __construct(int $limit = self::DEFAULT_LIMIT)
    {
        $this->ids = new BoundedMap($limit);
    }

    public function remember(EventId $eventId): bool
    {
        $key = $eventId->toHex();

        if ($this->ids->has($key)) {
            return false;
        }

        $this->ids->set($key, true);

        return true;
    }
}
