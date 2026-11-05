## Purpose

Converts the extension's merchant documentation into the layout TaxCloud's internal knowledge base (`taxcloud-workspace`, published to Confluence by md2conf) expects, verbatim and deterministically, so an automated sync can publish it on every release without anyone rewriting it.

## ADDED Requirements

### Requirement: Stable command-line contract

The export SHALL be runnable from a checkout of this repository with a single command that takes the output directory as its only required argument. It SHALL read only the checkout's own `mkdocs.yml` and `docs/` (no network access, no language model, no Magento installation) and SHALL need nothing beyond a Python 3 interpreter and the dependencies listed in the export's own requirements file.

It SHALL exit with status 0 when the export succeeded, and with a non-zero status and a message naming the file and line when it did not. A failed export SHALL leave no partially written guide behind.

#### Scenario: Successful export
- **WHEN** the export is run against a checkout whose docs are valid
- **THEN** it writes the guide to the given directory and exits with status 0

#### Scenario: Failed export
- **WHEN** the export meets a page it cannot convert
- **THEN** it exits non-zero, reports the offending file and line, and the output directory is left as it was before the run

### Requirement: The export owns its output directory

The export SHALL replace the whole content of its output directory, so pages removed or renamed in the docs disappear from the output. It SHALL mark the directory as its own, and SHALL refuse to write into a non-empty directory that does not carry that mark.

#### Scenario: Re-export after a page is removed
- **WHEN** a page is removed from the docs and the export is run again into the same directory
- **THEN** the removed page is no longer in the output

#### Scenario: Pointed at someone else's directory
- **WHEN** the output directory already contains files and was not written by the export
- **THEN** the export refuses, changes nothing, and exits non-zero

### Requirement: Deterministic output

Exporting the same docs SHALL produce byte-identical output: no timestamps, no run-specific identifiers, a fixed file order, and fixed line endings.

#### Scenario: Two runs on unchanged docs
- **WHEN** the export is run twice on the same commit
- **THEN** the two outputs are identical, file for file and byte for byte

### Requirement: Structure follows the docs site navigation

The output SHALL mirror the docs site's navigation:
- Each navigation section SHALL become a directory containing an `index.md` page that lists the section's pages.
- The site's home page SHALL become the guide's top-level `index.md`.
- Every page and section SHALL carry a numeric filename prefix that reproduces the navigation order when sorted by name.

Navigation sections designated as developer-only (the Development section) SHALL be excluded, together with their pages.

#### Scenario: Navigation order is preserved
- **WHEN** a section lists pages A, B, C in that order
- **THEN** the exported files for A, B, C sort by filename in that same order

#### Scenario: Developer pages are not exported
- **WHEN** the export runs
- **THEN** no page from the Development section appears in the output

### Requirement: Unique, stable page titles

Each exported page's title, taken from its first heading, SHALL be prefixed with "Magento 2 — " unless it already names Magento 2, so titles cannot collide with other integrations' pages. Titles SHALL depend only on the page's own heading or section name, never on its position. Two exported pages with the same title SHALL fail the export.

#### Scenario: Generic title is prefixed
- **WHEN** a page's heading is "Capture"
- **THEN** its exported title is "Magento 2 — Capture"

#### Scenario: Duplicate title
- **WHEN** two exported pages would carry the same title
- **THEN** the export fails, naming both pages

### Requirement: Content is copied verbatim apart from format conversion

Page content SHALL be copied unchanged except for the conversions required to publish it:
- the title prefix;
- rewritten links;
- converting content tabs into sub-headings;
- escaping dollar signs outside code, so they are not read as math.

Note, tip and warning callouts SHALL be kept as they are. Markup the publisher cannot render and the export does not convert SHALL fail the export rather than be dropped or passed through.

#### Scenario: Content tabs
- **WHEN** a page uses content tabs
- **THEN** each tab becomes a sub-heading followed by the tab's content, in the original order

#### Scenario: Currency in prose
- **WHEN** a page contains two dollar amounts in one sentence outside code
- **THEN** the exported page escapes them and the publisher renders them as literal amounts

#### Scenario: Unsupported markup
- **WHEN** a page uses markup the export does not support (for example collapsible blocks or attribute lists)
- **THEN** the export fails, naming the file and line

### Requirement: Links and images resolve in the published guide

Links between exported pages SHALL point at the exported files, keeping any section anchor. Links to pages that are not exported SHALL point at that page on the public docs site. Images referenced by exported pages SHALL be copied into the output and linked relatively, and only referenced images SHALL be copied. External links SHALL be left unchanged. A link to a page or image that does not exist SHALL fail the export.

#### Scenario: Link to another exported page
- **WHEN** a page links to another exported page with an anchor
- **THEN** the exported link points at that page's exported file with the same anchor

#### Scenario: Link to a developer page
- **WHEN** a merchant page links to a page in the Development section
- **THEN** the exported link points at that page on the public docs site

#### Scenario: Broken link
- **WHEN** a page links to a docs file that does not exist
- **THEN** the export fails, naming the file and line

### Requirement: Publish compatibility is checked on every docs change

Every change to the docs, the navigation, or the export itself SHALL be checked automatically: the export runs, and its output is rendered offline with the same md2conf build that `taxcloud-workspace` publishes with. If either fails, the check fails. The check SHALL NOT contact Confluence.

#### Scenario: A docs change that would break the publish
- **WHEN** a pull request changes a page so the publisher could not render it
- **THEN** the check fails on that pull request
