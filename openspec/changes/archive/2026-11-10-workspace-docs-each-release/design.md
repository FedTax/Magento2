## Context

`scripts/workspace-docs/export.py` already exports deterministically, owns its output directory through the `.magento-docs-export` marker, and is checked in CI. The one-time import into `taxcloud-workspace` (#412) removed that marker and hand-trimmed the guide's home page. The client has now chosen to re-export on every release (see proposal.md).

## Goals / Non-Goals

**Goals:** a re-run that reproduces exactly what is published, including the home-page trims; one command per release; a reminder that needs no access beyond this repo.

**Non-Goals:** automating the workspace PR; editing docs-sync-bot (separate PR).

## Decisions

- **Skip markers are HTML comment lines** (`<!-- workspace-export: skip -->` / `<!-- workspace-export: end -->`). MkDocs renders them as nothing, so the public site is unchanged. They're explicit in the source, so whoever edits the page sees why that content is missing from PD. The alternative, hardcoding the trims in the script, was rejected: it would silently break when the home page is reworded.
- **A single line uses a trailing `<!-- workspace-export: skip-line -->`.** A block marker between list items splits the list on the public site, and an indented one turns its neighbours into spaced-out "loose" items. Both were checked against the built HTML. The trailing marker leaves the list exactly as it was.
- **Markers are handled in the existing per-line pass.** Skipped lines are kept as placeholders until the end, so error line numbers stay true, and are then dropped. Markers inside fenced code are ignored. Unbalanced or nested markers are errors.
- **Adoption is a flag, `--adopt`,** passed through the sync command as `ADOPT=1`. It's only needed for the first sync into the folder #412 created. A flag keeps the refusal of unmarked folders as the default.
- **The sync command is a `make` target,** `docs-sync-workspace TAG=… WORKSPACE=…`. It verifies the tag exists and the path looks like a workspace clone, clones the tag into a temp dir with `git clone --branch` (not `git archive`, because `docs/` and `mkdocs.yml` are export-ignored), and runs the current checkout's export against it. It doesn't touch git in the workspace. The workspace's PR rules need a ticket ID in the title, and a person picks it.
- **The script runs from the current checkout, the docs from the tag.** Tags cut before this change don't contain the newest script, and this way any tag can be synced.
- **The reminder workflow** runs on `release: published`, the same trigger as the Marketplace package workflow. It has `permissions: issues: write`, uses `gh issue create` with the built-in token, and first checks for an open issue with the same title.

## Risks / Trade-offs

- [Syncing a tag older than the markers, such as v1.6.1, brings back the home-page content] → Only releases after this change get synced. The README says markers only take effect from the next release.
- [Someone runs the sync from a stale local checkout and the tag is missing] → The target fails with "fetch tags first" before touching anything.
- [The reminder is ignored] → It stays open in the issues list until closed. It's a prompt, not enforcement.

## Migration Plan

The first sync after this merges uses `ADOPT=1` once. That PR adds the marker file to the workspace folder, and later syncs need nothing extra.
