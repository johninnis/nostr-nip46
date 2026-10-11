# 40. An envelope more than ten minutes from the receiver's clock is dropped

## Status

Accepted

## Context

Both roles drop an event whose id they have already seen (ADR-0010, ADR-0024). The seen-id set handles redelivery, the same event arriving once per relay, and only that. It is bounded, so it forgets: an id evicted after 10,000 newer events, or forgotten at a restart, is accepted again. That is harmless for redelivery, whose window closes in seconds, but it means the set is not replay protection. A captured request republished later would be executed again once its id had left the set.

Both roles subscribe from a little before now (ADR-0031), so an honest relay never delivers an old event at subscription time. A relay that ignores `since`, or that matches only stored events against it and forwards any new arrival, delivers a republished old request as if it were current. NIP-46 says nothing about an envelope's age. Its requests are short-lived: a client whose request has gone unanswered for more than a few minutes has timed out and given up, so an old request has no one waiting for it.

## Decision

- Both the bunker and the client drop, without a reply, an inbound envelope whose `created_at` is more than 600 seconds before or after the channel's clock. One exactly 600 seconds away is kept. `Nip46Channel::isFresh` judges it, against the public constant `Nip46Channel::FRESHNESS_WINDOW_SECONDS`.
- The age is judged after the signature is verified (ADR-0037), so it judges the timestamp the author signed, and before the event id is remembered or the payload decrypted. The inbound order in both roles is: the session is alive, the author is the awaited peer (in the client), the signature verifies, the envelope is fresh, the id has not been seen, then decrypt.
- The seen-id set stays as it is, 10,000 ids by default, oldest evicted first.

## Consequences

- A request replayed after ten minutes is dropped whatever the relay does. One replayed within ten minutes is caught by the seen-id set, unless 10,000 newer events have arrived in that time.
- A stale envelope is never remembered, so it cannot occupy the seen-id set.
- Ten minutes absorbs the clock skew of an unsynchronised phone and a slow relay. A peer whose clock is further off cannot exchange messages at all, and must fix its clock.
- `Nip46ChannelInterface` gains `isFresh`. An implementation of it outside this package has to add the method.
- The window is public so a host has one source for it. A host that re-subscribes after a reconnect and floors `since` to bound what a relay replays takes this constant as its floor: anything older is fetched only to be dropped.
- Do not read the seen-id set as replay protection, and do not grow it to make it one; the window is what bounds a replay. A fence at `Nip46Channel::isFresh` points here, and tests drive an envelope just inside and just outside the window into each role, and a future-dated one that later becomes fresh into the bunker, to show it was not remembered.
- Shared decision: nostr-adrs ADR-0050.
