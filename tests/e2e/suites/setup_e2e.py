"""1.40 setup wizard, on the fresh install: opens by itself for the first admin on a new install, every step can be
skipped, the forms are the app's own and come back to the wizard, and updates never send anyone to it."""
import atexit, pymysql
from lib import *
import sitecustomize

FB = B_FRESH
FENV = dict(ENV, ALIGN_CONFIG=FRESH_CONFIG)
fdb = pymysql.connect(unix_socket=SOCKET, user="root", database=DB_FRESH, autocommit=True, cursorclass=pymysql.cursors.DictCursor)
def fq(sql, *a):
    with fdb.cursor() as c:
        c.execute(sql, a or None)
        return c.fetchall()
def fset(k, v): fq("insert into settings (name,value,is_secret) values (%s,%s,0) on duplicate key update value=values(value)", k, v)
def png(w, h):
    import zlib, struct
    raw = b"".join(b"\x00" + b"\x1a\x7f\x5a" * w for _ in range(h))
    chunk = lambda t, d: struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")
def fcsrf(s, p): return re.search(r'name="_csrf" value="([^"]+)"', s.get(FB + p).text).group(1)

KEYS = ("mail_reply_to", "setup_state", "setup_seen", "setup_skipped", "company_name", "company_phone", "company_email", "company_website", "brand_primary", "locale_currency", "locale_date", "timezone",
        "mail_provider", "mail_mode", "smtp_host", "smtp_port", "smtp_security", "smtp_user", "mail_from", "mail_from_name", "m365_tenant")
saved = fq("select * from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
def restore():
    fq("delete from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
    for r in saved:
        fq("insert into settings (name,value,is_secret) values (%s,%s,%s)", r["name"], r["value"], r["is_secret"])
    fq("delete from users where email like '%%@setup.example'")
    php('Align\\Branding::removeLogo();', env=FENV)
atexit.register(restore)
fq("delete from settings where name in ('setup_seen','setup_skipped','company_name','company_phone','company_email','company_website')")
fset("setup_state", "pending")

# ---- opens by itself, once per sign-in, for admins
st = requests.Session()
st.post(FB + "/login", data={"_csrf": fcsrf(st, "/login"), "email": "new@example.com", "password": "FreshAdminPass123!"}, allow_redirects=False)
r = st.get(FB + "/")
ok(r.url.endswith("/setup/company") and "Set up MSP-ALIGN" in r.text and not errs(r.text), "first admin lands in the wizard, at the first step to do: " + r.url)
r = st.get(FB + "/"); ok(r.url.rstrip("/") == FB and "Continue setup" in r.text, "only once per sign-in; the dashboard then offers to continue")
tech = login("tech@example.com", TECH_PASSWORD)
r = tech.get(B + "/setup/company"); ok(r.status_code == 403, "techs can't open it")
q("update settings set value='pending' where name='setup_state'")
r = tech.get(B + "/"); ok("Set up MSP-ALIGN" not in r.text and "Continue setup" not in r.text, "and aren't sent to it")
q("update settings set value='done' where name='setup_state'")

# ---- every step renders, and skipping works on each
for s in ["company", "locale", "psa", "rmm", "more", "email", "clients", "team", "finish"]:
    r = st.get(FB + "/setup/" + s); ok(r.status_code == 200 and not errs(r.text) and ('aria-current="step"' in r.text or s == "finish"), "step " + s + " renders")
r = st.post(FB + "/setup/psa/skip", data={"_csrf": fcsrf(st, "/setup/psa")})
ok(r.url.endswith("/setup/rmm") and "psa" in fq("select value from settings where name='setup_skipped'")[0]["value"], "skip a step: marked skipped, on to the next")
t = st.get(FB + "/setup/finish").text; ok("Skipped" in t and "Go to step" in t, "finish page shows what was skipped, with a way back")
st.get(FB + "/setup/locale"); st.post(FB + "/setup/locale/skip", data={"_csrf": fcsrf(st, "/setup/locale")})
t = st.get(FB + "/setup/finish").text
ok(re.search(r'fa-circle-minus[^>]*></i><span class="mr-auto">Currency &amp; dates', t) is not None, "opening and skipping Currency & dates leaves it skipped, not done")
t = st.get(FB + "/setup/psa").text; ok("Continue without a PSA" in t and 'action="/setup/psa/skip"' in t, "no PSA: the way on is a skip")
ok(st.get(FB + "/setup/nonsense", allow_redirects=False).status_code in (302, 303), "unknown step goes back to the start")

# ---- company (the wizard's own form)
r = st.post(FB + "/setup/company", data={"_csrf": fcsrf(st, "/setup/company"), "company_name": "Fresh MSP", "company_email": "not-an-email"})
ok("must be an email address" in flash(r.text) and not fq("select 1 from settings where name='company_name' and value='Fresh MSP'"), "company email checked, nothing saved")
r = st.post(FB + "/setup/company", data={"_csrf": fcsrf(st, "/setup/company"), "company_name": "Fresh MSP <Co>", "company_phone": "(555) 010-2000", "company_email": "hello@fresh.example", "company_website": "fresh.example", "brand_primary": "#1a7f5a"},
            files={"logo": ("logo.png", png(32, 32), "image/png")})
vals = {r["name"]: r["value"] for r in fq("select name, value from settings where name in ('company_name','company_phone','brand_primary')")}
ok(r.url.endswith("/setup/locale") and vals == {"company_name": "Fresh MSP <Co>", "company_phone": "(555) 010-2000", "brand_primary": "#1a7f5a"}, "company saved, on to Currency & dates: " + str(vals))
ok("Fresh MSP &lt;Co&gt;" in st.get(FB + "/setup/company").text, "company name escaped on the page")
r = st.post(FB + "/setup/company", data={"_csrf": fcsrf(st, "/setup/company"), "company_name": ""}); ok("company name" in flash(r.text).lower(), "company name needed to continue (or skip)")

# ---- the app's own forms come back to the wizard
r = st.post(FB + "/settings", data={"_csrf": fcsrf(st, "/setup/locale"), "_tab": "general", "return": "/setup/psa", "locale_currency": "CAD", "locale_date": "dmy", "timezone": "America/Toronto"})
ok(r.url.endswith("/setup/psa") and fq("select value from settings where name='locale_currency'")[0]["value"] == "CAD", "Currency & dates saved through Settings, back in the wizard")
r = st.post(FB + "/settings", data={"_csrf": fcsrf(st, "/setup/locale"), "_tab": "general", "return": "/setup/locale", "return_ok": "/setup/psa", "timezone": "Mars/Olympus"})
ok(r.url.endswith("/setup/locale") and "Choose a timezone" in flash(r.text), "an error stays on the step it came from")
r = st.post(FB + "/settings", data={"_csrf": fcsrf(st, "/setup/locale"), "_tab": "general", "return": "/setup/locale\n"}); ok(r.status_code == 200 and r.url.endswith("/settings"), "a return with a line break is ignored")
r = st.post(FB + "/settings", data={"_csrf": fcsrf(st, "/setup/locale"), "_tab": "general", "return[]": "/setup/psa"}); ok(r.status_code == 200 and not errs(r.text), "a return sent as a list is ignored")
r = st.post(FB + "/settings", data={"_csrf": fcsrf(st, "/setup/locale"), "_tab": "general", "return": "https://evil.example/setup"})
ok(r.url.startswith(FB) and "evil" not in r.url, "return only goes to wizard pages: " + r.url)
r = st.post(FB + "/settings", data={"_csrf": fcsrf(st, "/setup/locale"), "_tab": "general", "return": "/setup/../users"}); ok(r.url.endswith("/settings"), "no path tricks")
t = st.get(FB + "/setup/rmm").text
ok("NinjaOne" in t and 'name="return" value="/setup/rmm"' in t and "Test connection" in t, "RMM step shows the connector form with Test")
F = {m.group(1): H.unescape(m.group(2)) for m in re.finditer(r'<input[^>]*type="(?:text|url)"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', t.split('action="/integrations/ninjaone"')[1].split("</form>")[0])}
r = st.post(FB + "/integrations/ninjaone", data={"_csrf": fcsrf(st, "/setup/rmm"), "return": "/setup/rmm", **F})
ok(r.url.endswith("/setup/rmm") and ("No changes" in flash(r.text) or "saved" in flash(r.text)), "integration saved from the wizard, back on the step: " + flash(r.text)[:60])
r = st.post(FB + "/integrations/ninjaone/test", data={"_csrf": fcsrf(st, "/setup/rmm"), "return": "/setup/rmm"})
ok(r.url.endswith("/setup/rmm") and "NinjaOne" in flash(r.text), "test from the wizard: " + flash(r.text)[:80])
# email: SMTP form keeps what other connections saved
fset("m365_tenant", "keep.onmicrosoft.com")
r = st.post(FB + "/integrations/email", data={"_csrf": fcsrf(st, "/setup/email"), "return": "/setup/email", "mail_provider": "smtp", "mail_mode": "app", "smtp_host": "smtp.fresh.example", "smtp_port": "587", "smtp_security": "starttls", "smtp_user": "", "mail_from": "alerts@fresh.example", "mail_from_name": "Fresh MSP"})
ok(r.url.endswith("/setup/email") and fq("select value from settings where name='smtp_host'")[0]["value"] == "smtp.fresh.example", "SMTP set up from the wizard")
ok(fq("select value from settings where name='m365_tenant'")[0]["value"] == "keep.onmicrosoft.com", "fields not on the form are left alone")
t = st.get(FB + "/setup/email").text; ok("Ready" in t and "Send a test email" in t, "email step shows it's ready, with a test")
fset("mail_reply_to", "reply@fresh.example")
F2 = {m.group(1): H.unescape(m.group(2)) for m in re.finditer(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"', st.get(FB + "/integrations/email").text.split('action="/integrations/email" class="card')[1].split("</form>")[0]) if 'type="checkbox"' not in m.group(0) and 'type="radio"' not in m.group(0)}
st.post(FB + "/integrations/email", data={**F2, "mail_provider": "smtp", "mail_mode": "app", "mail_reply_to": ""})
ok(fq("select value from settings where name='mail_reply_to'")[0]["value"] == "", "the Email page can still clear a field")
# team: add someone, temporary password shown once
r = st.post(FB + "/users", data={"_csrf": fcsrf(st, "/setup/team"), "return": "/setup/team", "name": "Sam Setup", "email": "sam@setup.example", "role": "tech"})
ok(r.url.endswith("/setup/team") and "temporary password" in r.text and fq("select role from users where email='sam@setup.example'")[0]["role"] == "tech", "staff added from the wizard, password shown")
ok("temporary password" not in st.get(FB + "/setup/team").text, "shown only once")
r = st.post(FB + "/users", data={"_csrf": fcsrf(st, "/setup/team"), "return": "/setup/team", "name": "Sam Again", "email": "sam@setup.example", "role": "tech"})
ok(r.url.endswith("/setup/team") and "already exists" in flash(r.text), "errors come back to the wizard too")
r = st.post(FB + "/sync", data={"_csrf": fcsrf(st, "/setup/clients"), "return": "/setup/clients"})
ok("/setup/clients" in r.url and "started=1" in r.url and 'http-equiv="refresh"' in r.text.split("</head>")[0], "sync from the wizard: back on the step, which refreshes in the page head")
for _ in range(60):
    if not fq("select 1 from sync_runs where status='running'"): break
    time.sleep(1)
r = st.post(FB + "/users", data={"_csrf": fcsrf(st, "/users"), "name": "Una Normal", "email": "una@setup.example", "role": "viewer"}); ok(r.url.endswith("/users"), "without return the Users page works as before")
t = st.get(FB + "/setup/company").text
ok(t.count("fa-circle-check") >= 5, "steps done outside or inside the wizard are ticked")

# ---- finish / skip everything
r = st.post(FB + "/setup/finish", data={"_csrf": fcsrf(st, "/setup/finish")})
ok(r.url.rstrip("/") == FB and "set up" in flash(r.text).lower() and fq("select value from settings where name='setup_state'")[0]["value"] == "done", "finish: back to the dashboard, done")
st2 = login("new@example.com", "FreshAdminPass123!", FB)
r = st2.get(FB + "/"); ok(r.url.rstrip("/") == FB and "Continue setup" not in r.text, "never opens by itself again")
ok(st2.get(FB + "/setup/company").status_code == 200 and "/setup" in st2.get(FB + "/settings").text, "still there from Settings → General")
fset("setup_state", "pending")
st3 = login("new@example.com", "FreshAdminPass123!", FB)
r = st3.post(FB + "/setup/finish", data={"_csrf": fcsrf(st3, "/setup/company"), "how": "skip"})
ok("Setup skipped" in flash(r.text) and fq("select value from settings where name='setup_state'")[0]["value"] == "done", "skip setup altogether from any step")

# ---- updates: an install in use is marked as set up
q("delete from settings where name='setup_state'")
r = php('(require "' + ROOT + '/db/migrations/040_setup_wizard.php")(); echo Align\\Settings::get("setup_state");')
ok(r.stdout == "done", "an existing install is never sent to the wizard: " + r.stdout + r.stderr[:100])
r = php('(require "' + ROOT + '/db/migrations/040_setup_wizard.php")(); echo Align\\Settings::get("setup_state");')
ok(r.stdout == "done" and q("select count(*) n from settings where name='setup_state'")[0]["n"] == 1, "the migration can run again")
done()
