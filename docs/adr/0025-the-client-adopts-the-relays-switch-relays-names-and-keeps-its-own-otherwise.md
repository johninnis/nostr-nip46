# 25. The client adopts the relays switch_relays names, and keeps its own otherwise

## Status

Accepted

## Context

NIP-46: "compliant clients should send a `switch_relays` request immediately upon establishing a connection (always, or at reasonable intervals). Upon receiving such requests, the _remote-signer_ should reply with its updated list of relays, or `null` if there is nothing to be changed. Immediately upon receiving an updated relay list, the _client_ should update its local state and send further requests on the new relays."

Until now the client never sent it, and talked to a signer only on the relays in the `bunker://` URL.

Deployed signers answer `switch_relays` in several ways: a relay list, `null`, an `unsupported method` error, or not at all. Every other client call returns a failure when the signer errors or stays silent. Doing the same here would let a signer that predates the method, or that simply ignores it, break a connection that is otherwise sound. The method is a hint for moving relays, not part of authenticating.

A relay list from the signer is untrusted input. It can be empty, can contain entries that are not relay URLs, or can name the relays the client already uses.

## Decision

`connect()` sends `switch_relays` after `connect` is acknowledged and before `get_public_key`, so the public key request already goes to the relays the signer prefers.

- If the answer is a JSON list with at least one valid relay URL that differs from the current set, the client takes that set (invalid entries dropped, duplicates removed). Every later request is published there, and the client cancels its subscription and subscribes again on the new set to await responses.
- For anything else (`null`, an empty or unusable list, the same set, an error, a timeout) the client keeps the relays it has, and `connect()` goes on to `get_public_key`. A `switch_relays` failure never fails the connection.

The client asks once per `connect()`. Asking again later, "at reasonable intervals", is left to the host reconnecting.

## Consequences

- A client paired on relays of its own choosing moves to the signer's relays as soon as it connects, which is what the specification intends and what ADR-0018's reply is for.
- One more round trip per connection. Against a signer that ignores the method, that is one timeout, the same as the caller's other timeouts.
- A reader may see a failure being dropped and read it as a swallowed error. It is not one: the answer is optional, and "keep the current relays" is a valid outcome. A fence at the call site points here, and tests check that an error, `null` and an empty list all keep the client's relays and still connect.
- Shared decision: nostr-adrs ADR-0060.
