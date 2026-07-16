<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;

interface Nip46TransportInterface
{
    public function subscribe(
        Filter $filter,
        RelayUrlCollection $relays,
        Nip46EventListenerInterface $listener,
    ): Nip46SubscriptionInterface;

    public function publish(RelayUrlCollection $relays, Event $event): void;
}
