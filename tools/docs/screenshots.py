#!/usr/bin/env python3
"""Screenshots for the docs site (docs/screenshots/), taken from the test environment's fresh install with the made-up
demo clients, so no real data can end up in them.

    tests/e2e/run.sh crawl_fresh        # (or any suite) seeds the test install and starts its servers
    KEEP=1 python3 tools/docs/screenshots.py [name ...]

Loads the demo data into the fresh install if it isn't there, signs in as its admin (shown as "Jordan Lee") and as a
demo portal user, and writes palette PNGs at the sizes docs/SCREENSHOTS.md expects. Needs the test requirements
(tests/README.md), Pillow and pdftoppm.
"""
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "tests", "e2e"))
from lib import *          # noqa: E402,F403  test settings: B_FRESH, DB_FRESH, SOCKET, FRESH_CONFIG, ENV, login, totp
import importlib.util      # noqa: E402
# tests/e2e/sitecustomize.py answers the staff two-factor step; load it by path (Python may already have its own)
_spec = importlib.util.spec_from_file_location("align_test_2fa", os.path.join(ROOT, "tests", "e2e", "sitecustomize.py"))
_spec.loader.exec_module(importlib.util.module_from_spec(_spec))
import pymysql             # noqa: E402
import requests            # noqa: E402
from PIL import Image      # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

OUT = os.path.join(ROOT, "docs", "screenshots")
RAW = tempfile.mkdtemp(prefix="msp-align-shots-")
ONLY = set(sys.argv[1:])
ADMIN, ADMIN_PW = "new@example.com", "FreshAdminPass123!"
CLIENT = "Harborview Family Dental"
PORTAL_USER = "dana@harborview.example"

db = pymysql.connect(unix_socket=SOCKET, user="root", database=DB_FRESH, autocommit=True, cursorclass=pymysql.cursors.DictCursor)


def fq(sql, *a):
    with db.cursor() as c:
        c.execute(sql, a)
        return c.fetchall()


def want(name):
    return not ONLY or name in ONLY


admin = fq("select name, email, theme from users where email=%s", ADMIN)[0]
fq("update users set theme='auto' where email=%s", ADMIN)   # follows the browser: light and dark contexts below
import atexit  # noqa: E402
atexit.register(lambda: fq("update users set theme=%s where email=%s", admin["theme"], admin["email"]))
staff = login(ADMIN, ADMIN_PW, B_FRESH)
if not fq("select id from clients where name=%s", CLIENT):
    tok = re.search(r'name="_csrf" value="([^"]+)"', staff.get(B_FRESH + "/settings").text).group(1)
    staff.post(B_FRESH + "/demo/load", data={"_csrf": tok})
    if not fq("select id from clients where name=%s", CLIENT):
        sys.exit("screenshots: the demo data didn't load (Settings → General → Demo data on the fresh install)")
C = fq("select id from clients where name=%s", CLIENT)[0]["id"]
dev = fq("select id from devices where client_id=%s and removed_at is null order by id limit 1", C)
fw = fq("select framework_id from client_frameworks where client_id=%s limit 1", C)

# name, path, width, height, theme
PAGES = [
    ("dashboard", "/", 1400, 900, "light"),
    ("dashboard-dark", "/", 1400, 900, "dark"),
    ("client-overview", f"/clients/{C}", 1400, 900, "light"),
    ("roadmap", f"/clients/{C}/roadmap", 1400, 900, "light"),
    ("alignment", f"/clients/{C}/alignment", 1400, 900, "light"),
    ("budget", f"/clients/{C}/budget", 1400, 900, "light"),
    ("devices", f"/clients/{C}/devices", 1400, 900, "light"),
    ("device", f"/devices/{dev[0]['id']}" if dev else "/devices", 1400, 760, "light"),
    ("compliance", f"/clients/{C}/compliance/{fw[0]['framework_id']}" if fw else f"/clients/{C}/compliance", 1400, 900, "light"),
    ("meetings", f"/clients/{C}/meetings", 1400, 900, "light"),
    ("licensing", f"/clients/{C}/licenses", 1400, 900, "light"),
    ("todo", "/todo", 1400, 800, "light"),
    ("integrations", "/integrations", 1400, 800, "light"),
]
HIDE = "document.querySelectorAll('.alert').forEach(a => { if (/is available|Demo data|Test server|setup wizard walks/.test(a.textContent)) a.remove(); })"

portal = fq("select * from portal_users where email=%s", PORTAL_USER)[0]
fq("update users set name='Jordan Lee', email='jordan@yourmsp.example' where email=%s", ADMIN)
SECRET = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP"   # 20 bytes: the app refuses authenticator keys under 16 (2.2.1)
try:
    with sync_playwright() as p:
        br = p.chromium.launch()
        for theme in ("light", "dark"):
            ctx = br.new_context(viewport={"width": 1400, "height": 900}, color_scheme=theme)
            ctx.add_cookies([{"name": c.name, "value": c.value, "url": B_FRESH} for c in staff.cookies])
            pg = ctx.new_page()
            for name, path, w, h, th in PAGES:
                if th == theme and want(name):
                    pg.set_viewport_size({"width": w, "height": h})
                    pg.goto(B_FRESH + path)
                    pg.wait_for_timeout(700)
                    pg.evaluate(HIDE)
                    pg.screenshot(path=f"{RAW}/{name}.png")
            ctx.close()
        if want("report-roadmap"):
            # a printed report: the client roadmap as a PDF, first page
            ctx = br.new_context(viewport={"width": 1100, "height": 1400}, color_scheme="light")
            ctx.add_cookies([{"name": c.name, "value": c.value, "url": B_FRESH} for c in staff.cookies])
            pg = ctx.new_page()
            pg.goto(B_FRESH + f"/clients/{C}/report/roadmap")
            pg.wait_for_timeout(800)
            pg.emulate_media(media="print")
            pg.pdf(path=f"{RAW}/report.pdf", format="Letter", print_background=True)
            ctx.close()
            subprocess.run(["pdftoppm", "-png", "-r", "110", "-f", "1", "-l", "1", "-singlefile", f"{RAW}/report.pdf", f"{RAW}/report-roadmap"], check=True)
        if want("portal") or want("portal-roadmap") or want("portal-phone"):
            # the client portal, as a demo client user with a known password and authenticator
            enc = subprocess.run(["php", "-r", 'require $argv[1]; echo Align\\Crypto::encrypt($argv[2]);', ROOT + "/src/bootstrap.php", SECRET],
                                 env=dict(ENV, ALIGN_CONFIG=FRESH_CONFIG), capture_output=True, text=True, check=True).stdout
            ph = subprocess.run(["php", "-r", 'echo password_hash("PortalShots-12345", PASSWORD_DEFAULT);'], capture_output=True, text=True, check=True).stdout
            fq("update portal_users set password_hash=%s, totp_secret_enc=%s, totp_enabled=1, totp_last_step=NULL, can_roadmap=1, can_budget=1, can_devices=1, can_documents=1 where id=%s",
               ph, enc, portal["id"])
            s = requests.Session()
            tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B_FRESH + "/portal/login").text).group(1)
            r = s.post(B_FRESH + "/portal/login", data={"_csrf": tok, "email": PORTAL_USER, "password": "PortalShots-12345"})
            tok = re.search(r'name="_csrf" value="([^"]+)"', r.text).group(1)
            s.post(B_FRESH + "/portal/login/2fa", data={"_csrf": tok, "code": totp(SECRET)})
            if not s.get(B_FRESH + "/portal").url.rstrip("/").endswith("/portal"):
                # without this the shots are quietly of the sign-in page (as from 2.2.1 to 2.2.4)
                sys.exit("screenshots: the demo portal user couldn't sign in, so no portal shots were taken")
            cookies = [{"name": c.name, "value": c.value, "url": B_FRESH} for c in s.cookies]
            ctx = br.new_context(viewport={"width": 1400, "height": 900}, color_scheme="light")
            ctx.add_cookies(cookies)
            pg = ctx.new_page()
            for name, path in [("portal", "/portal"), ("portal-roadmap", "/portal/roadmap")]:
                if want(name):
                    pg.goto(B_FRESH + path)
                    pg.wait_for_timeout(600)
                    pg.screenshot(path=f"{RAW}/{name}.png")
            ctx.close()
            if want("portal-phone"):
                m = br.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, color_scheme="light")
                m.add_cookies(cookies)
                mp = m.new_page()
                mp.goto(B_FRESH + "/portal")
                mp.wait_for_timeout(600)
                mp.screenshot(path=f"{RAW}/portal-phone.png")
                m.close()
        br.close()
finally:
    fq("update users set name=%s, email=%s, theme=%s where email='jordan@yourmsp.example'", admin["name"], admin["email"], admin["theme"])
    cols = ["password_hash", "totp_secret_enc", "totp_enabled", "totp_last_step", "can_roadmap", "can_budget", "can_devices", "can_documents", "last_login_at", "session_version"]
    fq("update portal_users set " + ", ".join(f"{c}=%s" for c in cols) + " where id=%s", *[portal[c] for c in cols], portal["id"])

# Smaller files for the site: 256-colour palette PNGs; the phone shot at 1x
SIZES = {"portal-phone": (390, 844)}
written = []
for f in sorted(os.listdir(RAW)):
    if not f.endswith(".png"):
        continue
    name = f[:-4]
    im = Image.open(f"{RAW}/{f}").convert("RGB")
    if name in SIZES:
        im = im.resize(SIZES[name], Image.LANCZOS)
    im.quantize(colors=256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE).save(f"{OUT}/{name}.png", optimize=True)
    written.append(name)
print(f"screenshots: {len(written)} written to docs/screenshots ({', '.join(written)})")
