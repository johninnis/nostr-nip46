<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Application\Service;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Application\Service\CapabilityGate;
use Innis\Nostr\Nip46\Application\Service\Nip46Bunker;
use Innis\Nostr\Nip46\Application\Service\Nip46Channel;
use Innis\Nostr\Nip46\Domain\Entity\BunkerSession;
use Innis\Nostr\Nip46\Domain\Enum\BunkerActivityOutcome;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerActivity;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Domain\ValueObject\NostrConnectUrl;
use Innis\Nostr\Nip46\Tests\Support\EncryptionFailureSigner;
use Innis\Nostr\Nip46\Tests\Support\FakeAuthenticator;
use Innis\Nostr\Nip46\Tests\Support\FakeAuthoriser;
use Innis\Nostr\Nip46\Tests\Support\FakeSigner;
use Innis\Nostr\Nip46\Tests\Support\FixedClock;
use Innis\Nostr\Nip46\Tests\Support\IncomingEnvelope;
use Innis\Nostr\Nip46\Tests\Support\RecordingActivityListener;
use Innis\Nostr\Nip46\Tests\Support\RecordingQueueListener;
use Innis\Nostr\Nip46\Tests\Support\RecordingSecretListener;
use Innis\Nostr\Nip46\Tests\Support\RecordingTransport;
use Innis\Nostr\Nip46\Tests\Support\SigningFailureSigner;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip46BunkerTest extends TestCase
{
    private const string SECRET = 'topsecret';

    private RecordingTransport $transport;
    private FakeSigner $signer;
    private FixedClock $clock;
    private RecordingQueueListener $queue;
    private RecordingActivityListener $activity;
    private RecordingSecretListener $secrets;
    private Nip46Bunker $bunker;

    protected function setUp(): void
    {
        $this->transport = new RecordingTransport();
        $this->signer = new FakeSigner(TestKeys::signerPubkey());
        $this->clock = new FixedClock(1_700_000_000);
        $this->queue = new RecordingQueueListener();
        $this->activity = new RecordingActivityListener();
        $this->secrets = new RecordingSecretListener();

        $this->bunker = $this->bunkerWith($this->signer, FakeAuthoriser::grantingEverythingButSigning());
        $this->bunker->setQueueListener($this->queue);
        $this->bunker->setActivityListener($this->activity);
        $this->bunker->setSecretListener($this->secrets);
        $this->bunker->start($this->relays());
    }

    public function testStartSubscribesForNostrConnectEvents(): void
    {
        self::assertNotNull($this->transport->filter);
        $kinds = $this->transport->filter->getKinds();
        self::assertNotNull($kinds);
        $this->assertContains(EventKind::NOSTR_CONNECT, $kinds->toInts());
    }

    public function testPingIsAnsweredWithoutAuthentication(): void
    {
        $this->deliver(['id' => 'p1', 'method' => 'ping', 'params' => []]);

        $this->assertSame('pong', $this->decodeResponse()['result'] ?? null);
    }

    public function testAnEnvelopeWithAnInvalidSignatureIsDroppedWithoutAReplyAndDoesNotPoisonDeduplication(): void
    {
        $this->signer->verifyVerdict = false;
        $this->deliver(['id' => 'p1', 'method' => 'ping', 'params' => []]);

        $this->assertSame([], $this->transport->published);

        $this->signer->verifyVerdict = true;
        $this->deliver(['id' => 'p1', 'method' => 'ping', 'params' => []]);

        $this->assertSame('pong', $this->decodeResponse()['result'] ?? null);
    }

    public function testARequestMoreThanTenMinutesOldIsDroppedWithoutAReply(): void
    {
        $this->deliverCreatedAt(['id' => 'p1', 'method' => 'ping', 'params' => []], $this->clock->now()->toInt() - 601);

        $this->assertSame([], $this->transport->published);
    }

    public function testARequestMoreThanTenMinutesAheadIsDroppedWithoutAReply(): void
    {
        $this->deliverCreatedAt(['id' => 'p1', 'method' => 'ping', 'params' => []], $this->clock->now()->toInt() + 601);

        $this->assertSame([], $this->transport->published);
    }

    public function testAStaleRequestIsNotDecrypted(): void
    {
        $this->deliverCreatedAt(['id' => 'p1', 'method' => 'ping', 'params' => []], $this->clock->now()->toInt() - 601);

        $this->assertSame([], $this->signer->decryptCiphers());
    }

    public function testAStaleRequestIsNotRememberedAsSeen(): void
    {
        $createdAt = $this->clock->now()->toInt() + 601;
        $this->deliverCreatedAt(['id' => 'p1', 'method' => 'ping', 'params' => []], $createdAt);
        $this->clock->advance(601);

        $this->deliverCreatedAt(['id' => 'p1', 'method' => 'ping', 'params' => []], $createdAt);

        $this->assertSame('pong', $this->decodeResponse()['result'] ?? null);
    }

    public function testARequestTenMinutesOldIsAnswered(): void
    {
        $this->deliverCreatedAt(['id' => 'p1', 'method' => 'ping', 'params' => []], $this->clock->now()->toInt() - 600);

        $this->assertSame('pong', $this->decodeResponse()['result'] ?? null);
    }

    public function testGetPublicKeyBeforeConnectIsRejected(): void
    {
        $this->deliver(['id' => 'g1', 'method' => 'get_public_key', 'params' => []]);

        $this->assertSame('not connected', $this->decodeResponse()['error'] ?? null);
    }

    public function testSignEventBeforeConnectIsRejectedAndNotQueued(): void
    {
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);

        $this->assertSame('not connected', $this->decodeResponse()['error'] ?? null);
        $this->assertSame(0, $this->bunker->getPending()->count());
    }

    public function testCipherMethodBeforeConnectIsRejected(): void
    {
        $this->deliver(['id' => 'e1', 'method' => 'nip44_encrypt', 'params' => [TestKeys::clientPubkey()->toHex(), 'hi']]);

        $this->assertSame('not connected', $this->decodeResponse()['error'] ?? null);
    }

    public function testUnsupportedMethodIsRejected(): void
    {
        $this->connect();

        $this->deliver(['id' => 'x1', 'method' => 'frobnicate', 'params' => []]);

        $this->assertSame('unsupported method: frobnicate', $this->decodeResponse()['error'] ?? null);
    }

    public function testConnectWithWrongSecretIsRejected(): void
    {
        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => ['', 'wrong']]);

        $this->assertSame('invalid secret', $this->decodeResponse()['error'] ?? null);
    }

    public function testConnectAddressedToTheSignerKeyIsAccepted(): void
    {
        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => [TestKeys::signerPubkey()->toHex(), self::SECRET]]);

        $this->assertSame('ack', $this->decodeResponse()['result'] ?? null);
    }

    public function testConnectAddressedToAnotherSignerKeyIsRejected(): void
    {
        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => [TestKeys::clientPubkey()->toHex(), self::SECRET]]);

        $this->assertSame('invalid signer', $this->decodeResponse()['error'] ?? null);
    }

    public function testConnectWithAMalformedSignerKeyIsRejected(): void
    {
        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => ['not-a-pubkey', self::SECRET]]);

        $this->assertSame('invalid signer', $this->decodeResponse()['error'] ?? null);
    }

    public function testConnectThenGetPublicKeyReturnsTheSignerKey(): void
    {
        $this->connect();

        $this->deliver(['id' => 'g1', 'method' => 'get_public_key', 'params' => []]);

        $this->assertSame(TestKeys::signerPubkey()->toHex(), $this->decodeResponse()['result'] ?? null);
    }

    public function testSwitchRelaysReportsTheFixedRelaySet(): void
    {
        $this->connect();

        $this->deliver(['id' => 'sr1', 'method' => 'switch_relays', 'params' => []]);

        $this->assertSame('["wss://relay.example.com"]', $this->decodeResponse()['result'] ?? null);
    }

    public function testLogoutDeauthenticatesTheClient(): void
    {
        $this->connect();

        $this->deliver(['id' => 'lo1', 'method' => 'logout', 'params' => []]);
        $this->assertSame('ack', $this->decodeResponse()['result'] ?? null);

        $this->deliver(['id' => 'g1', 'method' => 'get_public_key', 'params' => []]);
        $this->assertSame('not connected', $this->decodeResponse()['error'] ?? null);
    }

    public function testAUsedSecretPresentedByAnotherClientIsAnsweredInvalidSecret(): void
    {
        $this->connect();

        $this->deliver(['id' => 'c2', 'method' => 'connect', 'params' => ['', self::SECRET]], TestKeys::secondClientPubkey());

        $this->assertSame(['id' => 'c2', 'error' => 'invalid secret'], $this->decodeResponseFor(TestKeys::secondClientPubkey()));
    }

    public function testAUsedSecretPresentedByAnotherClientIsNotReportedUsedAgain(): void
    {
        $this->connect();

        $this->deliver(['id' => 'c2', 'method' => 'connect', 'params' => ['', self::SECRET]], TestKeys::secondClientPubkey());

        $this->assertCount(1, $this->secrets->used);
    }

    public function testAClientPresentingAUsedSecretIsNotConnected(): void
    {
        $this->connect();
        $this->deliver(['id' => 'c2', 'method' => 'connect', 'params' => ['', self::SECRET]], TestKeys::secondClientPubkey());

        $this->deliver(['id' => 'g1', 'method' => 'get_public_key', 'params' => []], TestKeys::secondClientPubkey());

        $this->assertSame('not connected', $this->decodeResponseFor(TestKeys::secondClientPubkey())['error'] ?? null);
    }

    public function testTheClientThatUsedASecretIsAcknowledgedAgain(): void
    {
        $this->connect();

        $this->deliver(['id' => 'c2', 'method' => 'connect', 'params' => ['', self::SECRET]]);

        $this->assertSame(['id' => 'c2', 'result' => 'ack'], $this->decodeResponse());
    }

    public function testALoggedOutClientCannotReconnectWithItsUsedSecret(): void
    {
        $this->connect();
        $this->deliver(['id' => 'lo1', 'method' => 'logout', 'params' => []]);

        $this->deliver(['id' => 'c2', 'method' => 'connect', 'params' => ['', self::SECRET]]);

        $this->assertSame(['id' => 'c2', 'error' => 'invalid secret'], $this->decodeResponse());
    }

    public function testASecretStaysUsedAcrossARestart(): void
    {
        $this->connect();
        $this->bunker->stop();
        $this->bunker->start($this->relays());

        $this->deliver(['id' => 'c2', 'method' => 'connect', 'params' => ['', self::SECRET]], TestKeys::secondClientPubkey());

        $this->assertSame(['id' => 'c2', 'error' => 'invalid secret'], $this->decodeResponseFor(TestKeys::secondClientPubkey()));
    }

    public function testUsingASecretTellsTheSecretListenerWhoUsedIt(): void
    {
        $this->connect();

        $this->assertSame([['secret' => self::SECRET, 'client' => TestKeys::clientPubkey()->toHex()]], $this->secrets->used);
    }

    public function testReacknowledgingTheClientThatUsedASecretDoesNotReportItUsedAgain(): void
    {
        $this->connect();
        $this->deliver(['id' => 'c2', 'method' => 'connect', 'params' => ['', self::SECRET]]);

        $this->assertCount(1, $this->secrets->used);
    }

    public function testARejectedSecretIsNotReportedUsed(): void
    {
        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => ['', 'wrong']]);

        $this->assertSame([], $this->secrets->used);
    }

    public function testBunkerUrlForAUsedSecretIsNull(): void
    {
        $this->connect();

        $this->assertNull($this->bunker->bunkerUrlFor(ConnectSecret::fromString(self::SECRET)));
    }

    public function testBunkerUrlForAFreshSecretIsIssuedAfterAnotherIsUsed(): void
    {
        $this->connect();

        $this->assertNotNull($this->bunker->bunkerUrlFor(ConnectSecret::fromString('rotated')));
    }

    public function testSignEventIsQueuedForApprovalNotSignedImmediately(): void
    {
        $this->connect();
        $publishedBefore = count($this->transport->published);

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1, 'content' => 'hi'])]]);

        $this->assertSame(1, $this->bunker->getPending()->count());
        $this->assertCount($publishedBefore, $this->transport->published);
    }

    public function testApproveSignsAndAnswersTheQueuedRequest(): void
    {
        $this->connect();
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1, 'content' => 'hi'])]]);

        $this->bunker->approve($this->firstPendingId($this->bunker));

        $this->assertSame(0, $this->bunker->getPending()->count());
        $result = $this->decodeResponse()['result'] ?? null;
        self::assertIsString($result);
        $this->assertStringContainsString('"kind":1', $result);
    }

    public function testApproveAnswersWithTheClientsOwnRequestId(): void
    {
        $this->connect();
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);

        $this->bunker->approve($this->firstPendingId($this->bunker));

        $this->assertSame('s1', $this->decodeResponse()['id'] ?? null);
    }

    public function testRejectAnswersWithUserRejected(): void
    {
        $this->connect();
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);

        $this->bunker->reject($this->firstPendingId($this->bunker));

        $this->assertSame(0, $this->bunker->getPending()->count());
        $this->assertSame('user rejected', $this->decodeResponse()['error'] ?? null);
    }

    public function testApproveReportsWhetherARequestWasActedOn(): void
    {
        $this->connect();
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);
        $id = $this->firstPendingId($this->bunker);

        $this->assertTrue($this->bunker->approve($id));
        $this->assertFalse($this->bunker->approve($id));
    }

    public function testRejectReportsWhetherARequestWasActedOn(): void
    {
        $this->connect();
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);
        $id = $this->firstPendingId($this->bunker);

        $this->assertTrue($this->bunker->reject($id));
        $this->assertFalse($this->bunker->reject($id));
    }

    public function testTwoClientsReusingARequestIdAreQueuedIndependently(): void
    {
        $this->connect();
        $this->bunker->restorePairing(TestKeys::secondClientPubkey(), $this->relays());

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1, 'content' => 'first'])]]);
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1, 'content' => 'second'])]], TestKeys::secondClientPubkey());

        $this->assertSame(2, $this->bunker->getPending()->count());
    }

    public function testTheSameClientReusingARequestIdQueuesBothRequests(): void
    {
        $this->connect();

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1, 'content' => 'first'])]]);
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1, 'content' => 'second'])]]);

        $this->assertSame(2, $this->bunker->getPending()->count());
    }

    public function testASignRequestBeyondTheQueueCapacityIsAnsweredWithAnError(): void
    {
        $this->connect();

        foreach (range(1, BunkerSession::PENDING_REQUEST_LIMIT) as $i) {
            $this->deliver(['id' => 'q'.$i, 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);
        }

        $this->deliver(['id' => 'overflow', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);

        $this->assertSame('too many pending requests', $this->decodeResponse()['error'] ?? null);
    }

    public function testStartOnAnEmptyRelaySetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->bunker->start(new RelayUrlCollection());
    }

    public function testSigningFaultBecomesAnErrorResponse(): void
    {
        $bunker = $this->bunkerWith(new SigningFailureSigner($this->signer), FakeAuthoriser::grantingEverythingButSigning());
        $bunker->start($this->relays());

        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => ['', self::SECRET]]);
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);
        $bunker->approve($this->firstPendingId($bunker));

        $this->assertSame('signing failed', $this->decodeResponse()['error'] ?? null);
    }

    public function testEncryptFaultOnUntrustedPlaintextBecomesAnErrorResponse(): void
    {
        $bunker = $this->bunkerWith(new EncryptionFailureSigner($this->signer), FakeAuthoriser::grantingEverythingButSigning());
        $bunker->start($this->relays());

        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => ['', self::SECRET]]);
        $this->deliver(['id' => 'e1', 'method' => 'nip44_encrypt', 'params' => [TestKeys::clientPubkey()->toHex(), '']]);

        $this->assertSame('encryption failed', $this->decodeResponse()['error'] ?? null);
    }

    public function testResponseTooLargeToSealDegradesToAnErrorReply(): void
    {
        $bunker = $this->bunkerWith(new EncryptionFailureSigner($this->signer, 100), FakeAuthoriser::grantingEverythingButSigning());
        $bunker->start($this->relays());

        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => ['', self::SECRET]]);
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1, 'content' => 'hi'])]]);
        $bunker->approve($this->firstPendingId($bunker));

        $this->assertSame('response too large', $this->decodeResponse()['error'] ?? null);
    }

    public function testDuplicateEventIsProcessedOnce(): void
    {
        $event = IncomingEnvelope::make(
            ['id' => 'p1', 'method' => 'ping', 'params' => []],
            TestKeys::clientPubkey(),
            TestKeys::signerPubkey(),
            $this->clock->now(),
        );

        $this->transport->deliver($event);
        $this->transport->deliver($event);

        $this->assertCount(1, $this->transport->published);
    }

    public function testStopCancelsTheSubscription(): void
    {
        $this->bunker->stop();

        $this->assertSame(1, $this->transport->cancelCount);
        $this->assertNull($this->bunker->bunkerUrlFor(ConnectSecret::fromString('secret')));
    }

    public function testSignEventQueueingNotifiesTheQueueListener(): void
    {
        $this->connect();
        $changesBefore = $this->queue->changes;

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);

        $this->assertSame($changesBefore + 1, $this->queue->changes);
    }

    public function testQueuedRequestCarriesTheClientPubkey(): void
    {
        $this->connect();
        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);

        $pending = $this->bunker->getPending()->toArray();

        $this->assertCount(1, $pending);
        $this->assertTrue($pending[0]->getClientPubkey()->equals(TestKeys::clientPubkey()));
    }

    public function testThePublicKeyIsTheSignersOwn(): void
    {
        $this->assertTrue($this->bunker->publicKey()->equals(TestKeys::signerPubkey()));
    }

    public function testThePublicKeyIsAvailableEvenWhenStopped(): void
    {
        $this->bunker->stop();

        $this->assertTrue($this->bunker->publicKey()->equals(TestKeys::signerPubkey()));
    }

    public function testBunkerUrlForEmbedsTheGivenSecret(): void
    {
        $url = $this->bunker->bunkerUrlFor(ConnectSecret::fromString('topsecret'));

        self::assertNotNull($url);
        $this->assertStringContainsString('secret=topsecret', (string) $url);
    }

    public function testConnectRecordsAnActivityAttributedToTheClient(): void
    {
        $this->connect();

        $activity = $this->lastActivity();
        $this->assertSame('connect', $activity->getMethod());
        $this->assertTrue($activity->getClient()->equals(TestKeys::clientPubkey()));
        $this->assertSame(BunkerActivityOutcome::Answered, $activity->getOutcome());
    }

    public function testGetPublicKeyRecordsAnAnsweredActivity(): void
    {
        $this->connect();

        $this->deliver(['id' => 'g1', 'method' => 'get_public_key', 'params' => []]);

        $activity = $this->lastActivity();
        $this->assertSame('get_public_key', $activity->getMethod());
        $this->assertTrue($activity->getClient()->equals(TestKeys::clientPubkey()));
        $this->assertSame(BunkerActivityOutcome::Answered, $activity->getOutcome());
        $this->assertNull($activity->getCounterparty());
    }

    public function testPingBeforeConnectRecordsNoActivity(): void
    {
        $this->deliver(['id' => 'p1', 'method' => 'ping', 'params' => []]);

        $this->assertSame([], $this->activity->activities);
    }

    public function testPingAfterConnectRecordsAnActivity(): void
    {
        $this->connect();

        $this->deliver(['id' => 'p1', 'method' => 'ping', 'params' => []]);

        $this->assertSame('ping', $this->lastActivity()->getMethod());
    }

    public function testCipherEncryptRecordsTheCounterparty(): void
    {
        $this->connect();

        $this->deliver(['id' => 'e1', 'method' => 'nip44_encrypt', 'params' => [TestKeys::clientPubkey()->toHex(), 'hi']]);

        $activity = $this->lastActivity();
        $this->assertSame('nip44_encrypt', $activity->getMethod());
        $this->assertSame(BunkerActivityOutcome::Answered, $activity->getOutcome());
        self::assertNotNull($activity->getCounterparty());
        $this->assertSame(TestKeys::clientPubkey()->toHex(), $activity->getCounterparty()->toHex());
    }

    public function testFailedDecryptRecordsAFailedActivity(): void
    {
        $this->connect();

        $this->deliver(['id' => 'd1', 'method' => 'nip44_decrypt', 'params' => [TestKeys::clientPubkey()->toHex(), 'not-encrypted']]);

        $activity = $this->lastActivity();
        $this->assertSame('nip44_decrypt', $activity->getMethod());
        $this->assertSame(BunkerActivityOutcome::Failed, $activity->getOutcome());
    }

    public function testSwitchRelaysRecordsAnActivity(): void
    {
        $this->connect();

        $this->deliver(['id' => 'sr1', 'method' => 'switch_relays', 'params' => []]);

        $this->assertSame('switch_relays', $this->lastActivity()->getMethod());
    }

    public function testLogoutRecordsAnActivityAttributedToTheClient(): void
    {
        $this->connect();

        $this->deliver(['id' => 'lo1', 'method' => 'logout', 'params' => []]);

        $activity = $this->lastActivity();
        $this->assertSame('logout', $activity->getMethod());
        $this->assertTrue($activity->getClient()->equals(TestKeys::clientPubkey()));
    }

    public function testSignEventDoesNotRecordAnActivity(): void
    {
        $this->connect();
        $recordedAfterConnect = count($this->activity->activities);

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);

        $this->assertCount($recordedAfterConnect, $this->activity->activities);
    }

    public function testAnsweringWithoutAnActivityListenerIsANoOp(): void
    {
        $bunker = $this->bunkerWith($this->signer, FakeAuthoriser::grantingEverythingButSigning());
        $bunker->start($this->relays());

        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => ['', self::SECRET]]);
        $this->deliver(['id' => 'p1', 'method' => 'ping', 'params' => []]);

        $this->assertSame('pong', $this->decodeResponse()['result'] ?? null);
    }

    public function testAcceptingANostrConnectUrlEchoesTheSecretToTheClient(): void
    {
        $this->bunker->acceptNostrConnect($this->nostrConnectUrl());

        $this->assertSame('nostrconnect-secret', $this->decodeResponseFor(TestKeys::secondClientPubkey())['result'] ?? null);
    }

    public function testAcceptingANostrConnectUrlPublishesToTheClientsOwnRelays(): void
    {
        $this->bunker->acceptNostrConnect($this->nostrConnectUrl());

        $this->assertSame(['wss://relay.example.com', 'wss://client.example.com'], $this->transport->lastPublishedTo());
    }

    public function testAcceptingANostrConnectUrlSubscribesOnTheRelaysItDoesNotYetListenOn(): void
    {
        $this->bunker->acceptNostrConnect($this->nostrConnectUrl());

        $this->assertSame([['wss://relay.example.com'], ['wss://client.example.com']], $this->transport->subscribedRelays);
    }

    public function testPairingTwiceOnTheSameRelaySubscribesOnce(): void
    {
        $this->bunker->acceptNostrConnect($this->nostrConnectUrl());
        $this->bunker->acceptNostrConnect($this->nostrConnectUrl());

        $this->assertCount(2, $this->transport->subscribedRelays);
    }

    public function testAPairedClientIsAuthenticatedWithoutSendingConnect(): void
    {
        $this->bunker->acceptNostrConnect($this->nostrConnectUrl());

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]], TestKeys::secondClientPubkey());

        $pending = $this->bunker->getPending()->toArray();
        $this->assertCount(1, $pending);
        $this->assertTrue($pending[0]->getClientPubkey()->equals(TestKeys::secondClientPubkey()));
    }

    public function testAcceptingANostrConnectUrlWithoutARunningSessionIsRefused(): void
    {
        $this->bunker->stop();

        $this->assertFalse($this->bunker->acceptNostrConnect($this->nostrConnectUrl()));
    }

    public function testRestoringAPairingAuthenticatesTheClientWithoutAnEcho(): void
    {
        $this->bunker->restorePairing(TestKeys::secondClientPubkey(), $this->clientRelays());

        $this->assertSame([], $this->transport->published);

        $this->deliver(['id' => 'g1', 'method' => 'get_public_key', 'params' => []], TestKeys::secondClientPubkey());

        $this->assertSame(TestKeys::signerPubkey()->toHex(), $this->decodeResponseFor(TestKeys::secondClientPubkey())['result'] ?? null);
    }

    public function testRestoringAPairingWithoutARunningSessionIsRefused(): void
    {
        $this->bunker->stop();

        $this->assertFalse($this->bunker->restorePairing(TestKeys::secondClientPubkey(), $this->clientRelays()));
    }

    public function testStopCancelsEverySubscription(): void
    {
        $this->bunker->acceptNostrConnect($this->nostrConnectUrl());

        $this->bunker->stop();

        $this->assertSame(2, $this->transport->cancelCount);
    }

    public function testAnUngrantedCipherRequestIsQueuedRatherThanRefused(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::grantingNothing());
        $this->connect();
        $publishedBefore = count($this->transport->published);

        $this->deliver(['id' => 'd1', 'method' => 'nip44_decrypt', 'params' => [TestKeys::clientPubkey()->toHex(), 'ciphertext']]);

        $this->assertSame(1, $bunker->getPending()->count());
        $this->assertCount($publishedBefore, $this->transport->published);
    }

    public function testApprovingAQueuedCipherRequestAnswersIt(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::grantingNothing());
        $this->connect();
        $this->deliver(['id' => 'd1', 'method' => 'nip44_decrypt', 'params' => [TestKeys::clientPubkey()->toHex(), $this->signer->encrypt(TestKeys::clientPubkey(), 'gm', EnvelopeCipher::Nip44)]]);

        $bunker->approve($this->firstPendingId($bunker));

        $this->assertSame('gm', $this->decodeResponse()['result'] ?? null);
    }

    public function testRejectingAQueuedCipherRequestAnswersUserRejected(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::grantingNothing());
        $this->connect();
        $this->deliver(['id' => 'd1', 'method' => 'nip44_decrypt', 'params' => [TestKeys::clientPubkey()->toHex(), 'ciphertext']]);

        $bunker->reject($this->firstPendingId($bunker));

        $this->assertSame('user rejected', $this->decodeResponse()['error'] ?? null);
    }

    public function testAnUngrantedGetPublicKeyIsQueued(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::grantingNothing());
        $this->connect();

        $this->deliver(['id' => 'g1', 'method' => 'get_public_key', 'params' => []]);

        $this->assertSame(1, $bunker->getPending()->count());
    }

    public function testAGrantedSignEventIsAnsweredWithoutQueueing(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::granting('sign_event:1'));
        $this->connect();

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1])]]);

        $this->assertSame(0, $bunker->getPending()->count());
        $this->assertNotNull($this->decodeResponse()['result'] ?? null);
    }

    public function testASignEventOfAnUngrantedKindIsStillQueued(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::granting('sign_event:1'));
        $this->connect();

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 3])]]);

        $this->assertSame(1, $bunker->getPending()->count());
    }

    public function testAnAutoAnsweredSignEventRecordsTheKind(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::granting('sign_event:1'));
        $bunker->setActivityListener($this->activity);
        $this->connect();

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1, 'content' => 'gm'])]]);

        $this->assertTrue($this->lastActivity()->getEvent()?->getKind()->is(1));
    }

    public function testAnAutoAnsweredSignEventRecordsTheContent(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::granting('sign_event:1'));
        $bunker->setActivityListener($this->activity);
        $this->connect();

        $this->deliver(['id' => 's1', 'method' => 'sign_event', 'params' => [(string) json_encode(['kind' => 1, 'content' => 'gm'])]]);

        $this->assertSame('gm', $this->lastActivity()->getEvent()?->getContent());
    }

    public function testAnAutoAnsweredCipherRequestSummarisesNoEvent(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::granting('nip44_encrypt'));
        $bunker->setActivityListener($this->activity);
        $this->connect();

        $this->deliver(['id' => 'e1', 'method' => 'nip44_encrypt', 'params' => [TestKeys::clientPubkey()->toHex(), 'secret plaintext']]);

        $this->assertNull($this->lastActivity()->getEvent());
    }

    public function testAnAutoAnsweredCipherRequestIsRecordedAsActivityButAQueuedOneIsNot(): void
    {
        $bunker = $this->bunkerGranting(FakeAuthoriser::grantingNothing());
        $bunker->setActivityListener($this->activity);
        $this->connect();

        $this->deliver(['id' => 'd1', 'method' => 'nip44_decrypt', 'params' => [TestKeys::clientPubkey()->toHex(), 'ciphertext']]);

        $this->assertSame(['connect'], array_map(static fn (BunkerActivity $activity): string => $activity->getMethod(), $this->activity->activities));
    }

    public function testEveryMethodRequiringAuthorisationIsQueuedWhenUngranted(): void
    {
        $askable = array_values(array_filter(Nip46Method::cases(), static fn (Nip46Method $method): bool => $method->requiresAuthorisation()));

        $bunker = $this->bunkerGranting(FakeAuthoriser::grantingNothing());
        $this->connect();

        foreach ($askable as $index => $method) {
            $this->deliver(['id' => 'q'.$index, 'method' => $method->value, 'params' => self::paramsFor($method)]);
        }

        $this->assertCount(count($askable), $bunker->getPending()->toArray());
    }

    public function testAMethodNotRequiringAuthorisationIsNeverQueued(): void
    {
        $answered = array_values(array_filter(Nip46Method::cases(), static fn (Nip46Method $method): bool => !$method->requiresAuthorisation() && Nip46Method::Connect !== $method));

        $bunker = $this->bunkerGranting(FakeAuthoriser::grantingNothing());
        $this->connect();

        foreach ($answered as $index => $method) {
            $this->deliver(['id' => 'a'.$index, 'method' => $method->value, 'params' => self::paramsFor($method)]);
        }

        $this->assertSame(0, $bunker->getPending()->count());
    }

    /**
     * @return list<string>
     */
    private static function paramsFor(Nip46Method $method): array
    {
        return match ($method) {
            Nip46Method::SignEvent => [(string) json_encode(['kind' => 1])],
            Nip46Method::Nip44Encrypt, Nip46Method::Nip44Decrypt,
            Nip46Method::Nip04Encrypt, Nip46Method::Nip04Decrypt => [TestKeys::clientPubkey()->toHex(), 'payload'],
            Nip46Method::Connect, Nip46Method::Ping, Nip46Method::GetPublicKey,
            Nip46Method::SwitchRelays, Nip46Method::Logout => [],
        };
    }

    private function bunkerWith(Nip46SignerInterface $signer, FakeAuthoriser $authoriser): Nip46Bunker
    {
        return new Nip46Bunker(
            new Nip46Channel($this->transport, $signer, $this->clock),
            new FakeAuthenticator(self::SECRET),
            new CapabilityGate($signer, $authoriser, $this->clock),
        );
    }

    private function bunkerGranting(FakeAuthoriser $authoriser): Nip46Bunker
    {
        $bunker = $this->bunkerWith($this->signer, $authoriser);
        $bunker->start($this->relays());

        return $bunker;
    }

    private function nostrConnectUrl(): NostrConnectUrl
    {
        return NostrConnectUrl::tryFromString(
            'nostrconnect://'.TestKeys::secondClientPubkey()->toHex().'?relay=wss%3A%2F%2Fclient.example.com&secret=nostrconnect-secret&name=Emanator',
        ) ?? throw new RuntimeException('nostrconnect url');
    }

    private function clientRelays(): RelayUrlCollection
    {
        return RelayUrlCollection::fromStrings(['wss://client.example.com']);
    }

    private function lastActivity(): BunkerActivity
    {
        self::assertNotEmpty($this->activity->activities);

        return $this->activity->activities[array_key_last($this->activity->activities)];
    }

    private function connect(): void
    {
        $this->deliver(['id' => 'c1', 'method' => 'connect', 'params' => ['', self::SECRET]]);
    }

    private function firstPendingId(Nip46Bunker $bunker): EventId
    {
        $pending = $bunker->getPending()->toArray();
        self::assertNotEmpty($pending);

        return $pending[0]->getId();
    }

    /**
     * @param array<string, mixed> $request
     */
    private function deliver(array $request, ?PublicKey $from = null): void
    {
        $this->transport->deliver(IncomingEnvelope::make(
            $request,
            $from ?? TestKeys::clientPubkey(),
            TestKeys::signerPubkey(),
            $this->clock->now(),
        ));
    }

    /**
     * @param array<string, mixed> $request
     */
    private function deliverCreatedAt(array $request, int $createdAt): void
    {
        $this->transport->deliver(IncomingEnvelope::make($request, TestKeys::clientPubkey(), TestKeys::signerPubkey(), Timestamp::fromInt($createdAt)));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeResponse(): array
    {
        return $this->decodeResponseFor(TestKeys::clientPubkey());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeResponseFor(PublicKey $peer): array
    {
        $event = $this->transport->lastPublished();
        self::assertInstanceOf(Event::class, $event);

        $plaintext = $this->signer->decrypt($peer, (string) $event->getContent(), EnvelopeCipher::Nip44);
        self::assertNotNull($plaintext);

        $decoded = json_decode($plaintext, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function relays(): RelayUrlCollection
    {
        $relay = RelayUrl::fromString('wss://relay.example.com');

        return new RelayUrlCollection([$relay]);
    }
}
