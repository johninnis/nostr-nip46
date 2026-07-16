<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Nip46\Application\Port\BunkerQueueListenerInterface;
use Override;

final class RecordingQueueListener implements BunkerQueueListenerInterface
{
    public int $changes = 0;

    #[Override]
    public function onQueueChanged(): void
    {
        ++$this->changes;
    }
}
