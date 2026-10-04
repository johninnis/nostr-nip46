<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Application\Port\Nip46SecretListenerInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Override;

final class RecordingSecretListener implements Nip46SecretListenerInterface
{
    /** @var list<array{secret: string, client: string}> */
    public array $used = [];

    #[Override]
    public function onSecretUsed(ConnectSecret $secret, PublicKey $client): void
    {
        $this->used[] = ['secret' => (string) $secret, 'client' => $client->toHex()];
    }
}
