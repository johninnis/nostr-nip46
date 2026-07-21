# 20. The bunker reports what it answers; the host records what it decides

## Status

Accepted

Supersedes ADR-0014.

## Context

The bunker notifies an optional activity listener of the requests it answers on its own, so a host can
keep an audit trail without inspecting the wire. ADR-0014 drew the line at the queue: `sign_event` was
excluded, because a queued request is answered by the host's own approve/reject call, and the host is
the only party that knows *which way* it decided and when. Reporting it from the bunker as well would
double-count every signature, and reporting it at queue time would record an answer that had not
happened.

Generalising the queue (ADR-0017) moves that line. The queue now holds any ungranted request —
decryption, encryption and identity as well as signing — so "exclude `sign_event`" no longer describes
the rule. Worse, the same method can now take either path: `nip44_decrypt` is reported by the bunker
when the app's grants cover it, and decided by the host when they do not.

Stating the exclusion by method name would therefore be wrong. It has to be stated by *who answered*.

## Decision

Carried forward from ADR-0014: an optional activity listener receives a `BunkerActivity` — method, app
id, counterparty where the request has one, and an `Answered` / `Failed` outcome — for each request the
bunker answers by itself, and the listener is a pure observer whose absence changes nothing.

Restated as the general rule: **the bunker reports a request when it answers it; it reports nothing
about a request it queues.** A queued request is recorded by the host at the point of decision, where
the outcome is known. The same method may be reported by the bunker on one request and recorded by the
host on the next, according to whether the app's grants covered it.

## Consequences

- Every answered request appears exactly once in an audit trail, from exactly one source, whichever path
  it took.
- A host that records only the bunker's activity has an incomplete log: everything it was asked about is
  missing. Recording the approve/reject decision is the host's job, and always was.
- The rule no longer mentions `sign_event`, so adding an askable method needs no change here.
- A reader may expect the queue to emit activity when a request arrives. It does not: arrival is not an
  outcome, and the queue-change listener already exists for that.
