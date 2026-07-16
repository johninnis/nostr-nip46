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

    public function allowsUnauthenticated(): bool
    {
        return self::Connect === $this || self::Ping === $this;
    }
}
