# 11. The envelope accepts a NIP-04 fallback for legacy peers

## Status

Accepted

## Context

The current NIP-46 specification defines the kind-24133 envelope content as NIP-44 encrypted, in both
directions. Earlier revisions of the protocol used NIP-04, and counterparties deployed against those
revisions still speak it. NIP-04 lacks an authenticating MAC and is deprecated upstream.

A strictly spec-current implementation that answers only NIP-44 shuts those counterparties out, and it
does so silently: an envelope that decrypts under neither cipher is dropped without a reply (ADR-0005),
so the legacy peer simply hangs until it times out. The choice is between following the current spec to
the letter and tolerating the cipher the protocol itself used until recently.

## Decision

Opening an envelope tries NIP-44 first and falls back to NIP-04; the cipher that succeeds is remembered
per peer, and replies to that peer are sealed with it. A conversation between two current
implementations therefore runs NIP-44 exclusively — NIP-04 appears only when a counterparty initiates
with it.

The `nip04_encrypt` / `nip04_decrypt` *methods* are unrelated to this record: they operate on
user-supplied payloads and remain defined by the current spec.

## Consequences

- A reader checking this package against the current spec will find envelope handling the text does not
  sanction. That is deliberate: it exists only for counterparties predating the NIP-44 requirement, and
  the README states the fallback and its reason user-facing.
- NIP-04's missing MAC weakens what a successful decryption proves; ADR-0005 records why the fallback
  still authenticates its sender (the key is the same ECDH secret) and what compensates (the plaintext
  must additionally parse as a well-formed request).
- The per-peer cipher state in both sessions exists solely to serve this fallback; removing the
  fallback removes that state with it.
- When counterparties that cannot speak NIP-44 no longer matter, this record is superseded and the
  fallback deleted rather than left as dead tolerance.
