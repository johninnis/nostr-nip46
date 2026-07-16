# 2. A signing failure is answered as a protocol error

## Status

Accepted

## Context

When the operator approves a queued request, the bunker builds the event and asks the injected signer to
sign it. Signing can fail — the underlying key material may be unavailable, or whatever backs the
injected signer may be down. Such a failure is a genuine fault, and the standing rule is that faults
propagate rather than being caught and converted.

But a NIP-46 client is blocked waiting for a reply correlated to its request id. If the signing fault
propagates out of `approve`, the client receives nothing and hangs until it times out, and the operator's
approval is silently lost. The protocol itself defines an error reply for exactly this: a response whose
`error` field tells the client the request was not fulfilled.

## Decision

`approve` catches a throwable from the single `sign` call and answers the waiting client with an
`error: "signing failed"` response instead of letting it propagate. The catch is scoped to the one
signing call and nothing else; any other fault in the bunker still bubbles to the host's process
boundary.

## Consequences

- A client always receives a correlated reply to an approved request — a signed event or an explicit
  error — and never hangs on a signer that failed.
- Swallowing the throwable is justified only because the caught failure is turned into a defined
  protocol message at the boundary where an external party is waiting on it; it is not a licence to
  catch faults elsewhere. Any other conversion of a fault into a value earns its own record with the
  same shape of justification.
- The call site carries a one-line fence pointing here, and a test drives a signer that throws and
  asserts the `signing failed` reply, so the catch cannot be removed as "dead" without breaking the
  build.
