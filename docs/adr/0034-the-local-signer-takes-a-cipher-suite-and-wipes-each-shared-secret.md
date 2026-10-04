# 34. The local signer takes a cipher suite and wipes each shared secret

## Status

Accepted

## Context

`LocalNip46Signer` signs and encrypts as a key held in process. It took the key pair, a signature service, a NIP-44 cipher, a NIP-04 cipher and an ECDH service, five collaborators, and for every encryption or decryption it derived the NIP-44 conversation key, or computed the NIP-04 shared secret, inline. Neither was ever zeroed, so key material outlived the operation that needed it.

nostr-core already has the NIP-44 half of this: `ConversationCipher` derives the conversation key from a private key and a peer, uses it, and zeroes it in a `finally` (nostr-core ADR-0109, where `GiftWrapper` takes it for the same reason). It has no NIP-04 counterpart, and NIP-46 still needs NIP-04, both for legacy peers' envelopes (ADR-0022) and for the `nip04_encrypt` / `nip04_decrypt` methods the specification defines.

Which conversation cipher to use is decided by `EnvelopeCipher`, and that choice was a conditional repeated in `encrypt` and `decrypt`.

## Decision

- `Nip04ConversationCipher` implements nostr-core's `ConversationCipherInterface` for NIP-04: it computes the ECDH shared x, encrypts or decrypts under it, and zeroes it in a `finally`.
- `EnvelopeCipherSuite`, behind `EnvelopeCipherSuiteInterface`, holds one conversation cipher per `EnvelopeCipher` and names the one to use (`cipherFor`).
- `LocalNip46Signer` takes the key pair, the signature service and the cipher suite. `create()` wires nostr-core's `ConversationCipher` over `Nip44Cipher` and this package's `Nip04ConversationCipher` over `Nip04Cipher`, sharing one `Secp256k1Ecdh`.

## Consequences

- Every conversation key and shared secret the local signer derives is wiped when the operation ends, whether it succeeds or throws.
- The choice of cipher by `EnvelopeCipher` is made in one place.
- The local signer calls nostr-core's deprecated NIP-04 API on purpose, for the reasons above; the one test that runs it marks the deprecation as expected.
- `Nip04ConversationCipher` belongs beside `ConversationCipher` in nostr-core. When nostr-core ships one, this record is superseded and the local copy deleted.
