# 13. An auth_url challenge is surfaced only as a web URL

## Status

Accepted

## Context

A bunker that needs out-of-band authorisation answers a request with an `auth_url` challenge, and the
client surfaces the carried URL to its host, which is expected to open it in a browser so the user can
authorise there. The spec calls the value simply a URL and attaches no further constraint.

The URL is counterparty-supplied input. The client role must be safe against any bunker it is pointed
at: a malicious or compromised bunker could send a `javascript:`, `data:` or `file:` URI, and a host
that dutifully opens whatever it is handed would execute it in the user's browser context. The host
cannot be relied on to validate — every host would have to re-derive the same check, and forgetting it
is invisible until exploited.

## Decision

The auth-url listener port receives an `AuthUrl` value object, never a raw string. `AuthUrl` parses at
the boundary and accepts only absolute `http` or `https` URLs with a host; anything else — other
schemes, scheme-less strings, malformed input — fails the parse, and the client drops the challenge
without notifying the listener, exactly as it drops any other malformed response.

## Consequences

- A host cannot receive a non-web URL: the type guarantees what the listener may safely open, and no
  host re-implements the check.
- A bunker using a custom scheme for a native-app deep link is not supported. That is deliberate:
  nothing in the wild does this, and widening the allowed schemes is a one-line change to be argued in
  a superseding record if it ever becomes real.
- A reader may see the http(s) allowlist as needless strictness over the spec's bare "URL" and be
  tempted to pass the string through. The fence at the allowlist points here, and a test drives a
  `javascript:` challenge and asserts the listener stays silent.
