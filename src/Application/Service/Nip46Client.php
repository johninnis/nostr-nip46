<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Core\Application\Port\ClockInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Nip46\Application\Port\Nip46AuthUrlListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46PendingResponsesInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46TransportInterface;
use Innis\Nostr\Nip46\Domain\Entity\ClientSession;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\Factory\Nip46FilterFactory;
use Innis\Nostr\Nip46\Domain\Failure\Nip46Failure;
use Innis\Nostr\Nip46\Domain\Service\Nip46EnvelopeCodec;
use Innis\Nostr\Nip46\Domain\Service\Nip46SignerInterface;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Request;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use LogicException;
use Override;
use Throwable;

final class Nip46Client implements Nip46ClientInterface, Nip46EventListenerInterface
{
    private readonly Nip46EnvelopeCodec $codec;

    private ?ClientSession $session = null;
    private ?Nip46SubscriptionInterface $subscription = null;
    private ?Nip46AuthUrlListenerInterface $authUrlListener = null;

    // Deliberate: five irreducible driven ports, not a decomposable unit — see ADR-0007
    public function __construct(
        private readonly Nip46TransportInterface $transport,
        private readonly Nip46SignerInterface $sessionSigner,
        private readonly SignatureServiceInterface $signatureService,
        private readonly ClockInterface $clock,
        private readonly Nip46PendingResponsesInterface $pending,
    ) {
        $this->codec = new Nip46EnvelopeCodec($sessionSigner);
    }

    #[Override]
    public function connect(BunkerUrl $bunker, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): PublicKey|Nip46Failure
    {
        $this->close();

        $session = new ClientSession($bunker);
        $this->session = $session;

        $filter = Nip46FilterFactory::addressedTo(
            $this->sessionSigner->publicKey(),
            $this->clock->now(),
            $bunker->getRemoteSignerPubkey(),
        );
        $this->subscription = $this->transport->subscribe($filter, $bunker->getRelays(), $this);

        $connected = $this->request(Nip46Method::Connect, self::connectParams($session), $timeoutSeconds);

        if ($connected instanceof Nip46Failure) {
            $this->close();

            return $connected;
        }

        $result = $this->request(Nip46Method::GetPublicKey, [], $timeoutSeconds);

        if ($result instanceof Nip46Failure) {
            $this->close();

            return $result;
        }

        $userPublicKey = PublicKey::tryFromHex($result);

        if (!$userPublicKey instanceof PublicKey) {
            $this->close();

            return Nip46Failure::invalidResponse(Nip46Method::GetPublicKey);
        }

        $session->identify($userPublicKey);

        return $userPublicKey;
    }

    #[Override]
    public function signEvent(Rumour $unsigned, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): Event|Nip46Failure
    {
        $result = $this->request(Nip46Method::SignEvent, [$unsigned->toJson()], $timeoutSeconds);

        if ($result instanceof Nip46Failure) {
            return $result;
        }

        $signed = Event::tryFromJson($result);

        if (!$signed instanceof Event) {
            return Nip46Failure::invalidResponse(Nip46Method::SignEvent);
        }

        $userPublicKey = $this->session?->getUserPublicKey()
            ?? throw new LogicException('Client has no connected user identity; connect() first');

        if (!$signed->getPubkey()->equals($userPublicKey)) {
            return Nip46Failure::identityMismatch(Nip46Method::SignEvent);
        }

        if (!$signed->verify($this->signatureService)) {
            return Nip46Failure::invalidSignature(Nip46Method::SignEvent);
        }

        return $signed;
    }

    #[Override]
    public function nip44Encrypt(PublicKey $peer, string $plaintext, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string|Nip46Failure
    {
        return $this->request(Nip46Method::Nip44Encrypt, [$peer->toHex(), $plaintext], $timeoutSeconds);
    }

    #[Override]
    public function nip44Decrypt(PublicKey $peer, string $ciphertext, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string|Nip46Failure
    {
        return $this->request(Nip46Method::Nip44Decrypt, [$peer->toHex(), $ciphertext], $timeoutSeconds);
    }

    #[Override]
    public function nip04Encrypt(PublicKey $peer, string $plaintext, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string|Nip46Failure
    {
        return $this->request(Nip46Method::Nip04Encrypt, [$peer->toHex(), $plaintext], $timeoutSeconds);
    }

    #[Override]
    public function nip04Decrypt(PublicKey $peer, string $ciphertext, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string|Nip46Failure
    {
        return $this->request(Nip46Method::Nip04Decrypt, [$peer->toHex(), $ciphertext], $timeoutSeconds);
    }

    #[Override]
    public function setAuthUrlListener(?Nip46AuthUrlListenerInterface $listener): void
    {
        $this->authUrlListener = $listener;
    }

    #[Override]
    public function close(): void
    {
        $this->subscription?->cancel();
        $this->subscription = null;
        $this->session = null;
    }

    #[Override]
    public function onEvent(Event $event): void
    {
        $session = $this->session;

        // Deliberate: duplicates are dropped here, not left to the pending port's idempotence — an auth_url challenge bypasses that port — see ADR-0010.
        if (null === $session || !$session->rememberSeen($event->getId())) {
            return;
        }

        if (!$event->getPubkey()->equals($session->getRemoteSignerPubkey())) {
            return;
        }

        $decoded = $this->codec->open($session->getRemoteSignerPubkey(), (string) $event->getContent(), $session->getCipher());

        if (null === $decoded) {
            return;
        }

        $session->recordCipher($decoded->getCipher());

        $response = Nip46Response::tryFromWire($decoded->getPayload());

        if (!$response instanceof Nip46Response) {
            return;
        }

        if ($response->isAuthUrlChallenge()) {
            $url = $response->getAuthUrl();

            if (null !== $url) {
                $this->authUrlListener?->onAuthUrl($url);
            }

            return;
        }

        $this->pending->complete($response);
    }

    /**
     * @param list<string> $params
     */
    private function request(Nip46Method $method, array $params, float $timeoutSeconds): string|Nip46Failure
    {
        $session = $this->session
            ?? throw new LogicException('Client is not connected to a bunker; connect() first');

        $request = Nip46Request::generate($method->value, $params);

        // Deliberate: sealing encrypts a caller-supplied payload; a cipher fault is returned as a failure before any pending slot is opened — see ADR-0009.
        try {
            $event = $this->codec->seal($session->getRemoteSignerPubkey(), $request->toJson(), $session->getCipher());
        } catch (Throwable) {
            return Nip46Failure::encryptionFailed($method);
        }

        $pending = $this->pending->open($request->getId());

        $this->transport->publish($session->getRelays(), $event);

        $response = $pending->await($timeoutSeconds);

        if (!$response instanceof Nip46Response) {
            return Nip46Failure::timedOut($method);
        }

        $error = $response->getError();

        if (null !== $error) {
            return Nip46Failure::rejected($method, $error);
        }

        return $response->getResult()
            ?? Nip46Failure::invalidResponse($method);
    }

    /**
     * @return list<string>
     */
    private static function connectParams(ClientSession $session): array
    {
        $secret = $session->getSecret();
        $remoteSignerPubkey = $session->getRemoteSignerPubkey()->toHex();

        return null === $secret ? [$remoteSignerPubkey] : [$remoteSignerPubkey, (string) $secret];
    }
}
