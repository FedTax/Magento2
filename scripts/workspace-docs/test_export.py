"""Tests for export.py. Run: python3 -m unittest discover -s scripts/workspace-docs"""

from __future__ import annotations

import contextlib
import filecmp
import io
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import export  # noqa: E402

REPO = Path(__file__).resolve().parents[2]

MKDOCS = """\
site_name: Test
site_url: https://example.github.io/Site/
nav:
  - Home: index.md
  - Getting started:
      - Overview: overview.md
      - Install: installing.md
  - Orders:
      - Capture: capture.md
  - Development:
      - Extending: extending.md
"""

PAGES = {
    "index.md": "# TaxCloud for Magento 2\n\nStart with [Overview](overview.md).\n",
    "overview.md": "# Overview\n\nSee [capture](capture.md#when-it-runs).\n",
    "installing.md": "# Installing the extension\n\n![Screen](images/install.png)\n",
    "capture.md": "# Capture\n\n## When it runs\n\nDevelopers: [extend it](extending.md#hooks).\n",
    "extending.md": "# Extending the extension\n\n## Hooks\n",
}


class ExportTestCase(unittest.TestCase):
    def setUp(self):
        self.tmp = Path(tempfile.mkdtemp())
        self.source = self.tmp / "repo"
        (self.source / "docs" / "images").mkdir(parents=True)
        (self.source / "mkdocs.yml").write_text(MKDOCS)
        for name, body in PAGES.items():
            self.write_page(name, body)
        (self.source / "docs" / "images" / "install.png").write_bytes(b"png")
        (self.source / "docs" / "images" / "unused.png").write_bytes(b"png")
        self.out = self.tmp / "workspace" / "merchant-guide"

    def tearDown(self):
        import shutil
        shutil.rmtree(self.tmp)

    def write_page(self, name: str, body: str) -> None:
        (self.source / "docs" / name).write_text(textwrap.dedent(body))

    def run_export(self, out: Path | None = None) -> tuple[int, str]:
        stderr = io.StringIO()
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(stderr):
            code = export.main(["--source", str(self.source), "--out", str(out or self.out)])
        return code, stderr.getvalue()

    def read(self, rel: str) -> str:
        return (self.out / rel).read_text()

    def files(self, root: Path | None = None) -> list[str]:
        root = root or self.out
        return sorted(str(p.relative_to(root)) for p in root.rglob("*") if p.is_file())


class StructureTest(ExportTestCase):
    def test_layout_follows_nav_and_excludes_development(self):
        self.assertEqual(self.run_export()[0], 0)
        self.assertEqual(self.files(), [
            ".magento-docs-export",
            "10-getting-started/10-overview.md",
            "10-getting-started/20-installing.md",
            "10-getting-started/index.md",
            "20-orders/10-capture.md",
            "20-orders/index.md",
            "images/install.png",
            "index.md",
        ])

    def test_prefixes_are_padded_so_names_sort_in_nav_order(self):
        pages = [f"p{i}.md" for i in range(12)]
        for name in pages:
            self.write_page(name, f"# Page {name}\n")
        nav = "\n".join(f"      - {name}" for name in pages)
        (self.source / "mkdocs.yml").write_text(
            f"site_url: https://example.github.io/Site/\nnav:\n  - Big:\n{nav}\n"
        )
        self.assertEqual(self.run_export()[0], 0)
        names = sorted(p.name for p in (self.out / "10-big").glob("*.md") if p.name != "index.md")
        self.assertEqual(names[0], "010-p0.md")
        self.assertEqual(names[-1], "120-p11.md")
        self.assertEqual([n.split("-", 1)[1] for n in names], pages)

    def test_section_index_lists_pages(self):
        self.run_export()
        self.assertEqual(self.read("10-getting-started/index.md"), textwrap.dedent("""\
            # Magento 2 — Getting started

            - [Overview](10-overview.md)
            - [Install](20-installing.md)
        """))


class TitleTest(ExportTestCase):
    def test_titles_are_prefixed_unless_they_name_magento_2(self):
        self.run_export()
        self.assertTrue(self.read("20-orders/10-capture.md").startswith("# Magento 2 — Capture\n"))
        self.assertTrue(self.read("index.md").startswith("# TaxCloud for Magento 2\n"))

    def test_duplicate_titles_fail(self):
        self.write_page("installing.md", "# Overview\n")
        code, err = self.run_export()
        self.assertEqual(code, 1)
        self.assertIn("duplicate title 'Magento 2 — Overview'", err)
        self.assertFalse(self.out.exists())


class ContentTest(ExportTestCase):
    def test_body_is_otherwise_unchanged(self):
        self.write_page("overview.md", """\
            # Overview

            !!! warning "Mind this"
                Callouts stay as they are.

            - [ ] Task lists too.
        """)
        self.run_export()
        self.assertEqual(self.read("10-getting-started/10-overview.md"), textwrap.dedent("""\
            # Magento 2 — Overview

            !!! warning "Mind this"
                Callouts stay as they are.

            - [ ] Task lists too.
        """))

    def test_content_tabs_become_subheadings(self):
        self.write_page("overview.md", """\
            # Overview

            ## Credentials

            === "V3 REST"

                Use the connection ID.

                !!! note
                    Nested callout.

            === "V1 SOAP"

                Use the API ID.

            After the tabs.
        """)
        self.run_export()
        self.assertEqual(self.read("10-getting-started/10-overview.md"), textwrap.dedent("""\
            # Magento 2 — Overview

            ## Credentials

            ### V3 REST

            Use the connection ID.

            !!! note
                Nested callout.

            ### V1 SOAP

            Use the API ID.

            After the tabs.
        """))

    def test_dollar_signs_are_escaped_outside_code(self):
        self.write_page("overview.md", """\
            # Overview

            A $1,100 base (tax ~$97), already \\$5, code `$x` stays.

            ```
            echo $HOME
            ```
        """)
        self.run_export()
        body = self.read("10-getting-started/10-overview.md")
        self.assertIn("A \\$1,100 base (tax ~\\$97), already \\$5, code `$x` stays.", body)
        self.assertIn("echo $HOME", body)

    def test_unsupported_markup_fails_with_location(self):
        for markup in ['??? note "More"', "Text { .big }", '<div markdown="1">', "[ref]: https://x"]:
            with self.subTest(markup=markup):
                self.write_page("overview.md", f"# Overview\n\n{markup}\n")
                code, err = self.run_export()
                self.assertEqual(code, 1)
                self.assertIn("docs/overview.md:3: unsupported markup", err)

    def test_markup_inside_code_is_ignored(self):
        self.write_page("overview.md", "# Overview\n\n```\n??? note\n```\n\nInline `{: .x }` too.\n")
        self.assertEqual(self.run_export()[0], 0)


class LinkTest(ExportTestCase):
    def test_links_between_exported_pages_keep_anchors(self):
        self.run_export()
        self.assertIn("[capture](../20-orders/10-capture.md#when-it-runs)", self.read("10-getting-started/10-overview.md"))
        self.assertIn("[Overview](10-getting-started/10-overview.md)", self.read("index.md"))

    def test_links_to_excluded_pages_go_to_the_public_site(self):
        self.run_export()
        self.assertIn("[extend it](https://example.github.io/Site/extending/#hooks)", self.read("20-orders/10-capture.md"))

    def test_images_are_copied_only_when_referenced(self):
        self.run_export()
        self.assertIn("![Screen](../images/install.png)", self.read("10-getting-started/20-installing.md"))
        self.assertFalse((self.out / "images" / "unused.png").exists())

    def test_wrapped_links_are_rewritten(self):
        self.write_page("overview.md", "# Overview\n\nSee [the capture\npage](capture.md) now.\n")
        self.run_export()
        self.assertIn("[the capture\npage](../20-orders/10-capture.md)", self.read("10-getting-started/10-overview.md"))

    def test_external_and_anchor_links_are_unchanged(self):
        self.write_page("overview.md", "# Overview\n\n[a](https://x.test/a.md) [b](#local) [c](mailto:x@y.z)\n")
        self.run_export()
        self.assertIn("[a](https://x.test/a.md) [b](#local) [c](mailto:x@y.z)", self.read("10-getting-started/10-overview.md"))

    def test_broken_link_fails_with_location(self):
        self.write_page("overview.md", "# Overview\n\nText\nsee [x](missing.md).\n")
        code, err = self.run_export()
        self.assertEqual(code, 1)
        self.assertIn("docs/overview.md:4: link target does not exist: missing.md", err)


class OutputDirectoryTest(ExportTestCase):
    def test_reexport_removes_deleted_pages(self):
        self.run_export()
        (self.source / "mkdocs.yml").write_text(MKDOCS.replace("      - Install: installing.md\n", ""))
        self.assertEqual(self.run_export()[0], 0)
        self.assertFalse((self.out / "10-getting-started" / "20-installing.md").exists())

    def test_refuses_a_foreign_directory(self):
        self.out.mkdir(parents=True)
        (self.out / "theirs.md").write_text("# Theirs\n")
        code, err = self.run_export()
        self.assertEqual(code, 2)
        self.assertIn("refusing to replace", err)
        self.assertEqual(self.files(), ["theirs.md"])

    def test_failed_export_leaves_previous_output(self):
        self.run_export()
        before = {f: (self.out / f).read_bytes() for f in self.files()}
        self.write_page("overview.md", "# Overview\n\n[x](missing.md)\n")
        self.assertEqual(self.run_export()[0], 1)
        self.assertEqual({f: (self.out / f).read_bytes() for f in self.files()}, before)
        self.assertEqual(sorted(p.name for p in self.out.parent.iterdir()), ["merchant-guide"])

    def test_output_is_deterministic(self):
        other = self.tmp / "again" / "merchant-guide"
        self.run_export()
        self.run_export(other)
        comparison = filecmp.dircmp(self.out, other)
        self.assertEqual(self.files(), self.files(other))
        for rel in self.files():
            self.assertEqual((self.out / rel).read_bytes(), (other / rel).read_bytes(), rel)
        self.assertFalse(comparison.diff_files)


class RealDocsTest(unittest.TestCase):
    """The repository's own docs must always export, identically each time."""

    def test_real_docs_export_cleanly_and_deterministically(self):
        with tempfile.TemporaryDirectory() as tmp:
            first, second = Path(tmp) / "a" / "guide", Path(tmp) / "b" / "guide"
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(export.main(["--source", str(REPO), "--out", str(first)]), 0)
                self.assertEqual(export.main(["--source", str(REPO), "--out", str(second)]), 0)
            files = sorted(p.relative_to(first) for p in first.rglob("*") if p.is_file())
            self.assertEqual(files, sorted(p.relative_to(second) for p in second.rglob("*") if p.is_file()))
            for rel in files:
                self.assertEqual((first / rel).read_bytes(), (second / rel).read_bytes(), str(rel))
            self.assertFalse(any("Development" in str(f) or "extending" in str(f) for f in files))


if __name__ == "__main__":
    unittest.main()
