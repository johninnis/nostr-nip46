# 4. switch_relays reports the fixed relay set

## Status

Accepted

## Context

A client may send `switch_relays` during its handshake and refuse to proceed until it receives a
non-error reply. The protocol defines the method's result as the list of relay URLs the signer is now
using (or `null`). The bunker's relay set, however, is fixed when the session is created at `start`, and
there is no mechanism — by design — to change which relays a running session listens on.

That leaves the question of what to answer. Replying with an error is accurate about "I did not switch",
but it stalls the very clients that gate their handshake on the method, for a capability the bunker does
not need to offer: the request already reached the bunker over a relay both parties share, so nothing has
to change for the conversation to continue. Inventing an `"ack"` the result type does not define would be
a reply the client cannot use as the spec says it may — and reads, at the call site, like a stub.

## Decision

`switch_relays` returns the session's current relay set — the JSON-stringified list of relay URLs the
bunker is listening on — and switches nothing. Because the set is fixed, the reply is simply the relays
the session was started with: a truthful, spec-shaped answer that performs no change.

## Consequences

- Handshake-gating clients proceed with a reply of the exact type the method defines, and the bunker
  keeps a single, fixed relay set per session.
- The result is the genuine relay set, not a sentinel — so there is nothing here that reads as a stub
  pretending to work; the method answers what it is asked, it just has nothing to change.
- A reader could still expect `switch_relays` to re-subscribe on a new set and "fix" this into a real
  switch. A one-line fence at the call site points here, and a test asserts the reply is the session's
  relay set, so the behaviour cannot be changed silently.
- If a future session ever needs to re-subscribe on a client-supplied relay set, this becomes a real
  operation and this record is superseded rather than edited.
