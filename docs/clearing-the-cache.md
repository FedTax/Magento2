# Clearing the TaxCloud cache

The extension caches what TaxCloud tells it, so a shopper reloading a page does
not trigger a fresh call every time. That is good for checkout speed and
occasionally confusing when you are making changes.

## What is cached, and for how long

| What | How long | Controlled by |
|---|---|---|
| Tax lookups | 24 hours by default | [Cache Lifetime](settings.md#cache-lifetime) |
| Address verifications | 24 hours by default | [Cache Lifetime](settings.md#cache-lifetime) |
| A customer's exemption certificates | 1 hour, fixed | Not configurable |

Note the last row: **Cache Lifetime does not affect certificates**. They are
cached for an hour regardless.

## When to clear it

- You changed a product or category **TIC** and want the next checkout to
  re-price immediately.
- You changed your **origin address** or your nexus settings in TaxCloud.
- You revoked or edited a **certificate in the TaxCloud dashboard** and need it
  reflected at checkout now, rather than within the hour.
- You changed the **WSDL Endpoint** while keeping the same credentials — cached
  answers are keyed by what was asked, not by which endpoint answered, so old
  results could still be served.
- You are **testing** and want every change reflected straight away.

## How to clear it

**From the admin.** *System → Cache Management*, tick **TaxCloud**, choose
*Refresh* from the Actions menu, and Submit.

![The TaxCloud row in Cache Management](images/cache-management-taxcloud.png)

**From the command line.**

```bash
bin/magento cache:clean taxcloud
```

Both clear only TaxCloud entries. Your configuration, block and full-page caches
are untouched, so this does not cost a site-wide cache warm-up.

`bin/magento cache:flush` still clears everything, TaxCloud included.

## Turning caching off while testing

Two ways, with different reach:

**Cache Lifetime = 0** stops tax lookups and address verifications being cached
for that store. Certificates are still cached for an hour.

**Disable the TaxCloud cache type** — *System → Cache Management*, tick
**TaxCloud**, Actions → Disable — stops all TaxCloud caching regardless of the
Cache Lifetime setting. Useful for telling whether a wrong figure is stale or
freshly returned.

!!! warning "Remember to turn it back on"
    With caching disabled, every checkout page load calls TaxCloud. That is slow
    for shoppers and hard on your API usage. Re-enable it before you leave.

## If the TaxCloud cache type is missing or disabled

The cache type is normally enabled automatically when the extension is
installed. On some hosted platforms the configuration file is read-only at
deploy time, and it stays disabled instead — a warning is logged rather than
failing the deployment.

Check it:

```bash
bin/magento cache:status
```

If `taxcloud` shows as `0`, enable it:

```bash
bin/magento cache:enable taxcloud
```

Nothing breaks while it is disabled — every lookup simply goes to TaxCloud,
which is slower.
