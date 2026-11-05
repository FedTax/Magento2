## Context

The docs site is MkDocs Material. `mkdocs.yml` holds the nav (two levels: a Home page plus sections of pages) and enables `admonition`, `attr_list`, `md_in_html`, `pymdownx.details`, `superfences`, `tabbed` and `tasklist`. `taxcloud-workspace` publishes `docs/<top>/` directories with md2conf, pinned at commit `cae8ceab161febc71c54ac306f5f156fef764f1d`, which:
- takes the page title from the first heading;
- orders siblings by filename;
- only creates parent pages for directories that have an `index.md`;
- fails on relative links that don't resolve.

A probe of that build confirmed:
- `!!! note|tip|warning ["title"]` admonitions and `- [ ]` task lists render natively;
- relative `page.md#anchor` links resolve to the target page's URL;
- two digit-adjacent `$` signs in one paragraph are read as LaTeX and crash the conversion (matplotlib is absent);
- `--local` writes `.csf` files next to the sources and needs a dummy `-d` domain and `-s` space, but never contacts Confluence.

## Goals / Non-Goals

**Goals:** a single-file converter the bot can call with nothing but Python and PyYAML; a CI job that proves the output publishes; zero manual steps per release on our side.

**Non-Goals:** anchor-slug parity between mkdocs and Confluence. Same-page and cross-page anchors keep their mkdocs slugs, and a mismatch degrades to a link to the right page rather than failing. Also out: rendering Mermaid (the docs use none) and a watch or incremental mode.

## Decisions

- **Python, one script, stdlib plus PyYAML.** The location is `scripts/workspace-docs/export.py`. Python matches both toolchains: mkdocs and md2conf are Python, and PyYAML is already a transitive dependency of md2conf, so the bot's environment has it. PHP was rejected because the bot would need PHP for one script. `scripts/` is already `export-ignore`d, so nothing ships to merchants.
- **CLI:** `export.py --out DIR [--source REPO_ROOT]`. `--source` defaults to the repository containing the script. Exit codes: `0` success, `1` conversion error (file:line reported), `2` usage error or refusing a foreign directory.
- **Atomic replace with an ownership marker.** The guide is built in a temp directory beside `--out`, then swapped in, so a failure leaves `--out` untouched. The marker file `.magento-docs-export` is a dotfile, so md2conf ignores it. A non-empty `--out` without the marker is refused.
- **Layout.** `docs/index.md` becomes `index.md`, and each nav section becomes `NN-<slug(section)>/index.md` (a generated list of its pages) with `NN-<page-stem>.md` beside it. `NN` counts up in tens and is zero-padded to a common width per directory, so sorting by filename always matches nav order. Referenced images go to `images/` at the guide root under their original names.
- **Titles.** The rule is "Magento 2 — " + the H1, unless the H1 already contains "Magento 2". For section index pages, the section name is used instead of the H1. Titles depend only on names, never on prefixes, so reordering the nav never renames a Confluence page. Duplicate titles are a hard error.
- **Excluded sections** are a constant list (`Development`). Links into them go to `site_url` + the mkdocs directory-style URL (`extending/`) + the anchor.
- **Conversions, applied line by line outside fenced code:**
  - Content tabs (`=== "Label"` plus an indented body) become a heading one level below the last heading seen, followed by the body dedented by four spaces.
  - Each unescaped `$` outside inline code becomes `\$`. Escaping is unconditional rather than only for valid pairs: it is simpler, and `\$` renders identically.
  - Links and images in inline `[..](..)` form are rewritten as described in the spec.
- **Unsupported-markup guard.** Any of the following fails the export with file:line, because silently dropping it would publish wrong content:
  - `???` details blocks;
  - `{: … }` or `{ .class }` attribute lists;
  - `markdown="1"` HTML blocks;
  - `--8<--` snippets;
  - reference-style link definitions.
- **CI:** a new workflow, `.github/workflows/workspace-docs.yml`, on PR and push for `docs/**`, `mkdocs.yml`, `scripts/workspace-docs/**` and the workflow itself. It runs:
  - the unit tests;
  - the export into `$RUNNER_TEMP`;
  - `md2conf --local --no-generated-by -d example.atlassian.net -s CHECK` on that output, with md2conf installed from `scripts/workspace-docs/requirements-check.txt` (the pin, copied from the workspace).

  It is a separate workflow rather than a job in `docs.yml`, so the Pages deploy stays untouched.
- **Tests:** `unittest` (stdlib), so no pytest dependency. They use a synthetic docs tree per case, plus whole-repo tests asserting the real docs export cleanly and identically twice.
- **Local use:** `make docs-export OUT=…` and `make docs-export-check` (the same steps as CI, in a `.venv-docs-export` venv).

## Risks / Trade-offs

- [The workspace bumps its md2conf pin and behavior changes] → The pin lives in one file with a comment pointing at the workspace's `docs/requirements.txt`, and bumping it is part of our maintenance.
- [A merchant page H1 is renamed → orphaned Confluence page] → The writing guide says H1s are stable identities. Orphans are flagged by the workspace's cleanup job.
- [Merchant-voiced pages sit among internal pages] → Accepted by the client.
- [mkdocs anchor slugs differ from Confluence's] → The link still lands on the right page, so the cost is cosmetic.

## Migration Plan

Nothing to migrate. The tool is additive. The bot integration happens in FedTax/docs-sync-bot once access is granted: on a new tag, check out the tag, run `export.py --out <workspace>/docs/products/integrations/magento/merchant-guide`, and open or update a PR if anything changed.
