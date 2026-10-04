# 29. One signing port, with an asymmetric decrypt contract

## Status

Accepted

Supersedes ADR-0003. The single signing port, its nullable `decrypt` beside a throwing `sign` and `encrypt`, and the conversion of every outbound encryption fault to a correlated protocol error are carried forward unchanged. What is revised is the statement of the cipher's size limit, which ADR-0003 left unnamed while nostr-core's NIP-44 limit moved from 65535 bytes to a configurable maximum, and the sibling record for the client's seal, now ADR-0028; and the reason for the nullable `decrypt`, which ADR-0003 gave as the codec's trial-decryption fallback that ADR-0022 removed. This record holds the complete current decision.

## Context

The bunker needs to act cryptographically as the user's identity: sign events, and encrypt and decrypt NIP-44 / NIP-04 payloads to a peer. The lower-level building blocks for this already exist as separate capabilities — a signature service, a NIP-44 cipher keyed by a conversation key, a NIP-04 cipher keyed by a shared secret, an ECDH service. A consumer could depend on all of those directly.

Two problems with that. First, those building blocks need the raw private key threaded through every call site, and the key is exactly what a signer is built to hold and protect — however the host materialises it at unlock, it should not be passed around. Second, depending on four crypto collaborators at once is a wide, incoherent dependency for what is really one idea: "do crypto as this identity."

There is also a contract question. Decrypting an *inbound* envelope is expected to fail routinely: the ciphertext comes from whoever published the event, and a payload that does not decrypt under the cipher it is written in is an anticipated outcome to be handled, not a fault. `EnvelopeCipher::ofPayload` names that cipher from the payload, so there is one attempt and no fallback (ADR-0022). Encrypting an *outbound* response, by contrast, operates on data the bunker itself produced; a failure there is a real fault.

## Decision

The bunker depends on a single port, `Nip46SignerInterface`, with four methods: `publicKey`, `sign`, `encrypt`, and `decrypt`. The host implements it once, over whatever holds the unlocked key, and is the only place the private key lives.

The decrypt and encrypt halves carry deliberately different contracts. `decrypt` returns `?string` and returns `null` when the ciphertext does not decrypt under the given cipher — letting the channel (ADR-0031) drop a payload that does not decrypt as ordinary value flow (ADR-0022). `sign` and `encrypt` return their result and signal failure by throwing.

`encrypt` is then called in two contexts, and a client is waiting on a reply in both, so neither is allowed to propagate a throw and hang it. Answering a client's `nip44_encrypt` / `nip04_encrypt` encrypts plaintext the client supplied — untrusted input a cipher rejects when it is empty or over-long — and the bunker catches the throw and answers `error: "encryption failed"`. Sealing a response envelope encrypts a message the bunker built, but that message can itself exceed the cipher's size limit, 262144 bytes of plaintext for nostr-core's `Nip44Cipher` by default (nostr-core ADR-0117) and 65567 for its `Nip04Cipher` (nostr-core ADR-0118): a large signed event, or the ciphertext of a near-maximal `nip44_encrypt`, which is wider than the plaintext that produced it; the channel's `seal` returns a seal failure as `null` (ADR-0031) and, when the response carried a result, degrades it to a small `error: "response too large"` the cipher can carry. The port contract is unchanged — `encrypt` still signals failure by throwing — but every outbound crypto failure is converted to a correlated protocol error at the request boundary rather than left to propagate.

This is the canonical statement of a rule the package applies wherever an external party is waiting on a reply: an outbound signing or encryption fault is converted to a correlated protocol error at the request boundary, while the underlying port keeps signalling failure by throwing. Two sibling records apply the same rule at their own call sites — a signing fault the operator's approval triggers (ADR-0002), and a request the client cannot seal before publishing (ADR-0028) — each with its own fence and test rather than folded in here, because each guards a different line of code against a different failure.

## Consequences

- The private key is held in one host-supplied implementation, not spread across the protocol code; the bunker never sees it.
- Dropping an undecryptable envelope is plain branching on a nullable return, with no exception handling in the domain.
- The interface looks asymmetric — one nullable method beside two throwing ones — and that asymmetry is the point: `decrypt` returns `null` so dropping a payload that does not decrypt under the cipher `EnvelopeCipher::ofPayload` named is plain value flow, while `encrypt` throws and the bunker converts that throw to a returned protocol error at the request boundary, rather than widening the port with a second nullable method. An implementation must still return `null` for an undecryptable payload rather than throwing, or the channel's drop turns into caught exceptions.
- The host's implementation typically wraps a throwing NIP-44 / NIP-04 decryptor and converts its failure into the `null` this port requires; that conversion belongs in the implementation, at the edge where attacker-supplied ciphertext is first handled.
- An `encrypt` throw is caught in two places — the `nip44_encrypt` / `nip04_encrypt` reply (`encryption failed`), and the channel's `seal` (ADR-0031), whose `null` the bunker degrades to `response too large`. Each carries a one-line fence pointing here, and a test drives an `encrypt` that throws — on untrusted plaintext, and on an over-large response — and asserts the degraded reply, so neither catch can be removed as dead without breaking the build.
- Shared decisions: nostr-adrs ADR-0001 (`decrypt` returns `null` for an anticipated failure), ADR-0047 (every accepted request is answered with a correlated reply) and ADR-0102 (the cipher limit the bunker's seal meets).
