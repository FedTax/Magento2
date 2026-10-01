## 1. README

- [x] 1.1 Replace the header and compatibility block with title, tagline, docs-site link and key-page links
- [x] 1.2 Remove merchant sections: How it works, account setup, installing/upgrading, configuring (all settings, products, categories, customers, certificates, Upgrading from 1.3.x), testing, refunds, cancellations, clearing the cache, event tables, switching to V3 REST, changing the log file location
- [x] 1.3 Trim "Checking that TaxCloud is the active tax calculation" to the mechanism and link to the docs page for the merchant fix
- [x] 1.4 Keep the diagnostics bundle and log correlation sections; repoint the relocated-log cross-reference to the docs
- [x] 1.5 Keep uninstall attribute/data-patch detail as an internals section; link to the docs for the merchant warning
- [x] 1.6 Development sections: remove history wording from the coding standard; add a documentation subsection (`make docs`, writing-documentation); link test docs to the site
- [x] 1.7 Releasing: `master` → `main`
- [x] 1.8 Check that no README statement contradicts docs pages and that no history wording remains

## 2. Docs and code references

- [x] 2.1 `docs/extending.md`: "Three values" → "Two values"
- [x] 2.2 Confirm the `docs/diagnostics.md` README pointer still matches a README section
- [x] 2.3 Repoint the "Changing the log file location" comments in `Logger/Handler.php`, `etc/di.xml`, `LogPathResolver.php` and `LogPathResolverTest.php` to the docs Extending page
- [x] 2.4 Update the `CLAUDE.md` doc-sync rule: merchant content only in `docs/`, README only for developer material

## 3. Verification

- [x] 3.1 Unit tests: none needed (comment-only code edits); run `make test-unit` and `make lint` to confirm the comment edits break nothing
- [x] 3.2 `mkdocs build --strict` passes
- [x] 3.3 Every docs-site URL in the README maps to an existing `docs/*.md` page listed in the `mkdocs.yml` nav
