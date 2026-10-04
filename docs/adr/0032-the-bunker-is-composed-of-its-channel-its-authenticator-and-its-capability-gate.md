# 32. The bunker is composed of its channel, its authenticator and its capability gate

## Status

Accepted

Supersedes ADR-0019. The authenticator and the authoriser remain separate, mandatory concerns, and the observers remain setter-injected. What is revised is the shape of the constructor: the five ports are no longer injected one by one. This record holds the complete current decision.

## Context

To answer a request the bunker needs a transport, a signer, an authenticator that decides whether a connecting client's secret is currently valid (ADR-0023), an authoriser that says whether a connected client's grants cover a request (ADR-0017), and a clock. ADR-0019 took all five in the constructor and argued that none could be gathered or split away.

Two of those arguments no longer hold. The transport, the signer's key on the wire and the clock's subscription window are the endpoint's channel, shared with the client (ADR-0031). What is left of the signer and the clock is used in one place only, together with the authoriser: deciding a capability request (`get_public_key`, `sign_event`, the four cipher methods), which is answered as the user when the client's grants cover it and otherwise stamped with the time and deferred for a human decision, and answering such a request once it is approved. That is one job, with one reason to change, and the rest of the request pipeline (decrypting, authenticating, dispatching, replying) never touches the signer, the authoriser or the clock directly.

NIP-46 also lets the remote-signer key differ from the user key. With one signer for both, the bunker could not be run that way.

The forces ADR-0019 recorded still stand. The authenticator answers *is this secret live*, once, at connection; the authoriser answers *may this client do this*, on every request, from a permission; a host implements them over different state. The authoriser cannot be optional: without it the bunker cannot choose between answering and asking, and either default is wrong. The queue, activity and secret listeners are observers: with none set, every request is still answered correctly.

## Decision

`Nip46Bunker` takes three collaborators:

- a `Nip46ChannelInterface` (ADR-0031), whose signer is the remote-signer key: the key the `bunker://` URL advertises and a `connect` request's first parameter is checked against;
- a `Nip46AuthenticatorInterface`, unchanged;
- a `CapabilityGateInterface`. `CapabilityGate` takes the user's signer, the authoriser and the clock. `answerOrDefer(incoming, detail)` returns the response, answered as the user, when the authoriser grants the request's permission, and otherwise a `PendingRequest` keyed by the carrier event and stamped with the clock's now. `answer(pendingRequest)` answers an approved request as the user.

Carried forward from ADR-0019: the authenticator and the authoriser are separate ports, the authoriser is mandatory (now inside the gate), and the observers stay setter-injected. What decides between constructor and setter is whether the bunker can answer correctly without the collaborator.

The common case passes the same signer to the channel and the gate.

## Consequences

- A host wires `new Nip46Bunker(new Nip46Channel($transport, $signer, $clock), $authenticator, new CapabilityGate($signer, $authoriser, $clock))`.
- A bunker whose remote-signer key differs from the user key works: `get_public_key` names the user, events are signed as the user, and envelopes are sealed with the remote-signer key. A round-trip test runs exactly that.
- The gate is the bunker's own collaborator, not a host port: the host supplies the authoriser and the signer, never the gate.
- A reader may be tempted to merge the authenticator and the authoriser into one policy port, or to demote the authoriser to an optional setter. Both mistakes are still ruled out: the first conflates identity with capability, the second makes correctness optional.
- If a fourth mandatory collaborator appears, question the shape of the class rather than extend the list; this record is then superseded, not edited.
