<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;

interface Nip46PendingResponsesInterface
{
    public function open(RequestId $requestId): Nip46PendingResponseInterface;

    public function complete(Nip46Response $response): void;
}
