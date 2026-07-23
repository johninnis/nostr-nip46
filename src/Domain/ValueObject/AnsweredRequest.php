<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class AnsweredRequest
{
    private function __construct(
        private string $method,
        private ?SignEventSummary $event,
        private ?PublicKey $counterparty,
    ) {
    }

    public static function forDetail(PendingRequestDetailInterface $detail): self
    {
        return new self(
            $detail->getPermission()->getMethod()->value,
            $detail->getSignEventSummary(),
            $detail->getCounterparty(),
        );
    }

    public static function forMethod(string $method): self
    {
        return new self($method, null, null);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getEvent(): ?SignEventSummary
    {
        return $this->event;
    }

    public function getCounterparty(): ?PublicKey
    {
        return $this->counterparty;
    }
}
