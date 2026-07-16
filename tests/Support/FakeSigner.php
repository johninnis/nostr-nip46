<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Support;

use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Override;
use RuntimeException;

final class FakeSigner implements Nip46SignerInterface
{
    private const string DUMMY_SIGNATURE_HEX = '00000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly PublicKey $publicKey)
    {
    }

    #[Override]
    public function publicKey(): PublicKey
    {
        return $this->publicKey;
    }

    #[Override]
    public function sign(Rumour $unsigned): Event
    {
        return new Event(
            $unsigned,
            $unsigned->getId(),
            Signature::tryFromHex(self::DUMMY_SIGNATURE_HEX) ?? throw new RuntimeException('Invalid dummy signature'),
        );
    }

    #[Override]
    public function encrypt(PublicKey $peer, string $plaintext, EnvelopeCipher $cipher): string
    {
        return self::seal($plaintext, $cipher);
    }

    #[Override]
    public function decrypt(PublicKey $peer, string $ciphertext, EnvelopeCipher $cipher): ?string
    {
        $prefix = self::prefix($cipher);

        if (!str_starts_with($ciphertext, $prefix)) {
            return null;
        }

        $decoded = base64_decode(substr($ciphertext, strlen($prefix)), true);

        return false === $decoded ? null : $decoded;
    }

    public static function seal(string $plaintext, EnvelopeCipher $cipher): string
    {
        return self::prefix($cipher).base64_encode($plaintext);
    }

    private static function prefix(EnvelopeCipher $cipher): string
    {
        return 'enc:'.$cipher->value.':';
    }
}
