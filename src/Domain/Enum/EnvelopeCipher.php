<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Enum;

enum EnvelopeCipher: string
{
    case Nip44 = 'nip44';
    case Nip04 = 'nip04';

    private const string NIP04_IV_SEPARATOR = '?iv=';

    // Deliberate: a NIP-04 payload always carries its ?iv= suffix and a NIP-44 payload is base64, which cannot, so the payload names its own cipher and nothing is decrypted on trial — see ADR-0022
    public static function ofPayload(string $ciphertext): self
    {
        return str_contains($ciphertext, self::NIP04_IV_SEPARATOR) ? self::Nip04 : self::Nip44;
    }
}
