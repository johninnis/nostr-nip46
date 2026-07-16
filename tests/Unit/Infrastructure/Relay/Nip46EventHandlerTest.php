<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Infrastructure\Relay;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Infrastructure\Relay\Nip46EventHandler;
use Innis\Nostr\Nip46\Tests\Support\IncomingEnvelope;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip46EventHandlerTest extends TestCase
{
    public function testHandleEventForwardsTheEventToTheListener(): void
    {
        $event = $this->event();
        $listener = new class implements Nip46EventListenerInterface {
            public ?Event $received = null;

            #[Override]
            public function onEvent(Event $event): void
            {
                $this->received = $event;
            }
        };

        new Nip46EventHandler($listener)->handleEvent($event, $this->subscriptionId());

        $this->assertSame($event, $listener->received);
    }

    public function testTheRelayProtocolCallbacksAreInertAndDoNotReachTheListener(): void
    {
        $listener = $this->createMock(Nip46EventListenerInterface::class);
        $listener->expects($this->never())->method('onEvent');

        $handler = new Nip46EventHandler($listener);

        $handler->handleEose($this->subscriptionId());
        $handler->handleClosed($this->subscriptionId(), 'subscription closed');
        $handler->handleNotice($this->relayUrl(), 'relay notice');

        $this->addToAssertionCount(1);
    }

    private function event(): Event
    {
        return IncomingEnvelope::make(
            ['id' => 'p1', 'method' => 'ping', 'params' => []],
            TestKeys::clientPubkey(),
            TestKeys::signerPubkey(),
            Timestamp::fromInt(1_700_000_000),
        );
    }

    private function subscriptionId(): SubscriptionId
    {
        return SubscriptionId::tryFromString('nip46-sub') ?? throw new RuntimeException('Invalid fixture subscription id');
    }

    private function relayUrl(): RelayUrl
    {
        return RelayUrl::tryFromString('wss://relay.example.com') ?? throw new RuntimeException('Invalid fixture relay');
    }
}
