## 1. CI trigger

- [x] 1.1 In `.github/workflows/test.yml`, drop `develop` from the push trigger so line 30 reads `branches: [ main, DEV-* ]`.
- [x] 1.2 Reword the header comment on line 7 so its illustrative example of a cheap-pipeline PR no longer names `develop`.
- [x] 1.3 Confirm no other occurrence of `develop` as a branch name remains in `.github/` (excluding the words developer/development/developing).

## 2. README deployment section

- [x] 2.1 Replace the false "**Automatic**: Push to `main`, `develop`, or `DEV-`* branches" claim at `README.md:557` with a statement that sandbox deployment is manual only.
- [x] 2.3 Reword the "Automated Deployment" section intro so it no longer implies push-triggered deployment; the pipeline is automated end to end but started by hand.
- [x] 2.2 Verify the corrected text matches `.github/workflows/deploy-to-sandbox.yml`, which declares `workflow_dispatch:` and no push trigger.

## 3. Verification

- [x] 3.1 Validate `test.yml` parses as YAML and the `on:` block still carries the `push` (tags `v*`), `pull_request`, and `workflow_dispatch` triggers.
- [x] 3.2 Re-grep the repo for branch-name references to `develop` and confirm only intended matches remain.
- [x] 3.3 Documentation check per project instructions: confirm whether any page under `docs/` describes CI, branches, or deployment, and record the conclusion.

## Notes

- 3.3 conclusion: no `docs/` page needed changing. `docs/E2E_TESTS.md` and
  `docs/INTEGRATION_TESTS.md` (Development nav section) describe job-level
  gating — integration and e2e run only on `v*` tags and `workflow_dispatch`,
  with unit/lint/security on branch pushes — not the workflow's branch list,
  and neither names `develop`. Both remain accurate. The merchant-facing pages
  do not describe CI, branches, or deployment at all.
- `design.md` deliberately not created: its instruction marks it conditional
  (cross-cutting work, new dependencies, migration/security complexity, or
  ambiguity needing decisions up front). None apply to a trigger-list edit and
  a prose correction.
