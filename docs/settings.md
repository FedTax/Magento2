# Settings reference

Every setting the TaxCloud extension adds or relies on, what it does, what it
defaults to, and what happens when you change it.

Most of what you need is in one place: *Stores → Configuration → Sales → Tax →* **TaxCloud Settings**. A few settings that affect your tax live elsewhere in
Magento, and a handful of per-product, per-category and per-customer values
finish the picture. All of them are covered below.

!!! tip "If you are setting up for the first time"
    Work through them in this order: [shipping origin](#shipping-origin-required)
    → [Enabled and credentials](#taxcloud-settings) →
    [Default TIC and Shipping TIC](#default-tic) →
    [product TICs](#product-settings). Everything else can keep its default.

## How scope works

Every TaxCloud setting can be set at three levels, chosen from the **Store
View** switcher at the top left of the configuration page:

| Level | Applies to |
|---|---|
| Default Config | Every store, unless overridden below |
| Website | All store views in that website |
| Store View | That one store view only |

To give one store view its own value, switch to it, clear the **Use Website**
(or **Use system value**) checkbox next to the field, and enter the value.

This matters more than it looks. The extension resolves every setting against
the store the *order* belongs to — not the store you happen to be looking at.
An order placed on your Canadian store view is processed with that store view's
settings even when a colleague in the admin is viewing another one. So you can,
for example, run one store view on the V3 REST API while another stays on V1
SOAP, or point a staging store view at a sandbox endpoint while production is
untouched.

## Shipping origin (required)

*Stores → Configuration → Sales → Shipping Settings →* **Origin**

This is standard Magento configuration, not a TaxCloud field, but TaxCloud
cannot calculate tax without it: the origin address is where you ship *from*,
and many states tax based on it.

| Field | Type | Default | Notes |
|---|---|---|---|
| Country | Select | United States | TaxCloud handles US sales tax only |
| Region/State | Select | — | Required |
| ZIP | Text | — | **Enter the full ZIP+4** (e.g. `60005-3924`) |
| City | Text | — | Required |
| Street Address | Text | — | Required |

!!! warning "A wrong or incomplete origin means wrong tax"
    If the origin ZIP code is missing or malformed, the extension cannot build
    a valid request and the tax lookup does not happen at all — the order falls
    back to [Magento's own rates](#fallback-to-magento-tax-rates) or to no tax.
    Enter the full ZIP+4; a five-digit ZIP can straddle more than one tax
    jurisdiction, and the extra four digits are what resolve it.

## TaxCloud Settings

*Stores → Configuration → Sales → Tax →* **TaxCloud Settings**

All of these are available at Default, Website and Store View scope. Fields
marked *conditional* only appear once a related setting is turned on.

| Setting | Type | Default | Values |
|---|---|---|---|
| [Enabled](#enabled) | Select | `Disable` | Enable, Disable |
| [Logging](#logging) | Select | `Enable - Basic` | Enable - Basic, Enable - Advanced, Disable |
| [Verify Address](#verify-address) | Select | `Enable` | Enable, Disable |
| [API Type](#api-type) | Select | `V3 REST` | V1 SOAP (legacy), V3 REST |
| [API ID](#api-id) | Text | — | Your TaxCloud API ID (conditional) |
| [API Key](#api-key-v1-soap) | Text | — | Your TaxCloud API Key (conditional) |
| [API Key](#api-key-v3-rest) (V3) | Password | — | Key from Developer → API (conditional) |
| [Connection ID](#connection-id) | Text | — | UUID from Integrations → Custom API (conditional) |
| [Verify Credentials](#verify-credentials) | Button | — | — |
| [Guest Customer ID](#guest-customer-id) | Text | `-1` | Any identifier |
| [Default TIC](#default-tic) | Text (autocomplete) | `00000` | Any TaxCloud TIC |
| [Shipping TIC](#shipping-tic) | Text (autocomplete) | `11010` | Any TaxCloud TIC |
| [Cache Lifetime](#cache-lifetime) | Number (seconds) | `86400` | `0`–any; `0` disables |
| [API Timeout](#api-timeout-seconds) | Number (seconds) | `10` | Any positive number |
| [WSDL Endpoint](#wsdl-endpoint) | URL | TaxCloud production WSDL | Any valid URL |
| [Fallback to Magento Tax Rates](#fallback-to-magento-tax-rates) | Select | `Disable` | Enable, Disable |
| [Only do tax calculations…](#only-do-tax-calculations-without-further-taxcloud-integration) | Select | `Disable` | Enable, Disable |
| [Capture in TaxCloud](#capture-in-taxcloud) | Select | `On order creation` | On order creation, On payment, On shipment (conditional) |
| [Enable Exemption Certificates](#enable-exemption-certificates) | Select | `No` | Yes, No |
| [Company Name](#company-name) | Text | — | Your business name (conditional) |

---

### Enabled

**Type:** Select · **Default:** `Disable` · **Values:** `Enable`, `Disable`

The master switch. While this is `Disable`, the extension does nothing at all:
Magento calculates tax with its own rate tables, nothing is sent to TaxCloud,
and no order is recorded there. Every other TaxCloud setting is hidden.

Set it to `Enable` once your credentials are entered and verified. Because it is
store-scoped, you can enable TaxCloud on one store view first and leave the
others on your existing setup while you test.

---

### Logging

**Type:** Select · **Default:** `Enable - Basic` · **Values:** `Enable - Basic`,
`Enable - Advanced`, `Disable`

Writes what the extension does to `var/log/taxcloud.log`.

- **Enable - Basic** — what happened and why: which API calls were made, which
  were served from cache, orders skipped because the address is not in the US
  or the ZIP is invalid, and every warning and error. Low volume; safe to leave
  on permanently. This is what installs upgraded from older versions land on.
- **Enable - Advanced** — everything Basic records, plus the full request and
  response for each call, the raw message sent over the wire, and per-call
  timing. This is what TaxCloud support will ask for when diagnosing a
  discrepancy. It produces a lot of data — turn it on to reproduce a problem,
  then turn it back to Basic.
- **Disable** — nothing is logged. Not recommended: the log is your record of
  what the extension did on a given order.

!!! note "Your credentials are never written to the log"
    In every mode the API ID and API Key are replaced with `***REDACTED***`
    before anything is written, including inside raw request dumps — so logs,
    backups and log-shipping tools never carry your credentials.

!!! note "Log rotation"
    On `Enable - Advanced`, ask your developer or host to confirm log rotation
    is configured for `var/log/taxcloud.log`.

---

### Verify Address

**Type:** Select · **Default:** `Enable` · **Values:** `Enable`, `Disable`

Sends the customer's shipping address to TaxCloud to be standardised and
completed — most importantly, to get the ZIP+4 for an address the customer
entered with a five-digit ZIP. A verified address usually means a more precise
tax rate.

Set it to `Disable` if you already run an address validation extension. Two
services correcting the same address is redundant and adds a call to every
checkout.

---

### API Type

**Type:** Select · **Default:** `V3 REST` · **Values:** `V1 SOAP (legacy)`,
`V3 REST`

Which generation of the TaxCloud API this store talks to. Each type uses its own
credentials, and the fields below change to match your choice.

- **V3 REST** — TaxCloud's current API, and the default for new installations.
  Uses [Connection ID](#connection-id), and optionally the
  [V3 API Key](#api-key-v3-rest).
- **V1 SOAP (legacy)** — the older API. Still fully supported for existing
  integrations. Uses [API ID](#api-id) and [API Key](#api-key-v1-soap).

If you upgraded from an earlier version of the extension and already had V1
credentials, your store is deliberately left on `V1 SOAP` — upgrading never
switches your API underneath you. Switch when you are ready, not because the
upgrade happened.

!!! tip "Switching a live store"
    Enter and [verify](#verify-credentials) the new credentials before changing
    API Type, then place a test order. The two APIs read your TaxCloud account
    the same way, so no historic data moves or is lost.

---

### API ID

**Type:** Text · **Default:** — · **Shown when:** API Type is `V1 SOAP (legacy)`

Your TaxCloud API login ID, from your TaxCloud account. Required for V1 SOAP.

---

### API Key (V1 SOAP)

**Type:** Text · **Default:** — · **Shown when:** API Type is `V1 SOAP (legacy)`

The API key paired with the API ID above.

!!! tip "V1 credentials also work on V3 REST"
    If you switch to V3 REST and leave these two filled in, the extension
    exchanges them automatically for the access it needs — you do not have to
    generate a new key. The fields are simply hidden while V3 REST is selected;
    the values are still there and still used.

---

### API Key (V3 REST)

**Type:** Password (stored encrypted) · **Default:** — · **Shown when:** API
Type is `V3 REST`

An API key generated in your TaxCloud dashboard under *Developer → API*.

Optional. Leave it blank to have your existing V1 API ID and API Key exchanged
automatically. Fill it in to authenticate directly with a V3 key, which then
takes precedence over the V1 pair. The value is stored encrypted and shown as
dots once saved.

---

### Connection ID

**Type:** Text · **Default:** — · **Shown when:** API Type is `V3 REST`

The identifier of your Custom API connection, found in your TaxCloud dashboard
under *Integrations → Custom API*. It looks like
`25eb9b97-5acb-492d-b720-c03e79cf715a`.

Required for V3 REST.

!!! warning "The connection decides test vs production"
    Whether your transactions are treated as test data or real, filable sales
    is a property of the connection you point at — not a setting in Magento.
    Check in your TaxCloud dashboard that the connection you paste here is the
    one you mean.

---

### Verify Credentials

**Type:** Button

Checks the credentials currently on screen — including ones you have typed but
not yet saved — against TaxCloud, and reports back immediately. Use it before
setting [Enabled](#enabled) to `Enable`, and after any credential change.

It tells you which of these you are looking at:

- Connection successful.
- TaxCloud rejected the credentials — the values are wrong.
- TaxCloud does not recognise the Connection ID.
- TaxCloud could not be reached at all — a network, firewall or DNS problem on
  your server rather than a credential problem.

---

### Guest Customer ID

**Type:** Text · **Default:** `-1`

The customer identifier reported to TaxCloud for orders placed without an
account. Guests have no Magento customer ID, so one stand-in value is used for
all of them.

Leave it at `-1` unless TaxCloud support asks you to change it.

---

### Default TIC

**Type:** Text with autocomplete · **Default:** `00000` (General Goods and
Services)

A **TIC** — Taxability Information Code — is TaxCloud's code for *what kind of
thing you are selling*. States tax categories differently: groceries, clothing,
digital goods and prescription drugs are all treated differently from a generic
taxable item, and the TIC is how TaxCloud knows which rules to apply.

This setting is the fallback: it is used for any item that has no TIC of its own
and belongs to no category with one. `00000` means "general goods and services",
taxed at the standard rate everywhere.

Start typing what you sell and pick from the matching codes, each shown with its
description. The field also accepts a code typed directly; a code TaxCloud does
not recognise is saved exactly as entered and never blocks you from saving.

!!! tip "Set the most common case here"
    If most of your catalogue is one category — a clothing store, say — set that
    TIC here and override only the exceptions on individual products.

---

### Shipping TIC

**Type:** Text with autocomplete · **Default:** `11010`

The TIC reported for the shipping line on an order. Some states tax shipping,
some do not, and several distinguish postage from handling — so which code you
use changes what your customers pay.

- `11010` — **postage only.** You pass through what the carrier charges.
- `11000` — **shipping and handling.** Your shipping charge includes a handling
  component.

Pick the one that describes what you actually charge.

---

### Cache Lifetime

**Type:** Number (seconds) · **Default:** `86400` (24 hours) · **`0` disables
caching**

How long the extension reuses a TaxCloud answer for an identical request instead
of asking again. This covers tax lookups and address verifications, and keeps
checkout fast when a shopper reloads the cart or steps back and forth through
checkout.

Set it to `0` while testing, so every change you make is reflected immediately.
Put it back to `86400` before going live.

!!! note "This does not control exemption certificates"
    A customer's exemption certificates are cached separately, for one hour,
    and this setting does not shorten that. To see a certificate change made
    directly in the TaxCloud dashboard straight away, flush the **TaxCloud**
    cache type under *System → Cache Management*.

---

### API Timeout (seconds)

**Type:** Number (seconds) · **Default:** `10`

The longest the extension waits for TaxCloud to answer before giving up on a
call. It applies to both API types.

The trade-off is a checkout one. A shorter timeout means a shopper waits less
when TaxCloud is slow, but the call is abandoned sooner — and what happens then
depends on [Fallback to Magento Tax Rates](#fallback-to-magento-tax-rates). A
longer timeout gives a slow response more chance to arrive, at the cost of a
shopper watching a spinner. Leave it blank or at `10` unless you have a
measured reason to change it.

---

### WSDL Endpoint

**Type:** URL · **Default:** `https://api.taxcloud.net/1.0/TaxCloud.asmx?wsdl`

*Advanced.* The TaxCloud V1 SOAP address the extension calls.

Leave this alone unless TaxCloud support has given you a sandbox or staging
address to use. Clearing the field restores the production default. Because it
is store-scoped, a staging store view can point at a sandbox while production
keeps the default.

!!! warning "Flush the cache when you change endpoints"
    Cached answers are keyed by what was asked, not by which endpoint answered.
    If you change this while keeping the same credentials, flush the **TaxCloud**
    cache type under *System → Cache Management* so results from the previous
    endpoint are not reused.

---

### Fallback to Magento Tax Rates

**Type:** Select · **Default:** `Disable` · **Values:** `Enable`, `Disable`

What happens when a tax lookup fails — TaxCloud is unreachable, the call times
out, or the credentials are rejected.

- **Disable** (default) — no tax is applied to that order.
- **Enable** — Magento falls back to its own tax rules and rate tables for that
  order.

Neither is risk-free, and the right choice depends on how you would rather be
wrong.

!!! warning "Both options have a cost"
    With fallback **off**, an outage means orders go out untaxed, and the tax
    you owe on them comes out of your margin. With fallback **on**, orders are
    taxed at whatever Magento's own rate tables say — which is only as accurate
    as the rates you maintain there, and may differ from what TaxCloud would
    have charged. If you enable fallback, keep Magento's tax rates current, and
    watch the log for the fallbacks that fired.

---

### Only do tax calculations without further TaxCloud integration

**Type:** Select · **Default:** `Disable` · **Values:** `Enable`, `Disable`

Calculates tax with TaxCloud but never reports the sale to it.

With this `Enable`d, the storefront still charges the right amount: tax lookups,
address verification and exemption checks all run as usual. What stops is
everything that records or reverses a sale in your TaxCloud account — captured
orders, refunds, and cancellations are not sent.

This is for merchants whose orders reach TaxCloud from somewhere else — an
accounting system such as QuickBooks that is itself connected to TaxCloud. In
that setup, sending the order from Magento too would report the same sale twice.

!!! warning "Your sales will not be in TaxCloud unless something else puts them there"
    TaxCloud files returns from the transactions it holds. Only turn this on if
    another connected system is reporting the same orders.

When this is `Enable`d, [Capture in TaxCloud](#capture-in-taxcloud) disappears —
there is no capture left for it to schedule.

---

### Capture in TaxCloud

**Type:** Select · **Default:** `On order creation` · **Values:**
`On order creation`, `On payment`, `On shipment` · **Hidden when:** *Only do tax
calculations…* is `Enable`

When the completed order is reported to TaxCloud as a sale. This is about
*timing*, not about amounts — the whole order is reported either way.

- **On order creation** — as soon as the order is placed. Simplest, and the
  default. Orders that are later cancelled will have reached TaxCloud, and are
  reversed when they are cancelled.
- **On payment** — when an invoice is paid. Recommended if you get cancellations
  or fraud, because unpaid orders never reach TaxCloud at all.
- **On shipment** — when a shipment is created. Use this if you only want to
  report tax on goods that actually went out the door.

With online payment methods, order creation and payment usually happen within
seconds of each other, so the choice matters most for offline payment methods
such as purchase orders, checks or bank transfer.

---

### Enable Exemption Certificates

**Type:** Select · **Default:** `No` · **Values:** `Yes`, `No`

Turns on exemption certificates: tax-exempt customers — resellers, schools,
government buyers — can have a TaxCloud certificate held against their account,
and it is applied automatically at checkout when it covers the destination
state.

Off by default, deliberately. Leaving it `No` keeps the behaviour of stores that
have no exempt customers, exactly as before.

Turning it on adds:

- A **TaxCloud Exemption Certificates** tab on the customer edit page in the
  admin.
- A **Tax Exemption Certificates** section in the customer's My Account.

!!! warning "You remain responsible for the certificate being valid"
    TaxCloud does not verify exemption claims. Turning this on means some
    customers stop being charged tax, and in an audit it is on you to produce a
    valid signed certificate for each of them.

---

### Company Name

**Type:** Text · **Default:** — · **Shown when:** *Enable Exemption
Certificates* is `Yes`

Your business name, recorded on certificates created through the extension as
the seller the exemption is being claimed from. Use your legal business name as
it appears on your TaxCloud account.

---

## Product settings

*Catalog → Products →* edit a product

| Setting | Type | Default | Scope |
|---|---|---|---|
| Tax Class | Select | `Taxable Goods` | Website |
| Taxcloud TIC | Text with autocomplete | — (empty) | Global — one value for all stores |

**Tax Class** — leave this as `Taxable Goods` for essentially everything you
sell, even products you expect to be exempt. TaxCloud decides taxability from
the TIC and the destination; the tax class only decides whether Magento asks at
all.

!!! warning "Do not use Tax Class `None` to make something tax-free"
    A product with tax class `None` is never sent to TaxCloud, so the sale
    leaves no audit trail in your TaxCloud account and cannot be included in a
    filing. If a product is exempt, express that with the correct TIC instead.

**Taxcloud TIC** — the code for this specific product, overriding the category
and store defaults. Leave it empty to inherit. The TIC column is available in
the product grid, so you can sort and filter to find products still missing one,
and you can set it on many products at once with *Actions → Update attributes*.

## Category settings

*Catalog → Categories →* select a category *→ TaxCloud*

| Setting | Type | Default | Scope |
|---|---|---|---|
| TaxCloud TIC | Text with autocomplete | — (empty) | Store view |

Sets a TIC for everything in a category, so you do not have to tag every
product. Being store-view scoped, it can differ per store view — switch the
store view at the top left, clear **Use Default Value**, and enter a different
code.

### Which TIC is used for an item

For each line on an order, the first of these that has a value wins:

1. The **product's** own Taxcloud TIC.
2. The TIC on the nearest **category** above it.
3. The store's **[Default TIC](#default-tic)** setting.

Category lookup includes every category the product is in *and all of their
parents*, so a TIC on `Grocery` covers `Grocery → Snacks → Chips` without
tagging each level. When more than one category carries a TIC, the **deepest**
one wins; if two are equally deep, the one with the lowest category ID wins. A
TIC on a store's root category is ignored — use **Default TIC** for a store-wide
value.

For configurable products, the TIC comes from the variant the customer actually
bought. Variants are usually not in categories themselves, so if the variant has
neither its own TIC nor a category with one, the parent product's categories are
used before falling back to the Default TIC.

## Customer settings

*Customers → All Customers →* edit a customer. Requires
[Enable Exemption Certificates](#enable-exemption-certificates) to be `Yes`.

| Setting | Type | Default | Notes |
|---|---|---|---|
| TaxCloud Customer ID | Text | The customer's Magento ID | The identity certificates are filed under |
| Attached certificate | Chosen in the TaxCloud Exemption Certificates tab | — (none) | What actually exempts their orders |

**TaxCloud Customer ID** normally needs no attention — it defaults to the
customer's Magento ID. Change it only to point several buyers at one company's
shared certificates, for example when a business has multiple people ordering
under one exemption. Changes are recorded in the TaxCloud log.

**Attached certificate** — the certificate on the customer's account is applied
at checkout when it covers the destination state. A certificate registered for
NY does not exempt a shipment to GA. Certificates that are disabled,
single-purchase, or filed under a different identity are never applied, and if
certificates cannot be retrieved the order is taxed rather than exempted.

## Related Magento settings and permissions

| Where | What it does |
|---|---|
| *System → Cache Management →* **TaxCloud** | Flushes cached tax lookups, address verifications and certificate states. Use after changing endpoints or credentials, or after editing a certificate directly in the TaxCloud dashboard. |
| *System → Permissions → User Roles →* **TaxCloud Exemption Certificates** | Controls which admin roles can see and manage exemption certificates. It is a separate permission because granting it means granting the ability to stop collecting tax from someone. |
| *Stores → Configuration → Sales → Shipping Settings → Origin* | [Shipping origin](#shipping-origin-required), above. |

## Advanced settings with no admin field

Three values exist for support and staging scenarios and have no field in the
admin. Changing them requires the command line.

| Setting | Default | Purpose |
|---|---|---|
| REST endpoint | `https://api.v3.taxcloud.com` | The V3 REST address called |
| REST authentication endpoint | TaxCloud's credential-exchange host | Where V1 credentials are exchanged for V3 access |
| Log file location | `var/log/taxcloud.log` | Where TaxCloud logging is written |

!!! note "Hand this to a developer"
    The first two are changed with `bin/magento config:set` using the paths in
    the appendix below; the log location is changed in a `di.xml` file. Only
    change any of them if TaxCloud support has asked you to.

## Appendix: configuration paths

For anyone configuring the extension from the command line or a deployment
pipeline, rather than the admin. Values are the stored values, which differ from
the labels shown in the admin.

| Path | Default | Stored values |
|---|---|---|
| `tax/taxcloud_settings/enabled` | `0` | `1` = Enable, `0` = Disable |
| `tax/taxcloud_settings/logging` | `1` | `1` = Basic, `2` = Advanced, `0` = Disable |
| `tax/taxcloud_settings/verify_address` | `1` | `1` / `0` |
| `tax/taxcloud_settings/api_type` | `rest` | `rest`, `soap` |
| `tax/taxcloud_settings/api_id` | — | Text |
| `tax/taxcloud_settings/api_key` | — | Text |
| `tax/taxcloud_settings/rest_api_key` | — | Text, stored encrypted |
| `tax/taxcloud_settings/rest_connection_id` | — | UUID |
| `tax/taxcloud_settings/guest_customer_id` | `-1` | Text |
| `tax/taxcloud_settings/default_tic` | `00000` | TIC |
| `tax/taxcloud_settings/shipping_tic` | `11010` | TIC |
| `tax/taxcloud_settings/cache_lifetime` | `86400` | Seconds; `0` disables |
| `tax/taxcloud_settings/api_timeout` | `10` | Seconds |
| `tax/taxcloud_settings/wsdl_url` | `https://api.taxcloud.net/1.0/TaxCloud.asmx?wsdl` | URL |
| `tax/taxcloud_settings/fallback_to_magento` | `0` | `1` / `0` |
| `tax/taxcloud_settings/calculations_only` | `0` | `1` / `0` |
| `tax/taxcloud_settings/capture_trigger` | `order_creation` | `order_creation`, `payment`, `shipment` |
| `tax/taxcloud_settings/exemptions_enabled` | `0` | `1` = Yes, `0` = No |
| `tax/taxcloud_settings/company_name` | — | Text |
| `tax/taxcloud_settings/rest_endpoint` | `https://api.v3.taxcloud.com` | URL — no admin field |
| `tax/taxcloud_settings/rest_auth_endpoint` | TaxCloud credential-exchange host | URL — no admin field |

Scope for every path above is store view (`--scope=stores --scope-code=<code>`),
website, or default.
