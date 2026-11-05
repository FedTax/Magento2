## Why

TaxCloud's internal knowledge base (the `taxcloud-workspace` repo, published to the PD Confluence space and indexed from there) still describes this extension as it was at v1.3.0. The `docs-sync-bot` drafts it maintains summarize our commits rather than our docs, and none have been merged. Our merchant docs under `docs/` are the reviewed, current source of truth. The client has agreed they should reach the workspace verbatim on every release, with `docs-sync-bot` calling a converter that we own and that lives in this repo.

## What Changes

- Add an export tool to this repo. It converts the mkdocs site (`mkdocs.yml` nav plus `docs/*.md` and images) into the `taxcloud-workspace` md2conf layout for `docs/products/integrations/magento/`:
  - each nav section becomes a folder with an `index.md`;
  - files get numeric prefixes in nav order;
  - every page title gets a "Magento 2 — " prefix, so titles are unique in PD;
  - internal links are rewritten and images copied;
  - mkdocs-only markup (content tabs) is converted;
  - the Development nav section is excluded.
- The tool has a stable command-line entry point that `docs-sync-bot` calls on each release tag. It takes an output directory, never uses an LLM, and is deterministic: unchanged docs produce byte-identical output, so a release with no doc changes produces no workspace PR.
- Add a CI job to this repo that runs the export and renders the result with md2conf in offline mode (`--local`), pinned to the md2conf build `taxcloud-workspace` uses. A docs change that would break the workspace publish then fails on our PR.
- Add a `make` target to run the export locally, and document the tool in the README's developer material.

## Capabilities

### New Capabilities
- `workspace-docs-export`: converting the merchant docs into the `taxcloud-workspace` / md2conf layout. Covers the CLI contract the sync bot depends on, structure and title rules, link and asset handling, markup conversion, determinism, and the CI compatibility check.

### Modified Capabilities
(none — no runtime behavior of the extension changes)

## Non-goals

- Changes to `docs-sync-bot` (FedTax/docs-sync-bot) or to the `taxcloud-workspace` repo, including opening PRs there or rewriting its hand-written Magento `index.md`. Those happen in their repos once access is granted.
- Rewriting, summarizing or re-voicing doc content for an internal audience. The export is a format conversion only.
- Publishing to Confluence. This repo never calls the Confluence API; only the offline render runs here.
- Cleaning up orphaned Confluence pages after a title rename or page removal.

## Impact

- **New code:** an export script (Python, matching the docs toolchain) and its tests, a `make` target, and a CI workflow job. No PHP, module, DI or database changes, and nothing ships in the Composer package's runtime behavior.
- **Store scoping:** none. This is build tooling with no settings or runtime path, so it never reads Magento config or store context.
- **Docs authoring:** pages must keep stable H1 titles (a rename orphans the Confluence page) and use only markup the converter supports. The CI job enforces the latter. The developer section of `docs/writing-documentation.md` is updated to say so.
- **External dependency:** md2conf pinned to the same commit as `taxcloud-workspace/docs/requirements.txt`. That pin must be bumped by hand when they bump theirs.
