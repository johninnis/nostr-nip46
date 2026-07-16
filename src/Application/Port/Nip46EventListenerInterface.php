<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Core\Domain\Entity\Event;

interface Nip46EventListenerInterface
{
    public function onEvent(Event $event): void;
}
