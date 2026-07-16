<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use RuntimeException;

final class IncomingEnvelope
{
    private const string DUMMY_SIGNATURE_HEX = '00000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param array<string, mixed> $request
     */
    public static function make(
        array $request,
        PublicKey $clientPubkey,
        PublicKey $signerPubkey,
        Timestamp $createdAt,
        EnvelopeCipher $cipher = EnvelopeCipher::Nip44,
    ): Event {
        $content = FakeSigner::seal(json_encode($request, JSON_THROW_ON_ERROR), $cipher);

        $rumour = RumourFactory::createCustomKind(
            $clientPubkey,
            EventKind::fromInt(EventKind::NOSTR_CONNECT),
            EventContent::fromString($content),
            new TagCollection([Tag::pubkey($signerPubkey)]),
            $createdAt,
        );

        return new Event(
            $rumour,
            $rumour->getId(),
            Signature::tryFromHex(self::DUMMY_SIGNATURE_HEX) ?? throw new RuntimeException('Invalid dummy signature'),
        );
    }
}
