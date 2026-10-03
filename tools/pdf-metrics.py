#!/usr/bin/env python3
"""Regenerates src/Pdf/Metrics.php from Adobe's Core 14 AFM files (as shipped with matplotlib).

Usage: python3 tools/pdf-metrics.py [path/to/pdfcorefonts]
Needs fontTools (for the Adobe Glyph List). Only widths and vertical metrics are taken, in WinAnsiEncoding order.
"""
import re, sys, os
from fontTools import agl

D = sys.argv[1] if len(sys.argv) > 1 else None
if not D:
    import matplotlib
    D = os.path.join(os.path.dirname(matplotlib.__file__), "mpl-data", "fonts", "pdfcorefonts")
D = D.rstrip("/") + "/"
fonts = ["Helvetica", "Helvetica-Bold", "Helvetica-Oblique", "Helvetica-BoldOblique", "Times-Roman", "Times-Bold", "Times-Italic", "Times-BoldItalic"]
out = ["<?php", "declare(strict_types=1);", "", "namespace Align\\Pdf;", "", "/**",
       " * Character widths (1/1000 em, WinAnsiEncoding bytes 0-255) and vertical metrics of the PDF core fonts used",
       " * for contracts. GENERATED from Adobe's Core 14 AFM files (see ADOBE-AFM-README.txt next to this file): only",
       " * the widths and metrics are taken, remapped from AdobeStandardEncoding glyph names to WinAnsiEncoding byte",
       " * order. This is a modified, derived form of the AFM data; the AFM files themselves are not included.",
       " * Regenerate with tools/pdf-metrics.py.", " */", "final class Metrics", "{", "    public const FONTS = ["]
for f in fonts:
    w, meta = {}, {}
    for line in open(D + f + ".afm", encoding="latin-1"):
        m = re.match(r"C (-?\d+) ; WX (\d+) ; N (\S+)", line)
        if m:
            w[m.group(3)] = int(m.group(2))
        for k in ("Ascender", "Descender", "CapHeight", "UnderlinePosition", "UnderlineThickness", "XHeight", "ItalicAngle"):
            if line.startswith(k + " "):
                meta[k] = float(line.split()[1])
        if line.startswith("FontBBox"):
            meta["FontBBox"] = [int(x) for x in line.split()[1:5]]
    arr = []
    for b in range(256):
        try:
            ch = bytes([b]).decode("cp1252")
        except UnicodeDecodeError:
            arr.append(w.get("space", 250)); continue
        if b < 32:
            arr.append(0); continue
        name = {" ": "space", "­": "hyphen"}.get(ch) or agl.UV2AGL.get(ord(ch))
        arr.append(w.get(name, w.get("space", 250)) if name else w.get("space", 250))
    out.append(f"        '{f}' => ['asc' => {int(meta.get('Ascender', 718))}, 'desc' => {int(meta.get('Descender', -207))}, 'cap' => {int(meta.get('CapHeight', 718))}, 'up' => {int(meta['UnderlinePosition'])}, 'ut' => {int(meta['UnderlineThickness'])}, 'angle' => {meta['ItalicAngle']}, 'bbox' => {meta['FontBBox']},")
    out.append("            'w' => [" + ", ".join(map(str, arr)) + "]],")
out += ["    ];", "}", ""]
open(os.path.join(os.path.dirname(__file__), "..", "src", "Pdf", "Metrics.php"), "w").write("\n".join(out))
print("wrote src/Pdf/Metrics.php")
