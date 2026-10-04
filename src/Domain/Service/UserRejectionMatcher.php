<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Service;

final class UserRejectionMatcher
{
    private const string DECLINE_WORDS = '/\b(rejected|denied|cancel|canceled|cancelled)\b/i';

    // Deliberate: NIP-46 defines no error code, so a decline is recognised by the words of the bunker's free-text error — see ADR-0027
    public static function matches(string $error): bool
    {
        return 1 === preg_match(self::DECLINE_WORDS, $error);
    }
}
