<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;

interface Nip46PendingResponseInterface
{
    public function await(float $timeoutSeconds): ?Nip46Response;
}
