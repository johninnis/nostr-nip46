# 19. The bunker constructor takes five irreducible ports

## Status

Accepted

Supersedes ADR-0006.

## Context

The bunker is the NIP-46 protocol endpoint. To answer a request it needs capabilities from the outside
world, and each is a distinct driven port:

- a **transport**, to receive kind-24133 request envelopes and publish response envelopes over relays;
- a **signer**, to produce the user's public key, sign events, and encrypt/decrypt payloads as the
  user's identity;
- an **authenticator**, to decide whether a connecting client's secret is authorised and to which
  opaque app id it maps;
- a **clock**, to bound the incoming subscription to a signed clock-skew window (`since`) and to stamp
  each queued request with the moment it arrived.

Deciding what a connected app may do (ADR-0017) adds a fifth: an **authoriser**, to say whether an
app's grants cover the request in hand.

A constructor that keeps growing is usually a unit that has taken on too much, so each addition has to
answer the same two questions: could the collaborator be optional, and should the class be split?

The bunker already has two collaborators set after construction — the queue listener and the activity
listener. Both are observers: with neither set, every request is still answered correctly, and all that
is lost is notification. The authoriser is not that. Without it the bunker cannot choose between
answering and asking, and neither default is safe — answering everything ignores the host's decision
entirely, asking about everything makes a granted app unusable. A missing answer here is a wrong
answer.

Nor does authorisation fold into the authenticator. They answer different questions at different
moments: the authenticator answers *who is this*, once, at connection, from a secret; the authoriser
answers *may this app do this*, on every request, from a permission. A host implements them over
different state, and merging them would force one interface to carry both.

## Decision

Carried forward from ADR-0006: the transport, signer, authenticator and clock are each a distinct
driven port with no overlap and none is removable, and the bunker is not split to reduce the count.
The five are orthogonal concerns — I/O, key custody, identity, capability, time — with nothing cohesive
to gather into a value object; bundling them would hide unrelated dependencies behind one opaque
argument. Splitting does not help either: the request path is a single cohesive pipeline — receive,
decrypt, authenticate, authorise, dispatch, respond — and every stage touches these ports, so a split
would sever a pipeline that belongs together and merely move the same dependencies across a new seam.

Restated for the added concern: the **authoriser is the fifth mandatory port**, injected in the
constructor beside the authenticator. Optional observers stay setter-injected; what decides which is
whether the bunker can answer correctly without the collaborator.

## Consequences

- Every host must supply an authoriser. A host wanting a bunker that answers every method for a
  connected client writes one that grants everything — explicit, rather than implied by an absent
  collaborator.
- Five constructor parameters is a lot, and a reader is right to stop at it. A fence at the constructor
  points here so the answer is one hop away.
- A reader may be tempted to merge the authenticator and authoriser into one "policy" port, or to demote
  the authoriser to a setter beside the listeners. Both are the mistakes this record exists to prevent:
  the first conflates identity with capability, the second makes correctness optional.
- If a sixth mandatory collaborator ever appears, that is the point to question the shape of the class
  rather than extend the list — this record is superseded rather than edited.
