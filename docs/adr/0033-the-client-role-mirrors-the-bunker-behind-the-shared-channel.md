# 33. The client role mirrors the bunker behind the shared channel

## Status

Accepted

Supersedes ADR-0007. Shipping both roles, the mutable client session, returned failures, a typed method per capability with no general method-caller, and request ids minted without an injected randomness port are carried forward unchanged. What is revised is the constructor, which takes the channel (ADR-0031) in place of the transport, session signer and clock, and the public surface, which has gained `resume()`, `getRelays()` and `logout()`. This record holds the complete current decision.

## Context

NIP-46 defines two symmetric roles over one wire format: the bunker, which holds a key and answers requests, and the client, which asks it to sign. A package that ships only one role has implemented half a protocol. Everything a client must do is host-independent: correlating responses to request ids and recognising `auth_url` challenges are protocol law; answering a legacy counterparty in the cipher it spoke is this package's own compatibility rule (ADR-0022); verifying that a returned event is authored by the connected identity and carries a valid signature is hardening the package applies for every host. Left to hosts, each one re-derives those rules and drifts; kept here, the two role implementations share one vocabulary and can be run against each other, which is the only direct test that the rules they embody actually agree.

The client role has one need the reactive bunker does not: it must *wait* for the response to a request it has published. That wait is a concurrency concern, and baking any one async runtime into the application service would couple every host to it.

ADR-0007 gave the client five constructor parameters: transport, session signer, signature verification, clock and pending-response awaiting. Three of them (transport, session signer, clock) served only the wire work the bunker does too, and that work is now the shared channel.

## Decision

`Nip46Client` is an application service mirroring `Nip46Bunker`:

- It speaks through a `Nip46ChannelInterface` (ADR-0031) whose signer is the client's session key, the same kind of channel the bunker uses, so any transport serves either role and a loopback pair of transports connects the two roles directly in tests.
- Session state (the cipher the signer last spoke, the relays in use, the connected user identity) lives in a mutable `ClientSession` entity, a state machine for the same reasons `BunkerSession` is one (ADR-0024).
- The constructor takes three collaborators: the channel, signature verification, and pending-response awaiting. How a host awaits a response is a concurrency concern behind its own driven port (ADR-0008).
- Anticipated protocol outcomes (timeout, a decline, any other signer error, malformed or forged responses, a request the cipher refuses) are returned as `Nip46Failure` values, never thrown; calling the client before `connect()` or `resume()` is a programmer fault and throws.
- The public surface is one typed method per operation: `connect()`, `resume()` (ADR-0026), `getRelays()`, `signEvent()`, the four `nip44Encrypt` / `nip44Decrypt` / `nip04Encrypt` / `nip04Decrypt` calls, `logout()`, and `close()`. Each takes domain types (a `PublicKey` peer, not a hex string; a `Rumour`, not a positional wire array) and returns its result or a `Nip46Failure`. The request-and-await mechanism they share is a *private* primitive, not a public general-purpose method-caller. A general `call(method, params)` would let a host drive `sign_event` directly and receive the raw event, silently bypassing the author-and-signature verification `signEvent()` exists to enforce. The asymmetry with the bunker, which dispatches every inbound method through a single enum `match`, is intended: the bunker *receives* an unknown method off the wire, while the client *sends* an operation it already knows at the call site.
- Request ids are the `RequestId` value object, minted by `RequestId::generate()`, which reads the entropy source directly in its named constructor: no behaviour of client or bunker depends on a specific id value, so injecting a randomness port would buy no test value.

## Consequences

- Hosts hold a `Nip46ClientInterface` and can substitute doubles; the shipped `Nip46Client` is the only implementation the package constructs.
- A host wires `new Nip46Client(new Nip46Channel($transport, $sessionSigner, $clock), $pendingResponses)`. (The `SignatureServiceInterface` was removed by ADR-0037: all signature verification now flows through the channel's `isAuthentic`.)
- Every public call returns its result or a `Nip46Failure`, forcing callers to handle failure in the type; hosts map `Nip46Failure` to their own exceptions or exit codes at their boundary.
- A host that needs a NIP-46 method with no typed wrapper (`ping`, or a future extension) has no public entry point. That is the accepted cost of not shipping a general method-caller that could bypass `signEvent()`; such a method earns its own typed wrapper when a host needs it, as `logout()` has.
- A host wanting granular status output must wrap the transport or listen at its own boundary; the client, like the bunker, does not log.
- The round-trip integration test drives the shipped client against the shipped bunker over a loopback transport pair with real cryptography; a change that breaks the roles' agreement fails the build.
