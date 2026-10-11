# 39. A signed event is the template sent, signed by the connected user

## Status

Accepted

## Context

A remote signer returns the signed event for a `sign_event` request. Trusting it as returned looks reasonable, since the client asked for it. But the key that signs is chosen by software outside the client (the operator may have switched accounts in the bunker), and a bunker can return anything. An event signed by another key, or with a broken signature, is then published under the user's apparent identity, or rejected later by every relay.

The client already refused an event authored by another key or failing signature verification (ADR-0033). That proves only who signed, not what. A bunker that changes the kind, the content, the tags or the timestamp returns an event the caller never asked for, correctly signed, and the caller publishes it. NIP-46 sends the signer `kind`, `content`, `tags` and `created_at`; the signer's part is to add `id`, `pubkey` and `sig`, nothing else.

## Decision

`Nip46Client::signEvent` checks the event the bunker returns, in this order, and returns the first failure:

1. Its `pubkey` must be the connected user's, else `IdentityMismatch`.
2. Its `kind`, `created_at`, `content` and `tags` must equal the template the client sent, else `TemplateMismatch`. The template is NIP-46's `sign_event` parameter and nothing more: the `Rumour`'s `kind`, `created_at`, `tags` and `content`, serialised by `UnsignedEventInput::fromRumour(...)->toJson()`, with no `pubkey` or `id`. Tags are compared in order: the same number of tags, each the same length, each string identical at the same position (nostr-core's `TagCollection::equals`). The comparison is `SignedTemplateMatcher`, and `id`, `pubkey` and `sig` are the only fields it lets the signer add.
3. Its id and signature must verify through `Nip46Channel::isAuthentic`, else `InvalidSignature`.

The client exposes no public raw request path that would bypass these checks for `sign_event` (ADR-0033).

## Consequences

- A caller holding a signed event from the client can publish it: it is the event the caller asked for, signed by the user's key.
- An account switch in the bunker surfaces as an `IdentityMismatch` the caller can report, and an altered event as a `TemplateMismatch`, never as a silently foreign event.
- The template carries no `pubkey` or id, so neither is compared with it: the signer adds both, the pubkey is checked against the connected user, and the id against the signature.
- The two cheap comparisons run before the signature is verified, so an altered event is refused without a Schnorr verification, and an event failing several checks reports the same failure in both ports.
- `TemplateMismatch` is a new `Nip46FailureReason` case; a host matching the reasons exhaustively has to handle it.
- Shared decision: nostr-adrs ADR-0049.
