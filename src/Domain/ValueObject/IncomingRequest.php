<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class IncomingRequest
{
    public function __construct(
        private EventId $carrierId,
        private PublicKey $clientPubkey,
        private Nip46Request $request,
    ) {
    }

    public function getCarrierId(): EventId
    {
        return $this->carrierId;
    }

    public function getClientPubkey(): PublicKey
    {
        return $this->clientPubkey;
    }

    public function getRequest(): Nip46Request
    {
        return $this->request;
    }

    public function getId(): RequestId
    {
        return $this->request->getId();
    }

    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    public function param(int $index): ?string
    {
        return $this->request->param($index);
    }
}
