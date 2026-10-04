# 35. The bunker authenticates clients, not apps; app grouping is the host's

## Status

Accepted

Supersedes ADR-0015. The second authentication entry point, the secret echo, the display-hint metadata and the restore operation are carried forward unchanged; what is revised is that authentication no longer resolves to a host-supplied app id. This record holds the complete current decision.

## Context

Until now the authenticator mapped a `connect` secret to an opaque `AppId`, the session recorded each client under that id, and the authoriser, the approval queue and the activity audit all keyed on it. The TypeScript port of this package does none of that: its authenticator answers whether a secret is valid, and its authoriser is asked per client public key.

The NIP-46 wire carries no app concept — there is no field for it in any message. And the library never decided anything from the `AppId`: it accepted the value from one host port (the authenticator), stored it, and handed it to another (the authoriser) and to the audit. That is couriering host bookkeeping, not protocol. It also forced every host to be multi-tenant: a single-purpose bunker had to invent an app id to satisfy the ports.

The host already owns the join. A host that groups clients into apps persists which client key paired under which app (it must, to restore pairings across restarts), so it can answer per-app policy from the client key alone.

## Decision

The library holds no app concept; `AppId` is removed.

- The authenticator answers `authenticate(?ConnectSecret): bool` — whether the presented secret is currently valid. A host running many named pairings accepts any of its live secrets; which secret belongs to which pairing stays in the host's store.
- The authoriser is asked `isAuthorised(PublicKey $client, Permission $permission): bool` — per connected client key.
- The session records that a client is authenticated, nothing more. A queued `PendingRequest` and a `BunkerActivity` carry the client's public key, which is the attribution the host resolves to its own grouping. (This reverses ADR-0014's preference for app-id attribution: the client key is the only identity the wire establishes, and resolving it is one lookup in the host's own records.)
- The secret listener receives the secret and the client's public key — enough for the host to retire the secret and to file the client under the pairing the secret belonged to.

Carried forward from ADR-0015, unchanged: there are exactly two authentication entry points — a `connect` request whose secret the authenticator accepts, and the host handing the bunker a parsed `nostrconnect://` URL through `acceptNostrConnect`, which publishes the URL's secret to the client as a `connect` response. `restorePairing` re-establishes a pairing after a restart — now taking the client key and its relays, no app id — without re-sending the echo. The URI's `name`, `url`, `image` and `perms` are display hints the bunker never reads when deciding anything, and a requested `perms` list is never a grant.

## Consequences

- Both ports of this package now share one authorisation model: authenticate by secret validity, authorise per client key, group in the host.
- A single-tenant host implements the authenticator as a one-secret check and stops fabricating app ids.
- A multi-app host (hubstr-signer) keeps its model entire: its authenticator accepts any app's live secret, its authoriser maps the client key to the app it paired under, and its audit labels by that mapping. Nothing about per-app secrets or grants moves into or out of the host — only the library stops carrying the label.
- The client public key is ephemeral per pairing, so a host that wants history grouped across re-pairings must resolve it through its own records — which it already keeps for `restorePairing`.
- `docs/adr/0014`'s app-id attribution rationale and `docs/adr/0015` survive as history; the live attribution rule is this record.
