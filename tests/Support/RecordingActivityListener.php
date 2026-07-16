<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Nip46\Application\Port\Nip46ActivityListenerInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerActivity;
use Override;

final class RecordingActivityListener implements Nip46ActivityListenerInterface
{
    /** @var list<BunkerActivity> */
    public array $activities = [];

    #[Override]
    public function onActivity(BunkerActivity $activity): void
    {
        $this->activities[] = $activity;
    }
}
