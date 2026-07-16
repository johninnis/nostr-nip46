<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Application\Port\BunkerQueueListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46ActivityListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46AuthenticatorInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46TransportInterface;
use Innis\Nostr\Nip46\Domain\Collection\PendingSignRequestCollection;
use Innis\Nostr\Nip46\Domain\Entity\BunkerSession;
use Innis\Nostr\Nip46\Domain\Enum\BunkerActivityOutcome;
use Innis\Nostr\Nip46\Domain\Enum\Nip46CryptoMethod;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\Factory\Nip46FilterFactory;
use Innis\Nostr\Nip46\Domain\Service\Nip46EnvelopeCodec;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerActivity;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Request;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingSignRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\UnsignedEventInput;
use InvalidArgumentException;
use Override;
use Throwable;

final class Nip46Bunker implements Nip46BunkerInterface, Nip46EventListenerInterface
{
    private readonly Nip46EnvelopeCodec $codec;

    private ?BunkerSession $session = null;
    private ?Nip46SubscriptionInterface $subscription = null;
    private ?BunkerQueueListenerInterface $queueListener = null;
    private ?Nip46ActivityListenerInterface $activityListener = null;

    // Deliberate: four irreducible driven ports, not a decomposable unit — see ADR-0006
    public function __construct(
        private readonly Nip46TransportInterface $transport,
        private readonly Nip46SignerInterface $signer,
        private readonly Nip46AuthenticatorInterface $authenticator,
        private readonly ClockInterface $clock,
    ) {
        $this->codec = new Nip46EnvelopeCodec($signer);
    }

    #[Override]
    public function setQueueListener(?BunkerQueueListenerInterface $listener): void
    {
        $this->queueListener = $listener;
    }

    #[Override]
    public function setActivityListener(?Nip46ActivityListenerInterface $listener): void
    {
        $this->activityListener = $listener;
    }

    #[Override]
    public function start(RelayUrlCollection $relays): void
    {
        if ($relays->isEmpty()) {
            throw new InvalidArgumentException('Cannot start a bunker session on an empty relay set');
        }

        $this->stop();

        $this->session = new BunkerSession($relays);

        $filter = Nip46FilterFactory::addressedTo($this->signer->publicKey(), $this->clock->now());

        $this->subscription = $this->transport->subscribe($filter, $relays, $this);
    }

    #[Override]
    public function stop(): void
    {
        $this->subscription?->cancel();
        $this->subscription = null;
        $this->session = null;
        $this->notifyQueueChanged();
    }

    #[Override]
    public function bunkerUrlFor(ConnectSecret $secret): ?BunkerUrl
    {
        if (null === $this->session) {
            return null;
        }

        return new BunkerUrl($this->signer->publicKey(), $this->session->getRelays(), $secret);
    }

    #[Override]
    public function getPending(): PendingSignRequestCollection
    {
        if (null === $this->session) {
            return new PendingSignRequestCollection();
        }

        return $this->session->pending()->sortedByReceivedAtDescending();
    }

    #[Override]
    public function approve(EventId $id): bool
    {
        return $this->answer($id, $this->signResponse(...));
    }

    #[Override]
    public function reject(EventId $id): bool
    {
        return $this->answer($id, static fn (PendingSignRequest $request): Nip46Response => Nip46Response::failure($request->getRequestId(), 'user rejected'));
    }

    /**
     * @param callable(PendingSignRequest): Nip46Response $decide
     */
    private function answer(EventId $id, callable $decide): bool
    {
        $session = $this->session;

        if (null === $session) {
            return false;
        }

        $request = $session->take($id);

        if (null === $request) {
            return false;
        }

        $this->notifyQueueChanged();

        $this->respond($session, $request->getClientPubkey(), $decide($request));

        return true;
    }

    #[Override]
    public function onEvent(Event $event): void
    {
        $session = $this->session;

        if (null === $session || !$session->rememberSeen($event->getId())) {
            return;
        }

        $peer = $event->getPubkey();

        $decoded = $this->codec->open($peer, (string) $event->getContent(), $session->cipherFor($peer));

        if (null === $decoded) {
            return;
        }

        $session->recordCipher($peer, $decoded->getCipher());

        $request = Nip46Request::tryFromWire($decoded->getPayload());

        if (null === $request) {
            return;
        }

        $this->dispatch($session, $event, $request);
    }

    private function dispatch(BunkerSession $session, Event $event, Nip46Request $request): void
    {
        $clientPubkey = $event->getPubkey();
        $method = Nip46Method::tryFrom($request->getMethod());

        if ((null === $method || !$method->allowsUnauthenticated()) && !$session->isAuthenticated($clientPubkey)) {
            $this->respond($session, $clientPubkey, Nip46Response::failure($request->getId(), 'not connected'));

            return;
        }

        if ($method instanceof Nip46Method) {
            match ($method) {
                Nip46Method::Connect => $this->handleConnect($session, $clientPubkey, $request),
                Nip46Method::Ping => $this->handlePing($session, $clientPubkey, $request),
                Nip46Method::GetPublicKey => $this->handleGetPublicKey($session, $clientPubkey, $request),
                Nip46Method::SwitchRelays => $this->reportFixedRelaySet($session, $clientPubkey, $request),
                Nip46Method::Logout => $this->handleLogout($session, $clientPubkey, $request),
                Nip46Method::SignEvent => $this->queueSignRequest($session, $event, $request),
            };

            return;
        }

        $crypto = Nip46CryptoMethod::tryFrom($request->getMethod());

        if ($crypto instanceof Nip46CryptoMethod) {
            $response = $this->cryptoResponse($crypto, $request);
            $this->respond($session, $clientPubkey, $response);
            $this->recordAnswer($session->appIdFor($clientPubkey), $request, $response);

            return;
        }

        $this->respond($session, $clientPubkey, Nip46Response::failure($request->getId(), 'unsupported method: '.$request->getMethod()));
    }

    private function handlePing(BunkerSession $session, PublicKey $clientPubkey, Nip46Request $request): void
    {
        $response = Nip46Response::result($request->getId(), 'pong');
        $this->respond($session, $clientPubkey, $response);
        $this->recordAnswer($session->appIdFor($clientPubkey), $request, $response);
    }

    private function handleGetPublicKey(BunkerSession $session, PublicKey $clientPubkey, Nip46Request $request): void
    {
        $response = Nip46Response::result($request->getId(), $this->signer->publicKey()->toHex());
        $this->respond($session, $clientPubkey, $response);
        $this->recordAnswer($session->appIdFor($clientPubkey), $request, $response);
    }

    // Deliberate: the relay set is fixed at start; report the unchanged set rather than switching — see ADR-0004.
    private function reportFixedRelaySet(BunkerSession $session, PublicKey $clientPubkey, Nip46Request $request): void
    {
        $relays = JsonWireFormat::encode($session->getRelays()->toStrings(), JsonWireFormat::MESSAGE);

        $response = Nip46Response::result($request->getId(), $relays);
        $this->respond($session, $clientPubkey, $response);
        $this->recordAnswer($session->appIdFor($clientPubkey), $request, $response);
    }

    private function handleLogout(BunkerSession $session, PublicKey $clientPubkey, Nip46Request $request): void
    {
        $appId = $session->appIdFor($clientPubkey);

        $response = Nip46Response::result($request->getId(), 'ack');
        $this->respond($session, $clientPubkey, $response);
        $this->recordAnswer($appId, $request, $response);

        $session->deauthenticate($clientPubkey);
    }

    private function handleConnect(BunkerSession $session, PublicKey $clientPubkey, Nip46Request $request): void
    {
        $requestedSigner = $request->param(0);

        if (null !== $requestedSigner && '' !== $requestedSigner) {
            $parsed = PublicKey::tryFromHex($requestedSigner);

            if (null === $parsed || !$parsed->equals($this->signer->publicKey())) {
                $this->respond($session, $clientPubkey, Nip46Response::failure($request->getId(), 'invalid signer'));

                return;
            }
        }

        $appId = $this->authenticator->authenticate(ConnectSecret::tryFromString($request->param(1) ?? ''));

        if (null === $appId) {
            $this->respond($session, $clientPubkey, Nip46Response::failure($request->getId(), 'invalid secret'));

            return;
        }

        $session->authenticate($clientPubkey, $appId);

        $response = Nip46Response::result($request->getId(), 'ack');
        $this->respond($session, $clientPubkey, $response);
        $this->recordAnswer($appId, $request, $response);
    }

    private function cryptoResponse(Nip46CryptoMethod $crypto, Nip46Request $request): Nip46Response
    {
        $peerHex = $request->param(0);
        $payload = $request->param(1);

        $peer = null === $peerHex ? null : PublicKey::tryFromHex($peerHex);

        if (null === $peer || null === $payload) {
            return Nip46Response::failure($request->getId(), 'invalid params');
        }

        if ($crypto->isEncrypt()) {
            // Deliberate: nip44_encrypt/nip04_encrypt encrypt untrusted client plaintext; a cipher failure is answered to the waiting client as a protocol error, not thrown — see ADR-0003.
            try {
                return Nip46Response::result($request->getId(), $this->signer->encrypt($peer, $payload, $crypto->cipher()));
            } catch (Throwable) {
                return Nip46Response::failure($request->getId(), 'encryption failed');
            }
        }

        $plaintext = $this->signer->decrypt($peer, $payload, $crypto->cipher());

        return null === $plaintext
            ? Nip46Response::failure($request->getId(), 'decryption failed')
            : Nip46Response::result($request->getId(), $plaintext);
    }

    private function queueSignRequest(BunkerSession $session, Event $event, Nip46Request $request): void
    {
        $clientPubkey = $event->getPubkey();
        $rawJson = $request->param(0);
        $decoded = null === $rawJson ? null : JsonWireFormat::decodeArray($rawJson);
        $eventToSign = null === $decoded ? null : UnsignedEventInput::tryFromWire($decoded);
        $appId = $session->appIdFor($clientPubkey);

        if (null === $eventToSign || null === $appId) {
            $this->respond($session, $clientPubkey, Nip46Response::failure($request->getId(), 'invalid event'));

            return;
        }

        $queued = $session->queue(new PendingSignRequest(
            id: $event->getId(),
            requestId: $request->getId(),
            clientPubkey: $clientPubkey,
            receivedAt: $this->clock->now(),
            eventToSign: $eventToSign,
            appId: $appId,
        ));

        if (!$queued) {
            $this->respond($session, $clientPubkey, Nip46Response::failure($request->getId(), 'too many pending requests'));

            return;
        }

        $this->notifyQueueChanged();
    }

    private function signResponse(PendingSignRequest $request): Nip46Response
    {
        $unsigned = $request->getEventToSign()->toRumour($this->signer->publicKey(), $this->clock->now());

        // Deliberate: a signing fault becomes a NIP-46 error response, not a thrown fault — see ADR-0002.
        try {
            return Nip46Response::result($request->getRequestId(), $this->signer->sign($unsigned)->toJson());
        } catch (Throwable) {
            return Nip46Response::failure($request->getRequestId(), 'signing failed');
        }
    }

    private function recordAnswer(?AppId $appId, Nip46Request $request, Nip46Response $response): void
    {
        if (null === $this->activityListener || null === $appId) {
            return;
        }

        $crypto = Nip46CryptoMethod::tryFrom($request->getMethod());
        $counterparty = $crypto instanceof Nip46CryptoMethod ? PublicKey::tryFromHex($request->param(0) ?? '') : null;
        $outcome = null === $response->getError() ? BunkerActivityOutcome::Answered : BunkerActivityOutcome::Failed;

        $this->activityListener->onActivity(new BunkerActivity(
            method: $request->getMethod(),
            appId: $appId,
            counterparty: $counterparty,
            outcome: $outcome,
        ));
    }

    private function respond(BunkerSession $session, PublicKey $clientPubkey, Nip46Response $response): void
    {
        $event = $this->trySeal($session, $clientPubkey, $response)
            ?? $this->degrade($session, $clientPubkey, $response);

        if (null === $event) {
            return;
        }

        $this->transport->publish($session->getRelays(), $event);
    }

    private function degrade(BunkerSession $session, PublicKey $clientPubkey, Nip46Response $response): ?Event
    {
        if (null === $response->getResult()) {
            return null;
        }

        return $this->trySeal($session, $clientPubkey, Nip46Response::failure($response->getId(), 'response too large'));
    }

    private function trySeal(BunkerSession $session, PublicKey $clientPubkey, Nip46Response $response): ?Event
    {
        // Deliberate: a response the bunker built can still exceed the transport cipher's size limit; a seal failure degrades to a correlated error rather than a thrown fault that hangs the waiting client — see ADR-0003.
        try {
            return $this->codec->seal($clientPubkey, $response->toJson(), $session->cipherFor($clientPubkey));
        } catch (Throwable) {
            return null;
        }
    }

    private function notifyQueueChanged(): void
    {
        $this->queueListener?->onQueueChanged();
    }
}
