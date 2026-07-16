# 1. The bunker session is a mutable state machine

## Status

Accepted

## Context

A remote signer is a long-lived process. While it is serving, it must remember things between
independent inbound events: which clients have presented the pairing secret, which event ids it has
already handled (relays redeliver, and the same request may arrive from several relays at once), which
cipher each client speaks, and which `sign_event` requests are waiting for a human decision. None of
that can be recomputed from a single event; it only exists because earlier events were seen.

This sits awkwardly beside the default of immutable values transformed by returning new instances. A
request handler that returned a new "signer" value per event would force every caller — the relay
subscription, the approval UI, the policy gate — to thread the latest instance around, and they all
observe the queue concurrently. The state is genuinely shared and genuinely mutable.

## Decision

The per-session state lives in one mutable entity, `BunkerSession`, created at `start` and discarded at
`stop`. It owns the seen-id set, the authenticated-client set, the per-client cipher map, and the
pending-request queue, and exposes only named operations (`rememberSeen`, `authenticate`,
`recordCipher`, `queue`, `take`). The orchestrating `Nip46Bunker` holds at most one session plus the
live subscription handle; everything else it depends on (transport, signer, clock) is immutable and
injected.

Everything the session holds is bounded, because a long-running signer must not grow without limit.
The seen-id set, the per-client cipher map and the authenticated-client map evict their oldest entry
past a fixed size; an evicted client is simply no longer authenticated and reconnects with its
secret, exactly as it would after a bunker restart. The pending queue must not evict — a silently
dropped request would hang the client awaiting it — so it refuses instead: past its capacity, a
further `sign_event` is answered with a `too many pending requests` protocol error.

## Consequences

- The session is the single place that holds mutable protocol state; the value objects around it stay
  immutable, and the request-handling code reads as plain method calls against one object.
- A reader expecting every type here to be immutable will find one that is not. That is deliberate: the
  session models a lifecycle, not a value, and the eviction bound is what keeps an unbounded process
  honest.
- Eviction can in principle forget an id seen long ago; at ten thousand entries the redelivery window
  this protects against has closed, so the risk is theoretical.
- The queue is bounded by refusal, not eviction: a client past the capacity receives an explicit
  error rather than a hang, and the operator drains the queue by deciding requests. A reader tempted
  to make `queue` evict like the maps do would reintroduce the silent hang the refusal exists to
  prevent.
