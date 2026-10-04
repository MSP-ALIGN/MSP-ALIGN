"""2.2.1 review (uploads, images and documents): a regression check for each fix, plus checks of the upload and
sanitizer defences next to them. Test data is made up (Example names, .example domains) and removed at the end."""
from lib import *
import os, glob, json, base64, struct, zlib, subprocess

PHPB = 'require "' + BOOTSTRAP + '"; '


def phpx(code, timeout=20):
    """PHP with every warning printed to stderr: (stdout, stderr), or (None, "timeout") if it doesn't finish."""
    try:
        r = subprocess.run(["php", "-d", "display_errors=stderr", "-d", "error_reporting=-1", "-d", "log_errors=0", "-r", PHPB + code],
                           env=ENV, capture_output=True, text=True, timeout=timeout)
        return r.stdout, r.stderr
    except subprocess.TimeoutExpired:
        return None, "timeout"


def b64(s): return base64.b64encode(s.encode() if isinstance(s, str) else s).decode()


def png(w, h, extra=b""):
    raw = b"".join(b"\x00" + b"\x10\x80\xc0\xff" * w for _ in range(h))
    ch = lambda t, d: struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + ch(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 6, 0, 0, 0)) + ch(b"tEXt", b"Comment\x00SECRET-IN-METADATA") + ch(b"IDAT", zlib.compress(raw)) + ch(b"IEND", b"") + extra


def png_bomb(w, h):
    """A valid PNG header claiming w x h pixels, with almost no data: decoding it would allocate w*h*4 bytes."""
    ch = lambda t, d: struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + ch(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 6, 0, 0, 0)) + ch(b"IDAT", zlib.compress(b"\x00" * 64)) + ch(b"IEND", b"")


def jpeg_with_comment(w, h):
    """A JPEG made with GD, with a comment segment (where cameras put details) and bytes after the image."""
    out = subprocess.run(["php", "-r", f"$i = imagecreatetruecolor({w}, {h}); imagefill($i, 0, 0, imagecolorallocate($i, 30, 90, 160)); imagejpeg($i, null, 90);"],
                         capture_output=True, check=True).stdout
    com = b"SECRET-CAMERA-GPS"
    return out[:2] + b"\xff\xfe" + struct.pack(">H", len(com) + 2) + com + out[2:] + b"APPENDED-SECRET"


def store(data, prefix="uqtest0", square=False):
    """Images::store on a temporary file, as an upload would reach it. Returns ([error, name], stderr); removes the file."""
    code = (f"$t = tempnam(sys_get_temp_dir(), 'uq'); file_put_contents($t, base64_decode('{b64(data)}'));"
            f"$r = Align\\Images::store(['error' => UPLOAD_ERR_OK, 'size' => filesize($t), 'tmp_name' => $t, 'name' => 'x'], 'avatars', '{prefix}', 256, 256, {'true' if square else 'false'});"
            "$p = $r[1] ? Align\\Images::path('avatars', $r[1]) : null; $bytes = $p ? base64_encode((string) file_get_contents($p)) : '';"
            "if ($p) unlink($p); unlink($t); echo json_encode([$r, $bytes]);")
    out, err = phpx(code)
    try:
        r, data = json.loads(out)
        return r, base64.b64decode(data), err
    except Exception:
        return [None, None], b"", err + (out or "")


admin = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")

# ---- Html::clean: a namespaced attribute used to make the attribute loop spin forever (2.2.1)
hang = ('<a xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="javascript:alert(1)">x</a>'
        '<p xmlns:ev="urn:example" ev:onclick="alert(1)" class="ql-align-center">y</p>')
out, err = phpx(f"echo Align\\Docs\\Html::clean(base64_decode('{b64(hang)}'));", timeout=15)
sanitizer_ok = out is not None and "javascript" not in out and "onclick" not in out and "<a>x</a>" in out and '<p class="ql-align-center">y</p>' in out
ok(sanitizer_ok, "the sanitizer finishes on namespaced attributes (xmlns:x + x:href) and drops them: " + repr(out if out is not None else err)[:160])

# ---- Html::clean: the bypasses it must keep refusing (each output may keep only the harmless part)
vectors = {
    "entity-encoded scheme": '<a href="&#106;avascript:alert(1)">x</a>',
    "tab inside the scheme": '<a href="java&Tab;script:alert(1)">x</a>',
    "control byte first": '<a href="&#x01;javascript:alert(1)">x</a>',
    "data: URL": '<a href="data:text/html,<script>alert(1)</script>">x</a>',
    "protocol-relative": '<a href="//evil.example/">x</a>',
    "backslash path": '<a href="/\\evil.example">x</a>',
    "event handler": '<p onmouseover="alert(1)">x</p>',
    "img onerror": '<img src=x onerror=alert(1)>',
    "svg script": '<svg><script>alert(1)</script></svg>',
    "math mXSS": '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
    "noscript mXSS": '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
    "template breakout": '</template><img src=x onerror=alert(1)>',
    "root breakout": '</div><img src=x onerror=alert(1)><div id="__root"><script>alert(1)</script>',
    "css url": '<p style="color:red;background:url(https://evil.example/x)">x</p>',
    "css expression": '<p style="color: expression(alert(1))">x</p>',
    "prefixed element": '<x:script>alert(1)</x:script><a:a href="javascript:alert(1)">y</a:a>',
    "comment": '<!--><img src=x onerror=alert(1)>-->',
    "id clobbering": '<p id="doc-app" name="csrf">x</p>',
}
code = (f"foreach (json_decode(base64_decode('{b64(json.dumps(vectors))}'), true) as $k => $h) $o[$k] = Align\\Docs\\Html::clean($h); echo json_encode($o);")
out, err = phpx(code, timeout=30)
res = json.loads(out) if out else {}
bad = [k for k, v in res.items() if any(x in v.lower() for x in ["javascript", "onerror", "onmouseover", "<img", "<script", "<svg", "<math", "url(", "expression", "evil.example", "data:", " id=", "name="])]
ok(res and len(res) == len(vectors) and not bad, "the sanitizer refuses every bypass attempt: " + (", ".join(bad) or "none got through"))
out, _ = phpx("echo Align\\Docs\\Html::clean(base64_decode('" + b64('<h2>Plan</h2><p class="ql-align-center"><a href="https://ok.example/a">link</a> <span style="color: #e60000">red</span></p><ol><li data-list="bullet">one</li></ol>') + "'));")
ok(out and '<a href="https://ok.example/a" target="_blank" rel="noopener noreferrer">link</a>' in out and 'style="color: #e60000"' in out and 'data-list="bullet"' in out and "<h2>Plan</h2>" in out,
   "...while normal editor output is kept")

# ---- Images::store: no PHP warning on every upload (an undefined $jpeg, 2.2.1), and only pixels are kept
r, data, err = store(png(40, 30, b"APPENDED-SECRET"))
ok(r[0] is None and r[1] and r[1].endswith(".png") and "Undefined variable" not in err and "Warning" not in err, "a PNG upload is stored as PNG with no warning: " + repr(r) + " " + err.strip()[:120])
ok(data.startswith(b"\x89PNG") and b"SECRET" not in data, "...re-encoded: the text chunk and the bytes after the image are gone")
r, data, err = store(jpeg_with_comment(64, 48), square=True)
ok(r[0] is None and r[1] and r[1].endswith(".jpg") and data[:2] == b"\xff\xd8" and b"SECRET" not in data and "Warning" not in err, "a JPEG stays a JPEG, without its comment or appended bytes")
r, _, _ = store(png_bomb(9000, 9000))
ok(r[0] and "8000" in r[0] and not r[1], "a 9000 x 9000 PNG of a few bytes is refused before GD decodes it: " + str(r[0]))
r, _, _ = store(png_bomb(7000, 7000))
ok(r[0] and "8000" in r[0] and not r[1], "so is 7000 x 7000 (over 40 million pixels)")
r, _, _ = store(b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')
ok(r[0] and "SVG" in r[0] and not r[1], "an SVG is refused")
r, _, _ = store(b"GIF89a" + b"<script>alert(1)</script>" * 4)
ok(r[0] and not r[1], "a fake GIF (the header and a script) is refused")

# ---- Images::path: a stored name must end the pattern exactly (no trailing newline) (2.2.1)
out, err = phpx("$d = Align\\Images::dir('avatars'); @mkdir($d, 0750, true); $n = \"uqtest-0123456789abcdef.png\\n\"; touch(\"$d/$n\");"
                "$p = Align\\Images::path('avatars', $n); unlink(\"$d/$n\"); var_dump($p);")
ok(out is not None and out.strip() == "NULL", "a stored image name with a trailing newline isn't accepted: " + repr(out))

# ---- profile pictures over HTTP (viewer), then served only with safe headers
vid = q("select id, avatar_file from users where email='viewer@example.com'")[0]
old_pic = UPLOADS + "/avatars/" + (vid["avatar_file"] or "-")
old_bytes = open(old_pic, "rb").read() if os.path.isfile(old_pic) else None  # an upload deletes the old picture: put it back after
import atexit
def _put_back_avatar():  # also when a check raises before the end
    q("update users set avatar_file=%s where id=%s", vid["avatar_file"], vid["id"])
    if old_bytes is not None and not os.path.isfile(old_pic):
        open(old_pic, "wb").write(old_bytes)
atexit.register(_put_back_avatar)
r = viewer.post(B + "/account/avatar", data={"_csrf": csrf(viewer, "/account")}, files={"avatar": ("me.png", png(300, 200), "image/png")})
f = q("select avatar_file from users where id=%s", vid["id"])[0]["avatar_file"]
ok(f and f != vid["avatar_file"] and re.fullmatch(r"user%d-[a-f0-9]{16}\.png" % vid["id"], f) and not errs(r.text), "a viewer can set their own picture (random name): " + str(f))
a = viewer.get(B + f"/users/{vid['id']}/avatar")
ok(a.status_code == 200 and a.headers.get("Content-Type") == "image/png" and a.headers.get("X-Content-Type-Options") == "nosniff"
   and "sandbox" in a.headers.get("Content-Security-Policy", "") and "private" in a.headers.get("Cache-Control", ""), "served as image/png, nosniff, sandbox CSP, private cache")
ok(requests.get(B + f"/users/{vid['id']}/avatar", allow_redirects=False).status_code in (302, 303), "not without signing in")
r = viewer.post(B + "/account/avatar", data={"_csrf": csrf(viewer, "/account")}, files={"avatar": ("x.svg", b'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', "image/png")})
ok("SVG" in flash(r.text) and q("select avatar_file from users where id=%s", vid["id"])[0]["avatar_file"] == f, "an SVG sent as image/png is refused, and the picture stays")
viewer.post(B + "/account/avatar", data={"_csrf": csrf(viewer, "/account"), "action": "remove"})
ok(not q("select avatar_file from users where id=%s", vid["id"])[0]["avatar_file"] and not os.path.exists(UPLOADS + "/avatars/" + f), "removing deletes the file")
q("update users set avatar_file=%s where id=%s", vid["avatar_file"], vid["id"])
if old_bytes is not None:
    open(old_pic, "wb").write(old_bytes)

# ---- client logos: removing one is audited like uploading one (2.2.1)
q("delete from clients where name='UQ Example Logo Co'")
r = tech.post(B + "/clients", data={"_csrf": csrf(tech, "/clients"), "name": "UQ Example Logo Co"}, files={"logo": ("logo.png", png(120, 60, b"APPENDED-SECRET"), "image/png")})
c = q("select id, logo_file from clients where name='UQ Example Logo Co'")
cid = c[0]["id"] if c else 0
lf = c[0]["logo_file"] if c else None
ok(lf and re.fullmatch(r"client%d-[a-f0-9]{16}\.png" % cid, lf), "client added with a logo under a random name: " + str(lf))
g = tech.get(B + f"/clients/{cid}/logo")
ok(g.status_code == 200 and g.content.startswith(b"\x89PNG") and b"SECRET" not in g.content and g.headers.get("X-Content-Type-Options") == "nosniff", "the logo is served re-encoded, with nosniff")
n = len(q("select id from audit_log where action='client.logo_removed' and detail like %s", f"%#{cid}"))
tech.post(B + f"/clients/{cid}", data={"_csrf": csrf(tech, f"/clients/{cid}"), "name": "UQ Example Logo Co", "remove_logo": "1"})
ok(not q("select logo_file from clients where id=%s", cid)[0]["logo_file"] and lf and not os.path.exists(UPLOADS + "/clients/" + lf), "Remove logo clears it and deletes the file")
ok(len(q("select id from audit_log where action='client.logo_removed' and detail like %s", f"%#{cid}")) == n + 1, "...and is in the audit log")
q("delete from clients where id=%s", cid)

# ---- documents: autosave checks the review date and the body's type (2.2.1)
tech.post(B + "/documents", data={"_csrf": csrf(tech, "/documents"), "client_id": "", "template_id": "0", "title": "UQ Example policy", "category": "policy"})
d = q("select id, version, body_html from documents where title='UQ Example policy' order by id desc limit 1")
did = d[0]["id"] if d else 0
tok = csrf(tech, "/documents")
def save(**data):
    v = q("select version from documents where id=%s", did)[0]["version"]
    return tech.post(B + f"/documents/{did}/save", data={"_csrf": tok, "base_version": str(v), **data})
r = save(body="<p>First text</p>")
ok(r.status_code == 200 and q("select body_html from documents where id=%s", did)[0]["body_html"] == "<p>First text</p>", "autosave works")
r = save(review_due="2026-02-30")
ok(r.status_code == 200 and q("select review_due from documents where id=%s", did)[0]["review_due"] is None, "a review date that isn't a real day (2026-02-30) is cleared, not a server error: %d" % r.status_code)
r = save(review_due="2028-02-29")
ok(r.status_code == 200 and str(q("select review_due from documents where id=%s", did)[0]["review_due"]) == "2028-02-29", "a real one (a leap day) is saved")
r = tech.post(B + f"/documents/{did}/save", data={"_csrf": tok, "base_version": str(q("select version from documents where id=%s", did)[0]["version"]), "body[]": "x"})
ok(r.status_code == 200 and q("select body_html from documents where id=%s", did)[0]["body_html"] == "<p>First text</p>", "body[]=… is ignored (it used to save the word Array)")
if sanitizer_ok:  # on the old code this request would hang the test server, so only after the check above passed
    r = save(body=hang)
    b = q("select body_html from documents where id=%s", did)[0]["body_html"]
    ok(r.status_code == 200 and "javascript" not in b and "onclick" not in b and "<a>x</a>" in b, "the namespaced-attribute body saves, cleaned")
r = save(body='<p onclick="alert(1)">ok<script>alert(1)</script></p>', base_version="0")
ok(r.status_code == 409 and r.json().get("conflict"), "an old base version is a conflict, not an overwrite")
r = viewer.post(B + f"/documents/{did}/save", data={"_csrf": csrf(viewer, "/documents"), "base_version": "1", "body": "<p>viewer</p>"})
ok(r.status_code == 403 and "viewer" not in (q("select body_html from documents where id=%s", did)[0]["body_html"] or ""), "a viewer can't save")
v = q("select id from document_versions where document_id=%s order by id limit 1", did)[0]["id"]
r = viewer.post(B + f"/documents/{did}/versions/{v}/restore", data={"_csrf": csrf(viewer, "/documents")})
ok(r.status_code == 403, "...or restore a version")
r = tech.post(B + f"/documents/{did}/versions/{v}/restore", data={"_csrf": tok})
kinds = [x["kind"] for x in q("select kind from document_versions where document_id=%s order by id", did)]
ok(kinds[-2:] == ["auto", "restore"] and q("select body_html from documents where id=%s", did)[0]["body_html"] in ("", None), "restoring keeps the current text in the history first: " + str(kinds))
q("delete from documents where id=%s", did)

# ---- templates: a non-string body keeps the template's text (2.2.1)
admin.post(B + "/documents/templates", data={"_csrf": csrf(admin, "/documents/templates"), "name": "UQ Example template", "category": "policy"})
t = q("select id, body_html from document_templates where name='UQ Example template' order by id desc limit 1")
tid = t[0]["id"] if t else 0
before = t[0]["body_html"] if t else None
admin.post(B + f"/documents/templates/{tid}", data={"_csrf": csrf(admin, f"/documents/templates/{tid}"), "action": "save", "name": "UQ Example template", "body[]": "x"})
ok(before and q("select body_html from document_templates where id=%s", tid)[0]["body_html"] == before, "body[]=… leaves the template's text alone (it used to become the word Array)")
admin.post(B + f"/documents/templates/{tid}", data={"_csrf": csrf(admin, f"/documents/templates/{tid}"), "action": "save", "name": "UQ Example template",
           "body": '<p onclick="alert(1)">Hello {{client_name}}</p><script>alert(1)</script>'})
ok(q("select body_html from document_templates where id=%s", tid)[0]["body_html"] == "<p>Hello {{client_name}}</p>", "a template body is cleaned like a document's")
r = tech.post(B + f"/documents/templates/{tid}", data={"_csrf": csrf(tech, "/documents"), "action": "delete"})
ok(r.status_code == 403 and q("select id from document_templates where id=%s", tid), "a tech can't change or delete templates")
q("delete from document_templates where id=%s", tid)

for p in glob.glob(UPLOADS + "/avatars/uqtest*"):
    os.unlink(p)
done()
