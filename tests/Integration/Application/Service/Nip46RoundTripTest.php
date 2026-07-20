<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Integration\Application\Service;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Nip46\Application\Port\BunkerQueueListenerInterface;
use Innis\Nostr\Nip46\Application\Service\Nip46Bunker;
use Innis\Nostr\Nip46\Application\Service\Nip46Client;
use Innis\Nostr\Nip46\Domain\Enum\Nip46FailureReason;
use Innis\Nostr\Nip46\Domain\Failure\Nip46Failure;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Infrastructure\Crypto\LocalNip46Signer;
use Innis\Nostr\Nip46\Tests\Support\FakeAuthenticator;
use Innis\Nostr\Nip46\Tests\Support\FixedClock;
use Innis\Nostr\Nip46\Tests\Support\InstantPendingResponses;
use Innis\Nostr\Nip46\Tests\Support\LoopbackTransport;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip46RoundTripTest extends TestCase
{
    private const string SECRET = 'topsecret';

    private Nip46Bunker $bunker;
    private Nip46Client $client;
    private BunkerUrl $bunkerUrl;

    protected function setUp(): void
    {
        [$bunkerTransport, $clientTransport] = LoopbackTransport::pair();
        $clock = new FixedClock(1_700_000_000);

        $this->bunker = new Nip46Bunker(
            $bunkerTransport,
            LocalNip46Signer::create(PrivateKey::generate()),
            new FakeAuthenticator(self::SECRET),
            $clock,
        );
        $this->bunker->start(new RelayUrlCollection([
            RelayUrl::tryFromString('wss://relay.example') ?? throw new RuntimeException('Invalid fixture relay'),
        ]));
        $this->bunker->setQueueListener(new class($this->bunker) implements BunkerQueueListenerInterface {
            public function __construct(private readonly Nip46Bunker $bunker)
            {
            }

            #[Override]
            public function onQueueChanged(): void
            {
                foreach ($this->bunker->getPending() as $request) {
                    $this->bunker->approve($request->getId());
                }
            }
        });

        $this->client = new Nip46Client(
            $clientTransport,
            LocalNip46Signer::create(PrivateKey::generate()),
            Secp256k1Signer::create(),
            $clock,
            new InstantPendingResponses(),
        );

        $secret = ConnectSecret::fromString(self::SECRET);
        $this->bunkerUrl = $this->bunker->bunkerUrlFor($secret)
            ?? throw new RuntimeException('Bunker did not start');
    }

    public function testTheShippedClientConnectsToTheShippedBunkerOverRealCrypto(): void
    {
        $userPublicKey = $this->client->connect($this->bunkerUrl);

        $this->assertInstanceOf(PublicKey::class, $userPublicKey);
    }

    public function testAnApprovedSignRequestRoundTripsToAVerifiedEvent(): void
    {
        $userPublicKey = $this->client->connect($this->bunkerUrl);
        self::assertInstanceOf(PublicKey::class, $userPublicKey);

        $signed = $this->client->signEvent(RumourFactory::createTextNote($userPublicKey, 'gm from a remote device'));

        $this->assertInstanceOf(Event::class, $signed);
        $this->assertTrue($signed->getPubkey()->equals($userPublicKey));
        $this->assertTrue($signed->verify(Secp256k1Signer::create()));
    }

    public function testAWrongSecretIsRejectedAcrossTheWire(): void
    {
        $wrongSecretUrl = new BunkerUrl(
            $this->bunkerUrl->getRemoteSignerPubkey(),
            $this->bunkerUrl->getRelays(),
            ConnectSecret::fromString('not-the-secret'),
        );

        $result = $this->client->connect($wrongSecretUrl);

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::Rejected, $result->getReason());
        $this->assertSame('invalid secret', $result->getDetail());
    }
}
