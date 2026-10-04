<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Port;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;

interface Nip46SecretListenerInterface
{
    public function onSecretUsed(ConnectSecret $secret, PublicKey $client): void;
}
