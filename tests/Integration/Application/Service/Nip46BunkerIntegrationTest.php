<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Integration\Application\Service;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Tag\Tag;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Nip46\Application\Service\Nip46Bunker;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Infrastructure\Crypto\LocalNip46Signer;
use Innis\Nostr\Nip46\Tests\Support\FakeAuthenticator;
use Innis\Nostr\Nip46\Tests\Support\FakeAuthoriser;
use Innis\Nostr\Nip46\Tests\Support\FixedClock;
use Innis\Nostr\Nip46\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip46BunkerIntegrationTest extends TestCase
{
    private const string SECRET = 'topsecret';

    private RecordingTransport $transport;
    private LocalNip46Signer $bunkerParty;
    private LocalNip46Signer $clientParty;
    private FixedClock $clock;
    private Nip46Bunker $bunker;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->bunkerParty = LocalNip46Signer::create(PrivateKey::generate());
        $this->clientParty = LocalNip46Signer::create(PrivateKey::generate());
        $this->clock = new FixedClock(1_700_000_000);

        $this->bunker = new Nip46Bunker($this->transport, $this->bunkerParty, new FakeAuthenticator(self::SECRET), FakeAuthoriser::grantingEverythingButSigning(), $this->clock);
        $this->bunker->start($this->relays());
    }

    public function testConnectIsAcknowledgedOverRealNip44(): void
    {
        $this->connect();

        $this->assertSame('ack', $this->lastResponse()['result'] ?? null);
    }

    public function testGetPublicKeyReturnsTheBunkerKey(): void
    {
        $this->connect();
        $this->sendFromClient(['id' => 'g1', 'method' => 'get_public_key', 'params' => []]);

        $this->assertSame($this->bunkerParty->publicKey()->toHex(), $this->lastResponse()['result'] ?? null);
    }

    public function testApprovedSignEventProducesAnEventThatVerifies(): void
    {
        $this->connect();
        $this->sendFromClient(['id' => 's1', 'method' => 'sign_event', 'params' => [$this->json(['kind' => 1, 'content' => 'gm from a remote device'])]]);

        $pending = $this->bunker->getPending()->toArray();
        self::assertNotEmpty($pending);
        $this->bunker->approve($pending[0]->getId());

        $result = $this->lastResponse()['result'] ?? null;
        self::assertIsString($result);

        $signed = Event::tryFromJson($result);
        self::assertNotNull($signed);
        $this->assertTrue($signed->verify(Secp256k1Signer::create()));
        $this->assertTrue($signed->getPubkey()->equals($this->bunkerParty->publicKey()));
        $this->assertTrue($signed->getKind()->is(EventKind::TEXT_NOTE));
    }

    public function testNip44EncryptRoundTripsBackToPlaintext(): void
    {
        $this->connect();
        $this->sendFromClient(['id' => 'e1', 'method' => 'nip44_encrypt', 'params' => [$this->clientParty->publicKey()->toHex(), 'hello peer']]);

        $ciphertext = $this->lastResponse()['result'] ?? null;
        self::assertIsString($ciphertext);

        $this->assertSame('hello peer', $this->clientParty->decrypt($this->bunkerParty->publicKey(), $ciphertext, EnvelopeCipher::Nip44));
    }

    public function testOversizeEncryptResultDegradesRatherThanThrowing(): void
    {
        $this->connect();
        $this->sendFromClient(['id' => 'e2', 'method' => 'nip44_encrypt', 'params' => [$this->clientParty->publicKey()->toHex(), str_repeat('a', 60000)]]);

        $this->assertSame('response too large', $this->lastResponse()['error'] ?? null);
    }

    #[IgnoreDeprecations]
    public function testNip04EncryptedRequestsRoundTripOverRealCrypto(): void
    {
        $this->connect(EnvelopeCipher::Nip04);
        $this->assertSame('ack', $this->lastResponse(EnvelopeCipher::Nip04)['result'] ?? null);

        $this->sendFromClient(['id' => 'g1', 'method' => 'get_public_key', 'params' => []], EnvelopeCipher::Nip04);
        $this->assertSame($this->bunkerParty->publicKey()->toHex(), $this->lastResponse(EnvelopeCipher::Nip04)['result'] ?? null);
    }

    private function connect(EnvelopeCipher $cipher = EnvelopeCipher::Nip44): void
    {
        $this->sendFromClient(['id' => 'c1', 'method' => 'connect', 'params' => ['', self::SECRET]], $cipher);
    }

    /**
     * @param array<string, mixed> $request
     */
    private function sendFromClient(array $request, EnvelopeCipher $cipher = EnvelopeCipher::Nip44): void
    {
        $bunkerPubkey = $this->bunkerParty->publicKey();
        $content = $this->clientParty->encrypt($bunkerPubkey, $this->json($request), $cipher);

        $carrier = RumourFactory::createCustomKind(
            $this->clientParty->publicKey(),
            EventKind::fromInt(EventKind::NOSTR_CONNECT),
            EventContent::fromString($content),
            new TagCollection([Tag::pubkey($bunkerPubkey)]),
            $this->clock->now(),
        );

        $this->transport->deliver($this->clientParty->sign($carrier));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function lastResponse(EnvelopeCipher $cipher = EnvelopeCipher::Nip44): array
    {
        $event = $this->transport->lastPublished();
        self::assertInstanceOf(Event::class, $event);

        $plaintext = $this->clientParty->decrypt($this->bunkerParty->publicKey(), (string) $event->getContent(), $cipher);
        self::assertNotNull($plaintext);

        $decoded = json_decode($plaintext, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function relays(): RelayUrlCollection
    {
        $relay = RelayUrl::tryFromString('wss://relay.example.com') ?? throw new RuntimeException('relay');

        return new RelayUrlCollection([$relay]);
    }
}
