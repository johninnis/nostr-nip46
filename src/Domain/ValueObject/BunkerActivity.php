<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\Enum\BunkerActivityOutcome;

final readonly class BunkerActivity
{
    private function __construct(
        private AppId $appId,
        private AnsweredRequest $request,
        private BunkerActivityOutcome $outcome,
    ) {
    }

    public static function forDetail(AppId $appId, PendingRequestDetailInterface $detail, Nip46Response $response): self
    {
        return new self($appId, AnsweredRequest::forDetail($detail), self::outcomeOf($response));
    }

    public static function forRequest(AppId $appId, IncomingRequest $incoming, Nip46Response $response): self
    {
        return new self($appId, AnsweredRequest::forMethod($incoming->getMethod()), self::outcomeOf($response));
    }

    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    public function getAppId(): AppId
    {
        return $this->appId;
    }

    public function getEvent(): ?SignEventSummary
    {
        return $this->request->getEvent();
    }

    public function getCounterparty(): ?PublicKey
    {
        return $this->request->getCounterparty();
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
