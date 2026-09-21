# Canadian tax

The extension can calculate Canadian sales tax — GST, HST, PST and QST — on
orders you ship to Canada, and report those orders to TaxCloud the same way it
reports US orders.

Canadian tax is off until you turn it on, and it needs two things:

1. **Canada enabled on your TaxCloud account.** This is an add-on that TaxCloud
   switches on for you. Contact TaxCloud support and ask for Canadian tax to be
   enabled on your account.
2. **Calculate Canadian Tax** set to `Yes` in Magento, on a store that uses the
   V3 REST API.

Either one without the other gives you no Canadian tax.

## Before you start

- Your store must use the **V3 REST** API. The setting does not appear while
  [API Type](settings.md#api-type) is `V1 SOAP (legacy)`, and has no effect on a
  store view that uses V1 SOAP. See [Choosing your API](choosing-your-api.md).
- Your [shipping origin](settings.md#shipping-origin-required) stays in the
  United States. Canadian tax is calculated for orders shipped from a US origin
  to a Canadian address.
- Ask TaxCloud support to enable Canada on your account, and wait for them to
  confirm before you rely on it.

## Turning it on

1. Go to *Stores → Configuration → Sales → Tax →* **TaxCloud Settings**.
2. Pick the scope in the **Store View** switcher. Like every TaxCloud setting,
   this one can be different per website or store view — see
   [how scope works](settings.md#how-scope-works).
3. Set **Calculate Canadian Tax** to `Yes`.
4. Click **Save Config**.

When you save, the extension checks straight away whether your TaxCloud account
has Canada enabled, and shows the answer at the top of the page next to the
save confirmation. It checks again whenever you save a change to the API type or
credentials while the setting is on. The setting is saved whatever the answer
is.

| Setting | Default | Shown when |
|---|---|---|
| **Calculate Canadian Tax** | `No` | TaxCloud is enabled and API Type is `V3 REST` |
| **Check Canada Access** (button) | — | Calculate Canadian Tax is `Yes` |

## Checking that your account has Canada

The **Check Canada Access** button, shown under the setting, asks TaxCloud to
calculate tax on a sample sale: one general-goods item worth $100, shipped from
your origin to Toronto, Ontario. It uses the settings already **saved** for the
store view you are looking at, so save any changes first.

You get one of three answers:

| Answer | What it means | What to do |
|---|---|---|
| **Canada access confirmed** — with the sample rate, for example 13% | Your account prices Canadian sales. | Nothing. Place a test order to a Canadian address. |
| **Canadian tax does not appear to be enabled on your TaxCloud account** | TaxCloud refused the sample, or charged no tax on it. Every province taxes general goods, so either one means Canada is not on for your account. | Contact TaxCloud support to enable Canada, then check again. |
| **Could not check Canada access** | Something else is wrong — rejected credentials, an unknown Connection ID, TaxCloud unreachable, a store not on V3 REST, or an incomplete shipping origin. The message names the problem. | Fix that problem first. [Verify Credentials](settings.md#verify-credentials) helps with credential issues. This answer says nothing about Canada. |

The sample sale is only a calculation. Nothing is filed or reported as a sale.
It shows up in your TaxCloud account as a cart named
`taxcloud-canada-access-check`, and repeat checks update that same cart.

## What happens at checkout

For a shipping address in Canada, the extension asks TaxCloud for tax as it
does for a US address, and applies it to the products and to shipping.

- **The province decides the rate.** Ontario charges 13% HST, British Columbia
  5% GST plus 7% PST, Quebec 5% GST plus 9.975% QST, and Alberta 5% GST only.
  The customer must pick a province.
- **The postal code must be a real Canadian postal code**, such as `M5H 2N2`.
  Customers can type it in lower case or without the space. An address with an
  invalid postal code gets no tax, and the reason is recorded in
  [the log](logs.md).
- **One tax amount per line.** TaxCloud returns a single combined rate, so an
  Ontario order shows one tax amount of 13%, not separate GST and PST amounts.
- **No address verification.** TaxCloud verifies US addresses only, so a
  Canadian address is used exactly as the customer entered it, even when
  [Verify Address](settings.md#verify-address) is enabled.
- **Prices in US or Canadian dollars** both work.
- **Addresses outside the United States and Canada** still get no tax from
  TaxCloud.

If TaxCloud refuses the calculation for a Canadian address, the log entry says
that Canada may not be enabled on your account, and the order is handled like
any other failed lookup: Magento's own rates if
[Fallback to Magento Tax Rates](settings.md#fallback-to-magento-tax-rates) is
enabled, no tax otherwise.

## Orders, refunds and cancellations

Canadian orders are [captured](capture.md), [refunded](refunds.md) and
[cancelled](cancellations.md) in TaxCloud exactly like US orders.

!!! warning "Turning the setting off stops Canadian orders reaching TaxCloud"
    Whether a Canadian order is reported is decided when it is captured, not
    when it is placed. If you set **Calculate Canadian Tax** back to `No` while
    Canadian orders are still waiting for their capture — for example, unpaid
    orders when [Capture in TaxCloud](settings.md#capture-in-taxcloud) is
    `On payment` — those orders charged the customer Canadian tax but are not
    reported to TaxCloud. The log records each one. Let those orders be
    captured, or cancel them, before turning the setting off.

## Exemptions

[Exemption certificates](exemptions-setup.md) cover US states only. A Canadian
order is always taxed, even for a customer who holds a certificate, and no
certificate is recorded on it.

## Related

- [Settings reference](settings.md#calculate-canadian-tax)
- [Multi-store setups](multi-store.md) — for a store view dedicated to Canada
- [Sending diagnostics to support](diagnostics.md) — the diagnostics file
  includes the Canada access check for every store with Canadian tax on
