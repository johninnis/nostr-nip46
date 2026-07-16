<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

interface Nip46SubscriptionInterface
{
    public function cancel(): void;
}
