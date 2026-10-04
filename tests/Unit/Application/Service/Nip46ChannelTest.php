<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Tag\TagType;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Service\Nip46Channel;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Factory\Nip46FilterFactory;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use Innis\Nostr\Nip46\Tests\Support\EncryptionFailureSigner;
use Innis\Nostr\Nip46\Tests\Support\FakeSigner;
use Innis\Nostr\Nip46\Tests\Support\FixedClock;
use Innis\Nostr\Nip46\Tests\Support\RecordingTransport;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip46ChannelTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    private RecordingTransport $transport;
    private FakeSigner $signer;
    private Nip46Channel $channel;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->signer = new FakeSigner(TestKeys::signerPubkey());
        $this->channel = new Nip46Channel($this->transport, $this->signer, new FixedClock(self::NOW));
    }

    public function testListensForEnvelopesAddressedToItsOwnKeyFromTheGivenSender(): void
    {
        $this->channel->listen($this->relays(), TestKeys::clientPubkey(), $this->createStub(Nip46EventListenerInterface::class));

        $this->assertEquals(
            Nip46FilterFactory::addressedTo(TestKeys::signerPubkey(), new FixedClock(self::NOW)->now(), TestKeys::clientPubkey()),
            $this->transport->filter,
        );
    }

    public function testOpensANip44EnvelopeWithNip44(): void
    {
        $decoded = $this->channel->open($this->envelope(FakeSigner::seal((string) json_encode(['id' => '1', 'method' => 'ping']), EnvelopeCipher::Nip44)));

        self::assertNotNull($decoded);
        $this->assertSame(EnvelopeCipher::Nip44, $decoded->getCipher());
        $this->assertSame(['id' => '1', 'method' => 'ping'], $decoded->getPayload());
    }

    public function testOpensANip04EnvelopeWithNip04(): void
    {
        $decoded = $this->channel->open($this->envelope(FakeSigner::seal((string) json_encode(['id' => '1', 'method' => 'ping']), EnvelopeCipher::Nip04)));

        self::assertNotNull($decoded);
        $this->assertSame(EnvelopeCipher::Nip04, $decoded->getCipher());
    }

    public function testDecryptsOnlyWithTheCipherThePayloadIsWrittenIn(): void
    {
        $this->channel->open($this->envelope(FakeSigner::seal((string) json_encode(['id' => '1', 'method' => 'ping']), EnvelopeCipher::Nip04)));

        $this->assertSame([EnvelopeCipher::Nip04], $this->signer->decryptCiphers());
    }

    #[DataProvider('payloadsThatAreNotAJsonObject')]
    public function testOpensNothingWhenThePayloadIsNotAJsonObject(string $plaintext): void
    {
        $this->assertNull($this->channel->open($this->envelope(FakeSigner::seal($plaintext, EnvelopeCipher::Nip44))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function payloadsThatAreNotAJsonObject(): iterable
    {
        yield 'a JSON array' => ['["1","ping"]'];
        yield 'a JSON string' => ['"ping"'];
        yield 'malformed JSON' => ['{"id":'];
    }

    public function testOpensAJsonObjectKeyedLikeAList(): void
    {
        $decoded = $this->channel->open($this->envelope(FakeSigner::seal('{"0":"1","1":"ping"}', EnvelopeCipher::Nip44)));

        $this->assertSame(['0' => '1', '1' => 'ping'], $decoded?->getPayload());
    }

    public function testOpensNothingWhenThePayloadDoesNotDecrypt(): void
    {
        $this->assertNull($this->channel->open($this->envelope('not-an-envelope')));
    }

    public function testSealsASignedConnectKindEventToThePeerThatDecryptsBack(): void
    {
        $event = $this->seal($this->channel);

        self::assertNotNull($event);
        $this->assertTrue($event->getKind()->is(EventKind::NOSTR_CONNECT));
        $this->assertTrue($event->getPubkey()->equals(TestKeys::signerPubkey()));
        $this->assertSame(TestKeys::clientPubkey()->toHex(), $event->getTags()->getSolePubkeyByType(TagType::pubkey())?->toHex());
        $this->assertSame('{"id":"1","result":"pong"}', $this->signer->decrypt(TestKeys::clientPubkey(), (string) $event->getContent(), EnvelopeCipher::Nip44));
    }

    public function testStampsASealedEnvelopeWithTheInjectedClock(): void
    {
        $this->assertSame(self::NOW, $this->seal($this->channel)?->getCreatedAt()->toInt());
    }

    public function testSealsNothingWhenTheCipherRefusesThePayload(): void
    {
        $channel = new Nip46Channel($this->transport, new EncryptionFailureSigner($this->signer, 10), new FixedClock(self::NOW));

        $this->assertNull($this->seal($channel));
    }

    public function testPublishesTheEnvelopeToTheGivenRelays(): void
    {
        $event = $this->seal($this->channel) ?? throw new RuntimeException('Seal failed');

        $this->channel->publish($this->relays(), $event);

        $this->assertSame(['wss://relay.example'], $this->transport->lastPublishedTo());
    }

    private function seal(Nip46Channel $channel): ?Event
    {
        return $channel->seal(
            TestKeys::clientPubkey(),
            Nip46Response::result(RequestId::tryFromString('1') ?? throw new RuntimeException('bad id'), 'pong')->toJson(),
            EnvelopeCipher::Nip44,
        );
    }

    private function envelope(string $content): Event
    {
        return new FakeSigner(TestKeys::clientPubkey())->sign(Rumour::draft(TestKeys::clientPubkey(), EventKind::fromInt(EventKind::NOSTR_CONNECT), EventContent::fromString($content)));
    }

    private function relays(): RelayUrlCollection
    {
        return new RelayUrlCollection([RelayUrl::fromString('wss://relay.example')]);
    }
}
