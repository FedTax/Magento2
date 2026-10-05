## Context

Two code paths install a Magento codebase for the test suites:

1. The CI **unit** job, which runs `composer create-project` directly on the
   runner (Composer 2.10.3 from `setup-php`), cached per version.
2. `scripts/install-magento.sh`, which runs `composer create-project` inside the
   `markoshust/magento-php` app container. It is used by the CI **integration**
   and **e2e** jobs, and locally by `make test-unit-version`,
   `make integration-test` and `make e2e-setup`.

Both fail identically for 2.4.7-p10 (see proposal). `PHPStan` and the
2.4.8/2.4.9 rows are unaffected.

Verified against repo.magento.com on both Composer versions the paths use,
2.10.3 (CI runner) and 2.9.8 (`markoshust/magento-php:8.2-fpm`):
- With the default policy, 2.4.7-p10 does not resolve on either, and both name
  `PKSA-w9tt-7782-78jx`.
- With `audit.ignore: ["PKSA-w9tt-7782-78jx"]` in the project's config, it
  resolves fully on both (619 packages, `league/flysystem 2.5.0`).
- 2.10's own error suggests `policy.advisories.ignore-id`, but 2.9.8 rejects
  that key ("does not exist"), so it cannot be used for the container path.

## Goals / Non-Goals

**Goals:** make the 2.4.7 rows install again, with the narrowest possible
exception that is visible where it applies and easy to remove.

**Non-Goals:** see proposal. Any other advisory, on any version, must keep
failing the install.

## Decisions

### D1. Project-level config, set between create and install

Each install path runs `create-project --no-install`, then (for 2.4.7 only)
`composer config audit.ignore --json '["PKSA-w9tt-7782-78jx"]'`
inside the new project, then `composer update`. The exception therefore lives
in that test install's own `composer.json`. It never touches the global Composer
home, which persists in the local app container across version switches and
would silently carry the exception into a 2.4.8/2.4.9 install.

`composer update` is the step `create-project` would have run anyway: the
Magento project package ships no lock file. Flags (`--no-interaction`,
`--no-progress`) carry over unchanged.

Alternatives considered:
- *`composer config --global …` before `create-project`.* This is one line, but
  it leaks across versions in the persistent local container (see above).
- *2.10's `policy.advisories.ignore-id`.* This is the key 2.10's error message
  names, but Composer 2.9.8 in the integration image rejects it. `audit.ignore`
  works on both.
- *Ignoring the whole `league/flysystem` package.* Rejected because it would
  also hide any future, possibly severe, flysystem advisory.
- *Turning advisory blocking off.* Rejected: it disables the protection for
  every package.

### D2. Scope by version prefix `2.4.7`

The exception applies when the requested Magento version starts with `2.4.7`,
which covers p10 and any later 2.4.7 patch still on flysystem 2. On 2.4.8+,
flysystem 3 resolves to a fixed release; scoping keeps any future regression
there loud.

### D3. Older Composer tolerates its absence

`audit.ignore` exists since Composer 2.6, and blocking arrived later, in 2.9.
In the container path, a Composer that rejects the key cannot be blocking
either, so the install logs that and proceeds rather than failing on a setting
it does not need.

### D4. Resume half-created projects in the install script

`scripts/install-magento.sh` used `composer.json` as its "Magento is installed"
marker. Every failed 2.4.7 attempt left exactly that file, with no `vendor/`,
so a re-run would skip installing and fail later in a confusing place. The
marker becomes `vendor/autoload.php`. When `composer.json` exists without it,
the script skips `create-project` and continues with the exception and
`composer update`.

## Risks / Trade-offs

- [The advisory ID is replaced, or a second 2.x advisory appears] → The install
  fails again, loudly, naming the new ID. That is the intended behavior: each
  exception is a reviewed decision, not a blanket.
- [Adobe ships a 2.4.7 patch on flysystem 3] → The exception becomes a no-op.
  Exit condition: bump the matrix to that patch and delete the exception (the
  comment beside it says so).
- [A cached CI codebase predates the change] → The unit job's cache key is
  per version, and a failed 2.4.7 install never populated it. No stale cache.

## Migration Plan

CI picks it up on the next run. Locally, a 2.4.7 install dir left half-created
by an earlier failed attempt is resumed by the next `make` run (D4). No manual
cleanup is needed.
