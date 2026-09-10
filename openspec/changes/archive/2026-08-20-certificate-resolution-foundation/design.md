## Context

See proposal.md — Why.

Four facts about the APIs shape everything below. All verified against the live sandbox on 2026-08-19, not inferred.

**The transports share exactly one retrieval.** v1 offers `AddExemptCertificate`, `DeleteExemptCertificate` and `GetExemptCertificates(customerID)` — no fetch-by-id. v3 adds a connection-scoped fetch-by-id, but a design that used it would work on one transport only. List-by-customer-identity is the whole common surface.

**TaxCloud enforces nothing about ownership.** A cart naming `customerId: "someone-else-99"` and carrying a certificate filed under customer `2` came back exempt, tax `$0.00`, against a `$8.25` baseline. Any certificate on the account applies to any cart that names it.

**Nothing is verified.** A certificate created with the invented tax id `12-3456789` was accepted. The v3 create endpoint validates enum membership and field length; nothing checks that the claim is real.

**v3 is the lossier representation.** For a certificate v1 reports as `Resale`, v3 reports `"reason": "Unknown"` — a value outside v3's own documented enum. v3 has no tax-id field at all, and cannot create single-purchase certificates. The portal, meanwhile, writes v1-compatible certificates, so a merchant's existing certificates are the richer kind.

The existing code is a single-certificate validator: `validate($certificateID, $customerID, $destinationState, $store)` answers "does this one pasted certificate cover this state". Both transports implement it, both cache the answer per `(customer, certificate, account)`, both fail closed.

## Goals / Non-Goals

**Goals:**
- Make "the customer's certificates" a thing the module can obtain, on either transport, without callers knowing which.
- Put ownership enforcement somewhere a later reader cannot mistake for redundant validation.
- Leave Change B with nothing to design about identity, resolution or persistence — only presentation.

**Non-Goals:**
- Everything in proposal.md — Non-goals.
- Preserving `getValidatedCertificateID()` as a public shape. It is superseded; keeping it as a facade over set-based resolution would leave two ways to ask the same question.

## Decisions

### A `CertificateGatewayInterface`, alongside the existing segregated contracts

`Api/` already splits the gateway by concern — `LookupGatewayInterface`, `OrderGatewayInterface`, `AddressGatewayInterface`, `ExemptionGatewayInterface`, `TicLookupInterface` — with `GatewayInterface` extending them and `Router` implementing the union. Certificates follow that pattern rather than inventing one: `list`, `create`, `delete`, each taking the store, each dispatched by `Router` on `api_type`.

`ExemptionGatewayInterface` is **replaced**, not extended. Its single method answers a question the new capability asks differently, and `Router`, `Api`/`SoapGateway` and `RestGateway` are its only implementors.

*Alternative considered:* one fat `CertificateManagerInterface` owning both transport calls and policy. Rejected — it puts "which certificate applies" behind the same seam as "how do I talk to v1", and the two change for entirely different reasons.

### Policy lives above the gateway, in a resolver

The gateway returns certificates. A `CertificateResolver` decides which one applies: filter to enabled certificates covering the destination, then explicit attachment → group auto-apply → none. It is the only component the lookup path talks to, and the only place the precedence rule exists.

This is what makes the Change B slot real rather than aspirational: group auto-apply is a branch in one method, and the settings that drive it are the only thing missing.

*Alternative considered:* leaving precedence in the lookup path, as it is today. Rejected — the lookup path exists twice, once per transport, and the rule would drift.

### Identity resolution is one component, used by reads and writes alike

`TaxCloudCustomerIdentity::resolve($customer, $store)` returns the stored attribute or the entity id. Every certificate call goes through it.

The failure this prevents is precisely WooCommerce's: their certificate path sends the bare user id while their order path sends `'customer-' . $id`, and DEV-6819 and DEV-9165 were both spent reconciling identities their own code had split. One resolver makes that class of bug unreachable rather than merely fixed.

The attribute is admin-only. Magento's `used_in_forms` governs where an attribute is *rendered*, not whether it can be written, so the customer-facing prohibition is enforced where customer data is saved, not by omitting a form field. This is exactly the kind of control that looks redundant and is not: with it removed, a crafted account-edit submission grants its sender any exemption on the account.

*Alternative considered:* WooCommerce's approach — no field, guess five identifiers per lookup (entity id, `customer-<id>`, login, email, `login-<id>`). Rejected. It costs five round-trips per cache miss, still fails for the `acme-corp` case that motivates this work, fails invisibly, and makes `user_email` a lookup key — so a certificate filed under an email address attaches to whoever registers with it. A discovery action in Change B gives merchants the same convenience with a human in the loop and nothing keyed on guessable identifiers.

### Ownership is enforced in the resolver, not at the call sites

Every path that names a certificate — checkout selection, an admin choosing one, a value stored on the customer — passes the identifier through the resolver, which accepts it only if it appears in that customer's own listing.

It has to sit here rather than in a controller because the whole point is that there is no other gate: TaxCloud will honour any certificate on the account. Placing the check at each entry point means the next entry point is one omission away from a privilege escalation. Placed in the resolver, an entry point that forgets is one that cannot apply a certificate at all — the failure is inert, not exploitable.

### One certificate model, with per-transport mappers and absent-not-invented

A `Certificate` value object — identifier, customer identity, covered states, disabled flag, single-purchase flag, and optional descriptive detail. Two mappers.

The rule that matters: a value a transport does not supply, or supplies outside its documented range, becomes **absent**. Concretely, v3's `reason: "Unknown"` maps to no reason rather than to a literal "Unknown" the UI would display as though the certificate said it. A REST store shows less about a certificate than a SOAP store, and never shows something untrue about it.

This is the decision most likely to be undone by accident, because a mapper author reaching for a default is following ordinary instincts. It is called out in the specs for that reason.

### Order storage: identifier plus snapshot, written once

Two columns on `sales_order` beside the existing `taxcloud_captured`: the applied certificate identifier, and a serialized snapshot of its detail.

The snapshot is the point. TaxCloud does not store the certificate document and does not track expiry — their documentation is explicit that both remain the merchant's responsibility — and certificates can be deleted. An identifier alone is a pointer into a store that may not contain the target when an auditor asks why a sale was untaxed. WooCommerce keeps only the identifier; this is a place to be better rather than to match.

Written when the exemption is applied to the order, never rewritten. A snapshot that tracked later edits would describe the certificate as it is now, which is not the question anyone asks it.

*Alternative considered:* a separate table keyed by order, for multiple certificates per order. Rejected as speculative — one order is exempted by one certificate, and the module has no notion of splitting an order across exemptions.

### Cache keyed per (identity, account), invalidated on write

The existing key is `(customer, certificate, account)` because the unit cached was one certificate's states. The unit is now the customer's certificate set, so the key drops the certificate component and keys on the resolved TaxCloud identity instead of the Magento customer id — otherwise two customers sharing an identity would each fetch and cache the same set separately, and one could be stale while the other is fresh.

Creating or deleting invalidates that customer's entry. Failures are still never cached, preserving today's behaviour: a transient outage must not pin an empty set for the TTL.

## Risks / Trade-offs

- **Set-based resolution turns one certificate read into a listing, on every cache miss.** → It is one request either way; the existing code already fetched the customer's whole listing to find one certificate in it. The cache absorbs the rest.
- **A customer with many certificates pages.** → v3 pages at 100; v1 returns the set. Realistic holdings are single digits. Not designed for beyond a bounded page count, and worth revisiting only if a merchant proves otherwise.
- **Removing `getValidatedCertificateID()` breaks anything calling the gateway directly.** → It is module-internal and not part of a published extension point. Worth a note for anyone who wrapped it.
- **Two customers sharing an identity share exemptions, by design.** → Intended, and the reason the attribute is ACL-gated and logged. The risk is a merchant setting it without understanding that; Change B's UI should say so at the point of editing.
- **The snapshot duplicates data that also lives in TaxCloud.** → Deliberate. It is a record of what was relied on, not a cache of current state, and its value is precisely that it does not change when TaxCloud does.
- **Identity is not store-scoped while certificates are account-scoped.** → A customer on two stores with different TaxCloud accounts resolves one identity against two accounts, which is correct: the identity is who they are, the account is where you look. But a merchant could set an identity meaningful on one account and meaningless on the other, and see exemptions on one store only. Acceptable; the alternative is a per-store identity nobody would maintain.

## Migration Plan

None in this change — it is additive. No attribute is removed, no column is dropped, and `taxcloud_cert` continues to be honoured as the explicit-attachment slot.

Existing behaviour is preserved without moving any data, because today's lookup already resolves against the Magento entity id and the new identity defaults to exactly that. A certificate that resolves today resolves after this change; one that does not, still does not — but becomes diagnosable rather than silent.

Retiring `taxcloud_cert` and migrating its values ships with Change B, alongside the UI that replaces it.

## Open Questions

None blocking.

One to settle when Change B's admin UI is designed rather than now, since it changes no interface here: whether a customer's certificate listing should be filtered to certificates covering *any* state the store has nexus in. It would shorten the list a merchant sees, but it would also hide a certificate that becomes relevant the moment nexus changes, so it is a presentation decision with a compliance edge — better made with the screen in front of us.
