# 22. The envelope payload names its cipher

## Status

Accepted

Supersedes ADR-0011. Accepting NIP-04 envelopes from legacy peers and answering each peer in the cipher it used are carried forward unchanged. What is revised is how the cipher of an inbound envelope is chosen: it is read from the payload, not found by trial decryption. This record holds the complete current decision.

## Context

NIP-46 defines the kind-24133 content as "[NIP-44](44.md) encrypted", in both directions. Earlier revisions used NIP-04, and counterparties deployed against them still speak it; refusing them drops their requests silently (ADR-0030), so the legacy peer hangs until it times out.

The two payloads cannot be confused. NIP-04: "The format is the following: `\"content\": \"<encrypted_text>?iv=<initialization_vector>\"`." NIP-44 v2 payloads are "Base64-encode (with padding) params using `concat(version, nonce, ciphertext, mac)`", and `?` is not in the base64 alphabet. ADR-0011 nevertheless decrypted with the remembered cipher and, on failure, with the other one, so every envelope in the other cipher cost a failed decryption, and a NIP-04 payload was offered to NIP-44 and the reverse.

## Decision

`EnvelopeCipher::ofPayload()` names the cipher: a payload containing `?iv=` is NIP-04, any other is NIP-44. `Nip46Channel::open()` (ADR-0031) decrypts once, with that cipher, and reports it on the `DecodedEnvelope`; there is no second attempt and no preferred cipher argument. Each session records the cipher of the last envelope it opened from a peer and seals its replies to that peer with it, so a conversation between two current implementations runs NIP-44 exclusively, and NIP-04 appears only when a counterparty writes it.

The `nip04_encrypt` / `nip04_decrypt` methods are unrelated: they operate on user-supplied payloads and remain defined by the current spec.

`nostr-nip46-ts` chooses the envelope cipher from the payload the same way.

## Consequences

- An envelope is decrypted exactly once, with the cipher its payload is written in. A payload that does not decrypt under it is dropped, as before.
- `EnvelopeCipher::fallback()` is removed, and opening an envelope takes no preferred cipher.
- NIP-04 has no MAC; ADR-0030 records why a NIP-04 request still authenticates its sender and what compensates.
- When counterparties that cannot speak NIP-44 no longer matter, this record is superseded and NIP-04 envelope support deleted rather than left as dead tolerance.
- Shared decisions: nostr-adrs ADR-0020 (outbound encryption never falls back from NIP-44 to NIP-04), ADR-0053 (an envelope names its cipher and each peer is answered in kind) and ADR-0083 (a payload is read as NIP-04 by its `?iv=` separator).
