## Why

Since 2026-10-01, every Magento 2.4.7 row of the CI matrix fails before a
single test runs: unit, integration and e2e, for both editions. Composer 2.10
(and 2.9, used by the integration image) block advisory-affected packages
during resolution by default. On 2026-09-29,
Packagist published `PKSA-w9tt-7782-78jx` (CVE-2026-102601, severity *low*)
against `league/flysystem <= 3.35.2`. Magento 2.4.7-p10, the newest 2.4.7 patch,
requires `league/flysystem ^2.4`, and no 2.x release is unaffected, so
`composer create-project` for 2.4.7 cannot resolve at all. 2.4.8 and 2.4.9
require flysystem 3 and resolve to a fixed release, so they are unaffected.

Dropping 2.4.7 from the matrix was considered and rejected. Adobe supports
2.4.7 until 2027-04-09, with an additional year of extended support, so
merchants run it. 2.4.7 is also the matrix's only PHP 8.2 / PHPUnit 9.5 row,
which is how CI enforces the module's PHP floor and PHPUnit compatibility.

## What Changes

- Every Magento install the test suites perform ignores exactly
  `PKSA-w9tt-7782-78jx`, and only when the Magento version is 2.4.7. This covers
  the CI unit job, and `scripts/install-magento.sh`, which drives CI
  integration/e2e and the local `make test-unit-version` / `integration-test` /
  `e2e-setup` targets. Every other advisory, on every version, keeps blocking.
- `scripts/install-magento.sh` decides "already installed" by `vendor/autoload.php`
  instead of `composer.json`. A create-project whose resolution failed (every
  2.4.7 attempt so far) leaves `composer.json` with no `vendor/`. The old check
  then skipped installing forever, while the new one resumes that project.
- The advisory ID lives in one place per install path, with a comment naming the
  advisory, why it is safe to ignore for a throwaway test install, and the exit
  condition: remove the exception once Adobe ships a 2.4.7 patch on flysystem 3,
  or when 2.4.7 leaves the matrix.
- Developer docs (`docs/INTEGRATION_TESTS.md`, README CI paragraph) note the
  exception, so a reader of the install step is not surprised by it.
- A merchant-facing note, only if verification shows the same block stops a
  2.4.7 store from installing or updating the extension (task 3.2 decides).

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

_None._ This is test-infrastructure only, with no change to extension behavior,
so the change sets `skip_specs: true`.

## Non-goals

- **Dropping or changing supported Magento versions.** 2.4.7-p10, 2.4.8-p5 and
  2.4.9 stay the tested set.
- **Turning off advisory blocking.** Disabling `policy.advisories.block`
  wholesale would let CI install any vulnerable dependency, on any version,
  without anyone noticing.
- **Changing what merchants install.** The flysystem version on a merchant's
  2.4.7 store is Magento core's dependency, not the extension's. The extension
  neither requires nor pins it.
- **Bumping 2.4.7-p10 to a later patch.** None exists yet.

## Store-scoping implications

None. No runtime code, configuration or store resolution changes.

## Impact

- `.github/workflows/test.yml`: the unit job's *Install Magento codebase* step.
- `scripts/install-magento.sh`: the `composer create-project` step, used by CI
  integration/e2e and by local targets.
- `docs/INTEGRATION_TESTS.md` and `README.md`: developer documentation.
- Possibly `docs/installing.md`, depending on task 3.2.
- No production code, tests or release artifacts change. The Marketplace zip
  excludes `scripts/` and `.github/`.
