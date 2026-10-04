<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Tests\Unit\Application\Service;

use Closure;
use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Collection\TagCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventContent;
use Innis\Nostr\Core\Domain\ValueObject\Content\EventKind;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Core\Domain\ValueObject\Timestamp;
use Innis\Nostr\Nip46\Application\Service\Nip46Channel;
use Innis\Nostr\Nip46\Application\Service\Nip46Client;
use Innis\Nostr\Nip46\Domain\Collection\PermissionCollection;
use Innis\Nostr\Nip46\Domain\Enum\EnvelopeCipher;
use Innis\Nostr\Nip46\Domain\Enum\Nip46FailureReason;
use Innis\Nostr\Nip46\Domain\Failure\Nip46Failure;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ClientMetadata;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Tests\Support\EncryptionFailureSigner;
use Innis\Nostr\Nip46\Tests\Support\FakeSigner;
use Innis\Nostr\Nip46\Tests\Support\FixedClock;
use Innis\Nostr\Nip46\Tests\Support\InstantPendingResponses;
use Innis\Nostr\Nip46\Tests\Support\RecordingAuthUrlListener;
use Innis\Nostr\Nip46\Tests\Support\ScriptedBunkerTransport;
use Innis\Nostr\Nip46\Tests\Support\TestKeys;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nip46ClientTest extends TestCase
{
    private const string SECRET = 'topsecret';

    public function testConnectReturnsTheUserPublicKey(): void
    {
        [$client] = $this->clientWith($this->handshake());

        $result = $client->connect($this->bunkerUrl());

        $this->assertInstanceOf(PublicKey::class, $result);
        $this->assertTrue(TestKeys::signerPubkey()->equals($result));
    }

    public function testConnectSubscribesForResponsesAddressedToTheSessionKey(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake());

        $client->connect($this->bunkerUrl());

        self::assertNotNull($transport->filter);
        $this->assertSame(
            [TestKeys::clientPubkey()->toHex()],
            $transport->filter->getTags()?->toArray()['#p'] ?? null,
        );
        $this->assertSame([TestKeys::signerPubkey()->toHex()], $transport->filter->getAuthors()?->toHexes());
    }

    public function testConnectSendsTheBunkerSecret(): void
    {
        $seenParams = null;
        $respond = function (array $request) use (&$seenParams): ?array {
            if ('connect' === $request['method']) {
                $seenParams = $request['params'];
            }

            return ($this->handshake())($request);
        };

        [$client] = $this->clientWith($respond);

        $client->connect($this->bunkerUrl());

        $this->assertSame([TestKeys::signerPubkey()->toHex(), self::SECRET], $seenParams);
    }

    public function testConnectSendsClientMetadataAfterAnEmptyPermissionsSlot(): void
    {
        $seenParams = $this->connectParamsSentWith(new ClientMetadata('My Client', null, null, new PermissionCollection()));

        $this->assertSame([TestKeys::signerPubkey()->toHex(), self::SECRET, '', '{"name":"My Client"}'], $seenParams);
    }

    public function testConnectSendsTheRequestedPermissionsBeforeTheMetadata(): void
    {
        $seenParams = $this->connectParamsSentWith(new ClientMetadata('My Client', 'https://client.example', null, PermissionCollection::fromPermsString('nip44_encrypt,sign_event:1')));

        $this->assertSame([TestKeys::signerPubkey()->toHex(), self::SECRET, 'nip44_encrypt,sign_event:1', '{"name":"My Client","url":"https://client.example"}'], $seenParams);
    }

    public function testConnectSendsOnlyTheRequestedPermissionsWhenTheMetadataDescribesNothing(): void
    {
        $seenParams = $this->connectParamsSentWith(new ClientMetadata(null, null, null, PermissionCollection::fromPermsString('get_public_key')));

        $this->assertSame([TestKeys::signerPubkey()->toHex(), self::SECRET, 'get_public_key'], $seenParams);
    }

    public function testConnectWithoutASecretLeavesTheSecretSlotEmptyBeforeMetadata(): void
    {
        $seenParams = $this->connectParamsSentWith(new ClientMetadata(null, null, 'https://client.example/icon.png', new PermissionCollection()), new BunkerUrl(TestKeys::signerPubkey(), $this->bunkerUrl()->getRelays(), null));

        $this->assertSame([TestKeys::signerPubkey()->toHex(), '', '', '{"image":"https://client.example/icon.png"}'], $seenParams);
    }

    public function testConnectAsksTheBunkerToSwitchRelaysBeforeFetchingThePublicKey(): void
    {
        $methods = [];
        $respond = function (array $request) use (&$methods): ?array {
            $methods[] = $request['method'];

            return ($this->handshake())($request);
        };

        [$client] = $this->clientWith($respond);

        $client->connect($this->bunkerUrl());

        $this->assertSame(['connect', 'switch_relays', 'get_public_key'], $methods);
    }

    public function testLaterRequestsAreSentOnTheRelaysSwitchRelaysReturns(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake(['switch_relays' => '["wss://signer.example","not a relay"]']));

        $client->connect($this->bunkerUrl());

        $this->assertSame(['wss://signer.example'], $transport->publishedTo[count($transport->publishedTo) - 1]);
    }

    public function testResponsesAreAwaitedOnTheRelaysSwitchRelaysReturns(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake(['switch_relays' => '["wss://signer.example"]']));

        $client->connect($this->bunkerUrl());

        $this->assertSame(['wss://signer.example'], $transport->relays?->toStrings());
    }

    public function testANullSwitchRelaysAnswerKeepsTheClientsRelays(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake(['switch_relays' => 'null']));

        $client->connect($this->bunkerUrl());

        $this->assertSame(['wss://relay.example'], $transport->publishedTo[count($transport->publishedTo) - 1]);
    }

    public function testASwitchRelaysErrorKeepsTheClientsRelaysAndStillConnects(): void
    {
        $respond = fn (array $request): ?array => 'switch_relays' === $request['method']
            ? ['id' => $request['id'], 'error' => 'unsupported method: switch_relays']
            : ($this->handshake())($request);

        [$client, $transport] = $this->clientWith($respond);

        $result = $client->connect($this->bunkerUrl());

        $this->assertInstanceOf(PublicKey::class, $result);
        $this->assertSame(['wss://relay.example'], $transport->publishedTo[count($transport->publishedTo) - 1]);
    }

    public function testASwitchRelaysAnswerWrittenAsAnObjectKeepsTheClientsRelays(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake(['switch_relays' => '{"relay":"wss://signer.example"}']));

        $client->connect($this->bunkerUrl());

        $this->assertSame(['wss://relay.example'], $transport->publishedTo[count($transport->publishedTo) - 1]);
    }

    public function testAnEmptySwitchRelaysListKeepsTheClientsRelays(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake(['switch_relays' => '[]']));

        $client->connect($this->bunkerUrl());

        $this->assertSame(['wss://relay.example'], $transport->publishedTo[count($transport->publishedTo) - 1]);
    }

    public function testGetRelaysReportsTheBunkerUrlsRelaysWhenTheSignerNamesNone(): void
    {
        [$client] = $this->clientWith($this->handshake(['switch_relays' => 'null']));

        $client->connect($this->bunkerUrl());

        $this->assertSame(['wss://relay.example'], $client->getRelays()->toStrings());
    }

    public function testGetRelaysReportsTheRelaysSwitchRelaysAdopted(): void
    {
        [$client] = $this->clientWith($this->handshake(['switch_relays' => '["wss://signer.example"]']));

        $client->connect($this->bunkerUrl());

        $this->assertSame(['wss://signer.example'], $client->getRelays()->toStrings());
    }

    public function testGetRelaysBeforeConnectIsAFault(): void
    {
        [$client] = $this->clientWith($this->handshake());

        $this->expectException(LogicException::class);

        $client->getRelays();
    }

    public function testResumeSendsNothing(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake());

        $client->resume($this->resumedBunkerUrl(), TestKeys::signerPubkey());

        $this->assertSame([], $transport->published);
    }

    public function testResumeSubscribesForResponsesOnTheResumedRelays(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake());

        $client->resume($this->resumedBunkerUrl(), TestKeys::signerPubkey());

        $this->assertSame(['wss://signer.example'], $transport->relays?->toStrings());
        $this->assertSame([TestKeys::signerPubkey()->toHex()], $transport->filter?->getAuthors()?->toHexes());
    }

    public function testAResumedSessionSendsRequestsOnTheResumedRelays(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake(['nip44_encrypt' => 'ciphertext-xyz']));
        $client->resume($this->resumedBunkerUrl(), TestKeys::signerPubkey());

        $client->nip44Encrypt(TestKeys::clientPubkey(), 'hello');

        $this->assertSame([['wss://signer.example']], $transport->publishedTo);
    }

    public function testAResumedSessionSignsAsTheResumedUser(): void
    {
        $signed = self::eventAuthoredBy(TestKeys::signerPubkey());
        [$client] = $this->clientWith($this->handshake(['sign_event' => $signed->toJson()]));
        $client->resume($this->resumedBunkerUrl(), TestKeys::signerPubkey());

        $result = $client->signEvent($signed->getRumour());

        $this->assertInstanceOf(Event::class, $result);
    }

    public function testAResumedSessionRejectsAnEventSignedByAnotherIdentity(): void
    {
        $signed = self::eventAuthoredBy(TestKeys::signerPubkey());
        [$client] = $this->clientWith($this->handshake(['sign_event' => $signed->toJson()]));
        $client->resume($this->resumedBunkerUrl(), TestKeys::clientPubkey());

        $result = $client->signEvent($signed->getRumour());

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::IdentityMismatch, $result->getReason());
    }

    public function testGetRelaysReportsTheResumedRelays(): void
    {
        [$client] = $this->clientWith($this->handshake());

        $client->resume($this->resumedBunkerUrl(), TestKeys::signerPubkey());

        $this->assertSame(['wss://signer.example'], $client->getRelays()->toStrings());
    }

    public function testResumeReplacesTheSessionAlreadyOpen(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake());
        $client->connect($this->bunkerUrl());

        $client->resume($this->resumedBunkerUrl(), TestKeys::signerPubkey());

        $this->assertTrue($transport->cancelled);
    }

    public function testLogoutEndsAResumedSession(): void
    {
        [$client] = $this->clientWith($this->handshake(['logout' => 'ack']));
        $client->resume($this->resumedBunkerUrl(), TestKeys::signerPubkey());

        $this->assertNull($client->logout());
        $this->expectException(LogicException::class);
        $client->getRelays();
    }

    public function testLogoutIsAcknowledged(): void
    {
        [$client] = $this->clientWith($this->handshake(['logout' => 'ack']));
        $client->connect($this->bunkerUrl());

        $this->assertNull($client->logout());
    }

    public function testLogoutEndsTheSession(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake(['logout' => 'ack']));
        $client->connect($this->bunkerUrl());

        $client->logout();

        $this->assertTrue($transport->cancelled);
        $this->expectException(LogicException::class);
        $client->nip44Encrypt(TestKeys::clientPubkey(), 'hi');
    }

    public function testALogoutAnswerOtherThanAckIsAnInvalidResponse(): void
    {
        [$client] = $this->clientWith($this->handshake(['logout' => 'bye']));
        $client->connect($this->bunkerUrl());

        $result = $client->logout();

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::InvalidResponse, $result->getReason());
    }

    public function testAnUnacknowledgedLogoutStillEndsTheSession(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake());
        $client->connect($this->bunkerUrl());

        $result = $client->logout();

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::TimedOut, $result->getReason());
        $this->assertTrue($transport->cancelled);
    }

    public function testLogoutBeforeConnectIsAFault(): void
    {
        [$client] = $this->clientWith($this->handshake());

        $this->expectException(LogicException::class);

        $client->logout();
    }

    public function testASilentBunkerTimesOutAndTheSessionIsClosed(): void
    {
        [$client, $transport] = $this->clientWith(static fn (array $request): ?array => null);

        $result = $client->connect($this->bunkerUrl());

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::TimedOut, $result->getReason());
        $this->assertTrue($transport->cancelled);
    }

    public function testABunkerErrorThatIsNotADeclineBecomesASignerErrorFailure(): void
    {
        [$client] = $this->clientWith(static fn (array $request): array => ['id' => $request['id'], 'error' => 'invalid secret']);

        $result = $client->connect($this->bunkerUrl());

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::SignerError, $result->getReason());
        $this->assertSame('invalid secret', $result->getDetail());
    }

    public function testABunkerErrorThatSaysTheUserDeclinedBecomesARejectedFailure(): void
    {
        $respond = function (array $request): ?array {
            if ('sign_event' === $request['method']) {
                return ['id' => $request['id'], 'error' => 'user rejected'];
            }

            return ($this->handshake())($request);
        };

        [$client] = $this->clientWith($respond);
        $client->connect($this->bunkerUrl());

        $result = $client->signEvent(Rumour::draft(TestKeys::signerPubkey(), EventKind::fromInt(EventKind::TEXT_NOTE), EventContent::fromString('gm')));

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::Rejected, $result->getReason());
        $this->assertSame('user rejected', $result->getDetail());
    }

    public function testANonHexPublicKeyAnswerIsAnInvalidResponse(): void
    {
        $respond = static fn (array $request): array => match ($request['method']) {
            'connect' => ['id' => $request['id'], 'result' => 'ack'],
            default => ['id' => $request['id'], 'result' => 'not-a-pubkey'],
        };

        [$client] = $this->clientWith($respond);

        $result = $client->connect($this->bunkerUrl());

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::InvalidResponse, $result->getReason());
    }

    public function testACipherCallBeforeConnectIsAFault(): void
    {
        [$client] = $this->clientWith($this->handshake());

        $this->expectException(LogicException::class);

        $client->nip44Encrypt(TestKeys::clientPubkey(), 'hi');
    }

    public function testNip44EncryptSendsThePeerAndPlaintextAndReturnsTheCiphertext(): void
    {
        $seen = null;
        $respond = function (array $request) use (&$seen): ?array {
            if ('nip44_encrypt' === $request['method']) {
                $seen = $request;

                return ['id' => $request['id'], 'result' => 'ciphertext-xyz'];
            }

            return ($this->handshake())($request);
        };

        [$client] = $this->clientWith($respond);
        $client->connect($this->bunkerUrl());

        $result = $client->nip44Encrypt(TestKeys::clientPubkey(), 'hello');

        $this->assertSame('ciphertext-xyz', $result);
        $this->assertSame('nip44_encrypt', $seen['method'] ?? null);
        $this->assertSame([TestKeys::clientPubkey()->toHex(), 'hello'], $seen['params'] ?? null);
    }

    public function testNip04DecryptReturnsTheSignersPlaintext(): void
    {
        [$client] = $this->clientWith($this->handshake(['nip04_decrypt' => 'the-plaintext']));
        $client->connect($this->bunkerUrl());

        $result = $client->nip04Decrypt(TestKeys::clientPubkey(), 'enc:nip04:opaque');

        $this->assertSame('the-plaintext', $result);
    }

    public function testAFailedCipherCallComesBackAsASignerErrorFailure(): void
    {
        $respond = function (array $request): ?array {
            if ('nip44_decrypt' === $request['method']) {
                return ['id' => $request['id'], 'error' => 'decryption failed'];
            }

            return ($this->handshake())($request);
        };

        [$client] = $this->clientWith($respond);
        $client->connect($this->bunkerUrl());

        $result = $client->nip44Decrypt(TestKeys::clientPubkey(), 'not-encrypted');

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::SignerError, $result->getReason());
        $this->assertSame('decryption failed', $result->getDetail());
    }

    public function testSignEventReturnsTheVerifiedSignedEvent(): void
    {
        $signed = self::eventAuthoredBy(TestKeys::signerPubkey());
        [$client] = $this->clientWith($this->handshake(['sign_event' => $signed->toJson()]));

        $client->connect($this->bunkerUrl());
        $result = $client->signEvent($signed->getRumour());

        $this->assertInstanceOf(Event::class, $result);
        $this->assertTrue($signed->getId()->equals($result->getId()));
    }

    public function testASignedEventFromAnotherIdentityIsRejected(): void
    {
        $foreign = self::eventAuthoredBy(TestKeys::clientPubkey());
        [$client] = $this->clientWith($this->handshake(['sign_event' => $foreign->toJson()]));

        $client->connect($this->bunkerUrl());
        $result = $client->signEvent($foreign->getRumour());

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::IdentityMismatch, $result->getReason());
    }

    public function testAnUnverifiableSignatureIsRejected(): void
    {
        $signed = self::eventAuthoredBy(TestKeys::signerPubkey());
        [$client] = $this->clientWith($this->handshake(['sign_event' => $signed->toJson()]), verifies: false);

        $client->connect($this->bunkerUrl());
        $result = $client->signEvent($signed->getRumour());

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::InvalidSignature, $result->getReason());
    }

    public function testAnAuthUrlChallengeNotifiesTheListenerWithoutCompletingTheCall(): void
    {
        [$client] = $this->clientWith(static fn (array $request): array => [
            'id' => $request['id'],
            'result' => 'auth_url',
            'error' => 'https://bunker.example/authorise',
        ]);
        $listener = new RecordingAuthUrlListener();
        $client->setAuthUrlListener($listener);

        $result = $client->connect($this->bunkerUrl());

        $this->assertSame(['https://bunker.example/authorise'], $listener->urls);
        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::TimedOut, $result->getReason());
    }

    public function testAnAuthUrlChallengeWithoutAListenerFailsTheRequestAtOnce(): void
    {
        [$client] = $this->clientWith(static fn (array $request): array => [
            'id' => $request['id'],
            'result' => 'auth_url',
            'error' => 'https://bunker.example/authorise',
        ]);

        $result = $client->connect($this->bunkerUrl());

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::SignerError, $result->getReason());
        $this->assertSame('bunker requires authentication: https://bunker.example/authorise', $result->getDetail());
    }

    public function testARequestTheCipherCannotCarryIsAReturnedFailure(): void
    {
        [$client] = $this->clientWith(
            $this->handshake(),
            sessionSigner: new EncryptionFailureSigner(new FakeSigner(TestKeys::clientPubkey()), 1000),
        );

        $client->connect($this->bunkerUrl());
        $result = $client->signEvent(self::oversizeRumour());

        $this->assertInstanceOf(Nip46Failure::class, $result);
        $this->assertSame(Nip46FailureReason::EncryptionFailed, $result->getReason());
    }

    public function testNoPendingSlotIsOpenedForARequestThatCannotBeSealed(): void
    {
        [$client, , $pending] = $this->clientWith(
            $this->handshake(),
            sessionSigner: new EncryptionFailureSigner(new FakeSigner(TestKeys::clientPubkey()), 1000),
        );

        $client->connect($this->bunkerUrl());
        $openedBefore = count($pending->openedRequestIds);

        $client->signEvent(self::oversizeRumour());

        $this->assertCount($openedBefore, $pending->openedRequestIds);
    }

    public function testARedeliveredAuthUrlChallengeNotifiesTheListenerOnce(): void
    {
        [$client] = $this->clientWith(static fn (array $request): array => [
            'id' => $request['id'],
            'result' => 'auth_url',
            'error' => 'https://bunker.example/authorise',
        ], replyCopies: 2);
        $listener = new RecordingAuthUrlListener();
        $client->setAuthUrlListener($listener);

        $client->connect($this->bunkerUrl());

        $this->assertSame(['https://bunker.example/authorise'], $listener->urls);
    }

    public function testAnAuthUrlChallengeWithoutAUrlIsIgnored(): void
    {
        [$client] = $this->clientWith(static fn (array $request): array => [
            'id' => $request['id'],
            'result' => 'auth_url',
        ]);
        $listener = new RecordingAuthUrlListener();
        $client->setAuthUrlListener($listener);

        $result = $client->connect($this->bunkerUrl());

        $this->assertSame([], $listener->urls);
        $this->assertInstanceOf(Nip46Failure::class, $result);
    }

    public function testAnAuthUrlChallengeWithANonWebUrlIsNotSurfaced(): void
    {
        [$client] = $this->clientWith(static fn (array $request): array => [
            'id' => $request['id'],
            'result' => 'auth_url',
            'error' => 'javascript:alert(1)',
        ]);
        $listener = new RecordingAuthUrlListener();
        $client->setAuthUrlListener($listener);

        $client->connect($this->bunkerUrl());

        $this->assertSame([], $listener->urls);
    }

    public function testTheSessionAdoptsTheCipherTheBunkerAnswersWith(): void
    {
        [$client, $transport] = $this->clientWith($this->handshake(), replyCipher: EnvelopeCipher::Nip04);

        $client->connect($this->bunkerUrl());

        $lastPublished = $transport->published[count($transport->published) - 1];
        $this->assertStringStartsWith('enc:nip04:', (string) $lastPublished->getContent());
    }

    /**
     * @param Closure(array<mixed>): ?array<string, mixed> $respond
     *
     * @return array{Nip46Client, ScriptedBunkerTransport, InstantPendingResponses}
     */
    private function clientWith(Closure $respond, EnvelopeCipher $replyCipher = EnvelopeCipher::Nip44, bool $verifies = true, ?Nip46SignerInterface $sessionSigner = null, int $replyCopies = 1): array
    {
        $transport = new ScriptedBunkerTransport($respond, $replyCipher, $replyCopies);
        $pending = new InstantPendingResponses();

        $signatureService = $this->createStub(SignatureServiceInterface::class);
        $signatureService->method('verify')->willReturn($verifies);

        $client = new Nip46Client(
            new Nip46Channel($transport, $sessionSigner ?? new FakeSigner(TestKeys::clientPubkey()), new FixedClock(1_700_000_000)),
            $signatureService,
            $pending,
        );

        return [$client, $transport, $pending];
    }

    /**
     * @param array<string, string> $results extra method-to-result answers on top of the handshake
     *
     * @return Closure(array<mixed>): ?array<string, mixed>
     */
    private function handshake(array $results = []): Closure
    {
        return static function (array $request) use ($results): ?array {
            $method = $request['method'];

            if (!is_string($method) || !is_string($request['id'])) {
                return null;
            }

            if (isset($results[$method])) {
                return ['id' => $request['id'], 'result' => $results[$method]];
            }

            return match ($method) {
                'connect' => ['id' => $request['id'], 'result' => 'ack'],
                'get_public_key' => ['id' => $request['id'], 'result' => TestKeys::signerPubkey()->toHex()],
                default => null,
            };
        };
    }

    /**
     * @return array<mixed>|null
     */
    private function connectParamsSentWith(ClientMetadata $metadata, ?BunkerUrl $bunkerUrl = null): ?array
    {
        $seenParams = null;
        $respond = function (array $request) use (&$seenParams): ?array {
            if ('connect' === $request['method']) {
                $seenParams = $request['params'];
            }

            return ($this->handshake())($request);
        };

        [$client] = $this->clientWith($respond);

        $client->connect($bunkerUrl ?? $this->bunkerUrl(), $metadata);

        return $seenParams;
    }

    private function bunkerUrl(): BunkerUrl
    {
        $relay = RelayUrl::fromString('wss://relay.example');

        $secret = ConnectSecret::fromString(self::SECRET);

        return new BunkerUrl(TestKeys::signerPubkey(), new RelayUrlCollection([$relay]), $secret);
    }

    private function resumedBunkerUrl(): BunkerUrl
    {
        $relay = RelayUrl::fromString('wss://signer.example');

        return new BunkerUrl(TestKeys::signerPubkey(), new RelayUrlCollection([$relay]), null);
    }

    private static function oversizeRumour(): Rumour
    {
        return Rumour::draft(
            TestKeys::signerPubkey(),
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString(str_repeat('a', 2000)),
            new TagCollection(),
            Timestamp::fromInt(1_700_000_000),
        );
    }

    private static function eventAuthoredBy(PublicKey $author): Event
    {
        $rumour = Rumour::draft(
            $author,
            EventKind::fromInt(EventKind::TEXT_NOTE),
            EventContent::fromString('gm'),
            new TagCollection(),
            Timestamp::fromInt(1_700_000_000),
        );

        return new Event(
            $rumour,
            $rumour->getId(),
            Signature::tryFromHex(str_repeat('0', 128)) ?? throw new RuntimeException('Invalid fixture signature'),
        );
    }
}
