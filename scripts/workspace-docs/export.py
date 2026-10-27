#!/usr/bin/env python3
"""Export the merchant docs into the taxcloud-workspace (md2conf) layout.

TaxCloud's internal knowledge base, the `taxcloud-workspace` repo, publishes
`docs/` to the PD Confluence space with md2conf. docs-sync-bot runs this script
on every release tag to copy our docs there verbatim:

    python3 scripts/workspace-docs/export.py --out <dir>

The output directory is owned by the export: it is replaced as a whole (so
removed pages disappear), marked with `.magento-docs-export`, and a non-empty
directory without that marker is refused. Output is deterministic, so a release
without doc changes produces no diff.

Content is copied as-is apart from the conversions md2conf needs: titles get a
"Magento 2 — " prefix, links are rewritten, content tabs become sub-headings and
dollar signs are escaped. Markup md2conf cannot render fails the export instead
of being published wrong.

Exit codes: 0 success, 1 conversion error, 2 usage error or refused directory.
"""

from __future__ import annotations

import argparse
import os
import posixpath
import re
import shutil
import sys
import tempfile
from dataclasses import dataclass, field
from pathlib import Path

import yaml

TITLE_PREFIX = "Magento 2 — "
MARKER = ".magento-docs-export"
# Nav sections written for developers, not for the people the workspace serves.
EXCLUDED_SECTIONS = {"Development"}

FENCE_RE = re.compile(r"^\s*(```|~~~)")
HEADING_RE = re.compile(r"^(#{1,6})\s+(.*?)\s*#*\s*$")
TAB_RE = re.compile(r'^=== "(.+)"\s*$')
INLINE_CODE_RE = re.compile(r"(`+)(?:.+?)\1")
# The same, but allowed to wrap onto following lines (not across a blank line).
INLINE_SPAN_RE = re.compile(r"(`+)(?:(?!\n\s*\n)[\s\S])+?\1")
# [text](target) and ![alt](target), with an optional "title"; text may hold
# nested brackets one level deep (e.g. a bolded or bracketed word).
LINK_RE = re.compile(
    r'(?P<bang>!?)\[(?P<text>(?:[^\[\]]|\[[^\]]*\])*)\]'
    r'\((?P<target>[^)\s]*)(?P<title>\s+"[^"]*")?\)'
)
# Markup md2conf would silently mangle. Matched outside code only.
UNSUPPORTED = [
    (re.compile(r"^\s*\?\?\?\+?\s"), "collapsible block (???)"),
    (re.compile(r"\{\s*[:.#][^}]*\}"), "attribute list ({: ...})"),
    (re.compile(r"markdown=[\"']?1"), "markdown-in-HTML block"),
    (re.compile(r"--8<--"), "snippet include (--8<--)"),
    (re.compile(r"^\s{0,3}\[[^\]]+\]:\s*\S"), "reference-style link definition"),
]


class ExportError(Exception):
    """A doc that cannot be converted; reported as `file:line: message`."""

    def __init__(self, message: str, path: str = "", line: int = 0):
        location = f"{path}:{line}: " if line else (f"{path}: " if path else "")
        super().__init__(location + message)


@dataclass
class Page:
    source: str  # docs-relative path, e.g. "capture.md"
    out: str  # guide-relative path, e.g. "50-orders/20-capture.md"
    title: str  # published title
    nav_title: str  # label shown in section listings


@dataclass
class Section:
    name: str
    out_dir: str
    title: str
    pages: list[Page] = field(default_factory=list)


def slugify(text: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def prefixed(title: str) -> str:
    return title if "Magento 2" in title else TITLE_PREFIX + title


def numbered(count: int) -> list[str]:
    """Prefixes 10, 20, ... zero-padded to one width, so names sort in order."""
    width = len(str(count * 10))
    return [str((i + 1) * 10).zfill(width) for i in range(count)]


def first_heading(text: str, path: str) -> tuple[int, str]:
    in_fence = False
    for lineno, line in enumerate(text.splitlines(), start=1):
        if FENCE_RE.match(line):
            in_fence = not in_fence
            continue
        if not in_fence and line.startswith("# "):
            return lineno, line[2:].strip()
    raise ExportError("page has no '# ' heading to use as its title", path)


def load_nav(source: Path) -> tuple[str, list]:
    config_path = source / "mkdocs.yml"
    if not config_path.is_file():
        raise ExportError("mkdocs.yml not found", str(config_path))
    config = yaml.safe_load(config_path.read_text(encoding="utf-8")) or {}
    site_url = config.get("site_url", "")
    if not site_url.endswith("/"):
        site_url += "/"
    nav = config.get("nav")
    if not isinstance(nav, list):
        raise ExportError("mkdocs.yml has no nav list", "mkdocs.yml")
    return site_url, nav


def nav_entry(item) -> tuple[str | None, object]:
    if isinstance(item, dict) and len(item) == 1:
        return next(iter(item.items()))
    if isinstance(item, str):
        return None, item
    raise ExportError(f"unsupported nav entry: {item!r}", "mkdocs.yml")


def page_title(docs: Path, source: str) -> str:
    path = docs / source
    if not path.is_file():
        raise ExportError(f"nav points at a missing page: {source}", "mkdocs.yml")
    return first_heading(path.read_text(encoding="utf-8"), f"docs/{source}")[1]


def plan(docs: Path, nav: list) -> tuple[Page | None, list[Page], list[Section]]:
    """Map the nav to output paths: (home page, top-level pages, sections)."""
    home: Page | None = None
    top: list[tuple[str | None, object]] = []
    for item in nav:
        label, value = nav_entry(item)
        if value == "index.md":
            title = page_title(docs, "index.md")
            home = Page("index.md", "index.md", prefixed(title), label or title)
        elif label in EXCLUDED_SECTIONS:
            continue
        elif isinstance(value, str) and re.match(r"^[a-z]+://", value):
            continue  # external nav link: not a page
        else:
            top.append((label, value))

    pages: list[Page] = []
    sections: list[Section] = []
    for prefix, (label, value) in zip(numbered(len(top)), top):
        if isinstance(value, list):
            if label is None:
                raise ExportError("nav section without a name", "mkdocs.yml")
            out_dir = f"{prefix}-{slugify(label)}"
            section = Section(label, out_dir, prefixed(label))
            children = [nav_entry(child) for child in value]
            if any(isinstance(v, list) for _, v in children):
                raise ExportError(f"nested nav sections are not supported: {label}", "mkdocs.yml")
            children = [(l, v) for l, v in children if not re.match(r"^[a-z]+://", v)]
            for child_prefix, (child_label, child) in zip(numbered(len(children)), children):
                title = page_title(docs, child)
                stem = posixpath.splitext(posixpath.basename(child))[0].lower()
                section.pages.append(Page(
                    child, f"{out_dir}/{child_prefix}-{stem}.md", prefixed(title), child_label or title,
                ))
            sections.append(section)
        else:
            title = page_title(docs, value)
            stem = posixpath.splitext(posixpath.basename(value))[0].lower()
            pages.append(Page(value, f"{prefix}-{stem}.md", prefixed(title), label or title))
    return home, pages, sections


def mkdocs_url(site_url: str, source: str) -> str:
    """The public URL of a docs page (mkdocs directory-style URLs)."""
    stem = posixpath.splitext(source)[0]
    if stem == "index":
        return site_url
    if stem.endswith("/index"):
        return site_url + stem[: -len("index")]
    return site_url + stem + "/"


class Converter:
    def __init__(self, docs: Path, site_url: str, pages: dict[str, Page]):
        self.docs = docs
        self.site_url = site_url
        self.pages = pages  # docs-relative source -> exported page
        self.images: set[str] = set()  # docs-relative image paths to copy

    def convert(self, page: Page) -> str:
        path = f"docs/{page.source}"
        lines = (self.docs / page.source).read_text(encoding="utf-8").splitlines()
        title_line, _ = first_heading("\n".join(lines), path)

        # Pass 1, per line: title, content tabs, fences, unsupported markup.
        # Output lines map 1:1 to source lines, so line numbers stay true.
        out: list[str] = []
        is_code: list[bool] = []
        in_fence = False
        tab_indent = False  # inside a content tab body (indented by 4)
        last_level = 1
        for lineno, line in enumerate(lines, start=1):
            if tab_indent and line.strip() and not line.startswith("    "):
                tab_indent = False
            body = line[4:] if tab_indent and line.startswith("    ") else line

            if FENCE_RE.match(body) or in_fence:
                if FENCE_RE.match(body):
                    in_fence = not in_fence
                out.append(body)
                is_code.append(True)
                continue

            is_code.append(False)
            if lineno == title_line:
                out.append(f"# {page.title}")
                continue
            tab = TAB_RE.match(body)
            if tab and not tab_indent:
                level = min(last_level + 1, 6)
                out.append(f"{'#' * level} {tab.group(1)}")
                tab_indent = True
                continue
            heading = HEADING_RE.match(body)
            if heading:
                last_level = len(heading.group(1))
            self.guard(body, path, lineno)
            out.append(body)
        if in_fence:
            raise ExportError("unclosed code fence", path, len(lines))

        # Pass 2, per run of prose lines: links and inline code may wrap
        # across lines, so rewrite whole runs rather than single lines.
        result: list[str] = []
        start = 0
        while start < len(out):
            end = start
            while end < len(out) and is_code[end] == is_code[start]:
                end += 1
            chunk = "\n".join(out[start:end])
            if not is_code[start]:
                chunk = self.rewrite(chunk, page, path, start + 1)
            result.append(chunk)
            start = end
        return "\n".join(result).rstrip("\n") + "\n"

    @staticmethod
    def guard(line: str, path: str, lineno: int) -> None:
        masked = INLINE_CODE_RE.sub("", line)
        for pattern, name in UNSUPPORTED:
            if pattern.search(masked):
                raise ExportError(f"unsupported markup: {name}", path, lineno)

    def rewrite(self, text: str, page: Page, path: str, lineno: int) -> str:
        """Escape `$` and rewrite links, leaving inline code untouched."""
        parts = []
        pos = 0
        for code in INLINE_SPAN_RE.finditer(text):
            parts.append(self.rewrite_text(text[pos:code.start()], page, path, lineno + text.count("\n", 0, pos)))
            parts.append(code.group(0))
            pos = code.end()
        parts.append(self.rewrite_text(text[pos:], page, path, lineno + text.count("\n", 0, pos)))
        return "".join(parts)

    def rewrite_text(self, text: str, page: Page, path: str, lineno: int) -> str:
        text = re.sub(r"(?<!\\)\$", r"\\$", text)
        return LINK_RE.sub(
            lambda m: self.link(m, page, path, lineno + text.count("\n", 0, m.start())), text,
        )

    def link(self, match: re.Match, page: Page, path: str, lineno: int) -> str:
        target = match.group("target")
        if not target or target.startswith("#") or re.match(r"^[a-z][a-z0-9+.-]*:", target):
            return match.group(0)  # same-page anchor, external URL or mailto
        target_path, _, anchor = target.partition("#")
        anchor = f"#{anchor}" if anchor else ""
        resolved = posixpath.normpath(posixpath.join(posixpath.dirname(page.source), target_path))
        if resolved.startswith("../") or not (self.docs / resolved).is_file():
            raise ExportError(f"link target does not exist: {target}", path, lineno)

        if resolved.endswith(".md"):
            if resolved in self.pages:
                new = self.relative(page.out, self.pages[resolved].out) + anchor
            else:
                new = mkdocs_url(self.site_url, resolved) + anchor
        else:
            self.images.add(resolved)
            new = self.relative(page.out, self.asset_out(resolved))
        title = match.group("title") or ""
        return f"{match.group('bang')}[{match.group('text')}]({new}{title})"

    @staticmethod
    def asset_out(source: str) -> str:
        return source  # images/x.png stays images/x.png at the guide root

    @staticmethod
    def relative(from_file: str, to_file: str) -> str:
        return posixpath.relpath(to_file, posixpath.dirname(from_file) or ".")


def section_index(section: Section) -> str:
    lines = [f"# {section.title}", ""]
    for page in section.pages:
        lines.append(f"- [{page.nav_title}]({posixpath.basename(page.out)})")
    return "\n".join(lines) + "\n"


def build(source: Path, dest: Path) -> None:
    docs = source / "docs"
    site_url, nav = load_nav(source)
    home, top_pages, sections = plan(docs, nav)

    all_pages = ([home] if home else []) + top_pages + [p for s in sections for p in s.pages]
    seen: dict[str, str] = {}
    for title, owner in [(p.title, p.source) for p in all_pages] + [(s.title, f"section {s.name}") for s in sections]:
        if title in seen:
            raise ExportError(f"duplicate title {title!r}: {seen[title]} and {owner}")
        seen[title] = owner

    converter = Converter(docs, site_url, {p.source: p for p in all_pages})
    files: dict[str, str] = {}
    for page in all_pages:
        files[page.out] = converter.convert(page)
    for section in sections:
        files[f"{section.out_dir}/index.md"] = section_index(section)
    if home is None:
        listing = [f"- [{s.name}]({s.out_dir}/index.md)" for s in sections]
        listing += [f"- [{p.nav_title}]({p.out})" for p in top_pages]
        files["index.md"] = f"# {TITLE_PREFIX}Merchant guide\n\n" + "\n".join(listing) + "\n"

    for rel in sorted(files):
        target = dest / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        with open(target, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(files[rel])
    for image in sorted(converter.images):
        target = dest / Converter.asset_out(image)
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(docs / image, target)
    (dest / MARKER).write_text(
        "Generated by scripts/workspace-docs/export.py in FedTax/Magento2. Do not edit by hand.\n",
        encoding="utf-8",
    )


def export(source: Path, out: Path) -> None:
    """Build into a sibling temp dir, then swap it in; `out` is untouched on failure."""
    if out.exists() and not out.is_dir():
        raise UsageError(f"{out} exists and is not a directory")
    if out.is_dir() and any(out.iterdir()) and not (out / MARKER).is_file():
        raise UsageError(f"refusing to replace {out}: it is not empty and was not written by this export")
    out.parent.mkdir(parents=True, exist_ok=True)
    staging = Path(tempfile.mkdtemp(prefix=f".{out.name}.", dir=out.parent))
    try:
        build(source, staging)
        if out.exists():
            shutil.rmtree(out)
        os.replace(staging, out)
    finally:
        if staging.exists():
            shutil.rmtree(staging)


class UsageError(Exception):
    pass


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--out", required=True, type=Path, help="directory to write the guide into (replaced as a whole)")
    parser.add_argument(
        "--source", type=Path, default=Path(__file__).resolve().parents[2],
        help="repository root holding mkdocs.yml and docs/ (default: this checkout)",
    )
    args = parser.parse_args(argv)
    try:
        export(args.source.resolve(), args.out.resolve())
    except UsageError as error:
        print(f"export: {error}", file=sys.stderr)
        return 2
    except ExportError as error:
        print(f"export failed: {error}", file=sys.stderr)
        return 1
    print(f"Exported docs to {args.out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
