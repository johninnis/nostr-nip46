<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Enum;

enum EnvelopeCipher: string
{
    case Nip44 = 'nip44';
    case Nip04 = 'nip04';

    public function fallback(): self
    {
        return self::Nip44 === $this ? self::Nip04 : self::Nip44;
    }
}
