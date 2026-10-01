## Context

See proposal.md for why. `README.md` mixes three kinds of content: merchant
documentation that `docs/` now covers, developer internals that only the README
has, and maintainer processes (deployment, releases). The docs site is built by
`.github/workflows/docs.yml` with MkDocs defaults (`use_directory_urls: true`),
so a page `docs/foo.md` is served at `https://fedtax.github.io/Magento2/foo/`.

## Goals / Non-Goals

**Goals:** after this change, each merchant-facing fact lives in exactly one
place (`docs/`). Every README section is developer or maintainer material, and
no README statement contradicts a docs page.

**Non-Goals:** editing docs pages beyond the fixes named in the proposal, or
changing developer internals as they are described (only history wording and
dead cross-references are touched).

## Decisions

**README links to the published site, not to `docs/*.md` files.** The point of
the link is to send readers to the docs site. Relative `docs/foo.md` links
would render as raw Markdown on GitHub, without navigation or search.
Alternative considered: relative links, which also resolve on forks and before
Pages is enabled. Rejected, because the proposal's Pages prerequisite has to be
met before merging anyway.

**Target README outline:**
1. Logo, title, tagline, **Documentation** link, and a short list of links to
   key pages (Installing, Settings reference, Troubleshooting, Extending).
2. Development: contributing, running tests, coding standard, static analysis,
   documentation (`make docs`, `writing-documentation`).
3. Internals: tax collector detection, diagnostics bundle, log correlation,
   uninstall and data patches.
4. Sandbox deployment.
5. Releasing to the Adobe Commerce Marketplace.
6. License.

**Split the mixed sections by audience.** The "Checking that TaxCloud is the
active tax calculation" section keeps the mechanism (the `preference`,
`around` plugins, the quote marker and its observer, the acknowledgement
fingerprint, the exit code of `taxcloud:diagnose`) and links to
`extension-conflicts` for the merchant fix, including the `<sequence>` recipe.
The Uninstall section keeps the attribute and data-patch detail and links to
`installing` for the merchant warning.

**Keep the diagnostics section's heading and CLI synopsis unchanged** so
`docs/diagnostics.md`'s "see the repository README for all options" stays true.
The only edit there is the "(below)" cross-reference to the log location,
which becomes a link to `extending`.

**Code comments pointing at README "Changing the log file location" are
repointed to the docs Extending page.** That content already exists there
word for word, so a duplicate is not kept in the README just to serve comments.

**The compatibility header becomes a link.** Supported versions are already on
`installing.md`, and the CI matrix is in `.github/workflows/test.yml`. A version
number in the README is the part most likely to go stale.

## Risks / Trade-offs

- [Pages is not enabled yet, so README links 404 until it is] → Merge is gated
  on the prerequisite in proposal.md. The docs `index` page exists, so the root
  link works the moment the site deploys.
- [A docs page is renamed later and a README link breaks silently: `mkdocs
  --strict` does not check the README] → Keep README links to a handful of
  stable pages. The CLAUDE.md doc-sync rule covers renames.
- [Removing sections breaks external deep links to README anchors] → Accepted.
  The top-of-README docs link gives those visitors a way forward.

## Migration Plan

Documentation only; nothing to deploy beyond the Pages prerequisite. Rollback
is a revert of the commit.
