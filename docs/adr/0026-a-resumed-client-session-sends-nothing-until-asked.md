# 26. A resumed client session sends nothing until asked

## Status

Accepted

## Context

A connect secret is accepted once (ADR-0023). NIP-46, on the `bunker://` token: "Optional secret can be used for single successfully established connection only, _remote-signer_ SHOULD ignore new attempts to establish connection with old secret." A client that restarts and calls `connect()` again with the same URL is therefore refused: a signer following that sentence ignores it and the client times out, and this package's bunker answers `invalid secret` (ADR-0023).

NIP-46 expects the client to be able to keep its session: the client "might choose to store [the `client-keypair`] locally and they should delete it on logout". What a client needs to carry on after a restart is that key, the remote signer's public key, the relays it talks on, and the user's public key. The relays are not always the URL's: `connect()` adopts the relays `switch_relays` names (ADR-0025), so a host that stored only the URL would come back on relays the signer may have left.

A resumed session could still ask the signer something before it is used: send `connect` again, ask `switch_relays`, or ask `get_public_key` to check the stored key. Each has a cost. `connect` with the spent secret is refused, and without it a signer rejects it or treats it as a new pairing. `switch_relays` and `get_public_key` are a round trip each, on every start, against a signer that may need a human to approve `get_public_key`. The TypeScript port faces the same choice and makes a restored session send nothing (its ADR-0018).

## Decision

`resume(BunkerUrl, PublicKey)` opens a session from what the host stored: the signer's key and relays as a `BunkerUrl` (its secret, if any, is not used) and the user's public key. It subscribes for responses on those relays and sends nothing. The first request the host makes is the first thing the signer sees.

`getRelays()` returns the relays the session uses: the URL's, or those `switch_relays` put in their place. The host stores them with the session, and passes them back to `resume()`.

Calling either before `connect()` or `resume()` is a programmer fault and throws, like every other call on an unconnected client (ADR-0033).

## Consequences

- A host can keep one pairing across any number of runs, which a one-shot secret otherwise makes impossible.
- A signer that has dropped the session (it restarted without restoring the pairing, the user revoked it, or the client was evicted) is noticed at the first request, which comes back as a rejection or a timeout. The host treats that as needing a new pairing. There is no automatic re-pair, because there is no secret left to pair with.
- Relays change only when `connect()` runs. A signer that moves while a session is resumed is followed at the next pairing.
- `signEvent()` checks the returned event against the stored user key, so a wrong stored key fails every signature as an identity mismatch rather than signing as someone else.
- A reader may expect `resume()` to re-send `connect` or `switch_relays`, as `connect()` does. A fence at the method points here, and tests check that a resumed session publishes nothing and then signs and logs out on the stored relays.
- Shared decision: nostr-adrs ADR-0060.
