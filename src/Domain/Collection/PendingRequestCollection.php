<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequest;
use Override;

/**
 * @extends TypedCollection<PendingRequest>
 */
final class PendingRequestCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return PendingRequest::class;
    }

    public function findById(EventId $id): ?PendingRequest
    {
        return array_find(
            $this->toArray(),
            static fn (PendingRequest $request): bool => $request->getId()->equals($id),
        );
    }

    public function sortedByReceivedAtDescending(): self
    {
        $items = $this->toArray();

        usort(
            $items,
            static fn (PendingRequest $a, PendingRequest $b): int => $b->getReceivedAt()->compareTo($a->getReceivedAt()),
        );

        return new self($items);
    }
}
