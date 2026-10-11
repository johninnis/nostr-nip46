<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Closure;
use Innis\Nostr\Nip46\Application\Port\Nip46PendingResponseInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46PendingResponsesInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use Override;

final class ScheduledPendingResponses implements Nip46PendingResponsesInterface
{
    private float $now = 0.0;

    /** @var list<array{float, Closure(): void}> */
    private array $scheduled = [];

    /** @var array<string, true> */
    private array $open = [];

    /** @var array<string, float> */
    private array $deadlines = [];

    /** @var array<string, Nip46Response> */
    private array $completed = [];

    /**
     * @param Closure(): void $action run when the virtual clock reaches this many seconds from now, while a request is awaited
     */
    public function after(float $seconds, Closure $action): void
    {
        $this->scheduled[] = [$this->now + $seconds, $action];
    }

    #[Override]
    public function open(RequestId $requestId): Nip46PendingResponseInterface
    {
        $key = (string) $requestId;
        $this->open[$key] = true;

        return new class($this, $key) implements Nip46PendingResponseInterface {
            public function __construct(
                private readonly ScheduledPendingResponses $responses,
                private readonly string $requestId,
            ) {
            }

            #[Override]
            public function await(float $timeoutSeconds): ?Nip46Response
            {
                return $this->responses->awaitResponseTo($this->requestId, $timeoutSeconds);
            }
        };
    }

    #[Override]
    public function complete(Nip46Response $response): void
    {
        $key = (string) $response->getId();

        if (isset($this->open[$key])) {
            $this->completed[$key] ??= $response;
        }
    }

    #[Override]
    public function restartTimeout(RequestId $requestId, float $timeoutSeconds): void
    {
        $key = (string) $requestId;

        if (isset($this->open[$key])) {
            $this->deadlines[$key] = $this->now + $timeoutSeconds;
        }
    }

    public function awaitResponseTo(string $requestId, float $timeoutSeconds): ?Nip46Response
    {
        $this->deadlines[$requestId] ??= $this->now + $timeoutSeconds;

        while (!isset($this->completed[$requestId])) {
            $action = $this->takeActionDueBy($this->deadlines[$requestId]);

            if (null === $action) {
                $this->now = max($this->now, $this->deadlines[$requestId]);
                $this->close($requestId);

                return null;
            }

            $action();
        }

        $response = $this->completed[$requestId];
        $this->close($requestId);

        return $response;
    }

    /**
     * @return (Closure(): void)|null
     */
    private function takeActionDueBy(float $deadline): ?Closure
    {
        usort($this->scheduled, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $next = $this->scheduled[0] ?? null;

        if (null === $next || $next[0] > $deadline) {
            return null;
        }

        array_shift($this->scheduled);
        $this->now = max($this->now, $next[0]);

        return $next[1];
    }

    private function close(string $requestId): void
    {
        unset($this->open[$requestId], $this->deadlines[$requestId], $this->completed[$requestId]);
    }
}
