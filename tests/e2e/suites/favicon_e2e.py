"""2.5.1 Browser icon: Settings → Branding takes a small square image of its own for browser tabs and bookmarks on every
page (staff, the client portal, sign-in, public pages, printed reports). Without one the tab shows the built-in MSP
Align mark, even when logos are uploaded. Re-encoded and scaled down to 256 pixels; a wide image and SVG are refused;
removing it brings the built-in mark back; admins only; uploads and removals are audited."""
from lib import *
import re, subprocess, struct

def img(w, h, rgb):
    """A PNG made with PHP's GD (the app needs it anyway)."""
    return subprocess.run(["php", "-r", f"$i = imagecreatetruecolor({w}, {h}); imagefill($i, 0, 0, imagecolorallocate($i, {rgb[0]}, {rgb[1]}, {rgb[2]})); imagepng($i);"],
                          capture_output=True).stdout

def png_size(b): return struct.unpack(">II", b[16:24]) if b[:8] == b"\x89PNG\r\n\x1a\n" else None

admin = login("admin@example.com", "LongPassword123!")
def up(files, extra=None):
    d = {"_csrf": csrf(admin, "/settings/branding"), "action": "save", "brand_name": "", "company_name": "", "brand_primary": "#1b68b8", "brand_sidebar": "dark"}
    d.update(extra or {})
    return admin.post(B + "/settings/branding", data=d, files=files)
def setting(n): return (q("select value from settings where name = %s", n) or [{"value": None}])[0]["value"]
def icon_href(t): m = re.search(r'<link rel="icon" href="([^"]+)"', t); return m.group(1) if m else None

KEYS = ("brand_favicon", "brand_logo", "brand_logo_light", "brand_name", "brand_primary", "brand_sidebar", "brand_logo_only")
saved = {k: setting(k) for k in KEYS}   # put back at the end (up() saves the name and colors too)
for k in ("brand_favicon", "brand_logo", "brand_logo_light"):
    q("delete from settings where name = %s", k)

# ---- the default: the built-in mark everywhere, even with a logo uploaded
t = admin.get(B + "/settings/branding").text
ok('name="favicon"' in t and "Browser icon" in t and "Empty: showing the MSP Align icon." in t, "Branding has a Browser icon field, empty")
up({"logo_light": ("navy.png", img(240, 60, (15, 42, 79)), "image/png")})
ok(setting("brand_logo_light"), "a light mode logo is uploaded")
ok((icon_href(admin.get(B + "/").text) or "").startswith("/assets/icon.png?v="), "with a logo but no browser icon, the tab shows the built-in mark")
ok(requests.get(B + "/branding/favicon", allow_redirects=False).headers.get("Location") == "/assets/icon.png", "/branding/favicon without an upload sends the built-in mark")

# ---- refused uploads
r = up({"favicon": ("wide.png", img(256, 64, (200, 30, 30)), "image/png")})
ok("square" in flash(r.text) and setting("brand_favicon") is None, "a wide image is refused: " + flash(r.text))
r = up({"favicon": ("x.svg", b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', "image/svg+xml")})
ok("SVG is not accepted" in flash(r.text) and setting("brand_favicon") is None, "SVG is refused")

# ---- a square upload: re-encoded, scaled down, served before sign-in, used on every kind of page
r = up({"favicon": ("big.png", img(600, 600, (11, 125, 136)), "image/png")})
f = setting("brand_favicon")
ok("saved" in flash(r.text).lower() and re.fullmatch(r"favicon-[a-f0-9]{16}\.png", f or ""), f"a square image is stored under its own name ({f})")
g = requests.get(B + "/branding/favicon")
ok(g.status_code == 200 and g.headers.get("Content-Type") == "image/png" and g.headers.get("X-Content-Type-Options") == "nosniff" and png_size(g.content) == (256, 256),
   f"served before sign-in as a PNG with nosniff, scaled to 256 × 256 ({png_size(g.content)})")
ok(q("select count(*) n from audit_log where action = 'branding.favicon_uploaded'")[0]["n"] >= 1, "the upload is audited")
pages = {"staff": admin.get(B + "/").text, "sign-in": requests.get(B + "/login").text, "portal sign-in": requests.get(B + "/portal/login").text,
         "terms": requests.get(B + "/terms").text, "report": admin.get(B + "/clients/1/report/assets").text}
bad = [k for k, t in pages.items() if not (icon_href(t) or "").startswith("/branding/favicon?v=" + (f or "")[8:16])]
ok(not bad, "staff, sign-in, portal, public and report pages all use it (cache-busted): " + ", ".join(bad))
ok("/branding/logo-light?v=" in pages["portal sign-in"], "the logos stay where they were")
ok(requests.get(B + "/favicon.ico").content == g.content, "/favicon.ico serves it too (pages without a <link rel=icon>)")

# ---- admins only
v = login("viewer@example.com", "ViewerPassword123!")
ok(v.post(B + "/settings/branding", data={"_csrf": csrf(v, "/clients"), "action": "remove_favicon"}).status_code == 403 and setting("brand_favicon") == f, "a viewer can't remove it")

# ---- remove: the built-in mark comes back, the file goes
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "remove_favicon"})
ok(setting("brand_favicon") is None and "MSP Align icon again" in flash(r.text), "removing it: " + flash(r.text))
ok((icon_href(admin.get(B + "/").text) or "").startswith("/assets/icon.png?v="), "the tab shows the built-in mark again")
ok(q("select count(*) n from audit_log where action = 'branding.favicon_removed'")[0]["n"] >= 1, "the removal is audited")
ok(php(f'echo is_file(Align\\Branding::uploadDir() . "/{f}") ? "yes" : "no";').stdout == "no", "the old file is deleted")
ok(requests.get(B + "/branding/favicon", allow_redirects=False).status_code == 302, "/branding/favicon sends the built-in mark again")

for k, val in saved.items():
    q("delete from settings where name = %s", k)
    if val is not None:
        q("insert into settings (name, value, is_secret) values (%s, %s, 0)", k, val)
done()
