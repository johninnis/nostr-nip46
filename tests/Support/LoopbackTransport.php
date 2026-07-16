<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46TransportInterface;
use Override;

final class LoopbackTransport implements Nip46TransportInterface
{
    private ?Nip46EventListenerInterface $listener = null;

    private ?self $peer = null;

    private function __construct()
    {
    }

    /**
     * @return array{self, self}
     */
    public static function pair(): array
    {
        $first = new self();
        $second = new self();
        $first->peer = $second;
        $second->peer = $first;

        return [$first, $second];
    }

    #[Override]
    public function subscribe(
        Filter $filter,
        RelayUrlCollection $relays,
        Nip46EventListenerInterface $listener,
    ): Nip46SubscriptionInterface {
        $this->listener = $listener;

        return new class implements Nip46SubscriptionInterface {
            #[Override]
            public function cancel(): void
            {
            }
        };
    }

    #[Override]
    public function publish(RelayUrlCollection $relays, Event $event): void
    {
        $this->peer?->listener?->onEvent($event);
    }
}
