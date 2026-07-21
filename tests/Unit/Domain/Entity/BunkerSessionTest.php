<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Domain\Entity;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Domain\Entity\BunkerSession;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use Innis\Nostr\Nip46\Domain\ValueObject\SignEventDetail;
use Innis\Nostr\Nip46\Domain\ValueObject\UnsignedEventInput;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BunkerSessionTest extends TestCase
{
    public function testRemembersSeenEventOnlyOnce(): void
    {
        $session = $this->session();
        $eventId = $this->eventId('a');

        $this->assertTrue($session->rememberSeen($eventId));
        $this->assertFalse($session->rememberSeen($eventId));
    }

    public function testClientIsNotAuthenticatedUntilAuthenticated(): void
    {
        $session = $this->session();

        $this->assertFalse($session->isAuthenticated(TestKeys::clientPubkey()));
        $this->assertNull($session->appIdFor(TestKeys::clientPubkey()));

        $session->authenticate(TestKeys::clientPubkey(), AppId::fromString('app-7'));

        $this->assertTrue($session->isAuthenticated(TestKeys::clientPubkey()));
        $this->assertSame('app-7', (string) $session->appIdFor(TestKeys::clientPubkey()));
    }

    public function testCipherDefaultsToNip44(): void
    {
        $this->assertSame(EnvelopeCipher::Nip44, $this->session()->cipherFor(TestKeys::clientPubkey()));
    }

    public function testRecordedCipherIsReturned(): void
    {
        $session = $this->session();

        $session->recordCipher(TestKeys::clientPubkey(), EnvelopeCipher::Nip04);

        $this->assertSame(EnvelopeCipher::Nip04, $session->cipherFor(TestKeys::clientPubkey()));
    }

    public function testAClientWithoutRecordedRelaysUsesTheSessionsOwn(): void
    {
        $session = $this->session();

        $this->assertSame(['wss://relay.example.com'], $session->relaysFor(TestKeys::clientPubkey())->toStrings());
    }

    public function testAClientsRecordedRelaysAreAddedToTheSessionsOwn(): void
    {
        $session = $this->session();

        $session->recordRelays(TestKeys::clientPubkey(), RelayUrlCollection::fromStrings(['wss://client.example.com']));

        $this->assertSame(['wss://relay.example.com', 'wss://client.example.com'], $session->relaysFor(TestKeys::clientPubkey())->toStrings());
    }

    public function testARecordedRelayAlreadyInTheSessionIsNotDuplicated(): void
    {
        $session = $this->session();

        $session->recordRelays(TestKeys::clientPubkey(), RelayUrlCollection::fromStrings(['wss://relay.example.com']));

        $this->assertSame(['wss://relay.example.com'], $session->relaysFor(TestKeys::clientPubkey())->toStrings());
    }

    public function testRecordedRelaysAreScopedToTheirClient(): void
    {
        $session = $this->session();

        $session->recordRelays(TestKeys::clientPubkey(), RelayUrlCollection::fromStrings(['wss://client.example.com']));

        $this->assertSame(['wss://relay.example.com'], $session->relaysFor(TestKeys::secondClientPubkey())->toStrings());
    }

    public function testTheSessionIsAlreadyListeningOnItsOwnRelays(): void
    {
        $session = $this->session();

        $this->assertTrue($session->startListeningOn(RelayUrlCollection::fromStrings(['wss://relay.example.com']))->isEmpty());
    }

    public function testStartListeningOnReturnsOnlyTheRelaysNotYetListenedOn(): void
    {
        $session = $this->session();

        $added = $session->startListeningOn(RelayUrlCollection::fromStrings(['wss://relay.example.com', 'wss://client.example.com']));

        $this->assertSame(['wss://client.example.com'], $added->toStrings());
    }

    public function testARelayIsOnlyEverStartedListeningOnOnce(): void
    {
        $session = $this->session();
        $relays = RelayUrlCollection::fromStrings(['wss://client.example.com']);

        $session->startListeningOn($relays);

        $this->assertTrue($session->startListeningOn($relays)->isEmpty());
    }

    public function testARepeatedRelayIsReturnedOnceByStartListeningOn(): void
    {
        $session = $this->session();

        $added = $session->startListeningOn(RelayUrlCollection::fromStrings(['wss://client.example.com', 'wss://client.example.com']));

        $this->assertSame(['wss://client.example.com'], $added->toStrings());
    }

    public function testQueueAndTakeRemovesTheRequest(): void
    {
        $session = $this->session();
        $request = $this->pending('req-1');
        $session->queue($request);

        $this->assertSame(1, $session->pending()->count());
        self::assertNotNull($session->take($request->getId()));
        $this->assertSame(0, $session->pending()->count());
        $this->assertNull($session->take($request->getId()));
    }

    public function testQueueAcceptsRequestsUpToTheCapacity(): void
    {
        $session = $this->session();

        foreach (range(1, BunkerSession::PENDING_REQUEST_LIMIT - 1) as $i) {
            $session->queue($this->pending('req-'.$i));
        }

        $this->assertTrue($session->queue($this->pending('last')));
    }

    public function testQueueRefusesARequestBeyondTheCapacity(): void
    {
        $session = $this->session();

        foreach (range(1, BunkerSession::PENDING_REQUEST_LIMIT) as $i) {
            $session->queue($this->pending('req-'.$i));
        }

        $this->assertFalse($session->queue($this->pending('overflow')));
    }

    public function testKeepsAuthenticatedClientsWithinTheLimit(): void
    {
        $session = $this->session();
        $appId = AppId::fromString('app-1');
        $first = $this->clientKey(1);
        $session->authenticate($first, $appId);

        foreach (range(2, BunkerSession::AUTHENTICATED_CLIENT_LIMIT) as $i) {
            $session->authenticate($this->clientKey($i), $appId);
        }

        $this->assertTrue($session->isAuthenticated($first));
    }

    public function testEvictsTheOldestAuthenticatedClientPastTheLimit(): void
    {
        $session = $this->session();
        $appId = AppId::fromString('app-1');
        $first = $this->clientKey(1);
        $session->authenticate($first, $appId);

        foreach (range(2, BunkerSession::AUTHENTICATED_CLIENT_LIMIT + 1) as $i) {
            $session->authenticate($this->clientKey($i), $appId);
        }

        $this->assertFalse($session->isAuthenticated($first));
    }

    private function session(): BunkerSession
    {
        $relay = RelayUrl::tryFromString('wss://relay.example.com') ?? throw new RuntimeException('relay');

        return new BunkerSession(new RelayUrlCollection([$relay]));
    }

    private function pending(string $requestId): PendingRequest
    {
        $input = UnsignedEventInput::tryFromWire(['kind' => 1]) ?? throw new RuntimeException('input');
        $carrier = EventId::tryFromHex(hash('sha256', $requestId)) ?? throw new RuntimeException('carrier id');
        $id = RequestId::tryFromString($requestId) ?? throw new RuntimeException('request id');

        return new PendingRequest($carrier, $id, TestKeys::clientPubkey(), Timestamp::fromInt(1000), new SignEventDetail($input), AppId::fromString('app-1'));
    }

    private function clientKey(int $seed): PublicKey
    {
        return PublicKey::tryFromHex(str_pad(dechex($seed), 64, '0', STR_PAD_LEFT)) ?? throw new RuntimeException('client key');
    }

    private function eventId(string $seed): EventId
    {
        return EventId::tryFromHex(str_pad($seed, 64, '0')) ?? throw new RuntimeException('event id');
    }
}
