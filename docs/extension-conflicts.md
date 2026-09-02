# Another extension is calculating tax

Magento lets only **one** extension calculate sales tax. There is no priority
setting and no way to rank providers: whichever extension claims tax
calculation wins outright, and every other one goes quiet.

If a second tax extension is installed alongside TaxCloud — Avalara, Vertex,
TaxJar, Sovos, or a tax customisation your agency wrote — it may be the one
calculating. TaxCloud then still shows as installed, still shows as **Enabled**,
and **Verify Credentials** still succeeds, because none of those check who is
doing the calculating.

!!! warning "This under-collects tax"
    While another extension is calculating, TaxCloud is not calculating tax and
    not reporting your sales for filing. Orders keep going through. Nothing on
    the storefront looks wrong. This is worth checking the moment you suspect
    it.

## How you find out

**A banner in the admin.** If TaxCloud detects that something else is
calculating tax, a red message appears at the top of every admin page naming the
extension that has taken over. Follow the link in it back to this page.

You can dismiss that banner once you have read it. It comes back on its own if
the situation changes — a different extension takes over, or the same one starts
affecting another store view — so dismissing it is safe.

**A note in the log.** With [logging](logs.md) on, an order placed while another
extension is calculating writes a line saying TaxCloud is enabled but did not
calculate. See [Reading the log](logs.md).

**A check you can run.** Someone with server access can run:

```
bin/magento taxcloud:diagnose
```

It prints, for each store where TaxCloud is enabled, whether TaxCloud is the
extension calculating tax and which one is if it is not.

!!! note "Needs a developer"
    This is a command line, so hand it to whoever maintains your server or your
    host. Ask them for the whole output — TaxCloud support can read it directly.

## How to fix it

**Decide which one you want.** Running two tax extensions is not supported by
Magento and one of them will always lose. Pick one.

**If you want TaxCloud:** remove or disable the other extension. Your developer
or host does this — disabling it in the admin is usually not enough, because the
conflict is in how the extension is installed, not in its settings.

**If you want the other one on some stores only:** turn TaxCloud **off** for
those store views instead of leaving it on and losing. *Stores → Configuration →
Sales → Tax →* **TaxCloud Settings** → **Enabled**, with the store view picked at
the top left. TaxCloud stops warning about a store where it is switched off. See
[Multi-store setups](multi-store.md).

**If both must stay installed:** a developer can make Magento load TaxCloud
after the other extension so TaxCloud wins. This is a code change to the
extension, not a setting, and it has to name the specific extension you are
conflicting with. Give them this, replacing the module name with the one the
banner or `taxcloud:diagnose` reported:

```xml
<!-- app/code/Taxcloud/Magento2/etc/module.xml -->
<sequence>
  <module name="Magento_Sales"/>
  <module name="TheOther_TaxModule"/>
</sequence>
```

!!! note "Needs a developer"
    Adding an extension there that is not installed does nothing, so this is
    safe — but it only helps against the extension actually named, and it has to
    be re-applied when TaxCloud is upgraded. Removing the conflicting extension
    is the durable fix.

## What the check does not tell you

A clean result means TaxCloud is the extension calculating your tax. It does
**not** mean your tax is right. Credentials, addresses, TICs and
[fallback](settings.md#fallback-to-magento-tax-rates) all still matter — see
[Common problems](common-problems.md) if tax is wrong rather than missing.
