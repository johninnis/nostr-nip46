# 28. A request the client cannot encrypt is a returned failure

## Status

Accepted

Supersedes ADR-0009. Sealing before opening a pending slot, and returning a seal failure as `Nip46Failure` with reason `EncryptionFailed`, are carried forward unchanged. What is revised is the statement of when a cipher refuses a request: ADR-0009 gave NIP-44's limit as 65535 bytes, which nostr-core no longer applies. This record holds the complete current decision.

## Context

Before the client publishes a request it seals it: the request JSON is encrypted to the remote signer under the session cipher. The payload is caller-supplied (`sign_event` carries the host's event verbatim, and a host may relay arbitrary plaintext through `nip44_encrypt` / `nip04_encrypt`), and a cipher legitimately refuses it. nostr-core's `Nip44Cipher` refuses a plaintext that is empty or over its configured maximum, 262144 bytes by default (nostr-core ADR-0117), and its `Nip04Cipher` refuses one over 65567 bytes (nostr-core ADR-0118). A host's signer may apply its own limit. So an ordinary host action, signing a large event, can make sealing throw.

Letting that throw propagate breaks the client's contract twice. Every anticipated outcome of a call (timeout, rejection, malformed response) is returned as a typed `Nip46Failure`, so an uncaught cipher exception escaping `signEvent()` is a crash path behind a signature that promises `Event|Nip46Failure`. And a pending-response slot opened before sealing would never be awaited and never time out, so every host implementation of the pending-responses port would leak it.

The bunker faces the same force in mirror image, an outbound payload its cipher cannot carry with a party waiting on the result, and converts the throw into a correlated protocol error (ADR-0029).

## Decision

The client seals each request before opening a pending-response slot. The channel's `seal` (ADR-0031) catches a throwable from encrypting and signing that one envelope and returns `null`, and the client returns a `Nip46Failure` with reason `EncryptionFailed` for it. The catch is scoped to sealing; any other fault in the client still reaches the host.

## Consequences

- `signEvent()` and the cipher methods keep their promise: every anticipated failure of a request, including one the session cipher cannot carry, arrives as a value the caller must handle.
- No pending slot exists for a request that was never published, so no host port implementation can leak one.
- The signing port's contract is unchanged: `encrypt` signals failure by throwing (ADR-0029), and the conversion to a value happens here, at the request boundary.
- The client's request primitive and the channel's `seal` each carry a one-line fence pointing here, and a test drives a signer whose `encrypt` throws on an over-long plaintext and asserts the returned failure, so the catch cannot be removed as dead without breaking the build. The test helper's default limit is `Nip44Cipher::DEFAULT_MAX_PLAINTEXT_LENGTH`, so it follows nostr-core's default rather than restating a number.
- Shared decisions: nostr-adrs ADR-0047 (the client-side mirror of answering every accepted request) and ADR-0102 (the cipher limit the client's seal meets).
