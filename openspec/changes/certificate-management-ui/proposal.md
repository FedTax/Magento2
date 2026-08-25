## Why

The foundation change made the module able to read, create and delete certificates on either transport, decide which one exempts an order, and record what was relied on. Nothing exposes any of it. A merchant still administers exemptions by opening the TaxCloud portal, creating a certificate by hand, copying a UUID, and pasting it into a free-text customer field — the workflow TaxCloud itself documents, and one that cannot represent a customer holding more than one certificate.

Three consequences follow from that single text field:

**A customer can hold exactly one certificate.** Real exempt buyers hold several — a resale certificate covering one state, a different one elsewhere. The attribute cannot express it, so the module has never been able to pick the right one.

**Nothing tells anyone when it is wrong.** A mistyped identifier, or one filed in TaxCloud under a name rather than the customer's identity, produces a silently taxed customer and no diagnostic anywhere. The identity that fixes it exists now but has no interface.

**Every exemption starts with manual portal work.** The extension can create certificates; nobody can ask it to.

## What Changes

- **A certificate grid on the customer admin page** — list, view, add, delete — with the TaxCloud identity beside it and a discovery action that shows what that identity currently resolves.
- **Certificate management in My Account**, so an exempt customer can add and remove their own certificates rather than emailing paperwork to a merchant.
- **Certificate selection at checkout** for signed-in customers: choose among certificates that cover the destination. Selection only; creation lives in My Account.
- **Certificate selection on the admin order screen**, while the order is still uncaptured.
- **Customer-group auto-apply**, filling the slot the resolver already leaves: for customers in a nominated group, a covering certificate applies without anyone choosing it.
- **Settings** gating all of it: a master switch defaulting to off, the exempt groups, restrict-to-those-groups, and the company name recorded on certificates.
- **A cache-refresh control**, so a certificate changed in the TaxCloud portal can be picked up without waiting out the hour.
- **BREAKING: the `taxcloud_cert` attribute is removed**, its values migrated to the new storage.

## Capabilities

### New Capabilities

None. Everything here extends the capability the foundation introduced.

### Modified Capabilities

- `exemption-certificates`: the capability currently describes what the module can do with certificates and says nothing about who may ask it to. This change adds the surfaces — administrator, customer, checkout, order — the settings that gate them, group auto-apply as a resolution step, and the retirement of the legacy attribute.

## Non-goals

- **Single-purchase certificates.** v3 cannot create them; supporting them on SOAP alone would make a customer-visible feature appear and disappear with a store's API type. Checkout therefore selects rather than creates, which also removes the trap of a shopper accidentally making themselves permanently exempt from a checkout step.
- **Guest exemptions.** Certificates hang off a customer account.
- **Certificate expiry tracking, reminders, or document storage.** TaxCloud keeps neither the document nor an expiry date, and the merchant remains responsible for both. Presenting an expiry the module cannot know would be worse than presenting none.
- **Validating exemption claims.** Certificates are attestations — a certificate created with an invented tax id is accepted without complaint, verified live. The gating settings are the control; the software is not.
- **Changing the `customerId` sent on carts and orders.** Still the Magento entity id, for the reasons the foundation recorded.

## Store-scoping implications

Every new setting is store-scoped, like every other TaxCloud setting, and resolves against the store of the entity being processed. Two consequences worth stating because the UI makes them visible for the first time:

A customer's certificates are **account-level**, so on a multi-store install where two stores use different TaxCloud accounts, the same customer legitimately shows different certificates on each. The admin grid is therefore scoped to a store, and shows which one it is asking about.

The exempt customer groups are a store-scoped setting while a customer's group is a global property, so the same customer may be auto-exempt on one store and not another. That is intended — a merchant may run an exempt B2B store and a consumer store from one catalog — and the resolver already resolves against the entity's store.

## Impact

- `Block/Adminhtml/`, `Ui/`, `view/adminhtml/` — customer certificate grid, identity field, discovery action, order-screen selector.
- `Controller/Adminhtml/Certificate/` and `Controller/Certificate/` — admin and storefront endpoints for list, add, delete, refresh. Every one of them passes certificate identifiers through the resolver's ownership check rather than trusting them.
- `view/frontend/` — My Account section and the checkout selector.
- `Model/Certificate/CertificateResolver` — the group auto-apply branch the foundation left as a slot.
- `etc/adminhtml/system.xml`, `etc/config.xml` — the settings and their defaults.
- `Setup/Patch/Data/` — migrate `taxcloud_cert` values, then remove the attribute.
- **Breaking**: `taxcloud_cert` disappears. Values migrate, but anything reading the attribute directly — an integration, a data import, custom code — needs updating. This is the release's upgrade note.
- **Behavioural**: a store that leaves the master switch off sees no change at all, which is every existing install until an admin turns it on.
