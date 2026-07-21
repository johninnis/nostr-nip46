<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\ValueObject;

use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Override;
use Throwable;

final readonly class SignEventDetail implements PendingRequestDetailInterface
{
    public function __construct(
        private UnsignedEventInput $eventToSign,
    ) {
    }

    #[Override]
    public function getPayload(): string
    {
        return (string) $this->eventToSign->getContent();
    }

    #[Override]
    public function getPermission(): Permission
    {
        return Permission::forSignEvent($this->eventToSign->getKind());
    }

    #[Override]
    public function getCounterparty(): ?PublicKey
    {
        return $this->eventToSign->getTags()->getFirstPubkeyByType(TagType::pubkey());
    }

    #[Override]
    public function answer(RequestId $id, Nip46SignerInterface $signer, Timestamp $now): Nip46Response
    {
        $unsigned = $this->eventToSign->toRumour($signer->publicKey(), $now);

        // Deliberate: a signing fault becomes a NIP-46 error response, not a thrown fault — see ADR-0002.
        try {
            return Nip46Response::result($id, $signer->sign($unsigned)->toJson());
        } catch (Throwable) {
            return Nip46Response::failure($id, 'signing failed');
        }
    }

    public static function tryFromWire(?string $rawEvent): ?self
    {
        $decoded = null === $rawEvent ? null : JsonWireFormat::decodeArray($rawEvent);
        $eventToSign = null === $decoded ? null : UnsignedEventInput::tryFromWire($decoded);

        return null === $eventToSign ? null : new self($eventToSign);
    }
}
