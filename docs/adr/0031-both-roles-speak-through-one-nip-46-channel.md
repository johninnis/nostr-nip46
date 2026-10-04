# 31. Both roles speak through one NIP-46 channel

## Status

Accepted

## Context

The bunker and the client do the same wire work with the same three dependencies. Each builds a kind-24133 filter addressed to its own key, with a `since` window read from the clock, and subscribes through the transport. Each seals an outbound payload: encrypts it to the peer, drafts a kind-24133 event `p`-tagging the peer, signs it and publishes it. Each opens an inbound envelope: names the cipher from the payload (ADR-0022), decrypts once and keeps the plaintext only if it is a JSON object.

Both roles built their own envelope codec with `new` in the constructor, and each wrapped sealing in its own `try`/`catch`, so the conversion of a refused seal into an outcome (ADR-0028, ADR-0029) was written twice. The codec drafted the envelope without a timestamp, so its `created_at` came from the system clock inside a domain object, not from the clock the roles were given. And each role's constructor took five parameters, three of them only for this shared work.

NIP-46 also separates two keys the bunker had merged: "remote-signer-keypair/pubkey: The keys used by _remote-signer_ to encrypt content and communicate with _client_. This keypair MAY be same as _user-keypair_, but not necessarily." The key that speaks on the relays is a property of the channel, not of the user.

## Decision

`Nip46Channel`, behind `Nip46ChannelInterface`, is one endpoint's presence on kind 24133. It takes the transport, the endpoint's own signer (the key it is addressed by and seals with) and the clock, and offers:

- `publicKey()`: the endpoint's key.
- `listen(relays, ?sender, listener)`: subscribes for kind-24133 events `p`-tagging the endpoint's key, since the clock's now less the skew tolerance, from `sender` when one is given.
- `open(event)`: names the cipher from the payload, decrypts once under the event author's key, and returns the payload only when it is a JSON object (nostr-core's `JsonWireFormat::decodeObject`), else `null`.
- `seal(peer, payloadJson, cipher)`: encrypts, drafts a kind-24133 event `p`-tagging the peer and stamped with the clock's now, and signs it. A throw from the signer is returned as `null`, because a waiting peer must get a correlated answer rather than a fault; each caller turns `null` into its own outcome (ADR-0028, ADR-0029).
- `publish(relays, envelope)`.

Both roles take a channel and nothing else for wire work. The envelope codec is folded into the channel and removed. In the bunker the channel's key is the remote-signer key; in the client it is the client's session key.

## Consequences

- Filter construction, cipher detection, the JSON shape of a payload, the seal-fault conversion and the envelope timestamp each exist once.
- Envelopes carry the injected clock's time, so a test pins them and no domain object reads the system clock.
- `seal` and `publish` stay separate: the client opens its pending-response slot between them (ADR-0028), so a combined send would reintroduce the leak that ordering prevents.
- `publish` forwards to the transport. A reader may call it a pass-through; it is there so neither role holds the transport as well as the channel, which would give each two ways to reach the wire.
- A host wires `new Nip46Channel($transport, $signer, $clock)` for each role it runs.
- `Nip46ChannelTest` pins the clock's timestamp on a sealed envelope, the single decryption, and `null` for a seal the cipher refuses.
