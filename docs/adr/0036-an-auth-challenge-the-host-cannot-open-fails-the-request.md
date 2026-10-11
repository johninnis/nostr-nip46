# 36. An auth challenge the host cannot open fails the request

## Status

Accepted

## Context

NIP-46 lets a bunker answer a request with an `auth_url` challenge: the client shows the URL to the user and keeps listening for the real reply under the same request id. The client's auth-url listener is optional (ADR-0013 surfaces the challenge only as a validated web URL), because a host with no way to open a page — a background task, a CLI — still wants a signer.

Such a host could ignore the challenge and let the request wait. But the reply the bunker promises arrives only after the user authorises in a page nobody opened, so the request would sit until its timeout and then report a timeout — hiding the real reason from the one party who could act on it.

## Decision

When a valid challenge (ADR-0013) arrives and no auth-url listener is registered, the client fails the request at once: the challenge completes the pending slot, and the waiting request returns a `Nip46Failure` with reason `SignerError` whose detail carries the URL exactly as received (`bunker requires authentication: <url>`). When a listener is registered, it is called and the request stays pending for the real reply.

The failure is the client's own, never the bunker's error, so it is never passed through the decline classifier (ADR-0027): a URL containing `cancel` or `denied` does not make it `Rejected`.

## Consequences

- A host without a way to show the page learns why the request failed, and when, rather than waiting out the timeout.
- The failure carries the URL, so the host can still offer it to the user by other means.
- A challenge whose URL is not a web URL fails the request whether or not a listener is registered, with a detail that carries no URL (ADR-0013), so this failure never carries one.
- Fences at `Nip46Client::challenge` and at the failure the client writes point here, and tests pin that a listenerless challenge fails the request instead of timing out, carries the URL exactly as received, and is not a decline when the URL holds a decline word.
- Shared decision: nostr-adrs ADR-0112.
