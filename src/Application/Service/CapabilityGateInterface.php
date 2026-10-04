<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Nip46\Domain\ValueObject\IncomingRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequestDetailInterface;

interface CapabilityGateInterface
{
    public function answerOrDefer(IncomingRequest $incoming, PendingRequestDetailInterface $detail): Nip46Response|PendingRequest;

    public function answer(PendingRequest $request): Nip46Response;
}
