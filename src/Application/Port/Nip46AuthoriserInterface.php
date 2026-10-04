<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\ValueObject\Permission;

interface Nip46AuthoriserInterface
{
    public function isAuthorised(PublicKey $client, Permission $permission): bool;
}
