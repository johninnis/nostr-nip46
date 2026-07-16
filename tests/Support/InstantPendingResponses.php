<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Nip46\Application\Port\Nip46PendingResponseInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46PendingResponsesInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use Override;

final class InstantPendingResponses implements Nip46PendingResponsesInterface
{
    /** @var array<string, Nip46Response> */
    private array $completed = [];

    /** @var list<string> */
    public array $openedRequestIds = [];

    #[Override]
    public function open(RequestId $requestId): Nip46PendingResponseInterface
    {
        $key = (string) $requestId;
        $this->openedRequestIds[] = $key;

        return new class($this, $key) implements Nip46PendingResponseInterface {
            public function __construct(
                private readonly InstantPendingResponses $responses,
                private readonly string $requestId,
            ) {
            }

            #[Override]
            public function await(float $timeoutSeconds): ?Nip46Response
            {
                return $this->responses->take($this->requestId);
            }
        };
    }

    #[Override]
    public function complete(Nip46Response $response): void
    {
        $this->completed[(string) $response->getId()] = $response;
    }

    public function take(string $requestId): ?Nip46Response
    {
        $response = $this->completed[$requestId] ?? null;
        unset($this->completed[$requestId]);

        return $response;
    }
}
