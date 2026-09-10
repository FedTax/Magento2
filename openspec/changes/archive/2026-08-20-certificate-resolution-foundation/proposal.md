## Why

The module can check one pasted certificate but cannot *manage* certificates. A customer holds exactly one, in a free-text attribute an admin fills by copying a UUID out of the TaxCloud portal; nothing records which certificate exempted an order; and certificate operations exist on neither transport beyond a single read.

Two things make that worse than it sounds.

**The identity a certificate is filed under is a string a human typed.** TaxCloud files every certificate under a `customerId`, and the portal — the only way a Magento merchant gets a certificate today, per TaxCloud's own documented workflow — asks a person to type it. They have no reason to type a Magento entity ID. The module queries the entity ID. So following the vendor's instructions produces a certificate the module cannot find, and the customer is silently taxed.

**Nothing is verified, and nothing is enforced.** Certificates are attestations: a made-up tax ID is accepted without complaint. And TaxCloud applies any valid certificate on the account to any cart — verified live: a cart carrying another customer's certificate is exempted without objection. Whatever prevents one customer using another's exemption has to be built here, because the API will not do it.

This change builds the foundation both problems need. No customer-facing UI; that is the follow-on change.

## What Changes

- **Certificate operations become a routed gateway capability** — list, create and delete, implemented for SOAP and REST and dispatched on the store's `api_type` like every other operation. The one operation both transports share is list-by-customer-id: v1 has no fetch-by-id at all, which is why the whole design is customer-id-centric.
- **A normalized certificate model** with a mapper per transport, so nothing above the gateway cares which API answered. This is where v3's lossiness is handled deliberately: no tax id, `reason` returned as `Unknown`, states as objects rather than `StateAbbr`.
- **A TaxCloud customer identity** for each Magento customer, defaulting to the entity id, resolved through one component used by both lookup and creation so they cannot drift apart. Admin-only, behind its own ACL, and changes are logged.
- **A server-side trust rule**: a certificate identifier arriving from a browser is never taken at face value. Applying or deleting one re-resolves it against that customer's own certificates first.
- **Certificates recorded on the order** — the applied identifier plus a snapshot of what it said.
- **A resolution rule** for which certificate applies, now that a customer may hold several.
- **The legacy `taxcloud_cert` attribute keeps working**, becoming the explicit-attachment slot in the resolution rule. Removing it is the follow-on change's job, alongside the UI that replaces it.
- **Single-purchase certificates are not supported** on either transport.

## Capabilities

### New Capabilities

- `exemption-certificates`: managing a customer's TaxCloud exemption certificates and deciding which one applies to an order — the customer identity they are filed under, the create/list/delete operations across both transports, the ownership rule, and the per-order record. The capability is introduced here and extended by the follow-on change, which adds the UI, the gating settings and group auto-apply, and retires the legacy attribute.

### Modified Capabilities

- `gateway-routing`: both requirements enumerate the operations that route by store `api_type`, and that list currently ends at "exemption validation". Certificate creation, listing and deletion are new operations that must route the same way.
- `rest-tax-operations`: its exemption requirement describes validating one certificate against v3. With policy moving to `exemption-certificates`, the REST requirement narrows to what v3 specifically must do — implement the certificate operations and expose covered states as the v3 schema shapes them.

## Non-goals

- **All certificate UI.** My Account management, checkout selection, the admin certificate grid, the discovery action, the order-screen picker, and the admin field for the customer identity all belong to the follow-on change. This one ships only the machinery beneath them. The identity attribute exists here and is honoured by resolution; surfacing it for editing arrives with the grid that gives it context, rather than as a plain text box the follow-on would immediately replace.

- **Removing `taxcloud_cert`, and migrating off it.** Both ship with the UI that replaces the attribute, so the breaking change lands in the same release as the feature justifying it — one upgrade note, one migration, no window where an admin has lost the old way and not yet gained the new one. The two changes are review-sized units of a single release, so this costs nothing in delivery and keeps this change purely additive.
- **Customer group auto-apply and the settings that gate exemptions** (master switch, exempt groups, restrict-to-groups, company name). The resolution rule below leaves an explicit slot for group auto-apply; filling it belongs with the UI that configures it.
- **Guest exemptions.** Certificates hang off a customer account and will stay that way, matching the WooCommerce plugin.
- **Changing the `customerId` sent on carts and orders.** It stays the Magento entity id. Order attribution wants a stable, unique-per-account identifier; the certificate identity is deliberately mutable and sometimes shared between accounts. One value cannot serve both, and TaxCloud does not require them to match — verified live.
- **Certificate expiry tracking or document storage.** TaxCloud stores neither, and the merchant remains responsible for both.

## Store-scoping implications

Certificates are account-level, so two stores on different TaxCloud accounts expose different certificates to the same customer. Every certificate operation SHALL resolve credentials, transport and cache key against the store of the entity being processed, never the ambient store — the same discipline the existing exemption cache already applies through its account-scoped key.

The customer identity itself is deliberately **not** store-scoped: it is a property of the customer, and a customer is already scoped per website in Magento. What varies per store is which TaxCloud account that identity is looked up against.

## Impact

- `Api/` — the gateway contract gains certificate operations; a new certificate model and its per-transport mappers.
- `Model/Gateway/` — SOAP (`AddExemptCertificate`, `DeleteExemptCertificate`, `GetExemptCertificates`) and REST (connection-scoped create/delete, account-level list) implementations, plus `Router` dispatch.
- `Model/Gateway/{ExemptionValidator,Rest/RestExemptionValidator}` — the single-certificate validators are superseded by set-based resolution.
- `Setup/Patch/Data/` — adds the customer identity attribute. `taxcloud_cert` is left in place; its removal and migration ship with the follow-on change.
- `etc/db_schema.xml` — order columns for the applied certificate and its snapshot.
- `etc/acl.xml`, `etc/di.xml`, `etc/config.xml` — new ACL resource, wiring, defaults.
- **Behavioural blast radius**: today's lookup already resolves certificates against the Magento entity id, and the new identity defaults to exactly that — so every configuration that works today keeps working. Configurations that were silently broken (a portal certificate filed under a name or email) stay broken until an admin sets the identity; they become *diagnosable* rather than invisible.
- **Nothing breaks here.** This change is additive: no attribute is removed, no admin workflow is withdrawn, and `taxcloud_cert` continues to be honoured. The upgrade note belongs to the follow-on change.
