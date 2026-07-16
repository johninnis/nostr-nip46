<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Nip46\Domain\ValueObject\AuthUrl;

interface Nip46AuthUrlListenerInterface
{
    public function onAuthUrl(AuthUrl $url): void;
}
