# 21. The audit summarises a signed event, but never a cipher payload

## Status

Accepted

Supersedes ADR-0020.

## Context

ADR-0014 established the activity listener and drew a hard line around what it may carry: "only derived
metadata — never the raw request event, its signature, or any decrypted plaintext", so that the
confidentiality and non-retention of ADR-0005 were preserved. ADR-0020 carried that line forward
unchanged while restating *which* requests are reported.

That line is too coarse, and it costs the host the single most useful fact about a signature. A
`sign_event` request the bunker answers under a standing grant reports its method and nothing else: an
audit trail records `sign_event — answered` with no indication of *what kind of event was signed*. Every
signature an app makes looks identical to every other. A host cannot distinguish an app that was granted
`sign_event:1` and signs short notes from the same app signing a `kind 5` deletion or a `kind 30023`
long-form article, even though the grant model (ADR-0017) is expressed in exactly those kind terms. The
one path that does record the kind is the queued path, where the host sees the request itself — so the
audit is richest precisely where the operator was already watching, and blindest where they were not.

The blanket "no plaintext" rule reads as one confidentiality policy but is actually covering two very
different payloads:

- A `nip04_*` / `nip44_*` payload is **private correspondence**. The signer is a transformation step: it
  decrypts or encrypts bytes that belong to a conversation between the user and a counterparty, and the
  plaintext is never meant to leave that conversation. Retaining it in an audit log would make the signer
  a durable store of the user's private messages — a far worse artefact than the request it came from,
  and squarely what ADR-0005 is protecting.
- A `sign_event` payload is **the body of an event about to be signed and broadcast**. Its whole purpose
  is publication to relays. Recording it locally cannot disclose anything the user is not, in the same
  breath, asking the signer to publish under their own key.

Treating these as one category is what forces the audit to be useless rather than merely careful.

## Decision

Carried forward from ADR-0020, unchanged: an optional activity listener receives a `BunkerActivity` for
each request the bunker answers by itself and nothing about a request it queues; a queued request is
recorded by the host at the point of decision; the same method may be reported by the bunker on one
request and recorded by the host on the next, according to whether the app's grants covered it; the
listener is a pure observer whose absence changes nothing. The activity is attributed by app id, not
client public key, for the reasons ADR-0014 gave.

Revised: the activity may carry a **`SignEventSummary` — the event kind and the event content — and only
for a `sign_event` request**. No other payload is summarised. Every other fact stays derived metadata as
before: the raw request event, its signature, and the request id are still never surfaced.

The distinction is enforced by the type system rather than by a rule anyone has to remember.
`PendingRequestDetailInterface` gains `getSignEventSummary(): ?SignEventSummary`; `SignEventDetail`
returns a summary and `CipherDetail` and `GetPublicKeyDetail` return `null`. There is no method-name
check anywhere, and `SignEventSummary` pairs kind and content as non-nullable fields, so a content string
cannot exist in an activity without the event kind that identifies what it is. A future detail type
carrying a confidential payload gets `null` by writing the same three lines its siblings do; leaking one
would take a deliberate act, not an oversight.

Reporting the full content, not a truncated one, is deliberate: how much to keep is a retention decision,
and retention belongs to whoever writes the log. The bunker retains nothing — the summary lives only as
long as the listener call.

## Consequences

- An audit trail distinguishes what an app actually signed, on the autonomously-answered path where the
  operator saw nothing. The kind is available for every signature, not only the ones that were queued.
- A host that persists the content is choosing to keep event bodies at rest, and must bound and protect
  them itself. The bunker hands over the whole content precisely so that this choice is visible in the
  host's code rather than pre-made here.
- ADR-0005 still stands and is still the record protecting the request event. What is surfaced is the
  event the client asked to have signed, reconstructed from the request it already decrypted — not the
  carrier event, not its signature, and not the conversation payload of a cipher request.
- A reader who expects the "never any decrypted plaintext" wording of ADR-0014 will not find it. It was
  not narrowed by accident: the argument is that a to-be-published event body and a private message are
  different kinds of secret, and only the second one was ever the point.
- If a cipher payload is ever wanted in the audit, this record is what must be superseded, and the
  argument above is what has to be answered.
