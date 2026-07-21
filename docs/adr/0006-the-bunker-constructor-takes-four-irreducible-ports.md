# 6. The bunker constructor takes four irreducible ports

## Status

Superseded by ADR-0019

## Context

The bunker is the NIP-46 protocol endpoint. To answer a request it needs four capabilities from the
outside world, and each is a distinct driven port:

- a **transport**, to receive kind-24133 request envelopes and publish response envelopes over relays;
- a **signer**, to produce the user's public key, sign events, and encrypt/decrypt payloads as the
  user's identity;
- an **authenticator**, to decide whether a connecting client's secret is authorised and to which
  opaque app id it maps;
- a **clock**, to bound the incoming subscription to a signed clock-skew window (`since`) and to stamp
  each queued sign request with the moment it arrived.

So the constructor takes four parameters. More than three is a design signal: the usual remedy is to
split the unit, or to group cohesive arguments into a value object. A reviewer expects one of those
moves here, and its absence reads as a unit that has grown too large.

Neither remedy fits. The four are orthogonal concerns — I/O, key custody, authorisation policy, time —
with nothing cohesive to gather into a value object; bundling them would be the anti-pattern the count
is meant to catch, hiding four unrelated dependencies behind one opaque argument. Splitting the bunker
does not help either: the request path is a single cohesive pipeline — receive, decrypt, authenticate,
dispatch, respond — and every stage of it touches these ports, so a split would sever a pipeline that
belongs together and merely move the same four dependencies across a new seam. None of the four is
removable: the clock in particular is load-bearing, fixing the subscription window and the queue
timestamps, and is exercised against a fixed clock in the tests.

## Decision

Keep the four ports as four constructor parameters. Do not introduce a parameter object to reduce the
count, and do not split the bunker to spread the ports across more units. The constructor carries a
one-line fence pointing at this record so the count is not "corrected" later.

## Consequences

- The `innis.tooManyParameters` signal is deliberately silenced for this one constructor, by the fence
  comment rather than a project-wide ignore, so the exception is local and visible at the code.
- A future contributor who adds a fifth port should treat that as the real signal — the point at which
  the pipeline may genuinely have taken on a second responsibility — and revisit this record rather
  than extending the fence.
- The bunker's dependencies stay explicit and individually substitutable in tests (a fake transport,
  an in-memory signer, a fake authenticator, a fixed clock), which a bundling value object would have
  obscured.
