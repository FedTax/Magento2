## 1. Install paths

- [x] 1.1 CI unit job (`.github/workflows/test.yml`, *Install Magento codebase*): `create-project --no-install`. For a `2.4.7*` version, set the project-level `audit.ignore` to `["PKSA-w9tt-7782-78jx"]`; then run `composer update`. Add a comment naming the advisory, why ignoring it is safe for a test install, and the exit condition.
- [x] 1.2 `scripts/install-magento.sh` (create-project step): key "already installed" on `vendor/autoload.php` and resume a half-created project (design D4); then the same sequence inside the app container, scoped to `2.4.7*`, tolerating a Composer that does not know the key (design D3), with the same comment.

## 2. Documentation

- [x] 2.1 `docs/INTEGRATION_TESTS.md`: note the 2.4.7 advisory exception in the install-steps description.
- [x] 2.2 `README.md` CI paragraph: one sentence on the exception, pointing at the workflow comment.
- [x] 2.3 Merchant docs: add a note to `docs/installing.md` only if task 3.2 shows a 2.4.7 store cannot `composer require` the extension under Composer 2.10's default policy. Record the conclusion either way. **Conclusion: no note.** With flysystem 2.x already locked, `composer require taxcloud/magento2:^1.4` resolves under the default policy (a partial update leaves the locked flysystem untouched).

## 3. Verification

- [x] 3.1 Dry runs of the exact sequence on both Composer 2.10.3 (runner) and 2.9.8 (integration image): 2.4.7-p10 resolves with the exception, and 2.4.9 resolves unchanged (no exception applied).
- [x] 3.2 Merchant scenario on Composer 2.10's default policy: a 2.4.7-p10 project with flysystem 2.x locked runs `composer require taxcloud/magento2`. Record whether the advisory block stops it.
- [x] 3.3 Lint the shell changes (`bash -n`, plus `shellcheck` if available) and validate the workflow YAML parses. (`bash -n` and the YAML parse pass; `shellcheck` is not installed locally.)
- [x] 3.4 Propose to the maintainer a CI run (`workflow_dispatch` on `1.6.0`, `magento_versions=2.4.7-p10`) as the end-to-end check, rather than claiming it.
