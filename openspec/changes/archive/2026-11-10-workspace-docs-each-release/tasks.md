## 1. Export

- [x] 1.1 Skip markers in `export.py`: drop marked lines, keep line numbers true, fail on unbalanced or nested markers, ignore markers in code
- [x] 1.2 `--adopt` option in `export.py`
- [x] 1.3 Mark the public-site-only parts of `docs/index.md`; confirm the public site renders the same

## 2. Tests

- [x] 2.1 Unit tests: skip markers (dropped, unbalanced, nested, in code), adoption, real docs still export
- [x] 2.2 Run the full export check (`make docs-export-check`) and `mkdocs build --strict`

## 3. Release routine

- [x] 3.1 `make docs-sync-workspace TAG= WORKSPACE= [ADOPT=1]` with tag and path checks, plus help text
- [x] 3.2 `.github/workflows/workspace-docs-reminder.yml` on release published, de-duplicated
- [x] 3.3 Try the sync target against a local workspace clone

## 4. Docs

- [x] 4.1 README: release steps and the export section
- [x] 4.2 `docs/writing-documentation.md`: skip markers
