# innis/nostr-nip46

[![CI](https://github.com/johninnis/nostr-nip46/actions/workflows/ci.yml/badge.svg)](https://github.com/johninnis/nostr-nip46/actions/workflows/ci.yml)

**NIP-46 (Nostr Connect / "bunker") remote-signer protocol for PHP — both roles**

Both sides of [NIP-46](https://github.com/nostr-protocol/nips/blob/master/46.md):

- the **bunker** (`Nip46Bunker`) — a process that holds a key and answers `connect` / `get_public_key` / `sign_event` / `nip44_*` / `nip04_*` requests that Nostr apps send over a relay. A request the app was granted is answered straight away; anything else is held in a queue for an explicit approve/reject decision, so a policy layer or a human stays in the loop.
- the **client** (`Nip46Client`) — the app side that pairs with a `bunker://` URL, asks the remote signer for the user's public key, and has events signed on the user's behalf.

The two roles share one wire vocabulary (`Nip46Method`, `Nip46Request`/`Nip46Response`) and one channel (`Nip46Channel`: the transport port, the endpoint's key and the clock, which subscribes, seals, opens and publishes kind-24133 envelopes), and the test suite runs the shipped client against the shipped bunker over real cryptography.

The library performs no network I/O of its own: the relay transport arrives as an injected port, so it is relay-pool-agnostic and the protocol logic is tested entirely against in-memory doubles.

---

## Features

- **Bunker role** - subscribes for kind-24133 requests addressed to the user, authenticates each client's `connect` through an injected authenticator (one or many named secrets), and dispatches every NIP-46 method.
- **Client role** - connects to any `bunker://` URL (sending the host's client metadata with `connect`, then asking `switch_relays` and moving to the relays the signer names), resumes a stored session without connecting again, correlates responses to requests, verifies that a signed event is the one it asked for (same kind, `created_at`, content and tags), authored by the connected identity and carrying a valid signature, and surfaces `auth_url` challenges to a listener as a validated `AuthUrl` — only an `http(s)` URL with a host, judged on the string and handed over exactly as received, reaches the host, so a malicious bunker cannot hand it a `javascript:` or `file:` URI to open; with a listener registered the request then waits for the real reply through an approval window, five minutes by default and restarted by each further challenge, and with none it fails at once, carrying the URL, instead of timing out, and a challenge carrying anything other than a web URL fails the request at once without carrying it. Timeouts, declines, other signer errors, malformed, altered or forged responses, and a request the session cipher cannot carry come back as typed `Nip46Failure` values, never exceptions.
- **One-shot connection secrets** - a secret establishes one connection. A later `connect` presenting a used secret is answered `invalid secret`, exactly as a secret never issued is, unless it comes from the client that used it while that client is still connected, which is acknowledged again. A secret-used listener tells the host which client used which secret, so it can retire the secret and issue a fresh one.
- **Stale envelopes dropped** - both roles drop, without a reply and before decrypting, an envelope whose `created_at` is more than ten minutes from their clock, so a captured request replayed later is not executed once its id has left the bounded seen-id set. The window is published as `Nip46Channel::FRESHNESS_WINDOW_SECONDS`, so a host that re-subscribes after a reconnect can floor `since` to it rather than fetch envelopes the channel would drop.
- **Per-client attribution** - every queued request carries the public key of the client that sent it, so a host can label requests and apply its own grouping (an app, a device, a tenant) on top.
- **Per-client permissions** - an injected authoriser decides, per request, whether the connected client's grants cover it, using the spec's own `method[:params]` vocabulary as a `Permission` value object. A granted request is answered immediately; an ungranted one is **queued for the host to decide**, never refused.
- **Approval queue** - any request the host has not granted the connected client — `sign_event`, `get_public_key` or the `nip04_*` / `nip44_*` operations — is queued and answered only when the host calls `approve` or `reject`; a listener fires whenever the queue changes. Each queued request is identified by the id of the event that carried it, so concurrent clients reusing the same request id can never displace each other. The queue is bounded: past capacity a further request is answered with a `too many pending requests` error rather than queued silently.
- **Activity audit** - an optional activity listener is notified of every request the bunker answers on its own, with the method, the client it was answered for, the counterparty public key of a crypto operation, and an `Answered` / `Failed` outcome. Queued requests are excluded: the host records those at its own approve/reject decision.
- **NIP-44 with NIP-04 for legacy peers** - each envelope's cipher is read from its payload (a NIP-04 payload carries `?iv=`, a NIP-44 payload is base64 and cannot) and replies to that peer use the same cipher, in both roles. NIP-04 is unauthenticated and deprecated upstream; accepting it exists only for older counterparties.
- **`bunker://` and `nostrconnect://` URLs** - both pairing directions, parsed and formatted by value objects sharing one query parser. A bunker-initiated pairing starts from a `bunker://` URL the signer mints; a client-initiated one starts from a `nostrconnect://` URL the client mints, which the host hands to `acceptNostrConnect` — the bunker then listens on the client's own relays, echoes the URL's secret to it, and treats it as connected without any `connect` request. `restorePairing` re-establishes such a pairing after a restart.
- **Client-supplied relays** - a client-initiated pairing's relays are recorded per client and published to alongside the signer's own, so a client works whether or not it honours the `switch_relays` migration hint (which reports the signer's own set and switches nothing).
- **Client metadata is a hint, never a grant** - the `name` / `url` / `image` / `perms` of a `nostrconnect://` URL are parsed and handed to the host to display and decide on. The bunker never authorises anything from them, and the optional `perms` / metadata arguments to a `connect` request are ignored. The client role sends them when the host supplies a `ClientMetadata`.
- **Transport-agnostic** - the one relay-facing surface is the `Nip46TransportInterface` port, shared by both roles; wire it to any relay client (for example `innis/nostr-client`).
- **Ready-made signer** - `LocalNip46Signer` implements the signing port over `innis/nostr-core`'s crypto for a key held in process; supply your own implementation for hardware or custom key custody.
- **Clean architecture** - strict layer separation, domain objects from `innis/nostr-core`, PHPStan level 9.

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

**1. A signer** — `LocalNip46Signer` for a key held in process (for a self-hosted signer, the key decrypted from a NIP-49 `ncryptsec` at unlock), or your own `Nip46SignerInterface` implementation over whatever holds the key. The channel's signer is the remote-signer key the `bunker://` URL advertises; the capability gate's signer is the user's key. NIP-46 lets them differ; pass the same signer to both for the usual single-key bunker.

Implementing `Nip46SignerInterface` by hand carries five contracts:

- `publicKey()` returns the key the signer acts as.
- `sign(rumour)` returns the signed event, throwing if the key cannot sign.
- `verify(event)` answers whether the event is authentic — its id matches its serialised fields and its Schnorr signature verifies against that id, exactly as NIP-01 defines — and never throws: any internal failure is `false`. It needs none of the signer's key material; delegate to nostr-core's `SignatureServiceInterface` (`$event->verify($signatureService)`), which is what `LocalNip46Signer` does. Returning a constant here disables the inbound-envelope authentication of `docs/adr/0037` — do not.
- `encrypt(peer, plaintext, cipher)` returns the ciphertext, throwing on a payload the cipher refuses (empty or over-long).
- `decrypt(peer, ciphertext, cipher)` returns the plaintext, or `null` when the cipher refuses the ciphertext or the peer's key; an undecryptable inbound envelope is an anticipated outcome, not a fault. Convert only that refusal: a fault in the signer's own machinery is thrown, not returned as `null`, which is what `LocalNip46Signer` does (see `docs/adr/0029`).

**2. A transport** — implement `Nip46TransportInterface` to subscribe and publish on your relays.

**3. An authenticator** — implement `Nip46AuthenticatorInterface` to decide whether a client's `connect` secret (a `?ConnectSecret` — `null` when the client sent none) is currently valid. Accepting one secret per named pairing — one per app, one per device — is what lets you run many pairings off one signer; which secret mapped to which pairing stays in your store.

A secret is accepted once. After a `connect` succeeds with it, the bunker records the secret as used and answers any later `connect` that presents it `invalid secret`, exactly as it answers a secret never issued. The one exception is the client that used it: while that client is still connected it gets `ack` again. `bunkerUrlFor()` returns `null` for a used secret. The record of used secrets outlives `stop()` / `start()` but not the `Nip46Bunker` object, and it is bounded: past 10,000 secrets the oldest is forgotten. So the library enforces the rule only for as long as it remembers. Keeping it across a restart, or past eviction, is up to the host, which must:

- register a secret listener (`setSecretListener`), which receives the secret and the client's public key as soon as a secret is used;
- retire that secret in its own store so the authenticator stops accepting it, then issue a fresh one and pass it to `bunkerUrlFor()` when the user wants to pair another device;
- keep the client's public key if the pairing should survive a restart, and hand it to `restorePairing()` once the bunker has started, because the client cannot `connect` again with the secret it already used.

```php
use Innis\Nostr\Core\Domain\Collection\RelayUrlCollection;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Nip46\Application\Service\CapabilityGate;
use Innis\Nostr\Nip46\Application\Service\Nip46Bunker;
use Innis\Nostr\Nip46\Application\Service\Nip46Channel;

$clock = new SystemClock();
$bunker = new Nip46Bunker(
    new Nip46Channel($transport, $signer, $clock),       // speaks on the relays as the remote-signer key
    $authenticator,
    new CapabilityGate($signer, $authoriser, $clock),    // answers as the user, or queues for a decision
);

$bunker->setSecretListener($yourSecretStore);      // retire each secret as it is used
$bunker->start($relays);                            // $relays: RelayUrlCollection
echo $bunker->bunkerUrlFor($appSecret);             // $appSecret: ConnectSecret; bunker://<pubkey>?relay=...&secret=..., or null once used

// Or accept a URL the client minted:
$bunker->acceptNostrConnect(NostrConnectUrl::tryFromString($pasted));

// Queued requests carry the public key of the client that sent them: $request->getClientPubkey().
// Decide on them when they arrive:
$bunker->setQueueListener($yourPolicyOrUiListener);
foreach ($bunker->getPending() as $request) {
    // inspect $request->getDetail() — a SignEventDetail, CipherDetail or GetPublicKeyDetail — then:
    $bunker->approve($request->getId());            // or $bunker->reject($request->getId());
}

$bunker->stop();
```

**4. An authoriser** — implement `Nip46AuthoriserInterface` to say whether a connected client's grants cover a `Permission` (`sign_event:1`, `nip44_decrypt`, …). It decides *answer now* versus *ask the host*, never *refuse*: a request the client was not granted is queued for `approve` / `reject` exactly as a signing request is. `PermissionCollection::grantable()` lists every permission the bunker will ask about, and `fromPermsString` / `toPermsString` parse and render the spec's comma-separated form.

`connect`, `ping`, `switch_relays` (which reports the signer's own relay set) and `logout` carry no capability and are always answered. `get_public_key`, `sign_event` and the four `nip04_*` / `nip44_*` methods are answered when granted, and queued when not.

For an audit trail of what each connected client did, register an activity listener — the bunker's optional observability hook, mirroring `setQueueListener`:

```php
$bunker->setActivityListener($yourAuditSink); // notified as each autonomous request is answered
```

It receives a `BunkerActivity` for every request the bunker answers on its own, carrying the method name, the client's public key, the counterparty public key of a crypto operation, and an `Answered` / `Failed` outcome. A queued request never reaches it — record those where you decide them, at `approve` / `reject` (see `docs/adr/0021`).

---

## Connecting as a client

Provide a transport (the same port as the bunker), a throwaway session key, and an implementation of the pending-response port for your runtime — awaiting a reply is deliberately left to the host so the library carries no event-loop dependency (see `docs/adr/0008`). The port opens a slot per request, completes it from the reply, and restarts the timeout of its await when an `auth_url` challenge reaches your listener, so that timeout must be one you can restart rather than a fixed timer; a restart made before the await begins replaces the timeout it is then given. On amp, the implementation wraps a `DeferredFuture` and a timer it cancels and schedules again on a restart; a synchronous loopback needs only an array.

```php
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Infrastructure\Time\SystemClock;
use Innis\Nostr\Nip46\Application\Service\Nip46Channel;
use Innis\Nostr\Nip46\Application\Service\Nip46Client;
use Innis\Nostr\Nip46\Domain\Collection\PermissionCollection;
use Innis\Nostr\Nip46\Domain\Failure\Nip46Failure;
use Innis\Nostr\Nip46\Domain\ValueObject\BunkerUrl;
use Innis\Nostr\Nip46\Domain\ValueObject\ClientMetadata;
use Innis\Nostr\Nip46\Infrastructure\Crypto\LocalNip46Signer;

$client = new Nip46Client(
    new Nip46Channel(
        $transport,                                     // your Nip46TransportInterface
        LocalNip46Signer::create(PrivateKey::generate()), // ephemeral session key; also verifies inbound events
        new SystemClock(),
    ),
    $pendingResponses,                              // your Nip46PendingResponsesInterface
    // approvalWindowSeconds: 600.0,                 // optional: how long a challenged request waits, default 300.0
);

$client->setAuthUrlListener($yourAuthUrlListener);  // optional: surfaces auth_url challenges

$bunkerUrl = BunkerUrl::tryFromString($pastedUrl) ?? exit('bad bunker URL');
$metadata = new ClientMetadata('My Client', 'https://client.example', null, new PermissionCollection());
$userPublicKey = $client->connect($bunkerUrl, $metadata); // $metadata is optional

if ($userPublicKey instanceof Nip46Failure) {
    exit($userPublicKey->describe());
}

$signed = $client->signEvent($unsignedRumour);      // Event|Nip46Failure

$client->logout();                                  // or close() to drop the session without telling the signer
```

`connect()` performs the handshake and returns the user's public key. It sends `connect` with the URL's secret, plus the metadata's requested permissions and its `name` / `url` / `image` when you pass a `ClientMetadata`. It then sends `switch_relays`: if the signer answers with a relay list, every later request goes to those relays and responses are awaited there; if it answers `null`, an error or nothing usable, the client stays on the URL's relays and the connection still succeeds (see `docs/adr/0025`). Last it sends `get_public_key`.

`getRelays()` returns the relays the session uses: the URL's, or those `switch_relays` put in their place.

A `bunker://` secret connects once, so a client that restarts cannot `connect()` again with the same URL: the signer refuses it. To keep a session across runs, store the session key, the signer's public key, `getRelays()` and the user's public key after `connect()` succeeds, and next time build the client on the same session key and call `resume()` instead of `connect()`:

```php
$client->resume(new BunkerUrl($storedSignerPublicKey, $storedRelays, null), $storedUserPublicKey);
```

`resume()` sends nothing: no `connect`, no `switch_relays`, no `get_public_key`. It subscribes on the stored relays and the session is ready. If the signer has dropped the session since, the first request comes back as a rejection or a timeout, and the user needs to pair again (see `docs/adr/0026`).

`logout()` sends `logout`, waits for the signer's `"ack"`, and ends the session whatever the answer. It returns `null` on `ack`, or a `Nip46Failure` for any other answer, an error or a timeout. Deleting the stored client key is still your job, whatever it returns: NIP-46 says the client must delete it on logout whether or not the request was acknowledged.

`signEvent()` sends the bunker only the four template fields NIP-46 defines (`kind`, `created_at`, `tags`, `content`) and checks the returned event before handing it back: its author must be the connected user (`IdentityMismatch`), its kind, `created_at`, content and tags must be those of the `Rumour` you passed, tags compared in order and element by element (`TemplateMismatch`), and its id and signature must verify (`InvalidSignature`), checked in that order (see `docs/adr/0039`). Every anticipated outcome — timeout, the user declining, the signer answering with any other error, invalid or forged response, a request too large for the session cipher — is a returned `Nip46Failure` with a reason and a human-readable `describe()`. NIP-46 defines no error codes, so a bunker's `error` whose words say the user declined (`rejected`, `denied`, `cancel`, `canceled`, `cancelled`, as whole words in any case) is `Rejected`; any other error is `SignerError`.

A bunker that needs out-of-band authorisation answers with an `auth_url` challenge. Its URL is accepted only when it starts with `http://` or `https://` (scheme in any case), names a non-empty host with no credentials and at most a decimal port, and contains no whitespace, control character or backslash; it is handed over exactly as received (see `docs/adr/0013`). With a listener registered, a valid challenge goes to the listener and the request keeps waiting for the real reply, its timeout restarted as an approval window: five minutes by default (`Nip46ClientInterface::DEFAULT_APPROVAL_WINDOW_SECONDS`, changed by the constructor's `approvalWindowSeconds`), because a person approving in a page, perhaps on another device, takes longer than a request takes on the network. Each further challenge restarts the window, and a request still unanswered when it closes fails as `TimedOut`, as one the bunker never answers does at the call's own timeout, which still applies until a challenge arrives. Without one, the request fails at once as `SignerError` with the detail `bunker requires authentication: <url>`. A challenge whose URL fails the check fails the request at once, listener or not, as `SignerError` with the detail `bunker sent an authentication URL that is not a web URL`, and the listener is not called. Both of these failures are the client's own, so neither is ever read as a decline, whatever words the URL holds (see `docs/adr/0036`). A response or challenge answering no request the client is awaiting, such as one arriving after its request timed out, is ignored.

To have the remote signer encrypt or decrypt a payload as the user's identity, call one of the four typed cipher methods — each takes the counterparty's `PublicKey` and returns the resulting string (or a `Nip46Failure`):

```php
$ciphertext = $client->nip44Encrypt($peerPublicKey, 'gm');       // string|Nip46Failure
$plaintext  = $client->nip44Decrypt($peerPublicKey, $ciphertext); // string|Nip46Failure
// nip04Encrypt / nip04Decrypt exist for legacy counterparties.
```

The client offers one typed method per capability rather than a general `call(method, params)` escape hatch: that keeps `signEvent()` the only way to obtain a signed event, so the checks it performs cannot be bypassed (see `docs/adr/0033`).

Run the worked end-to-end example (both roles, real secp256k1 + NIP-44 crypto, no network):

```bash
php examples/remote_signer.php
```

---

## Architecture

- **Domain** - the protocol vocabulary (`Nip46Method`, `Permission`, `Nip46Request`, `Nip46Response`, `UnsignedEventInput`, `PendingRequest`, `BunkerUrl`, `EnvelopeCipher`, `Nip46Failure`), the `BunkerSession` and `ClientSession` state machines with their shared `SeenEventIds` redelivery guard, the `Nip46SignerInterface` capability, the subscription filter factory, the decline classifier `UserRejectionMatcher`, the `SignedTemplateMatcher` that holds a signed event to the template it was asked to sign, and the `EnvelopeCipherSuite`, which pairs nostr-core's `ConversationCipher` (NIP-44) and `Nip04ConversationCipher` (NIP-04).
- **Application** - the transport / listener / subscription / pending-response / auth-url / activity / secret ports, the `Nip46Channel` both roles speak through, the bunker's `CapabilityGate`, and the `Nip46Bunker` and `Nip46Client` orchestrators.
- **Infrastructure** - `LocalNip46Signer` (the signing port over `innis/nostr-core` crypto for an in-process key) and `Nip46EventHandler` (adapts `innis/nostr-core`'s relay event handler to the listener port). Network transport and response-awaiting stay host-supplied by design.

Design rationale lives in [`docs/adr/`](docs/adr).

---

## Development

```bash
composer test          # phpunit + phpstan (level 9)
composer check-style   # php-cs-fixer dry run
composer check-rector  # rector dry run
```
