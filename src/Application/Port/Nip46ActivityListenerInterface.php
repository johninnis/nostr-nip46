<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Nip46\Domain\ValueObject\BunkerActivity;

interface Nip46ActivityListenerInterface
{
    public function onActivity(BunkerActivity $activity): void;
}
