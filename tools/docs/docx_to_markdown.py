#!/usr/bin/env python3
"""Convert the specification .docx files into versioned, greppable Markdown.

The four specs were delivered as Word documents. Word is fine for authoring but
useless for review: a .docx cannot be diffed, grepped, or read by an agent. This
converter produces docs/specs/*.md from docs/specs/source/*.docx using only the
Python standard library (no pandoc in the dev environment).

Usage:  python3 tools/docs/docx_to_markdown.py
"""

from __future__ import annotations

import re
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"

ROOT = Path(__file__).resolve().parents[2]
SOURCE_DIR = ROOT / "docs" / "specs" / "source"
TARGET_DIR = ROOT / "docs" / "specs"

TARGETS = {
    "Spec_Fonctionnelle_Core_System.docx": "spec-fonctionnelle.md",
    "Spec_Technique_Core_System.docx": "spec-technique.md",
    "Plan_Delivery_MVP_Core_System.docx": "plan-delivery-mvp.md",
    "Gouvernance_Anti_Derive_Core_System.docx": "gouvernance-anti-derive.md",
}

MONO_FONTS = ("courier", "consolas", "mono", "menlo", "source code")


def load_styles(archive: zipfile.ZipFile) -> dict[str, str]:
    try:
        root = ET.fromstring(archive.read("word/styles.xml"))
    except KeyError:
        return {}
    styles = {}
    for style in root.iter(W + "style"):
        name = style.find(W + "name")
        style_id = style.get(W + "styleId") or ""
        styles[style_id] = (name.get(W + "val") if name is not None else style_id).lower()
    return styles


def load_numbering(archive: zipfile.ZipFile) -> dict[tuple[str, str], str]:
    """(numId, ilvl) -> numFmt ('bullet', 'decimal', ...)."""
    try:
        root = ET.fromstring(archive.read("word/numbering.xml"))
    except KeyError:
        return {}
    abstract_formats: dict[tuple[str, str], str] = {}
    for abstract in root.iter(W + "abstractNum"):
        abstract_id = abstract.get(W + "abstractNumId") or ""
        for level in abstract.iter(W + "lvl"):
            fmt = level.find(W + "numFmt")
            abstract_formats[(abstract_id, level.get(W + "ilvl") or "0")] = (
                fmt.get(W + "val") if fmt is not None else "bullet"
            )
    formats: dict[tuple[str, str], str] = {}
    for num in root.iter(W + "num"):
        num_id = num.get(W + "numId") or ""
        ref = num.find(W + "abstractNumId")
        if ref is None:
            continue
        abstract_id = ref.get(W + "val") or ""
        for (aid, ilvl), fmt in abstract_formats.items():
            if aid == abstract_id:
                formats[(num_id, ilvl)] = fmt
    return formats


def paragraph_text(paragraph: ET.Element) -> str:
    parts = []
    for node in paragraph.iter():
        if node.tag == W + "t":
            parts.append(node.text or "")
        elif node.tag == W + "tab":
            parts.append("\t")
        elif node.tag == W + "br":
            parts.append("\n")
    return "".join(parts)


def is_monospace(paragraph: ET.Element, style_name: str) -> bool:
    if "code" in style_name or "source" in style_name:
        return True
    fonts = [
        (fonts.get(W + "ascii") or fonts.get(W + "hAnsi") or "").lower()
        for fonts in paragraph.iter(W + "rFonts")
    ]
    return bool(fonts) and all(any(m in f for m in MONO_FONTS) for f in fonts if f)


def code_language(lines: list[str]) -> str:
    text = "\n".join(lines)
    if re.search(r"\bCREATE (TABLE|INDEX)\b", text):
        return "sql"
    if text.lstrip().startswith("{"):
        return "json"
    if "deptrac:" in text:
        return "yaml"
    if re.search(r"^(POST|GET|PUT|PATCH|DELETE) /", text, re.M):
        return "http"
    if re.search(r"\$\w+|\bfunction\b|\binterface\b|\bclass\b", text):
        return "php"
    return "text"


def cell_text(cell: ET.Element) -> str:
    text = " ".join(paragraph_text(p).strip() for p in cell.iter(W + "p"))
    return re.sub(r"\s+", " ", text).replace("|", "\\|").strip()


def convert(docx: Path) -> str:
    archive = zipfile.ZipFile(docx)
    styles = load_styles(archive)
    numbering = load_numbering(archive)
    body = ET.fromstring(archive.read("word/document.xml")).find(W + "body")
    assert body is not None

    out: list[str] = []
    code: list[str] = []

    def flush_code() -> None:
        if code:
            out.extend(["", f"```{code_language(code)}", *code, "```", ""])
            code.clear()

    for element in body:
        if element.tag == W + "tbl":
            flush_code()
            rows = [
                [cell_text(cell) for cell in row.findall(W + "tc")]
                for row in element.findall(W + "tr")
            ]
            if not rows:
                continue
            width = max(len(r) for r in rows)
            rows = [r + [""] * (width - len(r)) for r in rows]
            out.append("")
            out.append("| " + " | ".join(rows[0]) + " |")
            out.append("| " + " | ".join(["---"] * width) + " |")
            out.extend("| " + " | ".join(r) + " |" for r in rows[1:])
            out.append("")
            continue

        if element.tag != W + "p":
            continue

        props = element.find(W + "pPr")
        style_id = ""
        if props is not None and props.find(W + "pStyle") is not None:
            style_id = props.find(W + "pStyle").get(W + "val") or ""  # type: ignore[union-attr]
        style = styles.get(style_id, style_id.lower())
        text = paragraph_text(element)

        if style.startswith("toc") or text.strip() == "Table of Contents":
            continue

        if is_monospace(element, style) and text.strip():
            code.extend(text.split("\n"))
            continue

        flush_code()
        stripped = text.strip()

        if not stripped:
            if out and out[-1] != "":
                out.append("")
            continue

        if style == "title":
            out.extend([f"# {stripped}", ""])
            continue

        heading = re.match(r"heading (\d)", style)
        if heading:
            level = min(int(heading.group(1)) + 1, 6)
            out.extend(["", "#" * level + " " + stripped, ""])
            continue

        num_props = props.find(W + "numPr") if props is not None else None
        if num_props is not None:
            num_id_el = num_props.find(W + "numId")
            ilvl_el = num_props.find(W + "ilvl")
            num_id = num_id_el.get(W + "val") if num_id_el is not None else ""
            ilvl = ilvl_el.get(W + "val") if ilvl_el is not None else "0"
            fmt = numbering.get((num_id or "", ilvl or "0"), "bullet")
            indent = "   " * int(ilvl or "0")
            marker = "1." if fmt not in ("bullet", "none") else "-"
            out.append(f"{indent}{marker} {stripped}")
            continue

        out.extend([stripped, ""])

    flush_code()

    markdown = "\n".join(out)
    markdown = re.sub(r"\n{3,}", "\n\n", markdown).strip() + "\n"
    header = (
        f"> Conversion Markdown de [`source/{docx.name}`](source/{docx.name}), "
        "générée par `tools/docs/docx_to_markdown.py`.\n"
        "> En cas d'écart, le document Word fait foi ; régénérer après toute mise à jour.\n\n"
    )
    return header + markdown


def main() -> None:
    TARGET_DIR.mkdir(parents=True, exist_ok=True)
    for source_name, target_name in TARGETS.items():
        source = SOURCE_DIR / source_name
        if not source.exists():
            raise SystemExit(f"missing source: {source}")
        markdown = convert(source)
        (TARGET_DIR / target_name).write_text(markdown, encoding="utf-8")
        fences = markdown.count("```") // 2
        tables = sum(1 for line in markdown.splitlines() if line.startswith("| ---"))
        print(f"{target_name}: {len(markdown.splitlines())} lines, {fences} code blocks, {tables} tables")


if __name__ == "__main__":
    main()
