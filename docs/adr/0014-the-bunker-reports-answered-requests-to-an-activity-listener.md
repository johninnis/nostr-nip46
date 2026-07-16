# 14. The bunker reports answered requests to an activity listener

## Status

Accepted

## Context

A host embedding the bunker may want an audit trail of what each connected app did — not only the
`sign_event` requests it queued for a human, but the requests the bunker answered on its own:
`connect`, `get_public_key`, the `nip04_*`/`nip44_*` crypto operations, `switch_relays`, `logout`. Those
are handled and answered entirely inside `dispatch`, and nothing about them reaches the host. The host
sees only the queue, through the existing queue-change listener, so it can reconstruct sign decisions but
is blind to every autonomously answered request.

Two forces shape how this is exposed. The bunker constructor already carries four irreducible ports and
is fenced against a fifth (ADR-0006), so an audit sink must not become a constructor dependency. And the
inbound request event is a confidential, sender-keyed payload whose signature the bunker deliberately does
not verify and does not retain as a standalone artefact (ADR-0005): surfacing the raw event for audit
would reintroduce exactly the retention that record forbids.

## Decision

The bunker gains an optional activity listener, wired through a `setActivityListener` setter that mirrors
the existing `setQueueListener` — not a constructor port. When a request is answered on behalf of an
**authenticated app**, the bunker notifies the listener with a `BunkerActivity` value describing the
action: the method name, the resolved app id, the counterparty public key for a crypto operation, and an
`Answered`/`Failed` outcome derived from whether the response carried an error.

The activity carries the app id, not the client public key: the app id is the trusted attribution the
`connect` handshake established (ADR-0001), whereas the client key is a weak, frequently-ephemeral handle
that would add noise without identifying the app. Each answered arm calls a `recordAnswer` sink after
`respond`, passing the resolved app id. It carries only derived metadata — never the raw request event,
its signature, or any decrypted plaintext — so the confidentiality and non-retention of ADR-0005 are
preserved and that record stands.

`sign_event` is deliberately excluded: it is not answered autonomously but queued for the operator, whose
Accept/Decline is the host's to record through the queue listener. Emitting it here as well would record
one action twice. A request the bunker refuses before authenticating — an unknown method, or any method
from a client that has not connected — has no app to attribute and is not reported.

## Consequences

- A host can maintain a per-app activity history covering every autonomously answered method, keyed by the
  same app id the queue attribution uses, without threading a fifth dependency into the bunker.
- Every reported activity is attributable: the listener only ever receives an action performed by an
  authenticated app, so the app id is always present. A pre-`connect` `ping`, which belongs to no app, is
  answered but not reported.
- The audit carries metadata only. Auditing the signed request event as a verifiable artefact remains out
  of scope; if that is ever needed it is ADR-0005 that must be superseded, not this record.
- `sign_event` never reaches the activity listener. A host that wants it in the same history records it
  where it decides it — at Accept/Decline — so there is one record per action and no overlap.
- `logout` reports before the client is deauthenticated, so the action is attributed to the app that was
  connected; a test pins that ordering.
