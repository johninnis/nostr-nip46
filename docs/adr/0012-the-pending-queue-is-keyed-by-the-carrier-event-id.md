# 12. The pending queue is keyed by the carrier event id

## Status

Accepted

## Context

A queued `sign_event` request must be addressable later — the operator approves or rejects it — and
the obvious key is the request's own `id`: the client chose it, the response must echo it, and it is
already in hand.

That id is only unique per client. NIP-46 lets each client mint request ids however it likes, and
sequential ids (`"1"`, `"2"`, …) are common in deployed clients. A bunker serves several
authenticated clients concurrently; keyed by the client-chosen id, two clients using the same id
would silently overwrite each other in the queue — the earlier request vanishes unanswered, its
client hangs until it times out, and the operator never sees it. The clients cannot avoid the
collision: payloads are encrypted, so neither can see the other's ids, and no discipline on their
side can prevent it.

Every request, however, arrives in its own kind-24133 carrier event, whose id is a hash — unique by
construction — and already deduplicated by the session's seen-id set before dispatch.

## Decision

A pending sign request's identity is the id of the carrier event that delivered it, held as an
`EventId`. The queue is keyed by it, and `approve`, `reject` and `findById` address it. The
client-chosen request id — a `RequestId` — is carried alongside solely to correlate the response
envelope; it identifies nothing inside the bunker.

## Consequences

- Concurrent clients — and a client reusing one of its own ids — can never displace each other's
  queued requests; every delivered `sign_event` is queued and answered independently.
- `PendingSignRequest` carries two ids, and a reader will be tempted to merge them. The split is the
  point — one is the queue identity, the other is wire correlation — and the fence at the value
  object points here, backed by a test that queues two requests sharing a request id.
- The response a client receives still carries exactly the id it chose; nothing about this decision
  is visible on the wire.
- A redelivered carrier event is dropped by the seen-id set before it reaches the queue, so keying by
  carrier id cannot double-queue a redelivery.
