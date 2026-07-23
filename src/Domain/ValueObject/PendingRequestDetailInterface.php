<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;

interface PendingRequestDetailInterface
{
    public function getPermission(): Permission;

    public function getCounterparty(): ?PublicKey;

    public function getPayload(): string;

    // Deliberate: only a sign_event detail summarises its payload for audit — a cipher payload never does — see ADR-0021
    public function getSignEventSummary(): ?SignEventSummary;

    public function answer(RequestId $id, Nip46SignerInterface $signer, Timestamp $now): Nip46Response;
}
