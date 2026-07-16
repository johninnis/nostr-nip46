# 8. Awaiting a response is a driven port and the package ships no runtime

## Status

Accepted

## Context

A NIP-46 client publishes a request and must suspend until the matching response event arrives or a
timeout passes. How to suspend is a property of the host's concurrency runtime, not of the protocol:
an event-driven host parks the task it is running, a test resolves instantly and deterministically, a
synchronous loopback completes before the wait even starts.

Shipping a default implementation would drag an event-loop dependency into a protocol package —
paid even by hosts that only run the bunker role, which is reactive and never awaits anything.

## Decision

Correlating and awaiting responses sits behind two driven ports: `Nip46PendingResponsesInterface`
(open a slot for a request id; complete a slot from an arriving response, ignoring unknown or
already-completed ids) and `Nip46PendingResponseInterface` (await with a timeout, yielding the
response or `null`). The package ships **no** implementation and carries no concurrency dependency;
each host implements the pair on its own runtime.

## Consequences

- The package's dependencies stay at `innis/nostr-core` alone.
- Every client host writes a small implementation on its own runtime; that duplication across hosts
  is accepted as the price of a runtime-agnostic package. If several hosts on the same runtime
  accumulate, the implementation belongs in a runtime-specific package, not here.
- An implementation must treat `complete()` as idempotent — duplicate response events from multiple
  relays and responses arriving after a timeout are normal — and a timed-out await must abandon its
  slot so a late response is ignored rather than completing a future no one holds.
- Package tests and the example drive `Nip46Client` with synchronous in-memory implementations; no
  event loop runs in this repository.
