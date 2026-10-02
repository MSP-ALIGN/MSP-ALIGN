"""1.45.2: the Branding page in the 1.43 look, with a live preview of the app, the sign-in page and the client portal.
2.1.1: sign-in backgrounds, one for staff and one for the client portal."""
from lib import *
import re

admin = login("admin@example.com", "LongPassword123!")
before = {r["name"]: r["value"] for r in q("select name, value from settings where name in ('brand_name','brand_primary','brand_sidebar','brand_logo_only','brand_login_message')")}
t = admin.get(B + "/settings/branding").text
ok(not errs(t) and 'id="brand-preview"' in t and all(x in t for x in ['id="bp-tab-app"', 'id="bp-tab-login"', 'id="bp-tab-portal"', 'data-preview-theme="dark"']), "the page has the preview: app, sign-in page and client portal, light and dark")
ok('class="bp-header"' in t and 'bp-search' in t and "Top bar, buttons" not in t, "the preview is the current look (white header with search, not the old colored top bar)")
ok(t.count('name="brand_sidebar"') == 2 and 'type="radio"' in t, "the menu color is two picture choices")
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "save", "brand_name": "Acme vCIO", "company_name": "Example MSP Group",
    "brand_primary": "#2F7A55", "brand_sidebar": "light", "brand_login_message": "Welcome back, team"})
t = admin.get(B + "/settings/branding").text
ok("Branding saved" in flash(r.text) or "saved" in flash(r.text).lower(), "saved: " + flash(r.text))
ok('value="light" checked' in t and "--bp-color: #2f7a55" in t and 'data-swatch="#2f7a55"' in t and 'class="swatch is-active" style="background: #2f7a55"' in t, "the saved color and light menu show as chosen, in the preview too")
ok("Acme vCIO" in t and "Welcome back, team" in t, "the preview shows the name and the sign-in message")
d = admin.get(B + "/").text
ok('app-sidebar shadow sidebar-light' in d and "--align-brand:#2f7a55" in d, "the app uses them")
ok("Welcome back, team" in requests.get(B + "/login").text, "the sign-in page shows the message")
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "reset"})
t = admin.get(B + "/settings/branding").text
ok('value="dark" checked' in t and "--bp-color: #007bff" in t, "reset: back to the default blue and dark menu")
# ---- 2.1.1: sign-in backgrounds, one for staff and one for the client portal
import subprocess
def img(w, h, color, fmt="JPEG", noise=False):
    """A test image made with PHP's GD (which the app needs anyway), so the tests don't need Pillow."""
    php = f"""$i = imagecreatetruecolor({w}, {h}); imagefill($i, 0, 0, imagecolorallocate($i, {color[0]}, {color[1]}, {color[2]}));
        if ({'true' if noise else 'false'}) for ($y = 0; $y < {h}; $y++) for ($x = 0; $x < {w}; $x++) imagesetpixel($i, $x, $y, mt_rand(0, 0xFFFFFF));
        {'imagepng($i);' if fmt == 'PNG' else f'imagejpeg($i, null, {82 if noise else 95});'}"""
    return subprocess.run(["php", "-r", php], capture_output=True, check=True).stdout
up = lambda files, data=None: admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "save", **(data or {})}, files=files)
q("delete from settings where name in ('brand_bg_staff','brand_bg_portal','brand_bg_staff_dim','brand_bg_portal_dim')")
t = requests.get(B + "/login").text
ok(re.search(r'--login-bg: url\("/assets/login-staff\.jpg\?v=[^"]+"\); --login-dim: 0.25', t) and "has-login-bg" in t, "out of the box: the built-in staff background, darkened a little")
ok(re.search(r'--login-bg: url\("/assets/login-portal\.jpg\?v=[^"]+"\); --login-dim: 0;', requests.get(B + "/portal/login").text), "and the built-in portal one, not darkened")
ok(requests.get(B + "/assets/login-staff.jpg").headers.get("Content-Type", "").startswith("image/jpeg") and requests.get(B + "/assets/login-portal.jpg").status_code == 200, "both built-in images are there")
t = admin.get(B + "/settings/branding").text
ok(t.count("Built-in image") == 2 and 'value="plain_bg_staff"' in t and 'value="remove_bg_staff"' not in t, "Branding says they're the built-in images, with No image")
r = up({"bg_staff": ("office.jpg", img(1920, 1080, (20, 60, 120)), "image/jpeg")}, {"bg_staff_dim": "45"})
ok("saved" in flash(r.text).lower(), "staff background uploaded: " + flash(r.text))
t = requests.get(B + "/login").text
m = re.search(r'--login-bg: url\("(/branding/background/staff\?v=[a-f0-9]{8})"\); --login-dim: 0.45', t)
ok(m and "has-login-bg" in t, "the staff sign-in page shows it, darkened 45%")
ok("/branding/background/staff" not in requests.get(B + "/portal/login").text and "/assets/login-portal.jpg" in requests.get(B + "/portal/login").text, "the client portal sign-in doesn't: it keeps its own (built-in) one")
r = requests.get(B + m.group(1)) if m else None
ok(r is not None and r.status_code == 200 and r.headers.get("Content-Type") == "image/jpeg" and r.headers.get("X-Content-Type-Options") == "nosniff" and r.content[:2] == b"\xff\xd8",
   "served before sign-in, as a JPEG, with nosniff")
r = up({"bg_portal": ("calm.png", img(1600, 900, (230, 240, 235), "PNG"), "image/png")}, {"bg_portal_dim": "0"})
t = requests.get(B + "/portal/login").text
ok(re.search(r'--login-bg: url\("/branding/background/portal\?v=[a-f0-9]{8}"\); --login-dim: 0;', t), "the portal sign-in shows its own background (a PNG, stored as a JPEG), not darkened")
f = q("select value from settings where name = 'brand_bg_portal'")[0]["value"]
ok(re.fullmatch(r"bg-portal-[a-f0-9]{16}\.jpg", f or ""), "stored re-encoded as a JPEG: " + str(f))
ok("has-login-bg" in requests.get(B + "/portal/forgot").text, "the portal's other sign-in pages use it too")
big = img(2400, 1600, (0, 0, 0), noise=True)
r = up({"bg_staff": ("big.jpg", big, "image/jpeg")})
ok(len(big) > 2 * 1024 * 1024 and "saved" in flash(r.text).lower(), "a background over 2 MB is accepted (%.1f MB; the logo's 2 MB limit doesn't apply): %s" % (len(big) / 1048576, flash(r.text)))
r = up({"bg_staff": ("tiny.jpg", img(300, 200, (1, 2, 3)), "image/jpeg")})
ok("at least 640" in flash(r.text), "a tiny image is refused: " + flash(r.text))
r = up({"bg_staff": ("x.svg", b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', "image/svg+xml")})
ok("JPG, PNG or WebP" in flash(r.text) and m and m.group(1).split("?")[0] in requests.get(B + "/login").text, "an SVG is refused and the old background stays")
ok(requests.get(B + "/branding/background/other").status_code == 404 and requests.get(B + "/branding/background/..%2fconfig").status_code == 404, "only the two backgrounds can be fetched")
t = admin.get(B + "/settings/branding").text
ok(t.count("brand-bg-thumb") == 2 and 'value="remove_bg_staff"' in t and 'value="remove_bg_portal"' in t and "bp-login has-bg" in t, "Branding shows both, with Remove, and the preview uses the staff one")
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "remove_bg_staff"})
ok("built-in" in flash(r.text) and "/assets/login-staff.jpg" in requests.get(B + "/login").text and "/branding/background/portal" in requests.get(B + "/portal/login").text,
   "removing your staff image brings back the built-in one, and leaves the portal's: " + flash(r.text))
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "plain_bg_portal"})
ok("has-login-bg" not in requests.get(B + "/portal/login").text and q("select value from settings where name = 'brand_bg_portal'")[0]["value"] == "none",
   "No image: the plain portal page (the uploaded one is removed)")
t = admin.get(B + "/settings/branding").text
ok("Plain page" in t and 'value="default_bg_portal"' in t, "Branding offers the built-in image again")
admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "default_bg_portal"})
ok("/assets/login-portal.jpg" in requests.get(B + "/portal/login").text, "Use the built-in image: back")
admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "plain_bg_staff"})
ok("has-login-bg" not in requests.get(B + "/login").text, "the staff page can be plain too")
q("delete from settings where name in ('brand_bg_staff','brand_bg_portal')")
viewer = login("viewer@example.com", "ViewerPassword123!")
ok(viewer.get(B + "/settings/branding").status_code == 403, "admins only")
q("delete from settings where name in ('brand_name','brand_primary','brand_sidebar','brand_logo_only','brand_login_message','brand_bg_staff_dim','brand_bg_portal_dim')")
for k, v in before.items():
    q("insert into settings (name, value, is_secret) values (%s, %s, 0)", k, v)
done()
