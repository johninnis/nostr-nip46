# 27. A decline is recognised by the words of the bunker's error

## Status

Accepted

## Context

A remote signer answers a request it did not carry out with a response whose `error` is free text. NIP-46 says only: "`error`, _optionally_, it is an error in string form, if any. Its presence indicates an error with the request." It defines no error codes, so nothing on the wire says whether the user declined or the signer failed.

The client returned every `error` as `Nip46Failure` with reason `Rejected`. A host treats a user's "no" differently from a failure: it is not reported, retried or shown as an error. With one reason for both, a host either showed every decline as an error or hid every failure as a decline. `nostr-nip46-ts` and `nostr-nip07-ts` tell the two apart by the error's words, through one classifier.

Matching words in free text reads as a fragile hack, and a reviewer will want to delete it or add more patterns in more places.

## Decision

- `UserRejectionMatcher::matches` is the one place that decides whether a bunker's `error` is a decline. It matches the whole words `rejected`, `denied`, `cancel`, `canceled` and `cancelled`, case-insensitively.
- The client returns a matching `error` as `Nip46Failure` with reason `Rejected`, and any other `error` as reason `SignerError`. Both carry the error text as their detail.
- This package's bunker declines on its operator's behalf with `user rejected` (`Nip46Bunker::reject`), which the matcher recognises.
- The matcher reads only an error the bunker sent. A failure the client writes itself, such as an authentication challenge it cannot open (ADR-0013, ADR-0036), never goes through it.

## Consequences

- A decline worded otherwise (another language, "refused", "not approved") is reported as `SignerError`, and an unrelated error containing one of the words is reported as `Rejected`. Neither is harmful: the request did not succeed either way.
- Supporting a new wording is a change to one pattern and its test. No other code matches decline words.
- A host that handled `Rejected` as "any error" handles `SignerError` as well.
- If NIP-46 defines a decline code, the client reads it and the matcher becomes its fallback.
- Shared decision: nostr-adrs ADR-0041.
