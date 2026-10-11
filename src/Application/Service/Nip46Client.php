<?php

declare(strict_types=1);

namespace Innis\Nostr\Nip46\Application\Service;

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Service\JsonWireFormat;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Rumour;
use Innis\Nostr\Nip46\Application\Port\Nip46AuthUrlListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46PendingResponsesInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Innis\Nostr\Nip46\Domain\Entity\ClientSession;
use Innis\Nostr\Nip46\Domain\Enum\Nip46Method;
use Innis\Nostr\Nip46\Domain\Failure\Nip46Failure;
use Innis\Nostr\Nip46\Domain\Service\SignedTemplateMatcher;
use Innis\Nostr\Nip46\Domain\Service\UserRejectionMatcher;
use Innis\Nostr\Nip46\Domain\ValueObject\AuthUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ClientMetadata;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Request;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\UnsignedEventInput;
use LogicException;
use Override;

final class Nip46Client implements Nip46ClientInterface, Nip46EventListenerInterface
{
    private const string AUTHENTICATION_REQUIRED = 'bunker requires authentication: ';
    private const string NOT_A_WEB_URL = 'bunker sent an authentication URL that is not a web URL';

    private ?ClientSession $session = null;
    private ?Nip46SubscriptionInterface $subscription = null;
    private ?Nip46AuthUrlListenerInterface $authUrlListener = null;

    public function __construct(
        private readonly Nip46ChannelInterface $channel,
        private readonly Nip46PendingResponsesInterface $pending,
    ) {
    }

    #[Override]
    public function connect(BunkerUrl $bunker, ?ClientMetadata $metadata = null, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): PublicKey|Nip46Failure
    {
        $this->close();

        $session = new ClientSession($bunker);
        $this->session = $session;
        $this->listen($session);

        $connected = $this->request(Nip46Method::Connect, self::connectParams($session, $metadata), $timeoutSeconds);

        if ($connected instanceof Nip46Failure) {
            $this->close();

            return $connected;
        }

        $this->switchRelays($session, $timeoutSeconds);

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

    // Deliberate: a resumed session sends nothing — no connect, switch_relays or get_public_key — see ADR-0026
    #[Override]
    public function resume(BunkerUrl $bunker, PublicKey $userPublicKey): void
    {
        $this->close();

        $session = new ClientSession($bunker);
        $session->identify($userPublicKey);
        $this->session = $session;
        $this->listen($session);
    }

    #[Override]
    public function getRelays(): RelayUrlCollection
    {
        return $this->session?->getRelays()
            ?? throw new LogicException('Client is not connected to a bunker; connect() or resume() first');
    }

    #[Override]
    public function signEvent(Rumour $unsigned, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): Event|Nip46Failure
    {
        $result = $this->request(Nip46Method::SignEvent, [UnsignedEventInput::fromRumour($unsigned)->toJson()], $timeoutSeconds);

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

        if (!SignedTemplateMatcher::matches($unsigned, $signed)) {
            return Nip46Failure::templateMismatch(Nip46Method::SignEvent);
        }

        if (!$this->channel->isAuthentic($signed)) {
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
    public function logout(float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): ?Nip46Failure
    {
        try {
            $result = $this->request(Nip46Method::Logout, [], $timeoutSeconds);
        } finally {
            $this->close();
        }

        if ($result instanceof Nip46Failure) {
            return $result;
        }

        return 'ack' === $result ? null : Nip46Failure::invalidResponse(Nip46Method::Logout);
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

        if (null === $session) {
            return;
        }

        if (!$event->getPubkey()->equals($session->getRemoteSignerPubkey())) {
            return;
        }

        // Deliberate: the signature is verified before the age is judged, the id remembered or the payload decrypted — it is what binds created_at and the event id a replay would freshen — see ADR-0037 and ADR-0040.
        // Deliberate: duplicates are dropped here, not left to the pending port's idempotence — an auth_url challenge bypasses that port — see ADR-0010.
        if (!$this->channel->isAuthentic($event) || !$this->channel->isFresh($event) || !$session->rememberSeen($event->getId())) {
            return;
        }

        $decoded = $this->channel->open($event);

        if (null === $decoded) {
            return;
        }

        $session->recordCipher($decoded->getCipher());

        $response = Nip46Response::tryFromWire($decoded->getPayload());

        if (!$response instanceof Nip46Response || !$session->isAwaiting($response->getId())) {
            return;
        }

        if ($response->isAuthUrlChallenge()) {
            $this->challenge($response);

            return;
        }

        $this->pending->complete($response);
    }

    // Deliberate: only a web URL reaches a registered listener and the request waits; any other challenge completes the request, which fails at once — see ADR-0013 and ADR-0036
    private function challenge(Nip46Response $challenge): void
    {
        $url = $challenge->getAuthUrl();

        if (null !== $url && null !== $this->authUrlListener) {
            $this->authUrlListener->onAuthUrl($url);

            return;
        }

        $this->pending->complete($challenge);
    }

    private function listen(ClientSession $session): void
    {
        $this->subscription = $this->channel->listen($session->getRelays(), $session->getRemoteSignerPubkey(), $this);
    }

    // Deliberate: a failed or empty switch_relays answer keeps the relays in use and does not fail the connection — see ADR-0025
    private function switchRelays(ClientSession $session, float $timeoutSeconds): void
    {
        $result = $this->request(Nip46Method::SwitchRelays, [], $timeoutSeconds);

        if ($result instanceof Nip46Failure) {
            return;
        }

        $relays = JsonWireFormat::decode($result);

        if (!is_array($relays) || !array_is_list($relays) || !$session->adoptRelays(RelayUrlCollection::fromStrings($relays))) {
            return;
        }

        $this->subscription?->cancel();
        $this->listen($session);
    }

    /**
     * @param list<string> $params
     */
    private function request(Nip46Method $method, array $params, float $timeoutSeconds): string|Nip46Failure
    {
        $session = $this->session
            ?? throw new LogicException('Client is not connected to a bunker; connect() first');

        $request = Nip46Request::generate($method->value, $params);

        // Deliberate: a request its cipher refuses is a returned failure, sealed before any pending slot is opened — see ADR-0028
        $event = $this->channel->seal($session->getRemoteSignerPubkey(), $request->toJson(), $session->getCipher());

        if (null === $event) {
            return Nip46Failure::encryptionFailed($method);
        }

        $pending = $this->pending->open($request->getId());
        $session->awaitResponseTo($request->getId());

        try {
            $this->channel->publish($session->getRelays(), $event);

            $response = $pending->await($timeoutSeconds);
        } finally {
            $session->stopAwaiting($request->getId());
        }

        if (!$response instanceof Nip46Response) {
            return Nip46Failure::timedOut($method);
        }

        if ($response->isAuthUrlChallenge()) {
            return self::unopenedChallenge($method, $response->getAuthUrl());
        }

        $error = $response->getError();

        if (null !== $error) {
            return UserRejectionMatcher::matches($error)
                ? Nip46Failure::rejected($method, $error)
                : Nip46Failure::signerError($method, $error);
        }

        return $response->getResult()
            ?? Nip46Failure::invalidResponse($method);
    }

    // Deliberate: the client writes this failure itself, so the decline classifier never reads it, whatever words the URL holds — see ADR-0036
    private static function unopenedChallenge(Nip46Method $method, ?AuthUrl $url): Nip46Failure
    {
        return Nip46Failure::signerError($method, null === $url ? self::NOT_A_WEB_URL : self::AUTHENTICATION_REQUIRED.$url);
    }

    /**
     * @return list<string>
     */
    private static function connectParams(ClientSession $session, ?ClientMetadata $metadata): array
    {
        $params = [
            $session->getRemoteSignerPubkey()->toHex(),
            (string) $session->getSecret(),
            $metadata?->getRequested()->toPermsString() ?? '',
            $metadata?->toJson() ?? '',
        ];

        while ('' === end($params)) {
            array_pop($params);
        }

        return $params;
    }
}
