<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Collection;

use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Domain\Collection\PendingSignRequestCollection;
use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingSignRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use Innis\Nostr\Nip46\Domain\ValueObject\UnsignedEventInput;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PendingSignRequestCollectionTest extends TestCase
{
    public function testFindByIdReturnsTheMatchingRequest(): void
    {
        $target = self::request('r2', 1_700_000_100);
        $collection = new PendingSignRequestCollection([
            self::request('r1', 1_700_000_000),
            $target,
        ]);

        $this->assertSame('r2', (string) $collection->findById($target->getId())?->getRequestId());
    }

    public function testFindByIdReturnsNullForAnUnknownId(): void
    {
        $collection = new PendingSignRequestCollection([self::request('r1', 1_700_000_000)]);

        $this->assertNull($collection->findById(self::carrierId('missing')));
    }

    public function testSortsTheNewestRequestFirst(): void
    {
        $collection = new PendingSignRequestCollection([
            self::request('older', 1_700_000_000),
            self::request('newest', 1_700_000_200),
            self::request('middle', 1_700_000_100),
        ]);

        $sorted = array_map(
            static fn (PendingSignRequest $request): string => (string) $request->getRequestId(),
            $collection->sortedByReceivedAtDescending()->toArray(),
        );

        $this->assertSame(['newest', 'middle', 'older'], $sorted);
    }

    public function testSortingLeavesTheOriginalCollectionUntouched(): void
    {
        $collection = new PendingSignRequestCollection([
            self::request('older', 1_700_000_000),
            self::request('newer', 1_700_000_100),
        ]);

        $collection->sortedByReceivedAtDescending();

        $this->assertSame('older', (string) $collection->toArray()[0]->getRequestId());
    }

    private static function request(string $requestId, int $receivedAt): PendingSignRequest
    {
        $eventToSign = UnsignedEventInput::tryFromWire(['kind' => 1, 'content' => 'gm'])
            ?? throw new RuntimeException('Invalid fixture event input');

        return new PendingSignRequest(self::carrierId($requestId), self::requestId($requestId), TestKeys::clientPubkey(), Timestamp::fromInt($receivedAt), $eventToSign, AppId::fromString('demo-app'));
    }

    private static function carrierId(string $seed): EventId
    {
        return EventId::tryFromHex(hash('sha256', $seed))
            ?? throw new RuntimeException('Invalid fixture carrier id: '.$seed);
    }

    private static function requestId(string $id): RequestId
    {
        return RequestId::tryFromString($id) ?? throw new RuntimeException('Invalid fixture request id: '.$id);
    }
}
