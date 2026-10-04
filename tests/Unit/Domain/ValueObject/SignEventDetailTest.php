<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Nip46\Domain\ValueObject\SignEventDetail;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignEventDetailTest extends TestCase
{
    public function testTheCounterpartyIsTheOnePubkeyTheEventTags(): void
    {
        $detail = self::detail([['p', TestKeys::clientPubkey()->toHex()], ['p', TestKeys::clientPubkey()->toHex()]]);

        $this->assertTrue($detail->getCounterparty()?->equals(TestKeys::clientPubkey()));
    }

    public function testThereIsNoCounterpartyWhenTheEventTagsSeveralPubkeys(): void
    {
        $detail = self::detail([['p', TestKeys::clientPubkey()->toHex()], ['p', TestKeys::signerPubkey()->toHex()]]);

        $this->assertNull($detail->getCounterparty());
    }

    #[DataProvider('eventsNotWrittenAsAnObjectWithATagArray')]
    public function testTryFromWireRefusesAnEventNotWrittenAsAnObjectWithATagArray(string $rawEvent): void
    {
        $this->assertNull(SignEventDetail::tryFromWire($rawEvent));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eventsNotWrittenAsAnObjectWithATagArray(): iterable
    {
        yield 'the tag list as an empty object' => ['{"kind":1,"content":"gm","tags":{}}'];
        yield 'the tag list as an object keyed by position' => ['{"kind":1,"content":"gm","tags":{"0":["t","nostr"]}}'];
        yield 'the event as a JSON array' => ['[{"kind":1,"content":"gm","tags":[]}]'];
    }

    /**
     * @param list<list<string>> $tags
     */
    private static function detail(array $tags): SignEventDetail
    {
        return SignEventDetail::tryFromWire(JsonWireFormat::encode(['kind' => 1, 'content' => 'gm', 'tags' => $tags], JsonWireFormat::MESSAGE))
            ?? self::fail('Expected a sign_event detail');
    }
}
