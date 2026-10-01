## Why

The documentation site (https://fedtax.github.io/Magento2/) is now the reference
for merchants, but `README.md` still holds its own copy of most of that
material — about two-thirds of its 640 lines. The two copies have already
diverged: different Composer constraints (`^1.3` vs `^1.4`), a refund
description that is only true on V1 SOAP, settings the README never mentions
(API Timeout, Fallback to Magento Tax Rates, Verify Credentials, Diagnostics),
and history wording the docs rules forbid. Every future change also has to
update both copies. The README should instead be the developer entry point and
link to the docs for everything a merchant reads.

**Prerequisite (repo admin, outside this change):** the docs site is not live
yet. GitHub Pages is set to *Deploy from a branch* (`main`, `/`), so
https://fedtax.github.io/Magento2/ serves `README.md` rendered by Jekyll, and
the `Docs` workflow's deploy on `main` fails with "Ensure GitHub Pages has been
enabled". Pages must be switched to *Source = GitHub Actions* and the `Docs`
workflow re-run before the README link points at the docs. Merging this change
first would strip the merchant content from the only page that currently
serves it.

## What Changes

- **Add** a prominent link to the documentation site at the top of
  `README.md`, plus a short list of links to key pages (Installing, Settings
  reference, Troubleshooting, Extending).
- **Remove** from `README.md` all merchant content that `docs/` already covers,
  replacing each section with a link where useful:
  - "How it works" description → `overview.md`
  - TaxCloud account setup → `before-you-begin.md`
  - Manual / Composer / GitHub VCS install, upgrading, uninstall warning →
    `installing.md`
  - Configuring (shipping origin, every TaxCloud setting, Colorado Retail
    Delivery Fee) → `settings.md`, `colorado-retail-delivery-fee.md`
  - Product, bulk and category TICs; how a TIC is chosen → `assigning-tics.md`
  - Customer settings, certificates, My Account, exempt orders, certificate
    caching → exemptions pages
  - Testing the module → `testing-your-setup.md`
  - Refunds and unpaid-order cancellations → `refunds.md`, `cancellations.md`
  - Merchant part of "Checking that TaxCloud is the active tax calculation" →
    `extension-conflicts.md`
  - Clearing the TaxCloud cache → `clearing-the-cache.md`
  - Event tables, changing the log location, switching to V3 REST →
    `extending.md`, `choosing-your-api.md`
  - All screenshots (each already appears on the corresponding docs page)
- **Keep** in `README.md` the developer and maintainer material the site does
  not cover: contributing, unit tests (with links to the integration and E2E
  test pages), coding standard, PHPStan, previewing the docs locally, the
  `taxcloud:diagnostics:export` command options and bundle layout with its
  implementation notes, log correlation internals, how tax-collector takeover
  is detected, uninstall attribute/data-patch details, sandbox deployment, the
  Marketplace release process, and the license.
- **Fix** what remains in the README:
  - Release steps tag on `master` → `main`, the repository's default branch.
  - Replace the hardcoded "Version 1.4.0" compatibility header with a link to
    the supported versions on `installing.md`, so it cannot go stale at the
    next release.
  - Remove history wording ("pre-1.4 behavior", "previous behavior",
    "the PSR-2 gate this repo used to run"). The "Upgrading from 1.3.x"
    `taxcloud_cert` note is dropped outright: `CHANGELOG.md` already records it.
- **Docs-side fixes** found during the review:
  - `docs/extending.md` says "Three values have no admin field" but lists two.
  - `docs/diagnostics.md` sends readers to the README for all command options;
    the README keeps that section so the link stays valid (the link may be
    made more specific).
- **Project instructions:** update the "update both" rule in `CLAUDE.md` so
  merchant-facing content (settings, install steps, feature behavior) is
  documented in `docs/` only, and the README is updated only for developer
  material.

## Capabilities

### New Capabilities
None.

### Modified Capabilities
None. This is a documentation-only change; the extension's behavior does not
change, so the change sets `skip_specs: true`.

## Non-goals

- Rewriting or restructuring the merchant docs pages beyond the two small fixes
  above.
- Moving developer material from the README into the docs site's Development
  section.
- Changing the docs site build, theme, navigation or hosting.
- Changing `CHANGELOG.md` (it already covers the 1.3.x migration).
- Any code, configuration or test change.

## Store scoping

None. No setting, feature gate or runtime behavior is touched. The docs pages
that describe store scoping (`settings.md`, `multi-store.md`) are left as they
are.

## Impact

- `README.md`: shrinks from about 640 lines to roughly 250, mostly developer
  material; no merchant content is duplicated.
- `docs/extending.md`, possibly `docs/diagnostics.md`: one-line fixes.
- `CLAUDE.md`: the documentation-sync rule changes.
- GitHub visitors land on a short README that sends merchants to the docs site.
- In-repo pointers to README sections:
  - `docs/diagnostics.md` (command options) and `.github/workflows/test.yml`
    ("Coding standard") point at sections that stay.
  - Code comments in `Logger/Handler.php`, `etc/di.xml`,
    `Model/Diagnostics/Bundle/Log/LogPathResolver.php` and
    `Test/Unit/Model/Diagnostics/Bundle/Log/LogPathResolverTest.php` point at
    README "Changing the log file location", which is removed. Those comments
    are repointed to `docs/extending.md`. They are comment-only edits, with no
    behavior change.
- External links to removed README anchors (for example
  `#switching-to-the-v3-rest-api`) will stop resolving to a section.
