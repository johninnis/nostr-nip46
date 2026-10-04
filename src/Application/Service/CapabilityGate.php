<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46AuthoriserInterface;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\IncomingRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequestDetailInterface;
use Override;

final readonly class CapabilityGate implements CapabilityGateInterface
{
    public function __construct(
        private Nip46SignerInterface $signer,
        private Nip46AuthoriserInterface $authoriser,
        private ClockInterface $clock,
    ) {
    }

    // Deliberate: an ungranted capability is deferred for a human decision, never refused — see ADR-0017
    #[Override]
    public function answerOrDefer(IncomingRequest $incoming, PendingRequestDetailInterface $detail): Nip46Response|PendingRequest
    {
        if (!$this->authoriser->isAuthorised($incoming->getClientPubkey(), $detail->getPermission())) {
            return new PendingRequest(
                id: $incoming->getCarrierId(),
                requestId: $incoming->getId(),
                clientPubkey: $incoming->getClientPubkey(),
                receivedAt: $this->clock->now(),
                detail: $detail,
            );
        }

        return $detail->answer($incoming->getId(), $this->signer, $this->clock->now());
    }

    #[Override]
    public function answer(PendingRequest $request): Nip46Response
    {
        return $request->getDetail()->answer($request->getRequestId(), $this->signer, $this->clock->now());
    }
}
