<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Enum;

enum Nip46CryptoMethod: string
{
    case Nip44Encrypt = 'nip44_encrypt';
    case Nip44Decrypt = 'nip44_decrypt';
    case Nip04Encrypt = 'nip04_encrypt';
    case Nip04Decrypt = 'nip04_decrypt';

    public function cipher(): EnvelopeCipher
    {
        return match ($this) {
            self::Nip44Encrypt, self::Nip44Decrypt => EnvelopeCipher::Nip44,
            self::Nip04Encrypt, self::Nip04Decrypt => EnvelopeCipher::Nip04,
        };
    }

    public function isEncrypt(): bool
    {
        return self::Nip44Encrypt === $this || self::Nip04Encrypt === $this;
    }
}
