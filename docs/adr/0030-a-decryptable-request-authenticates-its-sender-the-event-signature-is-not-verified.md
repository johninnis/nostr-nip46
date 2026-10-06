# 30. A decryptable request authenticates its sender; the event signature is not verified

## Status

Superseded by ADR-0037. The rule below argued key custody of the payload but left `created_at` and the event id unauthenticated — a replay opening against id-based deduplication — and contradicted NIP-44's MUST that the event signature be validated before decrypting. ADR-0037 verifies the signature first.

## Context

Each request reaches the bunker as a signed kind-24133 event. The event carries a `pubkey` field naming its author, and an encrypted payload. The bunker reads that `pubkey`, derives a conversation key against it, decrypts the payload, and from then on treats the sender as that public key — gating `connect`, queue attribution, and per-client cipher choice on it.

The obvious safeguard is to verify the event's signature before trusting its `pubkey`, so a forged event claiming another client's key is rejected. That is the move a reviewer expects, and its absence reads as a missing check.

It is redundant here. The payload is NIP-44 encrypted under a conversation key derived by ECDH between the bunker's private key and the sender's public key. That key is symmetric: the value the bunker computes from `(bunker private key, claimed sender key)` is the same one the sender computes from `(sender private key, bunker key)`, and it can be reached from no other pair. NIP-44 authenticates the ciphertext with a MAC under that key, so a payload that decrypts is a payload whose author held the private key for the `pubkey` it claims. An attacker who sets `pubkey` to an already-connected client, without that client's private key, cannot derive the conversation key and so cannot produce a payload the bunker will decrypt at all — the request is dropped before any field is read. Verifying the signature would re-establish the very key custody the decryption already proved, at the cost of threading a signature-verification capability into the request path and adding a second failure mode.

A legacy NIP-04 payload (ADR-0022) has no MAC, but its key is the same ECDH secret: deriving it still requires the sender's private key, so a forged sender still cannot produce a payload the bunker can read, and the decrypted bytes must additionally parse as a well-formed NIP-46 request.

## Decision

The bunker does not verify the inbound event's signature. A successful decryption under the conversation key for the claimed `pubkey`, with the cipher `EnvelopeCipher::ofPayload` names from the payload (ADR-0022), is taken as proof that the sender holds that key, and is the sole authentication of sender identity. An event that does not decrypt under that cipher is dropped without a reply; there is no second attempt with the other cipher.

This authenticates *who* the sender is. It is distinct from authorising *which app* a sender may act as, which the `connect` secret and the authenticator establish separately (ADR-0023).

## Consequences

- Sender identity is proven once, by the decryption the bunker must do anyway, with no dependency on a signature-verification service in the request path.
- A reader expecting an explicit signature check before the `pubkey` is trusted will not find one. That is deliberate: the conversation-key decryption is the gate, and a forged sender cannot pass it.
- An undecryptable event yields no response — the bunker cannot even recover a request id from it to correlate one — which is correct for what such an event is: relay noise or a forgery.
- This holds only while requests are confidential, sender-keyed payloads. If a future requirement needs the signed event retained, relayed, or audited as a standalone verifiable artefact, signature verification becomes a real need and this record is superseded.
- Shared decision: nostr-adrs ADR-0048.
