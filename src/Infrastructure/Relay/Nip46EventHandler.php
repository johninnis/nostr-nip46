<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Infrastructure\Relay;

use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Override;

final readonly class Nip46EventHandler implements EventHandlerInterface
{
    public function __construct(private Nip46EventListenerInterface $listener)
    {
    }

    #[Override]
    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        $this->listener->onEvent($event);
    }

    #[Override]
    public function handleEose(SubscriptionId $subscriptionId): void
    {
    }

    #[Override]
    public function handleClosed(SubscriptionId $subscriptionId, string $message): void
    {
    }

    #[Override]
    public function handleNotice(RelayUrl $relayUrl, string $message): void
    {
    }
}
