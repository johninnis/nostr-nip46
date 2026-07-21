<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\ValueObject\Permission;
use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    public function testAMethodPermissionHasNoKind(): void
    {
        $permission = Permission::forMethod(Nip46Method::Nip44Decrypt);

        $this->assertNull($permission->getKind());
    }

    public function testAMethodPermissionRendersAsItsMethodName(): void
    {
        $this->assertSame('nip44_decrypt', (string) Permission::forMethod(Nip46Method::Nip44Decrypt));
    }

    public function testASignEventPermissionRendersWithItsKind(): void
    {
        $this->assertSame('sign_event:1', (string) Permission::forSignEvent(EventKind::fromInt(1)));
    }

    public function testParsesAMethodPermission(): void
    {
        $permission = Permission::tryFromString('get_public_key');

        $this->assertTrue(Permission::forMethod(Nip46Method::GetPublicKey)->equals($permission ?? self::fail('Expected a permission')));
    }

    public function testParsesASignEventPermissionWithItsKind(): void
    {
        $permission = Permission::tryFromString('sign_event:24242');

        $this->assertTrue(Permission::forSignEvent(EventKind::fromInt(24242))->equals($permission ?? self::fail('Expected a permission')));
    }

    public function testParsesSignEventWithoutAKindAsEveryKind(): void
    {
        $permission = Permission::tryFromString('sign_event');

        $this->assertNull(($permission ?? self::fail('Expected a permission'))->getKind());
    }

    public function testRejectsAnUnknownMethod(): void
    {
        $this->assertNull(Permission::tryFromString('launch_missiles'));
    }

    public function testRejectsAnEmptyString(): void
    {
        $this->assertNull(Permission::tryFromString(''));
    }

    public function testRejectsAKindOnAMethodThatTakesNoParameter(): void
    {
        $this->assertNull(Permission::tryFromString('nip44_encrypt:1'));
    }

    public function testRejectsANonNumericKind(): void
    {
        $this->assertNull(Permission::tryFromString('sign_event:spam'));
    }

    public function testRejectsAnOutOfRangeKind(): void
    {
        $this->assertNull(Permission::tryFromString('sign_event:70000'));
    }

    public function testAMethodPermissionCoversTheSameMethod(): void
    {
        $this->assertTrue(Permission::forMethod(Nip46Method::Nip04Decrypt)->covers(Permission::forMethod(Nip46Method::Nip04Decrypt)));
    }

    public function testAMethodPermissionDoesNotCoverAnotherMethod(): void
    {
        $this->assertFalse(Permission::forMethod(Nip46Method::Nip04Decrypt)->covers(Permission::forMethod(Nip46Method::Nip44Decrypt)));
    }

    public function testSignEventWithoutAKindCoversEveryKind(): void
    {
        $this->assertTrue(Permission::forMethod(Nip46Method::SignEvent)->covers(Permission::forSignEvent(EventKind::fromInt(30023))));
    }

    public function testSignEventWithAKindCoversOnlyThatKind(): void
    {
        $granted = Permission::forSignEvent(EventKind::fromInt(1));

        $this->assertTrue($granted->covers(Permission::forSignEvent(EventKind::fromInt(1))));
        $this->assertFalse($granted->covers(Permission::forSignEvent(EventKind::fromInt(3))));
    }

    public function testSignEventWithAKindDoesNotCoverEveryKind(): void
    {
        $this->assertFalse(Permission::forSignEvent(EventKind::fromInt(1))->covers(Permission::forMethod(Nip46Method::SignEvent)));
    }
}
