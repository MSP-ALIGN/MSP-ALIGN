"""2.2.4: two logos. The app logo goes in the menu (and the staff sign-in in dark mode); the report logo goes on white
pages: printed reports, contracts and their PDFs, emails, the client portal, onboarding pages, the light sign-in page
and the browser tab. Either one stands in for the other while only one is uploaded."""
from lib import *
import re, subprocess, tempfile, os

def img(w, h, rgb):
    """A PNG made with PHP's GD (the app needs it anyway)."""
    return subprocess.run(["php", "-r", f"$i = imagecreatetruecolor({w}, {h}); imagefill($i, 0, 0, imagecolorallocate($i, {rgb[0]}, {rgb[1]}, {rgb[2]})); imagepng($i);"],
                          capture_output=True).stdout

admin = login("admin@example.com", "LongPassword123!")
def up(files, extra=None):
    d = {"_csrf": csrf(admin, "/settings/branding"), "action": "save", "brand_name": "", "company_name": "", "brand_primary": "#1b68b8", "brand_sidebar": "dark"}
    d.update(extra or {})
    return admin.post(B + "/settings/branding", data=d, files=files)
def setting(n): return (q("select value from settings where name = %s", n) or [{"value": None}])[0]["value"]
def srcs(t, cls): return re.findall(r'<img src="([^"]+)"[^>]*class="[^"]*' + cls, t)

for k in ("brand_logo", "brand_logo_report", "brand_sidebar", "brand_logo_only"):
    q("delete from settings where name = %s", k)

# ---- the form
t = admin.get(B + "/settings/branding").text
ok('name="logo"' in t and 'name="report_logo"' in t and "Report logo" in t and 'brand-logo-drop is-dark' in t and 'brand-logo-drop is-light' in t,
   "Branding has two logo slots: the app logo on dark, the report logo on white")
ok('data-has-app="0" data-has-report="0"' in t and "Empty: showing the default icon." in t, "with neither uploaded, the default icon is used")

# ---- upload both
r = up({"logo": ("white.png", img(240, 60, (255, 255, 255)), "image/png"), "report_logo": ("navy.png", img(240, 60, (15, 42, 79)), "image/png")})
ok("saved" in flash(r.text).lower(), "both uploaded: " + flash(r.text))
app_f, rep_f = setting("brand_logo"), setting("brand_logo_report")
ok(re.fullmatch(r"logo-[a-f0-9]{16}\.png", app_f or "") and re.fullmatch(r"rlogo-[a-f0-9]{16}\.png", rep_f or ""), f"stored under their own names: {app_f}, {rep_f}")
a, rr = requests.get(B + "/branding/logo"), requests.get(B + "/branding/report-logo")
ok(a.status_code == 200 and rr.status_code == 200 and a.content != rr.content and rr.headers.get("Content-Type") == "image/png" and rr.headers.get("X-Content-Type-Options") == "nosniff",
   "each is served on its own address before sign-in, as a PNG with nosniff")
ok(q("select count(*) n from audit_log where action = 'branding.report_logo_uploaded'")[0]["n"] >= 1, "the report logo upload is audited")

# ---- where each one goes
d = admin.get(B + "/").text
ok(srcs(d, "brand-image") and srcs(d, "brand-image")[0].startswith("/branding/logo?v="), "the dark menu shows the app logo")
ok(re.search(r'<link rel="icon" href="/branding/report-logo\?v=', d), "the browser tab shows the report logo")
up({}, {"brand_sidebar": "light"})
ok(srcs(admin.get(B + "/").text, "brand-image")[0].startswith("/branding/report-logo?v="), "a light menu shows the report logo")
up({}, {"brand_sidebar": "dark"})
lg = requests.get(B + "/login").text
ok(re.search(r'src="/branding/report-logo\?v=[^"]+"[^>]*class="login-logo-img[^"]*on-light', lg) and re.search(r'src="/branding/logo\?v=[^"]+"[^>]*class="login-logo-img[^"]*on-dark', lg),
   "the staff sign-in has both: the report logo in light mode, the app logo in dark mode")
pl = requests.get(B + "/portal/login").text
ok("/branding/report-logo?v=" in pl and "/branding/logo?v=" not in pl, "the client portal sign-in (always light) shows only the report logo")
rp = admin.get(B + "/clients/1/report/assets").text
ok(re.search(r'<img src="/branding/report-logo\?v=[^"]+"[^>]*class="provider-logo"', rp), "printed reports show the report logo")
ok("/branding/report-logo?v=" in requests.get(B + "/terms").text, "the public pages (terms) show the report logo")
out = php('echo basename((string) (Align\\Mail\\Template::logo()["path"] ?? "")), "|", basename((string) Align\\Branding::lightLogoFile());').stdout
ok(out == f"{rep_f}|{rep_f}", "emails and contract PDFs use the report logo file: " + out)

# ---- either stands in for the other
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "remove_report_logo"})
ok(setting("brand_logo_report") is None and "Reports use the app logo again" in flash(r.text), "removing the report logo: " + flash(r.text))
ok(re.search(r'<img src="/branding/logo\?v=[^"]+"[^>]*class="provider-logo"', admin.get(B + "/clients/1/report/assets").text), "reports fall back to the app logo")
ok(not os.path.exists(os.path.join(php('echo Align\\Branding::uploadDir();').stdout, rep_f)), "the removed file is deleted")
up({"report_logo": ("navy.png", img(240, 60, (15, 42, 79)), "image/png")})
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "remove_logo"})
ok("report logo is used everywhere" in flash(r.text), "removing the app logo: " + flash(r.text))
ok(srcs(admin.get(B + "/").text, "brand-image")[0].startswith("/branding/report-logo?v=") and "is-builtin" not in admin.get(B + "/").text,
   "the menu then shows the report logo (not the built-in mark)")
lg = requests.get(B + "/login").text
ok("/branding/report-logo?v=" in lg and "on-dark" not in lg.split('class="login-logo"')[1].split("</div>")[0], "and the sign-in shows just that one logo")
ok(requests.get(B + "/branding/logo", allow_redirects=False).headers.get("Location") == "/assets/icon.png", "the app logo address goes to the built-in mark when there's none")

# ---- checks on the upload
r = up({"report_logo": ("x.svg", b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', "image/svg+xml")})
ok("Report logo:" in flash(r.text) and "SVG" in flash(r.text), "an SVG report logo is refused, saying which logo: " + flash(r.text))
v = login("viewer@example.com", "ViewerPassword123!")
ok(v.post(B + "/settings/branding", data={"_csrf": csrf(v, "/clients"), "action": "remove_report_logo"}).status_code == 403 and setting("brand_logo_report"),
   "viewers can't remove it")

# ---- in the browser: the preview follows a new file in each slot
from playwright.sync_api import sync_playwright
with sync_playwright() as pw:
    br = pw.chromium.launch(); pg = br.new_page(viewport={"width": 1360, "height": 900})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
    pg.goto(B + "/settings/branding")
    tmp = tempfile.NamedTemporaryFile(suffix=".png", delete=False); tmp.write(img(200, 50, (250, 250, 250))); tmp.close()
    pg.set_input_files("#logo", tmp.name); pg.wait_for_timeout(200)
    ok(pg.get_attribute("#logo-img", "src").startswith("blob:") and pg.get_attribute("#brand-preview [data-logo='menu']", "src").startswith("blob:")
       and pg.get_attribute("#brand-preview [data-logo='light']", "src").startswith("/branding/report-logo"),
       "a new app logo shows in its slot and the dark menu; the light sign-in keeps the report logo")
    pg.check("input[name=brand_sidebar][value=light]", force=True); pg.wait_for_timeout(100)
    ok(pg.get_attribute("#brand-preview [data-logo='menu']", "src").startswith("/branding/report-logo"), "switching to a light menu shows the report logo there")
    ok(not errors, "no script errors: " + str(errors[:2]))
    br.close()
    os.unlink(tmp.name)

for k in ("brand_logo", "brand_logo_report", "brand_sidebar"):
    q("delete from settings where name = %s", k)
done()
