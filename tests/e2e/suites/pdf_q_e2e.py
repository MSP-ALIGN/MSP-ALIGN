"""2.2.1: PDF engine (src/Pdf, src/Contracts/PdfStamp) robustness. Checks that a page whose /Contents array repeats
the same uncompressed stream can't expand past the file's size and exhaust memory, plus a couple of save-depth
(balance) sanity checks. These call PHP directly (no server, no DB writes)."""
from lib import *
import os, struct

WORKDIR = WORK + "/pdf_q"
os.makedirs(WORKDIR, exist_ok=True)


def build(objs, root=1, size=None):
    """A minimal PDF from {num: bytes} with a classic xref table."""
    out = b"%PDF-1.4\n"
    off = {}
    for k in sorted(objs):
        off[k] = len(out)
        out += b"%d 0 obj\n" % k + objs[k] + b"\nendobj\n"
    x = len(out)
    n = (size or max(objs) + 1)
    out += b"xref\n0 %d\n0000000000 65535 f \n" % n
    for k in range(1, n):
        out += (b"%010d 00000 n \n" % off[k]) if k in off else b"0000000000 00000 f \n"
    out += b"trailer\n<< /Size %d /Root %d 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (n, root, x)
    return out


def write_pdf(name, data):
    p = WORKDIR + "/" + name
    open(p, "wb").write(data)
    return p


def page_content(path, mem_mb=512):
    """Returns ('ok', bytes_len) or ('throw', class) or ('fatal', text). Runs in its own PHP with a memory cap, so
    an unbounded expansion fails as a fatal (which the test treats as a FAIL) instead of hanging the runner."""
    code = (
        'ini_set("memory_limit", "%dM");' % mem_mb
        + '$d = new Align\\Pdf\\PdfDoc(file_get_contents("%s"));' % path
        + 'try { $c = $d->pageContent($d->pages()[0]["dict"]); echo "ok " . strlen((string)$c); }'
        + ' catch (\\InvalidArgumentException $e) { echo "throw"; }'
    )
    r = php(code)
    s = (r.stdout or "").strip()
    if s.startswith("ok "):
        return ("ok", int(s.split()[1]))
    if s == "throw":
        return ("throw", None)
    return ("fatal", (r.stdout + r.stderr)[:200])


# ---- A /Contents array that repeats one small uncompressed stream expands far past the file -----------------
# ~1 KB stream listed 60,000 times would decode to ~60 MB (> the 50 MB per-page cap) from a ~0.3 MB file.
stream = b"q Q\n" + b" " * 1020
refs = b" ".join([b"4 0 R"] * 60000)
bomb = build({
    1: b"<< /Type /Catalog /Pages 2 0 R >>",
    2: b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    3: b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents [" + refs + b"] >>",
    4: b"<< /Length %d >>\nstream\n" % len(stream) + stream + b"\nendstream",
})
p = write_pdf("amplify.pdf", bomb)
res = page_content(p, mem_mb=256)
ok(len(bomb) < 2 * 1024 * 1024, "the amplification PDF is itself under 2 MB (%d KB)" % (len(bomb) // 1024))
ok(res[0] == "throw", "a page whose /Contents repeats an uncompressed stream is refused, not expanded to exhaust memory (%s)" % (res,))

# A /Contents array with a sane number of refs still reads (the cap doesn't over-reject normal multi-stream pages).
ok_refs = b" ".join([b"4 0 R"] * 50)
good = build({
    1: b"<< /Type /Catalog /Pages 2 0 R >>",
    2: b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    3: b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents [" + ok_refs + b"] >>",
    4: b"<< /Length %d >>\nstream\n" % len(stream) + stream + b"\nendstream",
})
res2 = page_content(write_pdf("good.pdf", good), mem_mb=256)
ok(res2[0] == "ok" and res2[1] > 0, "a page with a handful of content streams still reads (%s)" % (res2,))

# ---- save-depth (balance) counts q/Q only as operators --------------------------------------------------------
def php_str(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def balance(s):
    r = php('echo json_encode(Align\\Contracts\\PdfStamp::balance(%s));' % php_str(s))
    return (r.stdout or r.stderr).strip()


ok(balance("q q Q Q") == "[0,0]", "balanced q/Q give [0,0]")
ok(balance("Q Q q 0 0 10 10 re W n") == "[-2,-1]", "leading extra Q are counted (so isolate pads for them)")
ok(balance("(q q q) Tj % q q q") == "[0,0]", "q inside a string or after a comment is not a save")
ok(balance("q 0.01 0 0 0.01 0 0 cm [ Q ] 0 Tz") == "[0,1]", "a Q inside an array isn't a restore: the page's scale stays open and isolate() closes it")
ok(balance("q 0 0 1 1 re W n /Span << /ActualText Q >> BDC EMC") == "[0,1]", "a Q inside a dictionary isn't a restore either (its 1x1 clip stays open and gets closed)")


# ---- ASCII85 after Flate: "z" turns one byte into four, so 13 MB of z's would decode to 52 MB (> the 50 MB cap)
import zlib
zs = zlib.compress(b"z" * (13 * 1024 * 1024) + b"~>", 9)
a85 = build({
    1: b"<< /Type /Catalog /Pages 2 0 R >>",
    2: b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
    3: b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>",
    4: b"<< /Length %d /Filter [/FlateDecode /ASCII85Decode] >>\nstream\n" % len(zs) + zs + b"\nendstream",
})
res = page_content(write_pdf("a85.pdf", a85), mem_mb=256)
ok(res[0] == "throw", "Flate then ASCII85 \"z\" expansion past the size limit is refused, not decoded: %s" % (res,))

done()
