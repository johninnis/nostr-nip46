<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Override;

final class RecordingSubscription implements Nip46SubscriptionInterface
{
    public function __construct(private readonly RecordingTransport $transport)
    {
    }

    #[Override]
    public function cancel(): void
    {
        $this->transport->onSubscriptionCancelled();
    }
}
