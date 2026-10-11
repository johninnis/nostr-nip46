# 23. A connect secret is accepted once

## Status

Accepted

## Context

NIP-46, on the `bunker://` token: "Optional secret can be used for single successfully established connection only, _remote-signer_ SHOULD ignore new attempts to establish connection with old secret."

Until now the bunker kept no record of secrets. Whether a secret could be used again was left to the host's authenticator, and the README said so. A host that did nothing let one `bunker://` URL connect any number of clients, for as long as the URL was around.

The bunker does not own the secrets. The authenticator decides whether a presented secret is currently valid, so a host can run many named pairings from one signer, and the host issues and stores them. The bunker cannot retire a secret in the host's store, but it sees every successful `connect`, so it knows when a secret has been used.

Some choices here are not obvious:

- **Ignore or refuse.** Ignoring a used secret looks like withholding from whoever holds a leaked URL that the secret was real and has been used. But the bunker answers a secret it never issued `invalid secret`, so silence for a used one tells that holder exactly what it was meant to withhold. Silence also leaves a client holding a mistyped or stale URL waiting out its timeout, as it would against a signer that is down. Only answering both the same way reveals nothing.
- **The client that used the secret.** A client that connected but lost the `ack`, or that restarts its handshake, sends the same `connect` again. Refusing it would strand the one client the secret was meant for.
- **How long the record lasts.** The session is discarded at `stop()`. If the record lived in the session, stopping and starting would make every used secret valid again.
- **Bounding.** Like all other per-peer state (ADR-0024), the record must be bounded. The bunker only remembers, and the host's store is what actually accepts a secret, so a used secret that is evicted becomes acceptable again unless the host has retired it.

## Decision

The bunker records each secret that establishes a connection, together with the client that used it.

- A later `connect` that presents a used secret is answered `invalid secret`, exactly as one presenting a secret the authenticator does not accept, and changes nothing: the client is not connected, the secret listener is not told again, and no activity is reported. The exception is the client that used the secret: while it is still connected, it is answered `ack` again. After it logs out or is evicted, it is answered like any other client.
- A used secret that has left the record is answered by the authenticator, so it is refused only if the host has retired it.
- The record belongs to the `Nip46Bunker`, not the session, so it survives `stop()` and `start()`. It holds up to 10,000 secrets and forgets the oldest past that. It stores a SHA-256 digest of each secret, never the secret itself.
- `bunkerUrlFor()` returns `null` for a used secret, so the bunker never advertises a URL it will refuse.
- An optional secret listener, set with `setSecretListener()` like the other observers (ADR-0032), receives the secret and the client's public key once the secret is used. The bunker records the use and tells the listener before it sends `ack`, so the host has filed the client before the `connect` activity is recorded.

Retiring and issuing secrets stays with the host. The bunker's record enforces the rule while the process runs. Keeping it across a restart, or past eviction, needs the host to retire each used secret in its own store.

## Consequences

- Answering a used secret departs from the NIP-46 sentence quoted above, which says the signer SHOULD ignore it. The departure is taken knowingly, against a SHOULD and not a MUST: ignoring a used secret while answering an unknown one is the very signal the silence was meant to withhold, and ignoring both strands every client holding a mistyped or stale URL until its timeout. Re-acknowledging the client that used the secret departs from the same sentence for the same reason.
- A `bunker://` URL connects one client. Pairing a second device needs a fresh secret from the host.
- A host that ignores the secret listener still gets the rule within one process. After a restart, or once 10,000 other secrets have been used, its old secrets work again. The README spells out what the host must do.
- A client paired with a `bunker://` URL cannot come back after a signer restart by sending `connect` again: its secret is spent, and it is answered `invalid secret`. The host keeps the client key from the secret listener and calls `restorePairing()`, just as it does for a `nostrconnect://` pairing.
- A `nostrconnect://` secret is not recorded. The client chose it and the bunker never checks it (ADR-0035).
- A reader may expect a reused secret to be ignored, as the NIP says. A fence at the call site points here, and tests check that a used secret is answered `invalid secret` by another client, by its own client after logout and after a restart, that the client that used it gets `ack` again, and that a restart does not clear the record.
- Shared decision: nostr-adrs ADR-0058.
