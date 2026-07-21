# 15. A client-initiated pairing is authenticated without a connect request

## Status

Accepted

## Context

Every pairing this package supported until now began at the bunker: the signer minted a `bunker://`
URL, the client dialled in with `connect`, and the presented secret — checked by the authenticator —
was what established the connection. Authentication had exactly one entry point, and a client became
authenticated only by proving knowledge of a secret the signer had chosen.

The protocol's other pairing direction inverts that. The client mints
`nostrconnect://<client-pubkey>?relay=…&secret=…`, listens on relays of its own choosing, and waits.
The signer, given that URI by its operator, sends the client a `connect` **response** whose result is
the URI's secret; the client learns the signer's identity from the response author and validates the
echoed secret. No `connect` request is ever sent, so there is nothing for the authenticator to check
and no moment at which the existing path could mark the client authenticated. Every subsequent request
from that client would be answered "not connected".

The obvious-looking alternative — wait for the client to send `connect` after the echo — does not
exist in the protocol and would strand every conforming client. The other — treat any client that
presents a known secret in a later request as connected — would weaken the one authentication rule the
bunker has.

## Decision

The bunker gains a second, explicit entry point for establishing a connection: `acceptNostrConnect`
records the client's public key as authenticated against a host-supplied app id, and publishes the
secret echo to the client. Authentication is therefore established either by a `connect` request whose
secret the authenticator accepts, or by the host handing the bunker a parsed `nostrconnect://` URL —
never by anything else.

The authorising act for the second path is the operator pasting the URI into the signer: the host
decides which app the pairing becomes and what it may do, and hands the bunker an `AppId` that already
represents that decision. `restorePairing` re-establishes such a pairing after a restart, taking the
same client key, app id and relays without re-sending an echo, because the client is not waiting for
one.

The client metadata in the URI — `name`, `url`, `image` and the requested `perms` — is parsed and
carried to the host as a display hint. The bunker never reads it when deciding anything.

## Consequences

- A client that mints its own URI can pair, and its ephemeral key is authenticated from the moment the
  operator accepts, with no `connect` request in the flow.
- There are two ways to become authenticated rather than one. They are both explicit and both end at
  `BunkerSession::authenticate`; a reader looking for "where does a client become connected" finds
  exactly these two call sites, and the queue and per-app attribution are unchanged downstream.
- The secret in a `nostrconnect://` URI is chosen by the client, not the signer. It authenticates the
  *signer to the client* — proving the echo came from the key the client is now talking to — and is not
  a credential the bunker validates. A host that also stores it as the app's secret gets the
  `connect`-path check for free if the client ever does send one.
- The requested `perms` list is not honoured by the bunker. Authorisation is the host's decision
  (ADR-0017), and a client-supplied list is a request, not a grant.
- Nothing in the bunker distinguishes a client-initiated pairing after the fact: once authenticated, a
  client is a client. Only the host's own records know which pairing was which, which is what lets it
  restore them at unlock.
