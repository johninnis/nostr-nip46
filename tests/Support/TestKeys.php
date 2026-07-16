<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use RuntimeException;

final class TestKeys
{
    private const string SIGNER_HEX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    private const string CLIENT_HEX = 'c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5';
    private const string SECOND_CLIENT_HEX = 'f9308a019258c31049344f85f89d5229b531c845836f99b08601f113bce036f9';

    public static function signerPubkey(): PublicKey
    {
        return self::parse(self::SIGNER_HEX);
    }

    public static function clientPubkey(): PublicKey
    {
        return self::parse(self::CLIENT_HEX);
    }

    public static function secondClientPubkey(): PublicKey
    {
        return self::parse(self::SECOND_CLIENT_HEX);
    }

    private static function parse(string $hex): PublicKey
    {
        return PublicKey::tryFromHex($hex) ?? throw new RuntimeException('Invalid test pubkey: '.$hex);
    }
}
