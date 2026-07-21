<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Domain\Enum;

enum Nip46Method: string
{
    case Connect = 'connect';
    case Ping = 'ping';
    case GetPublicKey = 'get_public_key';
    case SwitchRelays = 'switch_relays';
    case Logout = 'logout';
    case SignEvent = 'sign_event';
    case Nip44Encrypt = 'nip44_encrypt';
    case Nip44Decrypt = 'nip44_decrypt';
    case Nip04Encrypt = 'nip04_encrypt';
    case Nip04Decrypt = 'nip04_decrypt';

    public function allowsUnauthenticated(): bool
    {
        return self::Connect === $this || self::Ping === $this;
    }

    public function requiresAuthorisation(): bool
    {
        return match ($this) {
            self::GetPublicKey, self::SignEvent,
            self::Nip44Encrypt, self::Nip44Decrypt,
            self::Nip04Encrypt, self::Nip04Decrypt => true,
            self::Connect, self::Ping, self::SwitchRelays, self::Logout => false,
        };
    }

    public function cipher(): ?EnvelopeCipher
    {
        return match ($this) {
            self::Nip44Encrypt, self::Nip44Decrypt => EnvelopeCipher::Nip44,
            self::Nip04Encrypt, self::Nip04Decrypt => EnvelopeCipher::Nip04,
            self::Connect, self::Ping, self::GetPublicKey,
            self::SwitchRelays, self::Logout, self::SignEvent => null,
        };
    }

    public function isEncrypt(): bool
    {
        return self::Nip44Encrypt === $this || self::Nip04Encrypt === $this;
    }
}
