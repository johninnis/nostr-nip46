<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Integration\Application\Service;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Nip46\Application\Port\BunkerQueueListenerInterface;
use Innis\Nostr\Nip46\Application\Service\CapabilityGate;
use Innis\Nostr\Nip46\Application\Service\Nip46Bunker;
use Innis\Nostr\Nip46\Application\Service\Nip46Channel;
use Innis\Nostr\Nip46\Application\Service\Nip46Client;
use Innis\Nostr\Nip46\Domain\Enum\Nip46FailureReason;
use Innis\Nostr\Nip46\Domain\Failure\Nip46Failure;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Infrastructure\Crypto\LocalNip46Signer;
use Innis\Nostr\Nip46\Tests\Support\FakeAuthenticator;
use Innis\Nostr\Nip46\Tests\Support\FakeAuthoriser;
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
    private LoopbackTransport $clientTransport;
    private FixedClock $clock;
    private BunkerUrl $bunkerUrl;

    protected function setUp(): void
    {
        $this->clock = new FixedClock(1_700_000_000);
        $signer = LocalNip46Signer::create(PrivateKey::generate());
        $this->bunkerUrl = $this->startBunker($signer, $signer);
        $this->client = $this->newClient();
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

        $signed = $this->client->signEvent(Rumour::draft($userPublicKey, EventKind::fromInt(EventKind::TEXT_NOTE), EventContent::fromString('gm from a remote device')));

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
        $this->assertSame(Nip46FailureReason::SignerError, $result->getReason());
        $this->assertSame('invalid secret', $result->getDetail());
    }

    public function testLogoutIsAcknowledgedAcrossTheWire(): void
    {
        $connected = $this->client->connect($this->bunkerUrl);
        self::assertInstanceOf(PublicKey::class, $connected);

        $this->assertNull($this->client->logout());
    }

    public function testALoggedOutClientCannotReconnectWithTheUsedSecret(): void
    {
        $connected = $this->client->connect($this->bunkerUrl);
        self::assertInstanceOf(PublicKey::class, $connected);
        $this->client->logout();

        $result = $this->client->connect($this->bunkerUrl);

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::TimedOut, $result->getReason());
    }

    public function testASecondClientPresentingTheUsedSecretIsIgnored(): void
    {
        $connected = $this->client->connect($this->bunkerUrl);
        self::assertInstanceOf(PublicKey::class, $connected);

        $result = $this->newClient()->connect($this->bunkerUrl);

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::TimedOut, $result->getReason());
    }

    public function testAClientResumedOnItsSessionKeySignsWithoutConnectingAgain(): void
    {
        $sessionKey = PrivateKey::generate();
        $first = $this->newClient($sessionKey);
        $userPublicKey = $first->connect($this->bunkerUrl);
        self::assertInstanceOf(PublicKey::class, $userPublicKey);
        $resumed = $this->newClient($sessionKey);

        $resumed->resume(new BunkerUrl($this->bunkerUrl->getRemoteSignerPubkey(), $first->getRelays(), null), $userPublicKey);
        $signed = $resumed->signEvent(Rumour::draft($userPublicKey, EventKind::fromInt(EventKind::TEXT_NOTE), EventContent::fromString('gm after a restart')));

        $this->assertInstanceOf(Event::class, $signed);
        $this->assertTrue($signed->verify(Secp256k1Signer::create()));
    }

    public function testARemoteSignerKeyApartFromTheUserKeySignsAsTheUser(): void
    {
        $user = LocalNip46Signer::create(PrivateKey::generate());
        $bunkerUrl = $this->startBunker(LocalNip46Signer::create(PrivateKey::generate()), $user);
        $client = $this->newClient();

        $userPublicKey = $client->connect($bunkerUrl);
        self::assertInstanceOf(PublicKey::class, $userPublicKey);
        $signed = $client->signEvent(Rumour::draft($userPublicKey, EventKind::fromInt(EventKind::TEXT_NOTE), EventContent::fromString('gm from a separate signer key')));

        $this->assertFalse($bunkerUrl->getRemoteSignerPubkey()->equals($userPublicKey));
        $this->assertTrue($userPublicKey->equals($user->publicKey()));
        $this->assertInstanceOf(Event::class, $signed);
        $this->assertTrue($signed->verify(Secp256k1Signer::create()));
    }

    private function startBunker(LocalNip46Signer $remoteSigner, LocalNip46Signer $user): BunkerUrl
    {
        [$bunkerTransport, $this->clientTransport] = LoopbackTransport::pair();

        $this->bunker = new Nip46Bunker(
            new Nip46Channel($bunkerTransport, $remoteSigner, $this->clock),
            new FakeAuthenticator(self::SECRET),
            new CapabilityGate($user, FakeAuthoriser::grantingEverythingButSigning(), $this->clock),
        );
        $this->bunker->start(new RelayUrlCollection([
            RelayUrl::fromString('wss://relay.example'),
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

        return $this->bunker->bunkerUrlFor(ConnectSecret::fromString(self::SECRET))
            ?? throw new RuntimeException('Bunker did not start');
    }

    private function newClient(?PrivateKey $sessionKey = null): Nip46Client
    {
        return new Nip46Client(
            new Nip46Channel($this->clientTransport, LocalNip46Signer::create($sessionKey ?? PrivateKey::generate()), $this->clock),
            new InstantPendingResponses(),
        );
    }
}
