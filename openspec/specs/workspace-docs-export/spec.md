# workspace-docs-export Specification

## Purpose

Converts the extension's merchant documentation into the layout TaxCloud's internal knowledge base (`taxcloud-workspace`, published to Confluence by md2conf) expects, verbatim and deterministically, so an automated sync can publish it on every release without anyone rewriting it.

## Requirements

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

A person MAY explicitly tell the export to adopt a non-empty, unmarked directory. The export then replaces it and marks it like any other run. Without that explicit instruction the refusal stands.

#### Scenario: Re-export after a page is removed
- **WHEN** a page is removed from the docs and the export is run again into the same directory
- **THEN** the removed page is no longer in the output

#### Scenario: Pointed at someone else's directory
- **WHEN** the output directory already contains files and was not written by the export
- **THEN** the export refuses, changes nothing, and exits non-zero

#### Scenario: Adopting an existing directory
- **WHEN** the output directory contains files, carries no mark, and the person explicitly asks the export to adopt it
- **THEN** the export replaces its content, marks it as its own, and later runs need no adoption

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
- escaping dollar signs outside code, so they are not read as math;
- replacing commas in image descriptions with semicolons, because Confluence rejects attachment comments that contain commas;
- dropping content the docs mark as belonging only on the public docs site.

Content is marked for dropping by enclosing it between a skip marker and an end marker, each on its own line, or, for a single line such as one list item, by ending that line with a skip-line marker. All markers are invisible on the public site and SHALL NOT appear in the output. A skip marker without a matching end marker, an end marker without a skip marker, or a skip marker inside a skipped block SHALL fail the export, naming the file and line.

Note, tip and warning callouts SHALL be kept as they are. Markup the publisher cannot render and the export does not convert SHALL fail the export rather than be dropped or passed through.

#### Scenario: Content tabs
- **WHEN** a page uses content tabs
- **THEN** each tab becomes a sub-heading followed by the tab's content, in the original order

#### Scenario: Currency in prose
- **WHEN** a page contains two dollar amounts in one sentence outside code
- **THEN** the exported page escapes them and the publisher renders them as literal amounts

#### Scenario: Image description with commas
- **WHEN** an image's description contains commas
- **THEN** the exported description uses semicolons instead, and text outside image descriptions keeps its commas

#### Scenario: Public-site-only content
- **WHEN** a page encloses content between a skip marker and an end marker
- **THEN** that content and both markers are absent from the exported page, and the rest of the page is unchanged

#### Scenario: A single public-site-only line
- **WHEN** a line, such as a list item, ends with a skip-line marker
- **THEN** that line is absent from the exported page and the surrounding list is otherwise unchanged

#### Scenario: Unbalanced markers
- **WHEN** a page has a skip marker with no end marker, or an end marker with no skip marker
- **THEN** the export fails, naming the file and line

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

### Requirement: Sync from a release tag

A single command SHALL export the docs exactly as they stood at a given release tag into the Magento folder of a local `taxcloud-workspace` clone, replacing that folder. It SHALL use the tag's docs, not the working tree's, and SHALL fail with a clear message if the tag doesn't exist locally or the path is not a `taxcloud-workspace` clone. It SHALL NOT create branches, commit or push in the workspace clone.

#### Scenario: Sync a release
- **WHEN** the command is run with an existing tag and a valid workspace clone
- **THEN** the clone's Magento folder holds that tag's exported docs, and nothing is committed

#### Scenario: Unknown tag
- **WHEN** the command is run with a tag that does not exist locally
- **THEN** it fails without touching the workspace clone

### Requirement: Release reminder

Publishing a GitHub release in this repository SHALL open an issue in this repository asking for the docs to be synced to `taxcloud-workspace`, naming the release tag and the exact command to run. It SHALL use only this repository's own token, and SHALL NOT open a second issue for a release that already has an open one.

#### Scenario: Release published
- **WHEN** a release for tag vX.Y.Z is published
- **THEN** an issue titled with that tag asks for the sync and shows the command

#### Scenario: Release re-published
- **WHEN** a release is published again and its reminder issue is still open
- **THEN** no second issue is opened
