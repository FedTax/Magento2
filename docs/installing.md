# Installing the extension

Installing is a command-line job. If you do not run commands on your own server,
hand this page to your developer or hosting provider — everything they need is
on it.

## Before you start

- **Magento Open Source or Adobe Commerce 2.4.x.** The extension is tested
  against 2.4.7-p10, 2.4.8-p5 and 2.4.9 on every change.
- **Access to run `bin/magento`** on the server.
- **A backup**, or a staging environment to try it on first. Installing adds
  database attributes; that is routine, but it is a database change.

## Install with Composer (recommended)

The extension is published on the [Adobe Commerce
Marketplace](https://commercedeveloper.adobe.com/extensions/versions/taxcloud-magento2),
so it installs from `repo.magento.com` with the authentication keys your project
already uses:

```bash
composer require taxcloud/magento2
```

```bash
bin/magento setup:upgrade
```

```bash
bin/magento setup:di:compile
```

If your store runs in production mode, also redeploy static content:

```bash
bin/magento setup:static-content:deploy
```

Then flush the cache:

```bash
bin/magento cache:flush
```

## Install manually

If Composer is not an option, download the extension as a ZIP from the
[releases page](https://github.com/FedTax/Magento2/releases/latest), unpack it
into `app/code/Taxcloud/Magento2` on the server, and run the same
`setup:upgrade` and `setup:di:compile` commands as above.

Manual installs have to be updated manually too — Composer will not tell you a
new version exists.

## Installing a release before it reaches the Marketplace

Every release is reviewed by Adobe's Extension Quality Program before it appears
on the Marketplace, which can take days or weeks. During that window the
Marketplace version lags behind the newest release on GitHub.

To install straight from GitHub instead, register it as a repository in your
project:

```bash
composer config repositories.taxcloud vcs https://github.com/FedTax/Magento2.git
```

```bash
composer require taxcloud/magento2:^1.4
```

Worth knowing:

- The repository is public, so no extra credentials are needed. Your existing
  `repo.magento.com` keys are still required for Magento's own packages.
- With the repository registered, Composer picks the highest version across both
  sources — so a newer GitHub release wins over an older Marketplace copy
  automatically.
- Use an explicit constraint such as `^1.4` rather than `*`. It keeps you on the
  current major version and makes the next major upgrade a decision rather than
  a surprise.

## Upgrading

```bash
composer update taxcloud/magento2
```

```bash
bin/magento setup:upgrade
```

```bash
bin/magento setup:di:compile
```

!!! note "Upgrading never changes how your store behaves"
    New features arrive switched off. Exemption certificates stay off until you
    enable them, and a store already using the V1 SOAP API stays on it — you
    move to V3 REST when you decide to, not because you upgraded. Read the
    [changelog](https://github.com/FedTax/Magento2/blob/main/CHANGELOG.md)
    before a major upgrade.

## Confirming it installed

```bash
bin/magento module:status Taxcloud_Magento2
```

You should see it listed as enabled. In the admin, *Stores → Configuration →
Sales → Tax* now has a **TaxCloud Settings** section.

Nothing is happening yet: the extension does nothing at all until you set
**Enabled** to `Enable`, which comes next.

## Uninstalling

```bash
bin/magento module:uninstall Taxcloud_Magento2
```

!!! warning "Uninstalling deletes your TaxCloud data in Magento"
    Removing the extension drops the attributes it added, and with them every
    TIC you set on a product or category and every exemption certificate link on
    a customer. Reinstalling brings the fields back empty — the values are gone.
    Export anything you would not want to re-enter before you uninstall.

Next: [Quick start](quick-start.md).
