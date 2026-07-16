<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Service;

use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Service\Nip46EnvelopeCodec;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use Innis\Nostr\Nip46\Tests\Support\FakeSigner;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip46EnvelopeCodecTest extends TestCase
{
    private Nip46EnvelopeCodec $codec;
    private FakeSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new FakeSigner(TestKeys::signerPubkey());
        $this->codec = new Nip46EnvelopeCodec($this->signer);
    }

    public function testOpensEnvelopeWithThePreferredCipher(): void
    {
        $ciphertext = FakeSigner::seal((string) json_encode(['id' => '1', 'method' => 'ping']), EnvelopeCipher::Nip44);

        $decoded = $this->codec->open(TestKeys::clientPubkey(), $ciphertext, EnvelopeCipher::Nip44);

        self::assertNotNull($decoded);
        $this->assertSame(EnvelopeCipher::Nip44, $decoded->getCipher());
        $this->assertSame(['id' => '1', 'method' => 'ping'], $decoded->getPayload());
    }

    public function testFallsBackToTheOtherCipher(): void
    {
        $ciphertext = FakeSigner::seal((string) json_encode(['id' => '1', 'method' => 'ping']), EnvelopeCipher::Nip04);

        $decoded = $this->codec->open(TestKeys::clientPubkey(), $ciphertext, EnvelopeCipher::Nip44);

        self::assertNotNull($decoded);
        $this->assertSame(EnvelopeCipher::Nip04, $decoded->getCipher());
    }

    public function testReturnsNullWhenNeitherCipherDecrypts(): void
    {
        $decoded = $this->codec->open(TestKeys::clientPubkey(), 'not-an-envelope', EnvelopeCipher::Nip44);

        $this->assertNull($decoded);
    }

    public function testSealProducesASignedConnectKindEventThatDecryptsBack(): void
    {
        $event = $this->codec->seal(
            TestKeys::clientPubkey(),
            Nip46Response::result(RequestId::tryFromString('1') ?? throw new RuntimeException('bad id'), 'pong')->toJson(),
            EnvelopeCipher::Nip44,
        );

        $this->assertTrue($event->getKind()->is(EventKind::NOSTR_CONNECT));
        $this->assertTrue($event->getPubkey()->equals(TestKeys::signerPubkey()));

        $plaintext = $this->signer->decrypt(TestKeys::clientPubkey(), (string) $event->getContent(), EnvelopeCipher::Nip44);

        self::assertNotNull($plaintext);
        $this->assertSame('{"id":"1","result":"pong"}', $plaintext);
    }
}
