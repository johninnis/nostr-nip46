# 7. The client role mirrors the bunker behind the shared transport port

## Status

Accepted

## Context

NIP-46 defines two symmetric roles over one wire format: the bunker, which holds a key and answers
requests, and the client, which asks it to sign. A package that ships only one role has implemented
half a protocol. Everything a client must do is host-independent: correlating responses to request
ids and recognising `auth_url` challenges are protocol law; falling back to the legacy envelope
cipher for an older counterparty is this package's own compatibility rule, recorded in its own right;
verifying that a returned event is authored by the connected identity and carries a valid signature
is hardening the package applies for every host. Left to hosts, each one re-derives those rules and drifts; kept here,
the two role implementations share one vocabulary and can be run against each other, which is the
only direct test that the rules they embody actually agree.

The client role has one need the reactive bunker does not: it must *wait* for the response to a
request it has published. That wait is a concurrency concern, and baking any one async runtime into
the application service would couple every host to it.

## Decision

`Nip46Client` is an application service mirroring `Nip46Bunker`:

- It is driven through the same `Nip46TransportInterface` the bunker uses, so any transport
  implementation serves either role, and a loopback pair of transports connects the two roles
  directly in tests.
- Session state (negotiated cipher, connected user identity) lives in a mutable `ClientSession`
  entity, a state machine for the same reasons `BunkerSession` is one (ADR-0001).
- The constructor takes five irreducible driven ports — transport, session signer, signature
  verification, clock, and pending-response awaiting — the same reasoning as the bunker's four
  (ADR-0006). How a host awaits a response is a concurrency concern behind its own driven port,
  recorded in its own right.
- Anticipated protocol outcomes — timeout, rejection, malformed or forged responses — are returned as
  `Nip46Failure` values, never thrown; calling the client before `connect()` is a programmer fault and
  throws.
- The public surface is one typed method per capability — `connect()`, `signEvent()`, and the four
  `nip44Encrypt` / `nip44Decrypt` / `nip04Encrypt` / `nip04Decrypt` calls — each taking domain types (a
  `PublicKey` peer, not a hex string; a `Rumour`, not a positional wire array) and returning its result
  or a `Nip46Failure`. The request-and-await mechanism they share is a *private* primitive, not a public
  general-purpose method-caller. This is deliberate: a general `call(method, params)` would let a host
  drive `sign_event` directly and receive the raw event, silently bypassing the author-and-signature
  verification `signEvent()` exists to enforce — the one guarantee this role adds. The asymmetry with the
  bunker, which dispatches every inbound method through a single enum `match`, is intended: the bunker
  *receives* an unknown method off the wire, while the client *sends* an operation it already knows at the
  call site, so a typed method reads better than passing the method back in as a value.
- Request ids are the `RequestId` value object, minted by `RequestId::generate()`, which reads the
  entropy source directly in its named constructor: no behaviour of client or bunker depends on a
  specific id value, so injecting a randomness port would buy no test value.

## Consequences

- Hosts hold a `Nip46ClientInterface` and can substitute doubles; the shipped `Nip46Client` is the
  only implementation the package constructs.
- Every public call returns its result or a `Nip46Failure`, forcing callers to handle failure in the
  type; hosts map `Nip46Failure` to their own exceptions or exit codes at their boundary.
- A host that needs a NIP-46 method with no typed wrapper (`ping`, `logout`, or a future extension) has
  no public entry point today. That is the accepted cost of not shipping a general method-caller that
  could bypass `signEvent()`; such a method earns its own typed wrapper if a host ever needs it.
- `Nip46Client::__construct` will read as an over-long argument list. Do not bundle the ports into a
  parameter object: they are five independent capabilities, not one concept.
- A host wanting granular status output must wrap the transport or listen at its own boundary; the
  client, like the bunker, does not log.
- The round-trip integration test drives the shipped client against the shipped bunker over a loopback
  transport pair with real cryptography; a change that breaks the roles' agreement fails the build.
