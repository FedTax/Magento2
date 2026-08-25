## Context

See proposal.md — Why. The foundation change left exactly the seams this one fills: a routed certificate gateway, a repository with invalidation, an identity resolver, an ownership check, and a precedence rule with an empty branch where group auto-apply goes.

Two facts from that change still govern everything here.

**TaxCloud enforces no ownership.** A cart naming one customer and carrying another's certificate came back exempt against a live sandbox. Every new surface adds a place where a certificate identifier arrives from a browser, and not one of them may trust it.

**Nothing verifies a certificate.** One created with an invented tax id was accepted. The software cannot tell a genuine exemption from a fabricated one, which is why the gating settings are a control rather than a convenience, and why they default to off.

## Goals / Non-Goals

**Goals:**
- Make every exemption operation reachable by the people who need it, without adding a second way to decide who may do what.
- Retire `taxcloud_cert` without any store losing an exemption that worked.

**Non-Goals:**
- Everything in proposal.md — Non-goals.
- A certificate CRUD REST/GraphQL API. The surfaces here are pages; exposing certificate mutation as a customer-token API multiplies the ownership surface for no requested benefit.

## Decisions

### Controllers resolve, they never decide

Every new endpoint — admin and storefront — funnels through `CertificateResolver` for ownership and `CertificateRepository` for reads and writes. No controller compares a certificate identifier itself.

This is the same reasoning that put the ownership check in the resolver rather than at call sites, now paying off: six new entry points arrive in this change, and the property that a forgetful one fails inert rather than exploitable only holds while none of them re-implements the check. A controller that skips the resolver cannot apply a certificate at all.

*Alternative considered:* a thin service per surface, each doing its own permission logic. Rejected — three surfaces with three interpretations of "may this customer use this certificate" is exactly how the WooCommerce plugin ended up keying certificate lookup on email addresses.

### Group auto-apply fills the existing branch, and consults the setting there

`CertificateResolver::resolve()` already returns early when nothing is claimed. That early return becomes: consult the store's exempt groups; if the customer is in one, pick the first eligible certificate; otherwise return as before.

Placing it there preserves the property the foundation established — a store not using exemptions pays no API call per cart — because the group check is a config read, and only a customer who passes it causes a listing.

An explicit clearing must survive, or a customer in an exempt group could never remove an exemption they are entitled to decline. That is a distinct state from "nothing chosen", so the quote records the clearing rather than the absence of a choice.

### Settings gate the surfaces, not the resolution

The master switch and group restriction decide what is *shown* and what a *request* may do. They deliberately do not gate `CertificateResolver`'s core: an order that already carries a certificate keeps resolving it even if a merchant later restricts the surfaces, because the alternative is a store silently re-taxing orders whose exemption was legitimately granted.

The one place a setting does reach resolution is group auto-apply, which is a resolution step defined entirely by configuration.

### Migration copies before it deletes, in two patches

One patch reads every `taxcloud_cert` value and writes it into the new per-customer attachment storage. A second, ordered after it, removes the attribute.

Split because a single patch that migrates and drops in one pass has no safe failure point: an interruption between the two halves leaves values neither copied nor recoverable. Separated, an interruption leaves the old attribute still present and the migration re-runnable.

The values themselves need no interpretation. A pasted identifier only ever worked if it was filed under the customer's entity id — that is what the old code queried — and the new identity defaults to the entity id, so every configuration that worked keeps working, and every one that silently did not becomes visible in the grid instead.

*Alternative considered:* leaving the attribute in place as a deprecated read-only field. Rejected — two stores of the same fact, one of them stale the moment anyone uses the new UI.

### The admin grid is store-scoped, and says so

Certificates belong to a TaxCloud account, and a multi-store install may point stores at different accounts. A grid that silently reported one store's account while an administrator thought about another would be actively misleading, so the grid names the store whose account it queried.

### Storefront creation is confined to exempt groups by default

The customer-facing creation form is available only to customers the store treats as exempt. A merchant putting a customer in an exempt group is the vetting step that stands in for verification nobody performs.

*Alternative considered:* creation open to any signed-in customer when exemptions are enabled, as the WooCommerce plugin allows. Rejected as a default: on a consumer storefront it amounts to a self-service "stop charging me tax" button, and the merchant carries the liability. A store that wants it can nominate a group containing everyone.

## Risks / Trade-offs

- **Six new entry points, each a place to leak someone else's exemption.** → All of them route through one resolver, and the ownership tests are written against the resolver rather than per controller so a new surface inherits the coverage.
- **Removing `taxcloud_cert` breaks anything reading it.** → Values migrate; the attribute does not. This is the release's upgrade note and the reason both changes ship together.
- **Group auto-apply can exempt a customer nobody consciously exempted for that order.** → It is off by default, requires nominating groups, and is exactly what a merchant asks for when they place a customer in an exempt group. The order's record still captures what was applied.
- **A customer creating their own certificate is asserting something legally binding.** → The form states the attestation and the permanence. The module cannot verify either, and does not pretend to.
- **Checkout selection recalculates tax on change, so a slow TaxCloud makes checkout feel slow.** → Same round trip the totals already make; the certificate list itself is cached.

## Migration Plan

Two ordered data patches: copy `taxcloud_cert` into the new attachment storage, then remove the attribute. Re-runnable, and an interruption between them leaves the source intact.

Release notes must carry the attribute removal — it is the one change a merchant or integrator has to act on. Stores that never enabled exemptions are otherwise unaffected, since the master switch defaults to off.

Rollback restores the attribute definition but not values written after the upgrade; a store that has begun managing certificates through the new UI should not roll back past it.

## Open Questions

None blocking.

One to settle with the first merchant who asks rather than now: whether the admin grid should offer bulk actions across customers (import a list of identities, or apply one certificate to several customers). It is additive, and speculating at the shape before anyone has described their workflow would produce a screen built for an imagined process.
