# 10. The client session deduplicates redelivered events

## Status

Accepted

## Context

A session listens on several relays, and the same response event routinely arrives once per relay.
For correlated responses this is already harmless: the pending-responses port must treat `complete()`
as idempotent (ADR-0008), so a duplicate completion of an answered request id is ignored.

One inbound path bypasses that port. An `auth_url` challenge is not correlated into a pending slot —
it goes straight to the host's auth-url listener. Without deduplication, a challenge delivered over N
relays is surfaced to the user N times for one authorisation. The bunker already solves the identical
problem for inbound requests with a session-held seen-id set (ADR-0001); the client role had no
equivalent, so its dedup guarantee silently ended at the pending port's edge.

## Decision

`ClientSession` remembers seen event ids exactly as `BunkerSession` does, through one shared
`SeenEventIds` entity holding the bounded, oldest-evicted id set for both roles. `Nip46Client` drops
an already-seen event before decrypting it, so every downstream path — response completion and
auth-url notification alike — observes each event once per session.

`Nip46PendingResponsesInterface::complete()` must remain idempotent regardless: a response arriving
after its request timed out, or after the seen-set evicted an old id, still reaches the port.

## Consequences

- The auth-url listener fires once per unique challenge event, however many relays redeliver it. A
  bunker that deliberately re-issues a challenge publishes a new event with a new id, which still
  reaches the listener.
- Deduplication logic exists once, in `SeenEventIds`; neither session re-implements the set or its
  eviction bound.
- The check sits before decryption, so redelivered ciphertext is not decrypted N times.
- The call site carries a one-line fence pointing here, and a test delivers the same challenge event
  twice and asserts a single notification, so the check cannot be removed as redundant with the
  port's idempotence without breaking the build.
