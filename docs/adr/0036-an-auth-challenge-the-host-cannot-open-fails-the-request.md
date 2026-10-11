# 36. An auth challenge the host cannot open fails the request

## Status

Accepted

## Context

NIP-46 lets a bunker answer a request with an `auth_url` challenge: the client shows the URL to the user and keeps listening for the real reply under the same request id. The client's auth-url listener is optional (ADR-0013 surfaces the challenge only as a validated web URL), because a host with no way to open a page — a background task, a CLI — still wants a signer.

Such a host could ignore the challenge and let the request wait. But the reply the bunker promises arrives only after the user authorises in a page nobody opened, so the request would sit until its timeout and then report a timeout — hiding the real reason from the one party who could act on it.

## Decision

When a valid challenge (ADR-0013) arrives and no auth-url listener is registered, the client fails the request at once: the challenge completes the pending slot, and the waiting request returns a `Nip46Failure` with reason `SignerError` whose detail carries the URL exactly as received (`bunker requires authentication: <url>`). When a listener is registered, it is called and the request stays pending for the real reply, and its timeout restarts as an approval window, five minutes by default (`Nip46ClientInterface::DEFAULT_APPROVAL_WINDOW_SECONDS`, changed by the `Nip46Client` constructor's `approvalWindowSeconds`). The request timeout bounds a bunker that does not answer; a challenge is an answer, saying a person must now act, and a person approving on another device takes longer than a request takes on the network. The client owns no deadline — the host's pending-response port does (ADR-0008) — so it restarts the waiting request's timeout through that port, `Nip46PendingResponsesInterface::restartTimeout`. Each challenge for the request restarts the window, replacing whatever was left of the request timeout or of the window before it. A request still unanswered when the window closes fails as a timed-out request does: a `Nip46Failure` with reason `TimedOut`.

The failure is the client's own, never the bunker's error, so it is never passed through the decline classifier (ADR-0027): a URL containing `cancel` or `denied` does not make it `Rejected`.

## Consequences

- A host without a way to show the page learns why the request failed, and when, rather than waiting out the timeout.
- The failure carries the URL, so the host can still offer it to the user by other means.
- A challenge whose URL is not a web URL fails the request whether or not a listener is registered, with a detail that carries no URL (ADR-0013), so this failure never carries one.
- A request waiting on a person is not cut off at the request timeout, and a host need not raise that timeout — which would also slow its detection of a bunker that never answers — to give a person time to approve. Both ports apply the same default window.
- Every host's pending-response implementation must be able to restart an await's timeout, before the await begins or while it runs (ADR-0008); a fixed timer set once when the await begins no longer satisfies the port.
- A bunker that keeps sending challenges keeps its request open. Each one reaches the listener, so the host sees it happen and can close the client; capping the restarts would be a change to this record.
- The window is set once, on the constructor, while the request timeout is an argument of each call: a per-call window would give `connect` and each cipher method a fourth parameter.
- Fences at `Nip46Client::challenge` and at the failure the client writes point here, and tests pin that a listenerless challenge fails the request instead of timing out, carries the URL exactly as received, and is not a decline when the URL holds a decline word; and, with a listener, that a reply arriving after the request timeout but within the window settles the request, that one still unanswered when the window closes times out, that the default window closes five minutes after the challenge, that the window can be configured, that a further challenge restarts it, and that a request no challenge answered still times out at the request timeout.
- Shared decision: nostr-adrs ADR-0112.
