<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

interface BunkerQueueListenerInterface
{
    public function onQueueChanged(): void;
}
