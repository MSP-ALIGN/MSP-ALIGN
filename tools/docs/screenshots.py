#!/usr/bin/env python3
"""Screenshots for the docs site (docs/screenshots/), taken from the test environment's fresh install with the made-up
demo clients, so no real data can end up in them.

    tests/e2e/run.sh crawl_fresh        # (or any suite) seeds the test install and starts its servers
    KEEP=1 python3 tools/docs/screenshots.py [name ...]
    KEEP=1 python3 tools/docs/screenshots.py video     # 2.7.3: only the home page's video tour (docs/screenshots/tour.mp4, .webm)

Loads the demo data into the fresh install if it isn't there, signs in as its admin (shown as "Jordan Lee") and as a
demo portal user, and writes palette PNGs at the sizes docs/SCREENSHOTS.md expects. Needs the test requirements
(tests/README.md), Pillow and pdftoppm; the video also needs ffmpeg.
"""
import os
import re
import subprocess
import sys
import shutil
import tempfile
import time

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
    ("changes", f"/clients/{C}/changes", 1400, 900, "light"),
    ("health", f"/clients/{C}#overview-health", 1400, 900, "light"),   # 2.5.0: the client page scrolled to its Health card
    ("budget", f"/clients/{C}/budget", 1400, 900, "light"),
    ("devices", f"/clients/{C}/devices", 1400, 900, "light"),
    ("device", f"/devices/{dev[0]['id']}" if dev else "/devices", 1400, 760, "light"),
    ("compliance", f"/clients/{C}/compliance/{fw[0]['framework_id']}" if fw else f"/clients/{C}/compliance", 1400, 900, "light"),
    ("meetings", f"/clients/{C}/meetings", 1400, 900, "light"),
    ("licensing", f"/clients/{C}/licenses", 1400, 900, "light"),
    ("todo", "/todo", 1400, 800, "light"),
    ("integrations", "/integrations", 1400, 800, "light"),
]
# 2.7.3 cards shot on their own (selector), from the client overview: Huntress (a made-up organization added below
# for the shot and removed after) and security awareness training (part of the demo data)
CARDS = [("huntress", f"/clients/{C}", "#huntress"), ("training", f"/clients/{C}", "#sat")]
HIDE = "document.querySelectorAll('.alert').forEach(a => { if (/is available|Demo data|Test server|setup wizard walks/.test(a.textContent)) a.remove(); })"



def fresh_php(code):
    """PHP against the fresh install (its config), for the few settings the shots need."""
    return subprocess.run(["php", "-r", 'require $argv[1]; ' + code, ROOT + "/src/bootstrap.php"], env=dict(ENV, ALIGN_CONFIG=FRESH_CONFIG),
                          capture_output=True, text=True, check=True).stdout


HN = "demo-shot-hn"   # the made-up Huntress organization's id


def huntress_fixture():
    """A Huntress organization for the demo client, as a sync would leave it: an agent on every computer but two
    (one of them not checking in), Defender healthy except one, a few closed incidents, an open low one, monthly
    reports and identity counts. Settings get a placeholder key so the card shows, and the client's computers look
    RMM-reported (as agents are compared with those); huntress_cleanup() undoes it all."""
    now = fq("select now() n")[0]["n"]
    fresh_php('Align\\Settings::setSecret("huntress_api_key", "screenshot-only"); Align\\Settings::setSecret("huntress_api_secret", "screenshot-only");')
    fq("insert into huntress_orgs (provider, org_id, name, agents_count, identities_total, identities_no_mfa, identities_high_risk, identities_at, ports_at, synced_at) "
       "values ('huntress', %s, %s, 0, 19, 0, 0, %s, %s, %s)", HN, CLIENT, now, now, now)
    fq("insert into client_links (client_id, provider, external_id, match_method) values (%s, 'huntress', %s, 'auto')", C, HN)
    global SAVED
    SAVED = fq("select id, source, rmm_provider, last_contact from devices where client_id=%s", C)
    fq("update devices set source='rmm', rmm_provider='ninjaone', last_contact=now() - interval 2 hour where client_id=%s and removed_at is null", C)
    devs = fq("select id, system_name, serial from devices where client_id=%s and removed_at is null and device_type in ('Desktop','Laptop','Server','Virtual server') order by id", C)
    for k, d in enumerate(devs[2:]):
        fq("insert into huntress_agents (agent_id, org_id, hostname, serial, platform, os, last_callback_at, defender_status, defender_substatus, defender_policy_status, firewall_status, synced_at) "
           "values (%s, %s, %s, %s, 'windows', 'Windows 11 Pro', now() - interval %s hour, %s, 'Up to date', 'Compliant', 'Enabled', now())",
           9_000_000 + k, HN, d["system_name"], d["serial"], 400 if k == 3 else 1, "Unhealthy" if k == 5 else "Protected")
    fq("update huntress_orgs set agents_count=(select count(*) from huntress_agents where org_id=%s) where org_id=%s", HN, HN)
    for k, (sev, status, days, subj) in enumerate([("low", "sent", 2, "Potentially unwanted browser extension"), ("high", "closed", 40, "Malicious PowerShell blocked"),
                                                    ("low", "closed", 95, "Unwanted remote access tool removed"), ("critical", "closed", 210, "Ransomware canary tripped")]):
        fq("insert into huntress_incidents (incident_id, org_id, severity, status, subject, sent_at, closed_at, synced_at) values (%s, %s, %s, %s, %s, now() - interval %s day, "
           "if(%s = 'sent', null, now() - interval %s day), now())", 9_100_000 + k, HN, sev, status, subj, days, status, max(0, days - 1))
    fq("insert into huntress_ports (port_id, org_id, ip_address, port, protocol, service, risky, last_scan_at, synced_at) values (9300000, %s, '203.0.113.20', 443, 'TCP', 'https', 0, now(), now())", HN)
    for k in range(3):
        fq("insert into huntress_reports (report_id, org_id, type, period_start, period_end, url, agents_count, incidents_reported, signals_investigated, synced_at) "
           "values (%s, %s, 'monthly_summary', date_format(now() - interval %s month, '%%Y-%%m-01'), last_day(now() - interval %s month), %s, %s, %s, %s, now())",
           9_200_000 + k, HN, k + 1, k + 1, "https://huntress.example/report.pdf", len(devs) - 2, k % 2, 120 + 30 * k)


SAVED = []


def huntress_cleanup():
    """Undoes huntress_fixture(). The key settings are deleted, not restored: the fresh test install has none."""
    for d in SAVED:
        fq("update devices set source=%s, rmm_provider=%s, last_contact=%s where id=%s", d["source"], d["rmm_provider"], d["last_contact"], d["id"])
    for t in ("huntress_agents", "huntress_incidents", "huntress_reports", "huntress_ports", "huntress_escalations", "huntress_orgs"):
        fq(f"delete from {t} where org_id=%s", HN)
    fq("delete from client_links where provider='huntress' and external_id=%s", HN)
    fq("delete from settings where name in ('huntress_api_key', 'huntress_api_secret')")


# 2.7.3 The home page's video tour: (caption, path, scroll to: a selector, pixels or None, seconds to stay). Recorded at
# 1440x900 (the layout of a laptop screen), saved at 1280x800, in light mode with the demo data (and the Huntress organization above), a caption in the corner of each scene.
TOUR = [
    ("Every client's to-dos and risks on one dashboard", "/", 520, 4.5),
    ("Each client at a glance: lifecycle, health and plans", f"/clients/{C}", None, 3.5),
    ("A health score built from lifecycle, backups, compliance and security", f"/clients/{C}", "#overview-health", 4),
    ("Huntress and security awareness training, checked automatically", f"/clients/{C}", "#huntress", 4.5),
    ("A three-year roadmap by quarter", f"/clients/{C}/roadmap", 300, 4.5),
    ("Alignment reviews against your own standards", f"/clients/{C}/alignment", 380, 4),
    ("A technology budget that builds itself", f"/clients/{C}/budget", 300, 4),
    ("Compliance checklists with owners, due dates and evidence", f"/clients/{C}/compliance/{fw[0]['framework_id']}" if fw else f"/clients/{C}/compliance", 420, 4),
    ("Devices with warranty, end of life and OS support", f"/clients/{C}/devices", 300, 4),
    ("Client-ready QBR packs in one click", f"/clients/{C}/report/qbr", 900, 5),
    ("Light or dark, your choice", "/", None, 3.5),
]
CAPTION = """(t) => { let c = document.getElementById('tour-caption'); if (!c) { c = document.createElement('div'); c.id = 'tour-caption';
  c.style.cssText = 'position:fixed;left:24px;bottom:24px;z-index:99999;background:rgba(15,23,42,.88);color:#fff;font:600 19px/1.35 system-ui,sans-serif;'
    + 'padding:12px 18px;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.25);max-width:760px;opacity:0;transition:opacity .4s';
  document.body.appendChild(c); } c.textContent = t; requestAnimationFrame(() => c.style.opacity = 1); }"""


def record_tour(br):
    """Records TOUR to docs/screenshots/tour.mp4 and tour.webm (no sound, small enough for the home page)."""
    vdir = tempfile.mkdtemp(prefix="msp-align-video-")
    try:
        huntress_fixture()
        ctx = br.new_context(viewport={"width": 1440, "height": 900}, color_scheme="light", record_video_dir=vdir, record_video_size={"width": 1440, "height": 900})
        ctx.add_cookies([{"name": c.name, "value": c.value, "url": B_FRESH} for c in staff.cookies])
        # the same banners HIDE removes, gone before they're drawn
        ctx.add_init_script("new MutationObserver(() => { document.querySelectorAll('.alert').forEach(a => { if (/is available|Demo data|Test server|setup wizard walks/.test(a.textContent)) a.remove(); }); })"
                            ".observe(document, {childList: true, subtree: true});")
        pg = ctx.new_page()
        t0 = time.time()
        start, here = None, None
        for k, (caption, path, to, secs) in enumerate(TOUR):
            if path != here:   # scenes on the same page just scroll on
                pg.goto(B_FRESH + path)
                pg.evaluate(HIDE)
                pg.wait_for_timeout(500)
                here = path
            if k == len(TOUR) - 1:   # the last scene: the same dashboard switching to dark mode
                pg.wait_for_timeout(1200)
                pg.evaluate("() => document.documentElement.setAttribute('data-bs-theme', 'dark')")
            if start is None:
                start = time.time() - t0   # the video is cut to start at the first page drawn, not the blank one
            pg.evaluate(CAPTION, caption)
            pg.wait_for_timeout(900)
            if isinstance(to, str):
                pg.evaluate("s => document.querySelector(s)?.scrollIntoView({behavior: 'smooth', block: 'start'})", to)
            elif to:
                pg.evaluate("y => window.scrollTo({top: y, behavior: 'smooth'})", to)
            pg.wait_for_timeout(int(secs * 1000) - 900)
        video = pg.video.path()
        ctx.close()
        cut = ["ffmpeg", "-y", "-loglevel", "error", "-ss", f"{max(0.0, start - 0.2):.2f}", "-i", video, "-an", "-vf", "fps=30,scale=1280:800:flags=lanczos,format=yuv420p"]
        # H.264 for Safari and most browsers, VP9 for browsers without H.264 (e.g. Chromium builds)
        # the poster: the first frame at the video's own size, so nothing jumps when it starts
        subprocess.run(cut + ["-frames:v", "1", "-q:v", "4", os.path.join(OUT, "tour-poster.jpg")], check=True)
        subprocess.run(cut + ["-c:v", "libx264", "-preset", "slow", "-crf", "28", "-movflags", "+faststart", os.path.join(OUT, "tour.mp4")], check=True)
        subprocess.run(cut + ["-c:v", "libvpx-vp9", "-b:v", "0", "-crf", "42", "-row-mt", "1", "-deadline", "good", os.path.join(OUT, "tour.webm")], check=True)
        print("screenshots: " + ", ".join(f"{f} ({os.path.getsize(os.path.join(OUT, f)) // 1024} KB)" for f in ("tour.mp4", "tour.webm")) + " written")
    finally:
        huntress_cleanup()
        shutil.rmtree(vdir, ignore_errors=True)


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
            if theme == "light" and any(want(n) for n, _, _ in CARDS):
                try:
                    huntress_fixture()
                    pg.set_viewport_size({"width": 1400, "height": 900})
                    for name, path, sel in CARDS:
                        if want(name):
                            pg.goto(B_FRESH + path)
                            pg.wait_for_timeout(700)
                            pg.evaluate(HIDE)
                            pg.locator(sel).screenshot(path=f"{RAW}/{name}.png")
                finally:
                    huntress_cleanup()
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
        if want("video") and ONLY:
            record_tour(br)
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
