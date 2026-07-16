<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

final readonly class PendingSignRequest
{
    // Deliberate: two ids by design — the carrier event id is the queue identity; the client-chosen request id only correlates the response — see ADR-0012
    public function __construct(
        private EventId $id,
        private RequestId $requestId,
        private PublicKey $clientPubkey,
        private Timestamp $receivedAt,
        private UnsignedEventInput $eventToSign,
        private AppId $appId,
    ) {
    }

    public function getId(): EventId
    {
        return $this->id;
    }

    public function getRequestId(): RequestId
    {
        return $this->requestId;
    }

    public function getAppId(): AppId
    {
        return $this->appId;
    }

    public function getClientPubkey(): PublicKey
    {
        return $this->clientPubkey;
    }

    public function getReceivedAt(): Timestamp
    {
        return $this->receivedAt;
    }

    public function getEventToSign(): UnsignedEventInput
    {
        return $this->eventToSign;
    }
}
