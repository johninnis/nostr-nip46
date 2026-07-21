<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\Permission;

interface Nip46AuthoriserInterface
{
    public function isAuthorised(AppId $appId, Permission $permission): bool;
}
