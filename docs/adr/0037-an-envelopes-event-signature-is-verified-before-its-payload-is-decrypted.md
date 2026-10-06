# 37. An envelope's event signature is verified before its payload is decrypted

## Status

Accepted

Supersedes ADR-0030 (and with it ADR-0005). Those records took a successful decryption under the claimed sender's conversation key as the sole authentication of an inbound event and skipped signature verification. That decision is reversed here in full.

## Context

Each NIP-46 message arrives as a signed kind-24133 event whose payload is encrypted under a conversation key derived by ECDH between the two parties. NIP-44 mandates the order of operations: "The outer signature serves to authenticate the full payload, and MUST be validated before decrypting", and "Before decryption, the event's pubkey and signature MUST be validated as defined in NIP 01." ADR-0030 did not cite these clauses.

ADR-0030's argument — that decryption already proves the sender's key custody — is true of the payload but of nothing else in the envelope. The signature is what covers `created_at` and what makes the event id unforgeable: anyone can take a captured envelope, rewrite `created_at`, and recompute the id without holding a key. Redelivered events are deduplicated by event id (ADR-0010), so an unverified envelope can be replayed with a fresh timestamp under a fresh id and the request executed again. A legacy NIP-04 envelope (ADR-0022) has no MAC at all; without the signature its only integrity check is that the decrypted bytes parse.

## Decision

`Nip46Bunker::onEvent` and `Nip46Client::onEvent` verify the inbound event — id match and Schnorr signature — through `Nip46Channel::isAuthentic`, before it is remembered for deduplication and before `Nip46Channel::open` decrypts it. Verification comes first so that a signature-mangled copy of a legitimate event cannot poison the seen-set. An event whose signature fails is dropped without a reply, exactly as an undecryptable one is. The rule is symmetric because both roles speak through the same channel shape (ADR-0031): the bunker verifies each request event, the client verifies each response event.

Both roles order their inbound gates cheapest-first: is the session alive, does the author match the awaited peer (where the role awaits one), is the signature valid, has this event id been seen, then decrypt. One order, both roles — anything else pays a Schnorr verification for an event a cheaper check would have dropped, or lets junk from an unexpected author occupy the seen-set.

The channel answers `isAuthentic` through the signing port, which gains a fifth method, `verify(Event): bool`, beside `sign` — amending ADR-0029's four-method contract. Verification is the same crypto family the port exists to gather (one "do crypto as this identity" collaborator rather than a second crypto dependency), `LocalNip46Signer` already holds the `SignatureServiceInterface` it delegates to, and neither the channel nor the bunker crosses the three-collaborator fence.

## Consequences

- The signing port has five methods where ADR-0029 wrote four; hosts implementing `Nip46SignerInterface` by hand gain one delegation. No constructor changes: the bunker and the channel keep their three collaborators.
- `Nip46Client` loses its `SignatureServiceInterface` constructor argument: every signature check in the library — inbound envelopes, and the bunker-signed event of a `sign_event` result — now flows through the one codepath, `Nip46Channel::isAuthentic` (amending ADR-0033's wiring).
- A replayed envelope with an altered `created_at` and recomputed id fails verification, so id-based deduplication cannot be bypassed that way.
- A MAC-less NIP-04 envelope gains integrity: every byte either role acts on is covered by the signature.
- ADR-0030's escape clause — that its rule held only while requests were confidential, sender-keyed payloads — is not what reversed it. The reversal is grounded in NIP-44's MUST and in the replay opening the earlier reading left.
- The request event remains a confidential, sender-keyed payload and is not retained as a standalone artefact; verifying its signature changes how it is authenticated, not how it is handled afterwards.
- Shared decision: nostr-adrs ADR-0048.
