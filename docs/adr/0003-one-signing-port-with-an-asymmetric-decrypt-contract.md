# 3. One signing port, with an asymmetric decrypt contract

## Status

Accepted

## Context

The bunker needs to act cryptographically as the user's identity: sign events, and encrypt and decrypt
NIP-44 / NIP-04 payloads to a peer. The lower-level building blocks for this already exist as separate
capabilities — a signature service, a NIP-44 cipher keyed by a conversation key, a NIP-04 cipher keyed
by a shared secret, an ECDH service. A consumer could depend on all of those directly.

Two problems with that. First, those building blocks need the raw private key threaded through every
call site, and the key is exactly what a signer is built to hold and protect — however the host
materialises it at unlock, it should not be passed around. Second, depending on four crypto
collaborators at once is a wide, incoherent dependency for what is really one idea: "do crypto as this
identity."

There is also a contract question. Decrypting an *inbound* envelope is expected to fail routinely: the
bunker does not know whether a client used NIP-44 or NIP-04, so it tries one and falls back to the
other. A failure there is an anticipated outcome to be handled, not a fault. Encrypting an *outbound*
response, by contrast, operates on data the bunker itself produced; a failure there is a real fault.

## Decision

The bunker depends on a single port, `Nip46SignerInterface`, with four methods: `publicKey`, `sign`,
`encrypt`, and `decrypt`. The host implements it once, over whatever holds the unlocked key, and is the
only place the private key lives.

The decrypt and encrypt halves carry deliberately different contracts. `decrypt` returns `?string` and
returns `null` when the ciphertext does not decrypt under the given cipher — letting the envelope codec
try the other cipher as ordinary value flow. `sign` and `encrypt` return their result and signal failure
by throwing.

`encrypt` is then called in two contexts, and a client is waiting on a reply in both, so neither is
allowed to propagate a throw and hang it. Answering a client's `nip44_encrypt` / `nip04_encrypt` encrypts
plaintext the client supplied — untrusted input a cipher rejects when it is empty or over-long — and the
bunker catches the throw and answers `error: "encryption failed"`. Sealing a response envelope encrypts a
message the bunker built, but that message can itself exceed the cipher's size limit (a large signed
event, or the ciphertext of a near-maximal `nip44_encrypt`, which is wider than the plaintext that
produced it); the bunker catches a seal failure too and, when the response carried a result, degrades it
to a small `error: "response too large"` the cipher can carry. The port contract is unchanged — `encrypt`
still signals failure by throwing — but every outbound crypto failure is converted to a correlated
protocol error at the request boundary rather than left to propagate.

This is the canonical statement of a rule the package applies wherever an external party is waiting on a
reply: an outbound signing or encryption fault is converted to a correlated protocol error at the request
boundary, while the underlying port keeps signalling failure by throwing. Two sibling records apply the
same rule at their own call sites — a signing fault the operator's approval triggers (ADR-0002), and a
request the client cannot seal before publishing (ADR-0009) — each with its own fence and test rather
than folded in here, because each guards a different line of code against a different failure.

## Consequences

- The private key is held in one host-supplied implementation, not spread across the protocol code; the
  bunker never sees it.
- The cipher-fallback logic is plain branching on a nullable return, with no exception handling in the
  domain.
- The interface looks asymmetric — one nullable method beside two throwing ones — and that asymmetry is
  the point: `decrypt` returns `null` so the codec's two-cipher fallback is plain value flow, while
  `encrypt` throws and the bunker converts that throw to a returned protocol error at the request
  boundary, rather than widening the port with a second nullable method. An implementation must still
  return `null` for an undecryptable payload rather than throwing, or the codec's fallback turns into
  caught exceptions.
- The host's implementation typically wraps a throwing NIP-44 / NIP-04 decryptor and converts its
  failure into the `null` this port requires; that conversion belongs in the implementation, at the edge
  where attacker-supplied ciphertext is first handled.
- The bunker catches an `encrypt` throw in two places — the `nip44_encrypt` / `nip04_encrypt` reply
  (`encryption failed`) and response sealing (`response too large`). Each carries a one-line fence
  pointing here, and a test drives an `encrypt` that throws — on untrusted plaintext, and on an
  over-large response — and asserts the degraded reply, so neither catch can be removed as dead without
  breaking the build.
