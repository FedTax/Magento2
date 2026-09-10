# Before you begin

Most of the setup work is in your TaxCloud account, not in Magento. Do it first
— the extension cannot calculate anything until TaxCloud knows who you are,
where you are, and where you collect.

## 1. Create your TaxCloud account

[Create an account at taxcloud.com](https://taxcloud.com/) and choose the
service level you want. New accounts get a free testing period, so you can prove
the integration works before you are paying for it.

## 2. Set your account up

Sign in to TaxCloud and work through these three. All of them are under
*Settings*.

**Add your store.** *Settings → Stores*. If your Magento store is not listed,
click *Add Store* and follow the prompts.

**Add your business locations.** *Settings → Locations → Add Location*. Register
every physical presence you have in the US — shops, warehouses, distribution
centres, offices. This is not optional bookkeeping: several states decide what
you owe based on where you operate from, so a missing warehouse means wrong tax.

**Choose your tax states.** *Settings → Tax States*. You get a map of the United
States; click the states where you want to collect sales tax.

!!! warning "Choosing states is a decision about your business"
    Collect in a state where you are not registered and you are holding tax you
    have no way to remit. Fail to collect in a state where you have nexus and
    you owe it out of your own pocket. If you are unsure where you have nexus,
    ask TaxCloud — working out where you have crossed a threshold is one of the
    things they do.

## 3. Get your credentials

Which credentials you need depends on which API you will use. New installations
use **V3 REST**, so unless you are an existing customer with V1 credentials
already in hand, that is the one you want. See
[Choosing your API](choosing-your-api.md) if you need to decide.

=== "V3 REST (new installations)"

    In your TaxCloud dashboard:

    - **Connection ID** — *Integrations → Custom API*. A long identifier that
      looks like `25eb9b97-5acb-492d-b720-c03e79cf715a`. Required.
    - **API Key** — *Developer → API*. Optional. Skip it unless you already have
      V1 credentials you would rather not use.

    !!! warning "The connection decides test versus production"
        Whether the transactions you send count as real, filable sales is a
        property of the connection, set in TaxCloud — not something you switch
        in Magento. Use a test connection while you are setting up, and swap in
        the production one when you go live.

=== "V1 SOAP (existing integrations)"

    - **API ID** and **API Key** — the pair from your TaxCloud account.

    These still work, and they also work if you later move to V3 REST: the
    extension exchanges them for you.

## 4. Check what you have in Magento

Two things on the Magento side need to be true before tax can be calculated at
all.

**Your origin address must be complete.** *Stores → Configuration → Sales →
Shipping Settings →* **Origin**, including the full ZIP+4. You will set this in
[Quick start](quick-start.md), but if you do not know your ZIP+4, look it up now
— the [USPS ZIP Code lookup](https://tools.usps.com/zip-code-lookup.htm) gives
you the full nine digits from a street address.

**You need admin access and someone who can run commands.** Installing the
extension is done from the command line. If that is not you, this is the point
to line up your developer or hosting provider — see
[Installing the extension](installing.md).

## Checklist

Before moving on you should have:

- [ ] A TaxCloud account, with your store added
- [ ] Your business locations registered
- [ ] Your tax states selected
- [ ] Your Connection ID (or your V1 API ID and API Key) written down
- [ ] Your origin address, with ZIP+4
- [ ] A way to run commands on your Magento server

Next: [Installing the extension](installing.md).
