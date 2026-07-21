# innis/nostr-nip46

[![CI](https://github.com/johninnis/nostr-nip46/actions/workflows/ci.yml/badge.svg)](https://github.com/johninnis/nostr-nip46/actions/workflows/ci.yml)

**NIP-46 (Nostr Connect / "bunker") remote-signer protocol for PHP — both roles**

Both sides of [NIP-46](https://github.com/nostr-protocol/nips/blob/master/46.md):

- the **bunker** (`Nip46Bunker`) — a process that holds a key and answers `connect` /
  `get_public_key` / `sign_event` / `nip44_*` / `nip04_*` requests that Nostr apps send over a relay.
  A request the app was granted is answered straight away; anything else is held in a queue for an
  explicit approve/reject decision, so a policy layer or a human stays in the loop.
- the **client** (`Nip46Client`) — the app side that pairs with a `bunker://` URL, asks the remote
  signer for the user's public key, and has events signed on the user's behalf.

The two roles share one wire vocabulary (`Nip46Method`, `Nip46Request`/`Nip46Response`, the envelope
codec, the subscription filter) and one transport port, and the test suite runs the shipped client
against the shipped bunker over real cryptography.

The library performs no network I/O of its own: the relay transport arrives as an injected port, so it
is relay-pool-agnostic and the protocol logic is tested entirely against in-memory doubles.

---

## Features

- **Bunker role** - subscribes for kind-24133 requests addressed to the user, authenticates each client's
  `connect` through an injected authenticator (one or many named secrets), and dispatches every NIP-46
  method.
- **Client role** - connects to any `bunker://` URL, correlates responses to requests, verifies that a
  signed event is authored by the connected identity and carries a valid signature, and surfaces
  `auth_url` challenges to a listener as a validated `AuthUrl` — only `http(s)` URLs reach the host, so
  a malicious bunker cannot hand it a `javascript:` or `file:` URI to open. Timeouts, rejections,
  malformed or forged responses, and a request the session cipher cannot carry come back as typed
  `Nip46Failure` values, never exceptions.
- **Per-app attribution** - the authenticator maps a secret to an opaque app id, and every queued request
  carries it, so a host can label requests and apply per-app policy.
- **Per-app permissions** - an injected authoriser decides, per request, whether an app's grants cover
  it, using the spec's own `method[:params]` vocabulary as a `Permission` value object. A granted
  request is answered immediately; an ungranted one is **queued for the host to decide**, never refused.
- **Approval queue** - any request the app was not granted — `sign_event`, `get_public_key` or the
  `nip04_*` / `nip44_*` operations — is queued and answered only when the host calls `approve` or
  `reject`; a listener fires whenever the queue changes. Each queued request is identified by the
  id of the event that carried it, so concurrent clients reusing the same request id can never
  displace each other. The queue is bounded: past capacity a further request is answered with a
  `too many pending requests` error rather than queued silently.
- **Activity audit** - an optional activity listener is notified of every request the bunker answers on
  its own, with the method, the `AppId` it was answered for, the counterparty public key of a crypto
  operation, and an `Answered` / `Failed` outcome. Queued requests are excluded: the host records those
  at its own approve/reject decision.
- **NIP-44 with NIP-04 fallback** - each peer's cipher is detected on first contact and reused for
  replies, in both roles. NIP-04 is unauthenticated and deprecated upstream; the fallback exists only for
  older counterparties.
- **`bunker://` and `nostrconnect://` URLs** - both pairing directions, parsed and formatted by value
  objects sharing one query parser. A bunker-initiated pairing starts from a `bunker://` URL the signer
  mints; a client-initiated one starts from a `nostrconnect://` URL the client mints, which the host
  hands to `acceptNostrConnect` — the bunker then listens on the client's own relays, echoes the URL's
  secret to it, and treats it as connected without any `connect` request. `restorePairing` re-establishes
  such a pairing after a restart.
- **Client-supplied relays** - a client-initiated pairing's relays are recorded per client and published
  to alongside the signer's own, so a client works whether or not it honours the `switch_relays`
  migration hint (which reports the signer's own set and switches nothing).
- **Client metadata is a hint, never a grant** - the `name` / `url` / `image` / `perms` of a
  `nostrconnect://` URL are parsed and handed to the host to display and decide on. The bunker never
  authorises anything from them, and the optional `perms` / metadata arguments to a `connect` request
  are ignored. The client role does not send them.
- **Transport-agnostic** - the one relay-facing surface is the `Nip46TransportInterface` port, shared by
  both roles; wire it to any relay client (for example `innis/nostr-client`).
- **Ready-made signer** - `LocalNip46Signer` implements the signing port over `innis/nostr-core`'s
  crypto for a key held in process; supply your own implementation for hardware or custom key custody.
- **Clean architecture** - strict layer separation, domain objects from `innis/nostr-core`, PHPStan
  level 9.

---

## Requirements

- PHP 8.4 or higher
- `innis/nostr-core` - core Nostr protocol entities and crypto interfaces

---

## Installation

```bash
composer require innis/nostr-nip46
```

---

## Running a bunker

Provide four things and the bunker handles the protocol.

**1. A signer** — `LocalNip46Signer` for a key held in process (for a self-hosted signer, the key
decrypted from a NIP-49 `ncryptsec` at unlock), or your own `Nip46SignerInterface` implementation over
whatever holds the key.

**2. A transport** — implement `Nip46TransportInterface` to subscribe and publish on your relays.

**3. An authenticator** — implement `Nip46AuthenticatorInterface` to validate a client's `connect`
secret (a `?ConnectSecret` — `null` when the client sent none) and return an opaque `AppId` (or `null`
to reject). Returning a distinct id per secret is what lets you run many named pairings off one signer
and attribute each request to an app.

Secret-reuse policy lives in your authenticator, not the library: a secret can be a durable per-app
pairing token or single-use (reject a secret once a connection has been established). `authenticate` is
called exactly at connection time and its non-`null` return is what establishes the connection, so
enforce whichever policy you want there against your own store — the library holds no secret state of
its own.

```php
use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Nip46\Application\Service\Nip46Bunker;

$bunker = new Nip46Bunker($transport, $signer, $authenticator, $authoriser, new SystemClock());

$bunker->start($relays);                            // $relays: RelayUrlCollection
echo $bunker->bunkerUrlFor($appSecret);             // $appSecret: ConnectSecret; bunker://<pubkey>?relay=...&secret=...

// Or accept a URL the client minted, pairing it to an app you decided on:
$bunker->acceptNostrConnect(NostrConnectUrl::tryFromString($pasted), $appId);

// Queued requests carry the app id the client authenticated as: $request->getAppId().
// Decide on them when they arrive:
$bunker->setQueueListener($yourPolicyOrUiListener);
foreach ($bunker->getPending() as $request) {
    // inspect $request->getDetail() — a SignEventDetail, CryptoDetail or GetPublicKeyDetail — then:
    $bunker->approve($request->getId());            // or $bunker->reject($request->getId());
}

$bunker->stop();
```

**4. An authoriser** — implement `Nip46AuthoriserInterface` to say whether an app's grants cover a
`Permission` (`sign_event:1`, `nip44_decrypt`, …). It decides *answer now* versus *ask the host*, never
*refuse*: a request the app was not granted is queued for `approve` / `reject` exactly as a signing
request is. `PermissionCollection::grantable()` lists every permission the bunker will ask about, and
`fromPermsString` / `toPermsString` parse and render the spec's comma-separated form.

`connect`, `ping`, `switch_relays` (which reports the signer's own relay set) and `logout` carry no
capability and are always answered. `get_public_key`, `sign_event` and the four `nip04_*` / `nip44_*`
methods are answered when granted, and queued when not.

For an audit trail of what each connected app did, register an activity listener — the bunker's optional
observability hook, mirroring `setQueueListener`:

```php
$bunker->setActivityListener($yourAuditSink); // notified as each autonomous request is answered
```

It receives a `BunkerActivity` for every request the bunker answers on its own, carrying the method
name, the resolved `AppId`, the counterparty public key of a crypto operation, and an `Answered` /
`Failed` outcome. A queued request never reaches it — record those where you decide them, at `approve` /
`reject` (see `docs/adr/0020`).

---

## Connecting as a client

Provide a transport (the same port as the bunker), a throwaway session key, and an implementation of
the pending-response port for your runtime — awaiting a reply is deliberately left to the host so the
library carries no event-loop dependency (see `docs/adr/0008`). On amp, the implementation is ~40
lines around a `DeferredFuture`; a synchronous loopback needs only an array.

```php
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Infrastructure\Crypto\Secp256k1Signer;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Nip46\Application\Service\Nip46Client;
use Innis\Nostr\Nip46\Domain\Failure\Nip46Failure;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Infrastructure\Crypto\LocalNip46Signer;

$client = new Nip46Client(
    $transport,                                     // your Nip46TransportInterface
    LocalNip46Signer::create(PrivateKey::generate()), // ephemeral session key
    Secp256k1Signer::create(),                      // verifies returned events
    new SystemClock(),
    $pendingResponses,                              // your Nip46PendingResponsesInterface
);

$client->setAuthUrlListener($yourAuthUrlListener);  // optional: surfaces auth_url challenges

$bunkerUrl = BunkerUrl::tryFromString($pastedUrl) ?? exit('bad bunker URL');
$userPublicKey = $client->connect($bunkerUrl);

if ($userPublicKey instanceof Nip46Failure) {
    exit($userPublicKey->describe());
}

$signed = $client->signEvent($unsignedRumour);      // Event|Nip46Failure

$client->close();
```

`connect()` performs the handshake (`connect` with the URL's secret, then `get_public_key`) and returns
the user's public key; `signEvent()` verifies the returned event's author and signature before handing
it back. Every anticipated outcome — timeout, rejection, invalid or forged response, a request too
large for the session cipher — is a returned `Nip46Failure` with a reason and a human-readable
`describe()`.

To have the remote signer encrypt or decrypt a payload as the user's identity, call one of the four
typed cipher methods — each takes the counterparty's `PublicKey` and returns the resulting string (or a
`Nip46Failure`):

```php
$ciphertext = $client->nip44Encrypt($peerPublicKey, 'gm');       // string|Nip46Failure
$plaintext  = $client->nip44Decrypt($peerPublicKey, $ciphertext); // string|Nip46Failure
// nip04Encrypt / nip04Decrypt exist for legacy counterparties.
```

The client offers one typed method per capability rather than a general `call(method, params)` escape
hatch: that keeps `signEvent()` the only way to obtain a signed event, so the author-and-signature check
it performs cannot be bypassed (see `docs/adr/0007`).

Run the worked end-to-end example (both roles, real secp256k1 + NIP-44 crypto, no network):

```bash
php examples/remote_signer.php
```

---

## Architecture

- **Domain** - the protocol vocabulary (`Nip46Method`, `Permission`, `Nip46Request`,
  `Nip46Response`, `UnsignedEventInput`, `PendingSignRequest`, `BunkerUrl`, `EnvelopeCipher`,
  `Nip46Failure`), the `BunkerSession` and `ClientSession` state machines with their shared
  `SeenEventIds` redelivery guard, the `Nip46SignerInterface` capability, the subscription filter
  factory, and the `Nip46EnvelopeCodec`.
- **Application** - the transport / listener / subscription / pending-response / auth-url / activity
  ports and the `Nip46Bunker` and `Nip46Client` orchestrators.
- **Infrastructure** - `LocalNip46Signer` (the signing port over `innis/nostr-core` crypto for an
  in-process key) and `Nip46EventHandler` (adapts `innis/nostr-core`'s relay event handler to the
  listener port). Network transport and response-awaiting stay host-supplied by design.

Design rationale lives in [`docs/adr/`](docs/adr).

---

## Development

```bash
composer test          # phpunit + phpstan (level 9)
composer check-style   # php-cs-fixer dry run
composer check-rector  # rector dry run
```
