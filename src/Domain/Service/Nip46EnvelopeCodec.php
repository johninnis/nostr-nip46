<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Service;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\ValueObject\DecodedEnvelope;

final class Nip46EnvelopeCodec
{
    public function __construct(
        private readonly Nip46SignerInterface $signer,
    ) {
    }

    public function open(
        PublicKey $peer,
        string $ciphertext,
        EnvelopeCipher $preferred,
    ): ?DecodedEnvelope {
        // Deliberate: the current spec mandates NIP-44 envelopes; the second attempt is a legacy-peer fallback — see ADR-0011.
        foreach ([$preferred, $preferred->fallback()] as $cipher) {
            $plaintext = $this->signer->decrypt($peer, $ciphertext, $cipher);
            $decoded = null === $plaintext ? null : JsonWireFormat::decodeArray($plaintext);

            if (null === $decoded) {
                continue;
            }

            return new DecodedEnvelope($decoded, $cipher);
        }

        return null;
    }

    public function seal(
        PublicKey $peer,
        string $payloadJson,
        EnvelopeCipher $cipher,
    ): Event {
        $rumour = RumourFactory::createCustomKind(
            $this->signer->publicKey(),
            EventKind::fromInt(EventKind::NOSTR_CONNECT),
            EventContent::fromString($this->signer->encrypt($peer, $payloadJson, $cipher)),
            new TagCollection([Tag::pubkey($peer)]),
        );

        return $this->signer->sign($rumour);
    }
}
