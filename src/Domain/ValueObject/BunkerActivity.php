<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\Enum\BunkerActivityOutcome;

final readonly class BunkerActivity
{
    private function __construct(
        private string $method,
        private AppId $appId,
        private ?PublicKey $counterparty,
        private BunkerActivityOutcome $outcome,
    ) {
    }

    public static function forDetail(AppId $appId, PendingRequestDetailInterface $detail, Nip46Response $response): self
    {
        return new self(
            $detail->getPermission()->getMethod()->value,
            $appId,
            $detail->getCounterparty(),
            self::outcomeOf($response),
        );
    }

    public static function forRequest(AppId $appId, IncomingRequest $incoming, Nip46Response $response): self
    {
        return new self($incoming->getMethod(), $appId, null, self::outcomeOf($response));
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

    private static function outcomeOf(Nip46Response $response): BunkerActivityOutcome
    {
        return null === $response->getError() ? BunkerActivityOutcome::Answered : BunkerActivityOutcome::Failed;
    }
}
