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

final class RecordingTransport implements Nip46TransportInterface
{
    public ?Filter $filter = null;
    public ?RelayUrlCollection $relays = null;
    public int $cancelCount = 0;

    /** @var list<Event> */
    public array $published = [];

    /** @var list<list<string>> */
    public array $subscribedRelays = [];

    /** @var list<list<string>> */
    public array $publishedTo = [];

    private ?Nip46EventListenerInterface $listener = null;

    #[Override]
    public function subscribe(
        Filter $filter,
        RelayUrlCollection $relays,
        Nip46EventListenerInterface $listener,
    ): Nip46SubscriptionInterface {
        $this->filter = $filter;
        $this->relays = $relays;
        $this->listener = $listener;
        $this->subscribedRelays[] = $relays->toStrings();

        return new RecordingSubscription($this);
    }

    #[Override]
    public function publish(RelayUrlCollection $relays, Event $event): void
    {
        $this->published[] = $event;
        $this->publishedTo[] = $relays->toStrings();
    }

    /**
     * @return list<string>
     */
    public function lastPublishedTo(): array
    {
        return [] === $this->publishedTo ? [] : $this->publishedTo[count($this->publishedTo) - 1];
    }

    public function deliver(Event $event): void
    {
        $this->listener?->onEvent($event);
    }

    public function onSubscriptionCancelled(): void
    {
        ++$this->cancelCount;
        $this->listener = null;
    }

    public function lastPublished(): ?Event
    {
        return [] === $this->published ? null : $this->published[count($this->published) - 1];
    }
}
