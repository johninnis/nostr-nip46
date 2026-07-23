<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Override;

final readonly class GetPublicKeyDetail implements PendingRequestDetailInterface
{
    #[Override]
    public function getPermission(): Permission
    {
        return Permission::forMethod(Nip46Method::GetPublicKey);
    }

    #[Override]
    public function getCounterparty(): ?PublicKey
    {
        return null;
    }

    #[Override]
    public function getPayload(): string
    {
        return '';
    }

    #[Override]
    public function getSignEventSummary(): ?SignEventSummary
    {
        return null;
    }

    #[Override]
    public function answer(RequestId $id, Nip46SignerInterface $signer, Timestamp $now): Nip46Response
    {
        return Nip46Response::result($id, $signer->publicKey()->toHex());
    }
}
