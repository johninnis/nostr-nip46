# 9. A request the client cannot encrypt is a returned failure

## Status

Accepted

## Context

Before the client publishes a request it seals it: the request JSON is encrypted to the remote signer
under the session cipher. The payload being encrypted is caller-supplied — `sign_event` carries the
host's event verbatim, and a host may relay arbitrary plaintext through the `nip44_encrypt` /
`nip04_encrypt` methods — and a cipher legitimately rejects it: NIP-44 refuses a plaintext that is
empty or over 65535 bytes. So a perfectly ordinary host action (signing a large event) can make the
one `seal` call in `call()` throw.

Letting that throw propagate breaks the client's contract twice over. First, every anticipated outcome
of a call — timeout, rejection, malformed response — is returned as a typed `Nip46Failure` the caller
must handle; an uncaught cipher exception escaping `signEvent()` is a crash path hiding behind an API
whose signature promises `Event|Nip46Failure`. Second, the pending-response slot was opened before
sealing: a slot registered for a request that is never published is never awaited and never times out,
so every host implementation of the pending-responses port leaks it.

The bunker faced the same force in mirror image — an outbound payload its cipher cannot carry, with a
party waiting on the result — and converts the throw into a correlated protocol error (ADR-0003). The
client is the last edge where an outbound crypto failure was still allowed to escape.

## Decision

`call()` seals the request **before** opening a pending-response slot, catches a throwable from that
single seal call, and returns a `Nip46Failure` with reason `EncryptionFailed`. The catch is scoped to
the one seal call and nothing else; any other fault in the client still bubbles to the host.

## Consequences

- `signEvent()` and `call()` keep their promise: every anticipated failure of a request, including one
  the session cipher cannot carry, arrives as a value the type system forces the caller to handle.
- No pending slot exists for a request that was never published, so no host port implementation can
  leak one; a slot is opened only once there is a sealed event to send.
- The signing port's contract is unchanged: `encrypt` still signals failure by throwing (ADR-0003),
  and the conversion to a value happens here, at the request boundary.
- The call site carries a one-line fence pointing here, and a test drives a signer whose `encrypt`
  throws on an over-long plaintext and asserts the returned failure, so the catch cannot be removed as
  "dead" without breaking the build.
