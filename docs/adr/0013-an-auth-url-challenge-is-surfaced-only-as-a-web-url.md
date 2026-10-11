# 13. An auth_url challenge is surfaced only as a web URL

## Status

Accepted

## Context

A bunker that needs out-of-band authorisation answers a request with an `auth_url` challenge, and the client surfaces the carried URL to its host, which is expected to open it in a browser so the user can authorise there. The spec calls the value simply a URL and attaches no further constraint.

The URL is counterparty-supplied input. The client role must be safe against any bunker it is pointed at: a malicious or compromised bunker could send a `javascript:`, `data:` or `file:` URI, and a host that dutifully opens whatever it is handed would execute it in the user's browser context. The host cannot be relied on to validate — every host would have to re-derive the same check, and forgetting it is invisible until exploited.

URL parsers disagree at the edges: PHP's `parse_url` accepts a host containing a space, and a WHATWG parser repairs `https:example.com` and `https:\\evil.com` into valid URLs, so "an http(s) URL with a host" names a different set of strings in each port. PHP 8.4 has no WHATWG parser to standardise on. A rule stated on the string itself is one every port can apply identically, and a string that passes it needs no repair, so the host can be handed exactly what was checked.

A challenge that fails the check could be dropped like any malformed response, but it is not like one: a response that cannot be decrypted names no request, while a challenge carries the id of the request it answers. Dropping it leaves that request waiting for a reply that comes only after an authentication nobody was shown, so it times out and hides the reason, the failure ADR-0036 exists to prevent.

## Decision

- The auth-url listener receives an `AuthUrl`, never a raw string. `AuthUrl::tryFromString` accepts a string only when all of these hold:
  - it starts with `http://` or `https://`, the scheme compared without regard to case;
  - the host that follows, up to the first `/`, `?` or `#` or the end of the string, is non-empty, contains no `@` and no `:`, and may be followed by a `:` and a decimal port of one or more ASCII digits;
  - the whole string contains no whitespace (Unicode `White_Space`), no control character (Unicode `Cc`: C0, DEL and C1) and no `\`, and is valid UTF-8.
- The string is kept exactly as received: it is not trimmed, case-folded or re-serialised, so what the host opens is what was checked.
- A challenge whose URL fails the check, or that carries none, fails the request it answers at once, as a `Nip46Failure` with reason `SignerError` and the detail `bunker sent an authentication URL that is not a web URL`. The detail carries no URL, and the listener is not called, whether or not one is registered.

## Consequences

- A host can never receive a non-web URL through a challenge, in its listener or in a failure's detail: the type guarantees what the listener may safely open, and no host re-implements the check.
- `https:example.com`, `https:\\evil.com`, `https://user@host/`, a host containing a space, leading or trailing whitespace, and a bracketed IPv6 host are refused, not repaired.
- A request answered by an unusable challenge fails when the challenge arrives, not at its timeout.
- A bunker using a custom scheme for a native-app deep link is not supported. That is deliberate: nothing in the wild does this, and widening the allowed schemes is to be argued in a superseding record if it ever becomes real.
- The rule reads like a hand-rolled URL parser a reviewer will want to replace with `parse_url` or a library, or like needless strictness over the spec's bare "URL". A fence at `AuthUrl` points here. The shared vectors in `tests/Vectors/auth-url.json`, the same file @innis/nostr-nip46 runs, pin every string accepted and refused and what the host is handed, through `AuthUrlTest` and through `Nip46ClientTest` with and without a listener; a string that is not UTF-8, which JSON cannot hold, is pinned locally.
- Shared decision: nostr-adrs ADR-0054.
