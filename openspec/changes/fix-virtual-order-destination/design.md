## Context

See proposal.md — Why. Three facts about Magento shape the approach, all verified against 2.4.9 in the integration stack rather than assumed:

1. **Quote addresses are never absent; order addresses are.** `Quote::_getAddressByType()` lazily creates and attaches an empty address of the requested type, so `$quote->getShippingAddress()` is always truthy — a `?:` fallback on a quote address is dead code. `Order::getShippingAddress()` returns `false` outright when the order has none, which is every order placed from a virtual quote (`QuoteManagement::submitQuote` converts a shipping address only when `!$quote->isVirtual()`).
2. **Magento already picks the quote address.** `Quote\Address::getAllItems()` tests `$this->getQuote()->isVirtual()` — the whole quote, not the item — and assigns every item to the billing address when the cart ships nothing, to the shipping address otherwise. `TotalsCollector::collect()` then runs the collectors once per address, and `Model/Tax::collect()` no-ops on an address with no items. So exactly one lookup fires, against the address Magento chose. Nothing on the quote side needs to change.
3. **The order side has no such rule.** `RequestBuilder::buildDestinationFromOrder()` reads `$order->getShippingAddress()` and returns `null` on `false`, and its callers correctly treat `null` as "cannot proceed".

Measured consequence for a placed digital-only order: `buildDestinationFromOrder()` → `null`, `RestRequestBuilder::buildOrderPayload()` → `null` (v3 capture cannot file it), SOAP `authorizedWithCapture` → succeeds anyway because it is cartID-based. That asymmetry is why the defect is invisible on V1 and fatal on V3.

## Goals / Non-Goals

**Goals:**
- One named place that answers "which address is this sale sourced to", for orders and for quotes, so the three current call sites cannot drift apart again.
- The order-side fallback, expressed so that every downstream consumer inherits it without its own edit.
- The quote-side rule stated in code rather than relied on implicitly, and pinned by tests.
- Failure to resolve stays loud: `null` in, failure out, never a fabricated address.

**Non-Goals:**
- No change to how a destination is *shaped* once resolved (street/city/state/ZIP mapping, ZIP parsing, region-code resolution all stay in `RequestBuilder`).
- No change to `Model/Tax::collect()` or `Api::lookupTaxes()`. They read the shipping-assignment address, which is already Magento's answer.
- No new interface in `Api/` — this is an internal collaborator, not an extension point. Adding one would imply third parties may substitute the sourcing rule, which is a compliance decision, not a customisation.

## Decisions

### D1: A dedicated `Model\Address\TaxAddressResolver` rather than a private method on `RequestBuilder`

`RequestBuilder` is the natural home for the order-side call, but two of the three call sites are observers that have no reason to depend on the request builder. `Observer\Sales\RecordCertificate` needs the destination *state*, not a payload; `Observer\Sales\PersistRetailDeliveryFee` needs the quote address that carried the fee. Routing them through `RequestBuilder` would give an observer a collaborator whose other ninety percent is payload assembly.

A small class with two methods keeps the rule in one place at a cost of one file:

- `forOrder($order): ?OrderAddressInterface` — `getShippingAddress() ?: getBillingAddress()`, `null` when neither exists.
- `forQuote($quote): ?Quote\Address` — `$quote->isVirtual() ? getBillingAddress() : getShippingAddress()`.

*Alternative considered — a private helper on `RequestBuilder`, with the observers keeping their inline `?:`.* Rejected: that is the status quo that produced the drift. The two observers currently express the rule in two different ways, one of which (`PersistRetailDeliveryFee`) is dead code on a quote.

*Alternative considered — a plugin on `Order::getShippingAddress()` returning the billing address.* Rejected outright: it would change the address every other Magento module and template sees, including the order view and shipping label generation.

### D2: `forQuote()` reproduces Magento's own test rather than falling back

`PersistRetailDeliveryFee` today does `$quote->getShippingAddress() ?: $quote->getBillingAddress()`, which — per Context (1) — always takes the shipping address. It is right today only because the Colorado fee never applies to a virtual-only cart, so there is never a fee on the billing address to miss. Expressing the rule as `isVirtual() ? billing : shipping` makes it correct by construction instead of correct by coincidence, and matches exactly what `Quote\Address::getAllItems()` does, which is what decides where the totals — and therefore the fee — were collected.

### D3: Logging lives at the call site, not in the resolver

The resolver stays pure and dependency-free: no logger, no config, trivially unit-testable, safe to call in a hot path. The "which address / no usable address" log lines go in `RequestBuilder::buildDestinationFromOrder()`, which already holds the store-scoped `GatewayLogger` (wired in `etc/di.xml:108`) and already owns the US/ZIP validation whose failure the spec requires logging. One method logs the whole outcome, rather than the resolver logging a choice and the caller logging a rejection of it.

### D4: `buildDestinationFromOrder()` keeps its signature and its `null` contract

Every consumer — `RestRequestBuilder::buildOrderPayload()`, `Api::lookupForOrderExempt()`, the v3 refund and exempt re-create paths — already branches on `null` and reports failure with a message. Widening the address it accepts while leaving the contract alone means the fix propagates with zero edits downstream, and the existing "cannot proceed" behaviour still covers the genuinely unusable case (no address, non-US, unparseable ZIP).

### D5: Correctness of the fallback is an equality, not a preference

The requirement "an order is filed against the address it was quoted against" is satisfiable by construction, not by luck:

- Digital-only: the lookup used the quote's **billing** address; `Quote\Address\ToOrderAddress` converts that same address onto the order as its billing address; `shipping ?: billing` selects it.
- Mixed or physical: a shipping address exists on quote and order alike; `shipping ?: billing` selects it and never consults billing.

There is no cart shape where the two disagree, which is why this is a two-branch resolver and not a policy object.

The equality is over the *address*, not the payload. Where **Verify Address** is enabled, `Observer\Sales\Address` normalises the destination on the lookup only — uppercased, ZIP+4 filled in — while the order copy is the raw stored address. That asymmetry predates this change and applies to physical orders identically; it is not something the fallback introduces, and the specs say so explicitly rather than demanding an equality the module has never had.

### D6: The resolver is a defaulted last argument, bound explicitly in di.xml

Discovered while verifying the change against a running install: adding the resolver as a *required* positional argument fataled every consumer whose compiled DI was still the pre-change one — `RequestBuilder::__construct(): Argument #7 must be of type TaxAddressResolver, GatewayLogger given`, because the compiled factory still held the old argument order. A merchant upgrading the module before running `setup:di:compile` would have taken their checkout down.

So each of the three consumers takes `?TaxAddressResolver $addressResolver = null` as its last argument and defaults it to `new TaxAddressResolver()`. This is safe here in a way it usually is not: the class is stateless and has no constructor arguments of its own, so the default is byte-for-byte the object DI would build. There is no configuration to lose — which is the failure mode that normally makes a defaulted Magento constructor argument a trap.

The default alone would, however, make the argument unreachable by DI: an argument the object manager never supplies is an argument no `<preference>` can redirect, so a store that had substituted its own resolver would silently keep running the stock one. `etc/di.xml` therefore binds `addressResolver` explicitly on all three types, and `TaxAddressResolverWiringTest` asserts those three bindings exist — the default is the upgrade safety net, the binding is the real wiring.

*Alternative considered — required argument plus a release note telling merchants to recompile.* Rejected: every Magento upgrade already runs `setup:upgrade`/`setup:di:compile`, so the note would be redundant when followed and useless when not, and the failure mode it guards against is a fatal on the storefront.

## Risks / Trade-offs

- **A physical order that legitimately lacks a shipping address would now file against billing instead of failing.** → No such order exists in Magento: an order without a shipping address is by definition an order whose quote was virtual. The `null` return still covers the case where billing itself is unusable, so nothing is invented.
- **Orders that previously failed to file now file, changing what appears on a merchant's return.** → This is the point of the change, but it is a visible behaviour change for REST merchants selling digital goods. The migration plan below states what does and does not self-heal.
- **`forQuote()` changes which address `PersistRetailDeliveryFee` reads.** → Only for virtual quotes, where the fee is always zero and the observer returns early regardless. No behaviour change is possible today; the change is to make it stay correct if the fee's eligibility rules ever move.
- **The billing address is a weaker sourcing signal than a delivery address.** → Accepted and out of our hands: SSUTA §310.A tier 3 is exactly "an address for the purchaser available from the business records of the seller", and tier 2 is unavailable because a download's place of receipt is unknowable. The alternative currently in production — no filing at all — is not a defensible tier.
- **Log volume.** → One line per order-side destination resolution, at info for the fallback and error for the unusable case, gated by the store's existing logging mode. Order-side resolution happens once per capture/refund, not per request.

## Migration Plan

No schema change, no configuration change, no deployment ordering constraint. Deploy is a code deploy.

**Self-healing:** a failed capture leaves `taxcloud_captured` unset, and `order-capture-lifecycle` retries at the next fulfilment document. A digital-only order placed before the fix but not yet invoiced will therefore capture on its next trigger with no intervention.

**Not self-healing:** a digital-only order already invoiced (or captured under an order-placement trigger) under V3 stays unfiled, because no further fulfilment document will occur — a virtual order is never shipped. Re-driving those is deliberately out of scope for this change (see proposal.md — Non-goals); the flag makes them identifiable later.

**Rollback:** revert the commit. Behaviour returns to failing on digital-only orders; nothing filed in the interim needs undoing, since a filed order is correct under both versions.

## Open Questions

None that affect the specs, the approach or the task breakdown. The backfill decision (whether to add a console command that re-drives orders with `taxcloud_captured` unset) is deferred by design and does not constrain anything here.
