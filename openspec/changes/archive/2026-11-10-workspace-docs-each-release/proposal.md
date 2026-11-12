## Why

The client chose to keep Magento's internal docs (`docs/products/integrations/magento/` in `taxcloud-workspace`, published to PD) as an exact copy of our reviewed docs. They're re-exported on every release, and docs-sync-bot stops writing AI edits to that folder. Today the export can't be safely re-run:
- the one-time import dropped the ownership marker, so the export refuses the folder;
- the home-page trims for the public-site-only content were a manual edit that a re-run would undo;
- there is no single command, and nothing reminds anyone on release.

## What Changes

- **Release-tag sync command:** one command (a `make` target) takes a release tag and the path to a local `taxcloud-workspace` clone. It exports that tag's docs into the Magento folder, replacing it. Branching, committing and opening the PR stay with the person running it, so the workspace's PR rules (ticket ID in the title) are followed by hand.
- **Skip markers:** docs pages can mark content that only belongs on the public site (`<!-- workspace-export: skip -->` … `<!-- workspace-export: end -->`). The export drops the marked content. The home page's contributing section and Development entry are marked, so re-runs reproduce the trimmed page without a manual edit. The markers are HTML comments, so the public site is unchanged.
- **Adopting the existing folder:** an explicit one-time option lets the export take over the current unmarked Magento folder. From then on the folder carries the ownership marker, and the normal refusal of unmarked folders stays in place.
- **Release reminder:** publishing a GitHub release in this repo opens an issue, "Sync docs for vX.Y.Z to taxcloud-workspace", containing the exact command to run. It uses only this repo's own token.
- The README's release section and developer docs describe the routine.

## Capabilities

### New Capabilities
(none)

### Modified Capabilities
- `workspace-docs-export`: adds skip markers to the allowed conversions, a one-time adoption option for the output directory, the release-tag sync command, and the release reminder.

## Non-goals

- Stopping docs-sync-bot from editing the Magento folder. That's a separate PR in FedTax/docs-sync-bot.
- Opening the workspace PR automatically, or giving this repo write access to `taxcloud-workspace`. The sync stays a step a person runs and reviews.
- Changing the content or structure of the export beyond the skip markers.
- Publishing to Confluence. The workspace's own pipeline does that on merge.

## Impact

- **Code:** `scripts/workspace-docs/export.py` and its tests, a `Makefile` target, a new workflow under `.github/workflows/`, `docs/index.md` (markers only), README and the developer docs page. No PHP, DI, database or runtime changes; nothing ships to merchants (`scripts/`, `docs/` and `.github/` are export-ignored).
- **Store scoping:** none. This is build and release tooling with no settings or runtime path.
- **Release process:** each release gains one step, running the sync and merging the resulting workspace PR, prompted by the reminder issue.
- **External:** the first sync uses the adoption option once on the existing workspace folder, and that PR adds the marker file there.
