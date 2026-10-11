# 24. The bunker session is a bounded, mutable state machine

## Status

Accepted

Supersedes ADR-0001. The mutable session entity, what it holds, and bounding everything in it are carried forward unchanged. What changes is what happens to an evicted client: now that a connect secret is accepted once (ADR-0023), it can no longer reconnect with its secret. This record holds the complete current decision.

## Context

A remote signer is a long-lived process. Between one inbound event and the next it has to remember things: which clients are authenticated, which event ids it has already handled (relays redeliver, and the same request can arrive from several relays at once), which cipher each client speaks, which relays each paired client listens on, and which requests are waiting for a human decision. None of that can be worked out from a single event. It exists only because earlier events were seen.

This sits awkwardly beside the default of immutable values that return new instances. A request handler that returned a new "signer" value for every event would make every caller (the relay subscription, the approval UI, the policy gate) pass the latest instance around, and they all watch the queue at the same time. The state really is shared and really is mutable.

ADR-0001 said an evicted client is no longer authenticated and simply reconnects with its secret, as it would after a bunker restart. Under ADR-0023 that is no longer true: a secret connects one client, and presenting it again is refused.

## Decision

The per-session state lives in one mutable entity, `BunkerSession`, created at `start` and discarded at `stop`. It holds the seen-id set, the authenticated-client map, the per-client cipher and relay maps, and the pending-request queue, and it exposes only named operations. `Nip46Bunker` holds at most one session and the live subscription handles. Everything else it depends on (its channel, authenticator and capability gate, ADR-0032) is injected and holds no session state. The record of used connect secrets belongs to `Nip46Bunker`, not the session, for the reason ADR-0023 gives.

Everything the bunker holds is bounded, because a long-running signer must not grow without limit. The seen-id set, the authenticated-client map, the per-client cipher and relay maps, and the used-secret record each forget their oldest entry past a fixed size.

An evicted client is no longer authenticated. It cannot connect again with the secret it already used, so it comes back only by a new pairing with a fresh secret, or by the host calling `restorePairing()` with its key. After a restart it is in the same position.

The pending queue must not evict, because a request dropped without a word leaves its client hanging. Instead it refuses: past its capacity, a further request is answered with a `too many pending requests` protocol error.

## Consequences

- The session is the one place that holds mutable protocol state. The value objects around it stay immutable, and the request-handling code reads as plain method calls on one object.
- A reader expecting every type here to be immutable will find one that is not. That is deliberate: the session models a lifecycle, not a value, and the bounds keep a process that never stops from growing.
- Eviction can forget an id seen long ago, so the seen-id set guards redelivery, not replay. A request replayed after its id is evicted is dropped by the ten-minute window (ADR-0040), not by the set.
- Eviction now costs a client more than a reconnect. At ten thousand authenticated clients this is theoretical too, and the host's `restorePairing()` is the way back.
- The queue is bounded by refusal, not eviction. A client past the capacity gets an explicit error, and the operator clears the queue by deciding requests. Making `queue` evict like the maps would bring back the silent hang the refusal prevents.
- Shared decisions: nostr-adrs ADR-0050 (the seen-id set), ADR-0051 (bounded state, and a full queue refuses rather than evicts) and ADR-0058 (the used-secret record).
