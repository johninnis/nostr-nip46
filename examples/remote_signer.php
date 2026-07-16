<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Factory\RumourFactory;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Nip46\Application\Port\BunkerQueueListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46ActivityListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46AuthenticatorInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46EventListenerInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46PendingResponseInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46PendingResponsesInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46SubscriptionInterface;
use Innis\Nostr\Nip46\Application\Port\Nip46TransportInterface;
use Innis\Nostr\Nip46\Application\Service\Nip46Bunker;
use Innis\Nostr\Nip46\Application\Service\Nip46Client;
use Innis\Nostr\Nip46\Domain\Failure\Nip46Failure;
use Innis\Nostr\Nip46\Domain\ValueObject\AppId;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerActivity;
use Innis\Nostr\Nip46\Domain\ValueObject\ConnectSecret;
use Innis\Nostr\Nip46\Domain\ValueObject\Nip46Response;
use Innis\Nostr\Nip46\Domain\ValueObject\RequestId;
use Innis\Nostr\Nip46\Infrastructure\Crypto\LocalNip46Signer;

/** A toy wire: each side's published events land straight in the other side's listener. */
$wire = new class {
    public ?Nip46EventListenerInterface $bunkerListener = null;

    public ?Nip46EventListenerInterface $clientListener = null;
};

$transport = static function (Closure $register, Closure $deliver): Nip46TransportInterface {
    return new class($register, $deliver) implements Nip46TransportInterface {
        public function __construct(
            private readonly Closure $register,
            private readonly Closure $deliver,
        ) {
        }

        #[Override]
        public function subscribe(Filter $filter, RelayUrlCollection $relays, Nip46EventListenerInterface $listener): Nip46SubscriptionInterface
        {
            ($this->register)($listener);

            return new class implements Nip46SubscriptionInterface {
                #[Override]
                public function cancel(): void
                {
                }
            };
        }

        #[Override]
        public function publish(RelayUrlCollection $relays, Event $event): void
        {
            ($this->deliver)($event);
        }
    };
};

$bunkerTransport = $transport(
    static function (Nip46EventListenerInterface $listener) use ($wire): void {
        $wire->bunkerListener = $listener;
    },
    static function (Event $event) use ($wire): void {
        $wire->clientListener?->onEvent($event);
    },
);

$clientTransport = $transport(
    static function (Nip46EventListenerInterface $listener) use ($wire): void {
        $wire->clientListener = $listener;
    },
    static function (Event $event) use ($wire): void {
        $wire->bunkerListener?->onEvent($event);
    },
);

$clock = new SystemClock();
$relay = RelayUrl::tryFromString('wss://relay.example.com') ?? exit("bad relay\n");
$secret = ConnectSecret::tryFromString('topsecret') ?? exit("bad secret\n");

$authenticator = new class($secret) implements Nip46AuthenticatorInterface {
    public function __construct(private readonly ConnectSecret $secret)
    {
    }

    #[Override]
    public function authenticate(?ConnectSecret $secret): ?AppId
    {
        return null !== $secret && $secret->equals($this->secret) ? AppId::fromString('demo-app') : null;
    }
};

$bunker = new Nip46Bunker($bunkerTransport, LocalNip46Signer::create(PrivateKey::generate()), $authenticator, $clock);
$bunker->start(new RelayUrlCollection([$relay]));

/* A stand-in operator that approves every queued signing request on sight. */
$bunker->setQueueListener(new class($bunker) implements BunkerQueueListenerInterface {
    public function __construct(private readonly Nip46Bunker $bunker)
    {
    }

    #[Override]
    public function onQueueChanged(): void
    {
        foreach ($this->bunker->getPending() as $request) {
            echo 'operator approving request '.$request->getId().PHP_EOL;
            $this->bunker->approve($request->getId());
        }
    }
});

/* An audit sink that prints every request the bunker answers on its own (sign_event is not reported here). */
$bunker->setActivityListener(new class implements Nip46ActivityListenerInterface {
    #[Override]
    public function onActivity(BunkerActivity $activity): void
    {
        echo 'activity: '.$activity->getMethod().' for '.$activity->getAppId().' -> '.$activity->getOutcome()->name.PHP_EOL;
    }
});

$bunkerUrl = $bunker->bunkerUrlFor($secret) ?? exit("bunker not started\n");
echo 'Bunker URL to paste into a client:'.PHP_EOL.'  '.$bunkerUrl.PHP_EOL.PHP_EOL;

/** The loopback wire answers synchronously, so awaiting is a simple in-memory lookup. */
$pendingResponses = new class implements Nip46PendingResponsesInterface {
    /** @var array<string, Nip46Response> */
    public array $completed = [];

    #[Override]
    public function open(RequestId $requestId): Nip46PendingResponseInterface
    {
        return new class($this, (string) $requestId) implements Nip46PendingResponseInterface {
            /**
             * @param object{completed: array<string, Nip46Response>} $responses
             */
            public function __construct(
                private readonly object $responses,
                private readonly string $requestId,
            ) {
            }

            #[Override]
            public function await(float $timeoutSeconds): ?Nip46Response
            {
                return $this->responses->completed[$this->requestId] ?? null;
            }
        };
    }

    #[Override]
    public function complete(Nip46Response $response): void
    {
        $this->completed[(string) $response->getId()] = $response;
    }
};

$client = new Nip46Client(
    $clientTransport,
    LocalNip46Signer::create(PrivateKey::generate()),
    Secp256k1Signer::create(),
    $clock,
    $pendingResponses,
);

$userPublicKey = $client->connect($bunkerUrl, timeoutSeconds: 2.0);

if ($userPublicKey instanceof Nip46Failure) {
    exit('connect failed: '.$userPublicKey->describe().PHP_EOL);
}

echo 'connected; signing as '.$userPublicKey->toHex().PHP_EOL;

$signed = $client->signEvent(RumourFactory::createTextNote($userPublicKey, 'gm from a remote device'), timeoutSeconds: 2.0);

if ($signed instanceof Nip46Failure) {
    exit('signing failed: '.$signed->describe().PHP_EOL);
}

echo 'signed event '.$signed->getId()->toHex().PHP_EOL;
echo 'signature verifies: '.($signed->verify(Secp256k1Signer::create()) ? 'yes' : 'no').PHP_EOL;

$client->close();
$bunker->stop();
