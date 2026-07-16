<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Factory;

use Innis\Nostr\Core\Domain\Collection\EventKindCollection;
use Innis\Nostr\Core\Domain\Collection\PublicKeyCollection;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagFilter;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;

final class Nip46FilterFactory
{
    public const int CLOCK_SKEW_TOLERANCE_SECONDS = 60;

    private function __construct()
    {
    }

    public static function addressedTo(PublicKey $recipient, Timestamp $now, ?PublicKey $sender = null): Filter
    {
        return new Filter(
            authors: null !== $sender ? new PublicKeyCollection([$sender]) : null,
            kinds: new EventKindCollection([EventKind::fromInt(EventKind::NOSTR_CONNECT)]),
            tags: TagFilter::fromValues([TagType::PUBKEY => [$recipient->toHex()]]),
            since: Timestamp::fromInt(max(0, $now->toInt() - self::CLOCK_SKEW_TOLERANCE_SECONDS)),
        );
    }
}
