## 1. Export script

- [x] 1.1 Create `scripts/workspace-docs/export.py` with the CLI (`--out`, `--source`), exit codes, and atomic replace guarded by the ownership marker
- [x] 1.2 Read `mkdocs.yml` (site_url, nav), build the page/section tree, skip excluded sections, and assign padded numeric prefixes
- [x] 1.3 Apply title rules, and generate section index pages; fail on duplicate titles
- [x] 1.4 Convert page bodies: content tabs → headings, `$` escaping outside code, the unsupported-markup guard
- [x] 1.5 Rewrite links (exported page, non-exported page → site URL, image → copied, external unchanged); fail on broken targets
- [x] 1.6 Add `scripts/workspace-docs/requirements.txt` (PyYAML) and `requirements-check.txt` (pinned md2conf)

## 2. Tests

- [x] 2.1 Unit tests with synthetic docs trees covering every spec scenario
- [x] 2.2 Whole-repo tests: the real docs export cleanly, and twice to identical output
- [x] 2.3 Render the real export with the pinned md2conf `--local` and confirm it converts

## 3. CI and tooling

- [x] 3.1 Add `.github/workflows/workspace-docs.yml` (tests, export, md2conf `--local`)
- [x] 3.2 Add `make docs-export` and `make docs-export-check` plus help text; ignore the new venv

## 4. Documentation

- [x] 4.1 README Documentation section: what the export is, how to run it, the bot contract
- [x] 4.2 `docs/writing-documentation.md`: H1s are stable page identities; supported markup for the export
