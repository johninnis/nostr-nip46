# 17. An ungranted request is queued for a decision, never refused

## Status

Accepted

## Context

The bunker answered `get_public_key` and the four `nip04_*` / `nip44_*` methods for any connected
client, unconditionally, and queued only `sign_event` for a decision. So a host could decide *whether
to sign*, but not *whether to decrypt* — a connected app could read any message the user could read,
with no moment at which anyone was asked.

The protocol has a vocabulary for this: a client states the permissions it wants as `method[:params]`,
and says plainly that client-supplied metadata must not be used for authorisation. Something on the
signer's side has to decide what an app may do.

Two shapes were available. Refuse an ungranted request with an error — simple, but it makes the grant
list the *only* way to ever answer a method, so anything not anticipated at pairing time fails
permanently, and the user is never asked. Or queue an ungranted request for the same accept/decline
the signing queue already provides — which means the queue can no longer be a queue of signing
requests.

The second is what the queue is *for*. "Ask me" is exactly the behaviour a remote signer exists to
offer, and a decrypt request the user has not pre-approved is no less worth asking about than a
signature.

## Decision

An injected authoriser decides, per request, whether the app's grants cover it. A granted request is
answered immediately, as before. An **ungranted request is queued** for the host to approve or reject,
whatever its method — signing, decryption, encryption or identity. Nothing is refused for want of a
grant.

The queue therefore holds a `PendingRequest` whose payload is a **detail**: `SignEventDetail`,
`CryptoDetail` or `GetPublicKeyDetail`, behind one interface. A detail knows the permission it needs,
the counterparty it concerns, and — given the signer and the current time — how to answer itself. The
bunker builds the detail once, at dispatch, from the untrusted parameters; a request whose parameters
do not parse is answered with an error there and never queued.

The same detail answers the request whether the authoriser granted it (answered at dispatch) or the
host approved it (answered at approval). There is one implementation of each method's answer.

Which methods are subject to this is named by `Nip46Method::requiresAuthorisation()`: identity, signing
and the four cipher operations. `connect` is gated by the secret instead; `ping`, `switch_relays` and
`logout` are protocol housekeeping that carry no capability, and are always answered. The dispatch
routes those same methods to the decision, and a test drives every case of the enum to assert the two
agree — the predicate is what a host reads to build a permission catalogue, so the two must not drift.

## Consequences

- A host can put a human in front of every capability, not just signing, and an app that asks for
  something it was not granted gets an answer once the user decides — not a permanent error.
- Answering lives on the details, not in the bunker's dispatch: adding a method means adding a detail,
  and the dispatch gains one arm. There is no `instanceof` ladder over the detail types anywhere,
  because a detail answers itself.
- A reader may expect an authorisation check to *reject*. It never does. The check chooses between
  answering now and asking; the only refusals in the bunker are for unparseable input, an unknown
  method, a bad secret, or an unconnected client.
- An ungranted request occupies a queue slot until it is decided, and the queue is bounded: a client
  that floods ungranted requests fills it and is answered "too many pending requests", as a flood of
  signing requests already was.
- Queued requests are still excluded from the activity listener (ADR-0020): the host records what it
  decides, the bunker records what it answers on its own.
- If the host grants everything, behaviour is what it was before this record for every method except
  `sign_event`, which stays queued unless its kind is granted.
