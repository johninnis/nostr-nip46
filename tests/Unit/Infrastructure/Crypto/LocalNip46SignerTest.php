<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Infrastructure\Crypto;

use Innis\Nostr\Core\Domain\Exception\EcdhException;
use Innis\Nostr\Core\Domain\Exception\EncryptionException;
use Innis\Nostr\Core\Domain\Exception\SecretKeyMaterialZeroedException;
use Innis\Nostr\Core\Domain\Service\ConversationCipherInterface;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Service\EnvelopeCipherSuiteInterface;
use Innis\Nostr\Nip46\Infrastructure\Crypto\LocalNip46Signer;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class LocalNip46SignerTest extends TestCase
{
    public function testACiphertextTheCipherRefusesDecryptsToNull(): void
    {
        $signer = $this->signerWhoseCipherThrows(new EncryptionException('Invalid MAC'));

        $this->assertNull($signer->decrypt(TestKeys::clientPubkey(), 'ciphertext', EnvelopeCipher::Nip44));
    }

    public function testAKeyAgreementExceptionACipherLetsEscapeIsThrown(): void
    {
        $signer = $this->signerWhoseCipherThrows(new EcdhException('ECDH public key is not a valid curve point'));

        $this->expectException(EcdhException::class);

        $signer->decrypt(TestKeys::clientPubkey(), 'ciphertext', EnvelopeCipher::Nip44);
    }

    public function testAFaultInTheSignersOwnMachineryIsThrownNotReadAsUnopenedCiphertext(): void
    {
        $signer = $this->signerWhoseCipherThrows(new SecretKeyMaterialZeroedException());

        $this->expectException(SecretKeyMaterialZeroedException::class);

        $signer->decrypt(TestKeys::clientPubkey(), 'ciphertext', EnvelopeCipher::Nip44);
    }

    private function signerWhoseCipherThrows(Throwable $thrown): LocalNip46Signer
    {
        $cipher = $this->createStub(ConversationCipherInterface::class);
        $cipher->method('decrypt')->willThrowException($thrown);

        $ciphers = $this->createStub(EnvelopeCipherSuiteInterface::class);
        $ciphers->method('cipherFor')->willReturn($cipher);

        $signatureService = $this->createStub(SignatureServiceInterface::class);
        $signatureService->method('derivePublicKey')->willReturn(TestKeys::signerPubkey());

        $privateKey = PrivateKey::tryFromHex(str_repeat('01', 32)) ?? throw new RuntimeException('Invalid fixture private key');

        return new LocalNip46Signer(KeyPair::fromPrivateKey($privateKey, $signatureService), $signatureService, $ciphers);
    }
}
