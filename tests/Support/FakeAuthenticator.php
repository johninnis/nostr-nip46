<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Nip46\Application\Port\Nip46AuthenticatorInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Override;

final readonly class FakeAuthenticator implements Nip46AuthenticatorInterface
{
    public function __construct(
        private string $secret,
        private string $appId = 'app-1',
    ) {
    }

    #[Override]
    public function authenticate(?ConnectSecret $secret): ?AppId
    {
        return null !== $secret && hash_equals($this->secret, (string) $secret)
            ? AppId::fromString($this->appId)
            : null;
    }
}
