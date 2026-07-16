<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Closure;
use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46TransportInterface;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Override;
use RuntimeException;

final class ScriptedBunkerTransport implements Nip46TransportInterface
{
    public ?Filter $filter = null;

    public ?RelayUrlCollection $relays = null;

    /** @var list<Event> */
    public array $published = [];

    public bool $cancelled = false;

    private ?Nip46EventListenerInterface $listener = null;

    /**
     * @param Closure(array<mixed>): ?array<string, mixed> $respond     takes the decoded request, returns the response wire array or null for silence
     * @param int                                          $replyCopies how many identical copies of each reply to deliver, as relays redelivering one event would
     */
    public function __construct(
        private readonly Closure $respond,
        private readonly EnvelopeCipher $replyCipher = EnvelopeCipher::Nip44,
        private readonly int $replyCopies = 1,
    ) {
    }

    #[Override]
    public function subscribe(
        Filter $filter,
        RelayUrlCollection $relays,
        Nip46EventListenerInterface $listener,
    ): Nip46SubscriptionInterface {
        $this->filter = $filter;
        $this->relays = $relays;
        $this->listener = $listener;

        return new class($this) implements Nip46SubscriptionInterface {
            public function __construct(private readonly ScriptedBunkerTransport $transport)
            {
            }

            #[Override]
            public function cancel(): void
            {
                $this->transport->cancelled = true;
            }
        };
    }

    #[Override]
    public function publish(RelayUrlCollection $relays, Event $event): void
    {
        $this->published[] = $event;

        $listener = $this->listener;

        if (null === $listener) {
            return;
        }

        $response = ($this->respond)(self::openRequest($event));

        if (null === $response) {
            return;
        }

        $reply = IncomingEnvelope::make(
            $response,
            TestKeys::signerPubkey(),
            TestKeys::clientPubkey(),
            Timestamp::fromInt(1_700_000_001),
            $this->replyCipher,
        );

        for ($copy = 0; $copy < $this->replyCopies; ++$copy) {
            $listener->onEvent($reply);
        }
    }

    /**
     * @return array<mixed>
     */
    private static function openRequest(Event $event): array
    {
        foreach (EnvelopeCipher::cases() as $cipher) {
            $plaintext = new FakeSigner(TestKeys::clientPubkey())->decrypt(TestKeys::signerPubkey(), (string) $event->getContent(), $cipher);
            $decoded = null === $plaintext ? null : JsonWireFormat::decodeArray($plaintext);

            if (null !== $decoded) {
                return $decoded;
            }
        }

        throw new RuntimeException('Published event does not carry a decodable NIP-46 request');
    }
}
