## MODIFIED Requirements

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

## ADDED Requirements

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
