<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46TransportInterface;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Factory\Nip46FilterFactory;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\DecodedEnvelope;
use Override;
use Throwable;

final readonly class Nip46Channel implements Nip46ChannelInterface
{
    public const int FRESHNESS_WINDOW_SECONDS = 600;

    public function __construct(
        private Nip46TransportInterface $transport,
        private Nip46SignerInterface $signer,
        private ClockInterface $clock,
    ) {
    }

    #[Override]
    public function publicKey(): PublicKey
    {
        return $this->signer->publicKey();
    }

    #[Override]
    public function listen(RelayUrlCollection $relays, ?PublicKey $sender, Nip46EventListenerInterface $listener): Nip46SubscriptionInterface
    {
        $filter = Nip46FilterFactory::addressedTo($this->signer->publicKey(), $this->clock->now(), $sender);

        return $this->transport->subscribe($filter, $relays, $listener);
    }

    #[Override]
    public function open(Event $envelope): ?DecodedEnvelope
    {
        $cipher = EnvelopeCipher::ofPayload((string) $envelope->getContent());
        $plaintext = $this->signer->decrypt($envelope->getPubkey(), (string) $envelope->getContent(), $cipher);
        $payload = null === $plaintext ? null : JsonWireFormat::decodeObject($plaintext);

        return null === $payload ? null : new DecodedEnvelope($payload, $cipher);
    }

    #[Override]
    public function isAuthentic(Event $envelope): bool
    {
        return $this->signer->verify($envelope);
    }

    // Deliberate: an envelope more than ten minutes from the clock is stale — the seen-set forgets, so this window is what bounds a replay — see ADR-0040
    #[Override]
    public function isFresh(Event $envelope): bool
    {
        return $envelope->getCreatedAt()->isWithinSecondsOf($this->clock->now(), self::FRESHNESS_WINDOW_SECONDS);
    }

    // Deliberate: a payload a cipher refuses is returned as null — the client reports it, the bunker degrades it — never thrown at a waiting peer; see ADR-0028 and ADR-0029
    #[Override]
    public function seal(PublicKey $peer, string $payloadJson, EnvelopeCipher $cipher): ?Event
    {
        try {
            return $this->signer->sign(Rumour::draft(
                $this->signer->publicKey(),
                EventKind::fromInt(EventKind::NOSTR_CONNECT),
                EventContent::fromString($this->signer->encrypt($peer, $payloadJson, $cipher)),
                new TagCollection([Tag::pubkey($peer)]),
                $this->clock->now(),
            ));
        } catch (Throwable) {
            return null;
        }
    }

    #[Override]
    public function publish(RelayUrlCollection $relays, Event $envelope): void
    {
        $this->transport->publish($relays, $envelope);
    }
}
