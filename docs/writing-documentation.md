# Writing documentation

This page defines who the documentation on this site is written for and how to
write for them. Read it before adding or editing a page.

## Target audience

**Store owners and Magento administrators — not developers.**

The reader is a merchant, an ecommerce manager, or the agency admin who
maintains their store. Concretely, assume the reader:

- Signs in to the Magento admin and can find their way around
  *Stores → Configuration*, *Catalog → Products* and *Customers*.
- Understands their own business: what they sell, where they ship, which
  customers are tax exempt.
- Is responsible for sales tax being right, and is nervous about getting it
  wrong. They want to know what a setting *does to their tax*, not what it does
  to the code.
- Does **not** read PHP, does not know what a plugin, observer, or dependency
  injection is, and may not have shell access to the server at all.
- Will hand anything requiring the command line to a developer or host. That is
  fine — but it must be clearly labelled so they know when to ask.

Developer-facing material (test suites, release process, extension points)
belongs in the repository `README.md` and the *Development* section, not in the
merchant-facing pages.

## How to write for them

- **Lead with the outcome, not the mechanism.** "Tax is charged even when
  TaxCloud is unreachable" beats "the fallback calculator is invoked on
  gateway failure".
- **Name the exact admin path** the first time a screen comes up, in italics
  with arrows: *Stores → Configuration → Sales → Tax → TaxCloud Settings*. To
  bold the final segment, close the italics first — `*Sales → Tax →* **TaxCloud
  Settings**`. Nesting bold inside italics (`*… → **Bold***`) renders a stray
  asterisk.
- **Use the label the admin actually shows**, in bold: **Capture in TaxCloud**.
  If the stored value differs from the label (`order_creation` vs *On order
  creation*), give the label first and the stored value only where it matters,
  such as a command-line appendix.
- **Always state the default** and what happens if the reader changes nothing.
- **Say what the risk is.** If a setting can cause under-collected tax, an
  unfiled sale, or a compliance exposure, say so plainly in a warning
  admonition. Do not bury it in a subordinate clause.
- **Prefer short sentences and concrete examples** — a real ZIP code, a real
  TIC, a real state — over abstractions.
- **Second person, present tense.** "You enter", not "the merchant should
  enter" or "this will be entered".
- **Keep jargon on a leash.** Sales tax terms the reader must learn (TIC,
  nexus, exemption certificate) get defined once, in plain words, at first use.
  Implementation terms (observer, gateway, cache frontend, data patch) do not
  appear at all.
- **Flag anything needing a developer** with a note admonition, and say what to
  hand over.
- **No screenshots of things that change often.** Field labels and admin paths
  in text survive UI reskins; screenshots do not.

## Formatting conventions

- Markdown, rendered by MkDocs Material. `admonition`, `attr_list`,
  `pymdownx.details` and `pymdownx.superfences` are available.
- Use `!!! warning` for compliance and money risks, `!!! note` for
  developer-required steps, `!!! tip` for recommendations.
- One `#` H1 per page, matching the nav title.
- Tables for reference material (settings, defaults, values); prose for
  explanations. Do not put a paragraph inside a table cell.
- Add every new page to the `nav:` block in `mkdocs.yml`, or it will not be
  reachable from the site navigation.

## Keeping pages true

Settings, defaults and option labels come from the module source, not from
memory:

- Fields, types, option sources and visibility rules —
  `etc/adminhtml/system.xml`
- Default values — `etc/config.xml`
- Option labels — `Model/Config/Source/*.php`
- Fallbacks applied when a stored value is blank or invalid —
  `Model/Config/TaxcloudConfig.php`

When a setting changes in any of those files, update
[Settings reference](settings.md) in the same change.
