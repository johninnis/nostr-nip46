<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\Enum\BunkerActivityOutcome;

final readonly class BunkerActivity
{
    public function __construct(
        private string $method,
        private AppId $appId,
        private ?PublicKey $counterparty,
        private BunkerActivityOutcome $outcome,
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getAppId(): AppId
    {
        return $this->appId;
    }

    public function getCounterparty(): ?PublicKey
    {
        return $this->counterparty;
    }

    public function getOutcome(): BunkerActivityOutcome
    {
        return $this->outcome;
    }
}
