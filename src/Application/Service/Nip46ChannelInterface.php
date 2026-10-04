<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\ValueObject\DecodedEnvelope;

interface Nip46ChannelInterface
{
    public function publicKey(): PublicKey;

    public function listen(RelayUrlCollection $relays, ?PublicKey $sender, Nip46EventListenerInterface $listener): Nip46SubscriptionInterface;

    public function open(Event $envelope): ?DecodedEnvelope;

    public function seal(PublicKey $peer, string $payloadJson, EnvelopeCipher $cipher): ?Event;

    public function publish(RelayUrlCollection $relays, Event $envelope): void;
}
