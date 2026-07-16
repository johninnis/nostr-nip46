<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Nip46\Application\Port\BunkerQueueListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46ActivityListenerInterface;
use Innis\Nostr\Nip46\Domain\Collection\PendingSignRequestCollection;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;

interface Nip46BunkerInterface
{
    public function start(RelayUrlCollection $relays): void;

    public function stop(): void;

    public function setQueueListener(?BunkerQueueListenerInterface $listener): void;

    public function setActivityListener(?Nip46ActivityListenerInterface $listener): void;

    public function bunkerUrlFor(ConnectSecret $secret): ?BunkerUrl;

    public function getPending(): PendingSignRequestCollection;

    public function approve(EventId $id): bool;

    public function reject(EventId $id): bool;
}
