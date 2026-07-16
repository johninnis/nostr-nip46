<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Domain\ValueObject\UnsignedEventInput;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

final class UnsignedEventInputTest extends TestCase
{
    public function testToUnsignedEventCarriesAuthorKindTagsAndContent(): void
    {
        $input = UnsignedEventInput::tryFromWire([
            'kind' => 1,
            'content' => 'gm',
            'tags' => [['p', TestKeys::clientPubkey()->toHex()]],
        ]);

        self::assertNotNull($input);

        $event = $input->toRumour(TestKeys::signerPubkey(), Timestamp::fromInt(1_700_000_000));

        $this->assertTrue($event->getPubkey()->equals(TestKeys::signerPubkey()));
        $this->assertTrue($event->getKind()->is(EventKind::TEXT_NOTE));
        $this->assertSame('gm', (string) $event->getContent());
        $this->assertSame(1, $event->getTags()->count());
    }

    public function testToUnsignedEventPrefersTheInputsOwnCreatedAt(): void
    {
        $input = UnsignedEventInput::tryFromWire(['kind' => 1, 'created_at' => 1234]);

        self::assertNotNull($input);

        $event = $input->toRumour(TestKeys::signerPubkey(), Timestamp::fromInt(9999));

        $this->assertSame(1234, $event->getCreatedAt()->toInt());
    }

    public function testToUnsignedEventFallsBackWhenCreatedAtIsAbsent(): void
    {
        $input = UnsignedEventInput::tryFromWire(['kind' => 1]);

        self::assertNotNull($input);

        $event = $input->toRumour(TestKeys::signerPubkey(), Timestamp::fromInt(9999));

        $this->assertSame(9999, $event->getCreatedAt()->toInt());
    }

    public function testRejectsNonIntegerKind(): void
    {
        $this->assertNull(UnsignedEventInput::tryFromWire(['kind' => '1']));
    }

    public function testRejectsPresentButInvalidCreatedAt(): void
    {
        $this->assertNull(UnsignedEventInput::tryFromWire(['kind' => 1, 'created_at' => 'soon']));
    }

    public function testRejectsNonArrayInput(): void
    {
        $this->assertNull(UnsignedEventInput::tryFromWire('not-an-object'));
    }
}
