## Why

The `develop` branch is being deleted as part of the post-1.4.0 branch cleanup.
It last moved on 2026-06-11, has triggered zero CI runs in the last hundred, and
the 1.3.x and 1.4.0 releases all shipped release-branch → `main` without touching
it. Its only unique content is two version bumps to 1.1.2 and one real fix —
DEV-8274's pretax snapshot guard — which is already in `main` at
`Model/Tax.php:205-216`, byte for byte. Two files still refer to it, and after
the branch is gone they describe a branch that does not exist.

Fixing those references surfaces a separate, pre-existing error. `README.md:557`
claims sandbox deployment is "**Automatic**: Push to `main`, `develop`, or
`DEV-`* branches", but `.github/workflows/deploy-to-sandbox.yml` declares
`workflow_dispatch:` and nothing else — no push trigger at all. That claim is
false today for every branch it names, independent of `develop`, and a developer
following it would push and wait for a deploy that never runs.

## What Changes

- Drop `develop` from the CI push trigger in `.github/workflows/test.yml`, leaving
  `branches: [ main, DEV-* ]`.
- Reword the `test.yml` header comment that uses "e.g. into develop" as its
  illustrative example of a PR that gets the cheap pipeline.
- Correct `README.md` to state that sandbox deployment is manual only, via
  Actions → Deploy to Sandbox → Run workflow, removing the false automatic-push
  claim for all three branch patterns.

## Capabilities

### New Capabilities
<!-- None. -->

### Modified Capabilities
<!-- None. This touches CI triggers and developer documentation only; no
     extension behavior changes, so the change sets skip_specs: true. -->

## Non-goals

- **No change to PR coverage.** The `pull_request:` trigger in `test.yml` carries
  no branch filter, so every PR runs regardless of target. Removing `develop`
  from the *push* list narrows nothing that is actually exercised.
- **No change to what the pipeline runs.** Job definitions, the integration and
  e2e matrices, and the tag-driven full-run gating are untouched. This edits
  trigger configuration and prose only.
- **No repair of the deploy workflow.** `deploy-to-sandbox.yml` staying
  dispatch-only is the current intended state as far as this change is
  concerned; the README is corrected to match the workflow, not the reverse. If
  automatic deployment is wanted, that is its own change.
- **No branch deletion.** Deleting `develop` and the other merged branches is a
  repository operation performed outside this change. This change only removes
  the references so the repo is consistent once that happens.

## Impact

**Store scoping.** None. No extension code, configuration, or store-resolved
setting is touched.

**CI.** After this lands, a push to a branch named `develop` would no longer
start a run. Since the branch is being deleted and nothing else matches that
name, no run that happens today stops happening.

**Docs.** `README.md` is the developer-facing entry point, so the deployment
section is corrected there. The merchant documentation under `docs/` does not
describe CI, branches, or deployment, so no page there changes.

**Files.** `.github/workflows/test.yml`, `README.md`.
