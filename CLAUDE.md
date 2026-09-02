# TaxCloud for Magento 2 — project instructions

## Keep the documentation in sync with the code

`docs/` is the merchant-facing documentation site (MkDocs → GitHub Pages, nav
in `mkdocs.yml`). It describes the extension's **current state only** — it is
a reference, not a history. `CHANGELOG.md` is where history lives.

Every change to the extension — feature added, behavior changed, setting
added/renamed/removed, feature removed — ends with a documentation check, and
any needed doc edits ship as part of the same change:

- Update every page whose described behavior is no longer accurate.
- Add pages (and `mkdocs.yml` nav entries) for new user-facing capabilities.
- Delete content for removed capabilities. Do not keep it with a "deprecated"
  warning: after the change, each page must read as if the current version is
  the only version that ever existed.
- No version-history phrasing in docs pages: no "as of 1.4", "previously",
  "new in this release".
- Read `docs/writing-documentation.md` before writing — it defines the
  audience (store owners/admins, not developers) and style. Developer material
  belongs only under the Development nav section.
- `README.md` remains the developer-facing entry point; when a change touches
  something both cover (settings, install steps, attributes), update both.

A purely internal change (refactor, tests, CI) with no observable behavior
change needs no doc edits — but state that conclusion explicitly when wrapping
up, so the check is visibly made rather than silently skipped.
