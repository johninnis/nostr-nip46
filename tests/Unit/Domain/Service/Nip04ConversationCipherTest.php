<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\Service\EcdhServiceInterface;
use Innis\Nostr\Core\Domain\Service\Nip04EncryptionInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\SecretKeyMaterial;
use Innis\Nostr\Nip46\Domain\Service\Nip04ConversationCipher;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip04ConversationCipherTest extends TestCase
{
    public function testEncryptsUnderTheSharedSecretOfTheTwoKeys(): void
    {
        $sharedSecret = SecretKeyMaterial::fromBytes(str_repeat("\x01", 32));
        $encryption = $this->createStub(Nip04EncryptionInterface::class);
        $encryption->method('encrypt')->willReturnCallback(static fn (string $plaintext, SecretKeyMaterial $secret): string => $secret === $sharedSecret ? 'sealed:'.$plaintext : 'wrong secret');

        $this->assertSame('sealed:gm', new Nip04ConversationCipher($encryption, $this->ecdhReturning($sharedSecret))->encrypt('gm', $this->ownKey(), TestKeys::clientPubkey()));
    }

    public function testZeroesTheSharedSecretAfterEncrypting(): void
    {
        $sharedSecret = SecretKeyMaterial::fromBytes(str_repeat("\x01", 32));

        new Nip04ConversationCipher($this->createStub(Nip04EncryptionInterface::class), $this->ecdhReturning($sharedSecret))->encrypt('gm', $this->ownKey(), TestKeys::clientPubkey());

        $this->assertTrue($sharedSecret->isZeroed());
    }

    public function testZeroesTheSharedSecretWhenDecryptingFails(): void
    {
        $sharedSecret = SecretKeyMaterial::fromBytes(str_repeat("\x01", 32));
        $encryption = $this->createStub(Nip04EncryptionInterface::class);
        $encryption->method('decrypt')->willThrowException(new RuntimeException('bad payload'));

        try {
            new Nip04ConversationCipher($encryption, $this->ecdhReturning($sharedSecret))->decrypt('garbage?iv=x', $this->ownKey(), TestKeys::clientPubkey());
        } catch (RuntimeException) {
        }

        $this->assertTrue($sharedSecret->isZeroed());
    }

    private function ecdhReturning(SecretKeyMaterial $sharedSecret): EcdhServiceInterface
    {
        $ecdh = $this->createStub(EcdhServiceInterface::class);
        $ecdh->method('computeSharedX')->willReturn($sharedSecret);

        return $ecdh;
    }

    private function ownKey(): PrivateKey
    {
        return PrivateKey::tryFromHex(str_repeat('0', 63).'1') ?? throw new RuntimeException('invalid fixture key');
    }
}
