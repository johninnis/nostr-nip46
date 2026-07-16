<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;

interface Nip46AuthenticatorInterface
{
    public function authenticate(?ConnectSecret $secret): ?AppId;
}
