<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Identity\EventId;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Nip46\Application\Port\BunkerQueueListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46ActivityListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46AuthenticatorInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SecretListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Innis\Nostr\Nip46\Domain\Collection\PendingRequestCollection;
use Innis\Nostr\Nip46\Domain\Entity\BunkerSession;
use Innis\Nostr\Nip46\Domain\Entity\UsedConnectSecrets;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerActivity;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\CipherDetail;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Domain\ValueObject\GetPublicKeyDetail;
use Innis\Nostr\Nip46\Domain\ValueObject\IncomingRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Request;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\NostrConnectUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequest;
use Innis\Nostr\Nip46\Domain\ValueObject\PendingRequestDetailInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use Innis\Nostr\Nip46\Domain\ValueObject\SignEventDetail;
use InvalidArgumentException;
use Override;

final class Nip46Bunker implements Nip46BunkerInterface, Nip46EventListenerInterface
{
    private readonly UsedConnectSecrets $usedSecrets;

    private ?BunkerSession $session = null;
    private ?BunkerQueueListenerInterface $queueListener = null;
    private ?Nip46ActivityListenerInterface $activityListener = null;
    private ?Nip46SecretListenerInterface $secretListener = null;

    /** @var list<Nip46SubscriptionInterface> */
    private array $subscriptions = [];

    public function __construct(
        private readonly Nip46ChannelInterface $channel,
        private readonly Nip46AuthenticatorInterface $authenticator,
        private readonly CapabilityGateInterface $gate,
    ) {
        $this->usedSecrets = new UsedConnectSecrets();
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
    public function setSecretListener(?Nip46SecretListenerInterface $listener): void
    {
        $this->secretListener = $listener;
    }

    #[Override]
    public function start(RelayUrlCollection $relays): void
    {
        if ($relays->isEmpty()) {
            throw new InvalidArgumentException('Cannot start a bunker session on an empty relay set');
        }

        $this->stop();

        $this->session = new BunkerSession($relays);

        $this->listenOn($relays);
    }

    #[Override]
    public function stop(): void
    {
        foreach ($this->subscriptions as $subscription) {
            $subscription->cancel();
        }

        $this->subscriptions = [];
        $this->session = null;
        $this->notifyQueueChanged();
    }

    #[Override]
    public function acceptNostrConnect(NostrConnectUrl $url): bool
    {
        $session = $this->session;

        if (null === $session || !$this->pair($url->getClientPubkey(), $url->getRelays())) {
            return false;
        }

        $this->respond($session, $url->getClientPubkey(), Nip46Response::result(RequestId::generate(), (string) $url->getSecret()));

        return true;
    }

    #[Override]
    public function restorePairing(PublicKey $client, RelayUrlCollection $relays): bool
    {
        return $this->pair($client, $relays);
    }

    private function pair(PublicKey $client, RelayUrlCollection $relays): bool
    {
        $session = $this->session;

        if (null === $session) {
            return false;
        }

        $session->recordRelays($client, $relays);
        $session->authenticate($client);

        $this->listenOn($session->startListeningOn($relays));

        return true;
    }

    private function listenOn(RelayUrlCollection $relays): void
    {
        if ($relays->isEmpty()) {
            return;
        }

        $this->subscriptions[] = $this->channel->listen($relays, null, $this);
    }

    #[Override]
    public function publicKey(): PublicKey
    {
        return $this->channel->publicKey();
    }

    #[Override]
    public function bunkerUrlFor(ConnectSecret $secret): ?BunkerUrl
    {
        if (null === $this->session || null !== $this->usedSecrets->usedBy($secret)) {
            return null;
        }

        return new BunkerUrl($this->channel->publicKey(), $this->session->getRelays(), $secret);
    }

    #[Override]
    public function getPending(): PendingRequestCollection
    {
        if (null === $this->session) {
            return new PendingRequestCollection();
        }

        return $this->session->pending()->sortedByReceivedAtDescending();
    }

    #[Override]
    public function approve(EventId $id): bool
    {
        return $this->answer($id, $this->gate->answer(...));
    }

    #[Override]
    public function reject(EventId $id): bool
    {
        return $this->answer($id, static fn (PendingRequest $request): Nip46Response => Nip46Response::failure($request->getRequestId(), 'user rejected'));
    }

    /**
     * @param callable(PendingRequest): Nip46Response $decide
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
        $decoded = $this->channel->open($event);

        if (null === $decoded) {
            return;
        }

        $session->recordCipher($peer, $decoded->getCipher());

        $request = Nip46Request::tryFromWire($decoded->getPayload());

        if (null === $request) {
            return;
        }

        $this->dispatch($session, new IncomingRequest($event->getId(), $peer, $request));
    }

    private function dispatch(BunkerSession $session, IncomingRequest $incoming): void
    {
        $clientPubkey = $incoming->getClientPubkey();
        $method = Nip46Method::tryFrom($incoming->getMethod());

        if ((null === $method || !$method->allowsUnauthenticated()) && !$session->isAuthenticated($clientPubkey)) {
            $this->respond($session, $clientPubkey, Nip46Response::failure($incoming->getId(), 'not connected'));

            return;
        }

        if (null === $method) {
            $this->respond($session, $clientPubkey, Nip46Response::failure($incoming->getId(), 'unsupported method: '.$incoming->getMethod()));

            return;
        }

        match ($method) {
            Nip46Method::Connect => $this->handleConnect($session, $incoming),
            Nip46Method::Ping => $this->answerNow($session, $incoming, Nip46Response::result($incoming->getId(), 'pong')),
            Nip46Method::SwitchRelays => $this->reportRelaySet($session, $incoming),
            Nip46Method::Logout => $this->handleLogout($session, $incoming),
            Nip46Method::GetPublicKey => $this->decide($session, $incoming, new GetPublicKeyDetail()),
            Nip46Method::SignEvent => $this->decide($session, $incoming, SignEventDetail::tryFromWire($incoming->param(0)) ?? Nip46Response::failure($incoming->getId(), 'invalid event')),
            Nip46Method::Nip44Encrypt, Nip46Method::Nip44Decrypt,
            Nip46Method::Nip04Encrypt, Nip46Method::Nip04Decrypt => $this->decide($session, $incoming, CipherDetail::tryFromWire($method, $incoming->param(0), $incoming->param(1)) ?? Nip46Response::failure($incoming->getId(), 'invalid params')),
        };
    }

    private function decide(BunkerSession $session, IncomingRequest $incoming, PendingRequestDetailInterface|Nip46Response $detail): void
    {
        $clientPubkey = $incoming->getClientPubkey();

        if ($detail instanceof Nip46Response) {
            $this->respond($session, $clientPubkey, $detail);

            return;
        }

        if (!$session->isAuthenticated($clientPubkey)) {
            $this->respond($session, $clientPubkey, Nip46Response::failure($incoming->getId(), 'not connected'));

            return;
        }

        $answer = $this->gate->answerOrDefer($incoming, $detail);

        if ($answer instanceof PendingRequest) {
            $this->queue($session, $answer);

            return;
        }

        $this->respond($session, $clientPubkey, $answer);
        $this->notify(BunkerActivity::forDetail($clientPubkey, $detail, $answer));
    }

    private function queue(BunkerSession $session, PendingRequest $pending): void
    {
        if (!$session->queue($pending)) {
            $this->respond($session, $pending->getClientPubkey(), Nip46Response::failure($pending->getRequestId(), 'too many pending requests'));

            return;
        }

        $this->notifyQueueChanged();
    }

    private function answerNow(BunkerSession $session, IncomingRequest $incoming, Nip46Response $response): void
    {
        $this->respond($session, $incoming->getClientPubkey(), $response);

        if ($session->isAuthenticated($incoming->getClientPubkey())) {
            $this->notify(BunkerActivity::forRequest($incoming, $response));
        }
    }

    // Deliberate: the signer's own relay set is reported, switching nothing — it is the migration hint a client-supplied relay set is answered with — see ADR-0018.
    private function reportRelaySet(BunkerSession $session, IncomingRequest $incoming): void
    {
        $relays = JsonWireFormat::encode($session->getRelays()->toStrings(), JsonWireFormat::MESSAGE);

        $this->answerNow($session, $incoming, Nip46Response::result($incoming->getId(), $relays));
    }

    private function handleLogout(BunkerSession $session, IncomingRequest $incoming): void
    {
        $this->answerNow($session, $incoming, Nip46Response::result($incoming->getId(), 'ack'));

        $session->deauthenticate($incoming->getClientPubkey());
    }

    private function handleConnect(BunkerSession $session, IncomingRequest $incoming): void
    {
        $clientPubkey = $incoming->getClientPubkey();
        $requestedSigner = $incoming->param(0);

        if (null !== $requestedSigner && '' !== $requestedSigner) {
            $parsed = PublicKey::tryFromHex($requestedSigner);

            if (null === $parsed || !$parsed->equals($this->channel->publicKey())) {
                $this->respond($session, $clientPubkey, Nip46Response::failure($incoming->getId(), 'invalid signer'));

                return;
            }
        }

        $secret = ConnectSecret::tryFromString($incoming->param(1) ?? '');
        $usedBy = null === $secret ? null : $this->usedSecrets->usedBy($secret);

        if (null !== $usedBy) {
            $this->reacknowledge($session, $incoming, $usedBy);

            return;
        }

        if (!$this->authenticator->authenticate($secret)) {
            $this->respond($session, $clientPubkey, Nip46Response::failure($incoming->getId(), 'invalid secret'));

            return;
        }

        $session->authenticate($clientPubkey);

        if (null !== $secret) {
            $this->usedSecrets->recordUse($secret, $clientPubkey);
        }

        $this->answerNow($session, $incoming, Nip46Response::result($incoming->getId(), 'ack'));

        if (null !== $secret) {
            $this->secretListener?->onSecretUsed($secret, $clientPubkey);
        }
    }

    // Deliberate: a used secret is ignored without a reply unless its own client, still connected, repeats it — see ADR-0023
    private function reacknowledge(BunkerSession $session, IncomingRequest $incoming, PublicKey $usedBy): void
    {
        $clientPubkey = $incoming->getClientPubkey();

        if (!$usedBy->equals($clientPubkey) || !$session->isAuthenticated($clientPubkey)) {
            return;
        }

        $this->answerNow($session, $incoming, Nip46Response::result($incoming->getId(), 'ack'));
    }

    private function notify(BunkerActivity $activity): void
    {
        $this->activityListener?->onActivity($activity);
    }

    private function respond(BunkerSession $session, PublicKey $clientPubkey, Nip46Response $response): void
    {
        $event = $this->seal($session, $clientPubkey, $response)
            ?? $this->degrade($session, $clientPubkey, $response);

        if (null === $event) {
            return;
        }

        $this->channel->publish($session->relaysFor($clientPubkey), $event);
    }

    // Deliberate: a result the cipher cannot carry is replaced by a small correlated error, never left to hang the waiting client — see ADR-0029
    private function degrade(BunkerSession $session, PublicKey $clientPubkey, Nip46Response $response): ?Event
    {
        if (null === $response->getResult()) {
            return null;
        }

        return $this->seal($session, $clientPubkey, Nip46Response::failure($response->getId(), 'response too large'));
    }

    private function seal(BunkerSession $session, PublicKey $clientPubkey, Nip46Response $response): ?Event
    {
        return $this->channel->seal($clientPubkey, $response->toJson(), $session->cipherFor($clientPubkey));
    }

    private function notifyQueueChanged(): void
    {
        $this->queueListener?->onQueueChanged();
    }
}
