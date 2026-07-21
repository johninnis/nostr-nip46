<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Nip46\Application\Port\Nip46AuthoriserInterface;
use Innis\Nostr\Nip46\Domain\Collection\PermissionCollection;
use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\Permission;
use Override;

final readonly class FakeAuthoriser implements Nip46AuthoriserInterface
{
    private function __construct(
        private PermissionCollection $granted,
    ) {
    }

    public static function granting(string $perms): self
    {
        return new self(PermissionCollection::fromPermsString($perms));
    }

    public static function grantingEverythingButSigning(): self
    {
        return new self(PermissionCollection::fromPermsString('get_public_key,nip04_encrypt,nip04_decrypt,nip44_encrypt,nip44_decrypt'));
    }

    public static function grantingNothing(): self
    {
        return new self(new PermissionCollection());
    }

    #[Override]
    public function isAuthorised(AppId $appId, Permission $permission): bool
    {
        return $this->granted->allows($permission);
    }
}
