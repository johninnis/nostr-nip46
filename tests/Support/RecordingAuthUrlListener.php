<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Nip46\Application\Port\Nip46AuthUrlListenerInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\AuthUrl;
use Override;

final class RecordingAuthUrlListener implements Nip46AuthUrlListenerInterface
{
    /** @var list<string> */
    public array $urls = [];

    #[Override]
    public function onAuthUrl(AuthUrl $url): void
    {
        $this->urls[] = (string) $url;
    }
}
