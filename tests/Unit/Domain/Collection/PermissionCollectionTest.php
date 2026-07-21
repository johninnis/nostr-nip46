<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Collection;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Nip46\Domain\Collection\PermissionCollection;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\ValueObject\Permission;
use PHPUnit\Framework\TestCase;

final class PermissionCollectionTest extends TestCase
{
    public function testParsesACommaSeparatedList(): void
    {
        $permissions = PermissionCollection::fromPermsString('get_public_key,sign_event:1,nip44_encrypt');

        $this->assertSame('get_public_key,sign_event:1,nip44_encrypt', $permissions->toPermsString());
    }

    public function testParsingToleratesSurroundingWhitespace(): void
    {
        $permissions = PermissionCollection::fromPermsString(' get_public_key , sign_event:1 ');

        $this->assertSame('get_public_key,sign_event:1', $permissions->toPermsString());
    }

    public function testParsingDropsEntriesItCannotUnderstand(): void
    {
        $permissions = PermissionCollection::fromPermsString('sign_event:1,launch_missiles,sign_event:spam');

        $this->assertSame('sign_event:1', $permissions->toPermsString());
    }

    public function testParsingDeduplicates(): void
    {
        $permissions = PermissionCollection::fromPermsString('sign_event:1,sign_event:1,get_public_key');

        $this->assertSame('sign_event:1,get_public_key', $permissions->toPermsString());
    }

    public function testAnEmptyStringParsesToNoPermissions(): void
    {
        $this->assertTrue(PermissionCollection::fromPermsString('')->isEmpty());
    }

    public function testAnEmptyCollectionRendersAsAnEmptyString(): void
    {
        $this->assertSame('', new PermissionCollection()->toPermsString());
    }

    public function testMergeCombinesBothCollections(): void
    {
        $merged = PermissionCollection::fromPermsString('get_public_key')->merge(PermissionCollection::fromPermsString('sign_event:1'));

        $this->assertSame('get_public_key,sign_event:1', $merged->toPermsString());
    }

    public function testMergedDuplicatesAreDeduplicatedByUnique(): void
    {
        $merged = PermissionCollection::fromPermsString('get_public_key')->merge(PermissionCollection::fromPermsString('get_public_key'));

        $this->assertSame('get_public_key', $merged->unique()->toPermsString());
    }

    public function testGrantableOffersEveryMethodTheBunkerAsksAbout(): void
    {
        $this->assertSame(
            'get_public_key,sign_event,nip44_encrypt,nip44_decrypt,nip04_encrypt,nip04_decrypt',
            PermissionCollection::grantable()->toPermsString(),
        );
    }

    public function testMinimalDropsAKindAlreadyCoveredByTheKindWideGrant(): void
    {
        $permissions = PermissionCollection::fromPermsString('sign_event,sign_event:1,sign_event:7');

        $this->assertSame('sign_event', $permissions->minimal()->toPermsString());
    }

    public function testMinimalKeepsPerKindGrantsWhenThereIsNoKindWideGrant(): void
    {
        $permissions = PermissionCollection::fromPermsString('sign_event:1,sign_event:7');

        $this->assertSame('sign_event:1,sign_event:7', $permissions->minimal()->toPermsString());
    }

    public function testMinimalKeepsUnrelatedMethods(): void
    {
        $permissions = PermissionCollection::fromPermsString('sign_event,sign_event:1,nip44_decrypt,get_public_key');

        $this->assertSame('sign_event,nip44_decrypt,get_public_key', $permissions->minimal()->toPermsString());
    }

    public function testMinimalDeduplicates(): void
    {
        $permissions = PermissionCollection::fromPermsString('nip44_decrypt,nip44_decrypt');

        $this->assertSame('nip44_decrypt', $permissions->minimal()->toPermsString());
    }

    public function testMinimalOfAnAlreadyMinimalSetChangesNothing(): void
    {
        $permissions = PermissionCollection::fromPermsString('get_public_key,sign_event:1');

        $this->assertSame('get_public_key,sign_event:1', $permissions->minimal()->toPermsString());
    }

    public function testAMinimalSetAllowsExactlyWhatTheOriginalDid(): void
    {
        $permissions = PermissionCollection::fromPermsString('sign_event,sign_event:1');

        $this->assertTrue($permissions->minimal()->allows(Permission::forSignEvent(EventKind::fromInt(1))));
        $this->assertTrue($permissions->minimal()->allows(Permission::forSignEvent(EventKind::fromInt(30023))));
    }

    public function testAllowsAGrantedMethod(): void
    {
        $granted = PermissionCollection::fromPermsString('nip44_decrypt');

        $this->assertTrue($granted->allows(Permission::forMethod(Nip46Method::Nip44Decrypt)));
    }

    public function testDoesNotAllowAnUngrantedMethod(): void
    {
        $granted = PermissionCollection::fromPermsString('nip44_decrypt');

        $this->assertFalse($granted->allows(Permission::forMethod(Nip46Method::Nip04Decrypt)));
    }

    public function testAKindWideGrantAllowsEveryKind(): void
    {
        $granted = PermissionCollection::fromPermsString('sign_event');

        $this->assertTrue($granted->allows(Permission::forSignEvent(EventKind::fromInt(30023))));
    }

    public function testAPerKindGrantAllowsOnlyThatKind(): void
    {
        $granted = PermissionCollection::fromPermsString('sign_event:1,sign_event:7');

        $this->assertTrue($granted->allows(Permission::forSignEvent(EventKind::fromInt(7))));
        $this->assertFalse($granted->allows(Permission::forSignEvent(EventKind::fromInt(3))));
    }

    public function testAnEmptyCollectionAllowsNothing(): void
    {
        $this->assertFalse(new PermissionCollection()->allows(Permission::forMethod(Nip46Method::GetPublicKey)));
    }
}
