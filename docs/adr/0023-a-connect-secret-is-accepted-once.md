# 23. A connect secret is accepted once

## Status

Accepted

## Context

NIP-46, on the `bunker://` token: "Optional secret can be used for single successfully established connection only, _remote-signer_ SHOULD ignore new attempts to establish connection with old secret."

Until now the bunker kept no record of secrets. Whether a secret could be used again was left to the host's authenticator, and the README said so. A host that did nothing let one `bunker://` URL connect any number of clients, for as long as the URL was around.

The bunker does not own the secrets. The authenticator decides whether a presented secret is currently valid, so a host can run many named pairings from one signer, and the host issues and stores them. The bunker cannot retire a secret in the host's store, but it sees every successful `connect`, so it knows when a secret has been used.

Some choices here are not obvious:

- **What "ignore" means.** The alternative is to answer `invalid secret`. That tells whoever holds a leaked URL that the secret was real and has been used. The NIP says ignore, and a client that gets no reply times out, as it would against a signer that is down.
- **The client that used the secret.** A client that connected but lost the `ack`, or that restarts its handshake, sends the same `connect` again. Ignoring that would strand the one client the secret was meant for.
- **How long the record lasts.** The session is discarded at `stop()`. If the record lived in the session, stopping and starting would make every used secret valid again.
- **Bounding.** Like all other per-peer state (ADR-0024), the record must be bounded. The bunker only remembers, and the host's store is what actually accepts a secret, so a used secret that is evicted becomes acceptable again unless the host has retired it.

## Decision

The bunker records each secret that establishes a connection, together with the client that used it.

- A later `connect` that presents a used secret gets no reply and changes nothing. The exception is the client that used the secret: while it is still connected, it is answered `ack` again. After it logs out or is evicted, it is ignored like any other client.
- The record belongs to the `Nip46Bunker`, not the session, so it survives `stop()` and `start()`. It holds up to 10,000 secrets and forgets the oldest past that. It stores a SHA-256 digest of each secret, never the secret itself.
- `bunkerUrlFor()` returns `null` for a used secret, so the bunker never advertises a URL it will ignore.
- An optional secret listener, set with `setSecretListener()` like the other observers (ADR-0032), receives the secret and the client's public key once the secret is used. The bunker records the use before it sends `ack`, and notifies the listener after.

Retiring and issuing secrets stays with the host. The bunker's record enforces the rule while the process runs. Keeping it across a restart, or past eviction, needs the host to retire each used secret in its own store.

## Consequences

- A `bunker://` URL connects one client. Pairing a second device needs a fresh secret from the host.
- A host that ignores the secret listener still gets the rule within one process. After a restart, or once 10,000 other secrets have been used, its old secrets work again. The README spells out what the host must do.
- A client paired with a `bunker://` URL cannot come back after a signer restart by sending `connect` again, because its secret is spent. The host keeps the client key from the secret listener and calls `restorePairing()`, just as it does for a `nostrconnect://` pairing.
- A `nostrconnect://` secret is not recorded. The client chose it and the bunker never checks it (ADR-0015).
- A reader may expect a reused secret to get an error. A fence at the call site points here, and tests check that a used secret gets no reply, that the client that used it gets `ack` again, and that a restart does not clear the record.
- Shared decision: nostr-adrs ADR-0058.
