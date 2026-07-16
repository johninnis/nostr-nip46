<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Collection;

use Innis\Nostr\Core\Domain\Collection\TypedCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingSignRequest;
use Override;

/**
 * @extends TypedCollection<PendingSignRequest>
 */
final class PendingSignRequestCollection extends TypedCollection
{
    #[Override]
    protected function elementType(): string
    {
        return PendingSignRequest::class;
    }

    public function findById(EventId $id): ?PendingSignRequest
    {
        return array_find(
            $this->toArray(),
            static fn (PendingSignRequest $request): bool => $request->getId()->equals($id),
        );
    }

    public function sortedByReceivedAtDescending(): self
    {
        $items = $this->toArray();

        usort(
            $items,
            static fn (PendingSignRequest $a, PendingSignRequest $b): int => $b->getReceivedAt()->compareTo($a->getReceivedAt()),
        );

        return new self($items);
    }
}
