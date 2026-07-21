# 16. A client's relays are recorded per client and published to alongside the session's

## Status

Accepted

## Context

A session used to hold one relay set, fixed when it started: the bunker subscribed there and published
every response there. That is sound while every client reaches the signer over relays the signer chose.

A client-initiated pairing (ADR-0015) breaks that assumption. The URI names the relays the *client* is
listening on, and they need not overlap the signer's set at all — the protocol says as much, and adds
that a client should ask `switch_relays` on connecting and migrate to whatever the signer answers.

That "should" is the whole difficulty. If the bunker published only to the client's relays, a client
that migrated would stop hearing responses. If it published only to its own, a client that never asked
would never hear one. And if the session simply replaced its relay set with each new pairing, the
clients already talking to it over the old set would go silent.

Widening the session's single set to the union of every pairing's relays would keep everyone reachable,
but it would also send every response for every client to every relay any client ever named, and it
would leak those relays into the `bunker://` URLs and `switch_relays` answers the signer advertises.

## Decision

Relays are recorded **per client**, beside the app id and cipher the session already tracks per client.
`relaysFor(client)` answers the session's own relays merged with that client's recorded set, and every
response is published there; a client with no recorded relays is answered exactly as before, on the
session's own set.

The session separately tracks which relays it is already listening on, and a pairing subscribes only on
the relays not yet covered — so two pairings naming one relay produce one subscription, and the
signer's own relays are never subscribed twice.

The session's own relay set stays what it was started with. It is what `bunker://` URLs advertise and
what `switch_relays` reports (ADR-0018).

## Consequences

- A client is reachable whether or not it honours the migration hint: responses go to its relays and to
  the signer's, for as long as the pairing lives.
- A response is published to a bounded set — one client's relays plus the signer's — not to the union of
  every pairing's, so an unrelated pairing's relays never carry another client's traffic.
- The bunker holds more than one subscription, and `stop` must cancel all of them. It does.
- Per-client relay sets are bounded like the other per-client state; the oldest entry is evicted past
  the limit, after which that client is answered on the signer's own relays only.
- A reader may expect one relay set per session, as this package had. The set is now per client by
  construction, and the tests assert both halves: a paired client is answered on the union, an unpaired
  one on the session's own.
