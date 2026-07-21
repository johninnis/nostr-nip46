# 18. switch_relays reports the signer's own relay set

## Status

Accepted

Supersedes ADR-0004.

## Context

A client may send `switch_relays` during its handshake and refuse to proceed until it receives a
non-error reply. The protocol defines the method's result as the list of relay URLs the signer is now
using (or `null`), and says the signer should stay in control of which relays a connection uses —
explicitly because a client-initiated pairing may name relays foreign to the signer's preferences.

ADR-0004 answered this by reporting the session's relay set, which was then fixed for the life of the
session: a truthful, spec-shaped reply that performed no change. That record anticipated its own
replacement — "if a future session ever needs to re-subscribe on a client-supplied relay set, this
becomes a real operation and this record is superseded".

That is now the case. A client-initiated pairing (ADR-0015) makes the bunker listen on relays the client
chose, and those relays are recorded per client and published to alongside the signer's own (ADR-0016).
The session is no longer a single fixed set, so "report the session's relay set" needs restating: which
set?

Reporting the union — the signer's relays plus that client's — would be an accurate account of where the
bunker will answer, but it is the wrong answer to this method. The method exists so the signer can steer
a client onto relays the signer prefers; replying with the client's own relays included tells it to stay
where it is.

## Decision

Carried forward from ADR-0004: `switch_relays` returns a JSON-stringified list of relay URLs and
switches nothing; the reply is truthful and of the exact type the method defines, and no re-subscription
is performed.

Restated for a session that is no longer single-set: the list reported is the **signer's own** relay set
— the relays the session was started with — not the per-client set the bunker also listens on and
publishes to. For a client that paired over its own relays, this is the migration hint the protocol
intends: it names where the signer would rather be talked to, and a client that honours it moves there.
A client that ignores it keeps working, because the bunker goes on answering on that client's relays too
(ADR-0016).

## Consequences

- Handshake-gating clients proceed with a reply of the exact type the method defines.
- A conforming client migrates onto the signer's relays after pairing, which is the protocol's stated
  intent and leaves the signer in control of its own connections.
- The reply is not the complete set of relays the bunker will answer on for that client. This is
  deliberate: it is an instruction about where to talk, not an inventory.
- The bunker still never re-subscribes in response to this method. Its relay set grows only when the
  host accepts or restores a pairing.
- A reader could expect `switch_relays` to perform a real switch. A fence at the call site points here,
  and a test asserts the reply is the signer's own set.
