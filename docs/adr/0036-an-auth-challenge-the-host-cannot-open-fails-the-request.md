# 36. An auth challenge the host cannot open fails the request

## Status

Accepted

## Context

NIP-46 lets a bunker answer a request with an `auth_url` challenge: the client shows the URL to the user and keeps listening for the real reply under the same request id. The client's auth-url listener is optional (ADR-0013 surfaces the challenge only as a validated web URL), because a host with no way to open a page — a background task, a CLI — still wants a signer.

Such a host could ignore the challenge and let the request wait. But the reply the bunker promises arrives only after the user authorises in a page nobody opened, so the request would sit until its timeout and then report a timeout — hiding the real reason from the one party who could act on it.

## Decision

When a valid challenge arrives and no auth-url listener is registered, the client fails the request at once: the pending slot is completed with a failure whose detail names the method's failure mode and carries the URL (`bunker requires authentication: <url>`). When a listener is registered, it is called and the request stays pending for the real reply.

## Consequences

- A host without a way to show the page learns why the request failed, and when, rather than waiting out the timeout.
- The failure carries the URL, so the host can still offer it to the user by other means.
- A challenge carrying a non-web URL is dropped either way (ADR-0013), so this failure never carries one.
- A fence at the branch in `Nip46Client::onEvent` points here, and a test pins that a listenerless challenge fails the request instead of timing out.
