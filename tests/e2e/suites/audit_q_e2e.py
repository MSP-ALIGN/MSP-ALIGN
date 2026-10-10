"""2.2.1 security audit fixes: a regression check for each. Email settings (validate everything, then the SMTP password
check on the final values, then the alert through the trusted settings, then the writes) and the last alert when
security alerts are switched off; the wrong two-factor code counter (staff and portal); IPv6 rate limits per /64;
portal reset links, requests and suggestions under parallel posts; anonymous portal sign-outs; view auditing; a
cancelled contract the client signed; the signature's code flag; PDF xref and parse budgets; backup workloads kept
when a list is unreadable; PSA poll and device push auditing; CSV import names; calendar feed links ended with the
sessions; long framework names; the QBR's roadmap switch; roadmap meetings in the portal; onboarding contact notes.

Parallel checks run against a second app server started here with PHP_CLI_SERVER_WORKERS (the main test server
handles one request at a time, so it can't show a race); it also trusts 127.0.0.1 as a proxy, so X-Forwarded-For
can stand in for IPv6 clients."""
import atexit, os, json, time, socket, threading, subprocess, signal, hashlib, http.server, html as H2
from lib import *
import sitecustomize

PHP = 'require "' + BOOTSTRAP + '"; '
PENV = dict(ENV, TZ=os.environ.get("TZ", "America/Los_Angeles"))
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=PENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def cli(*a): return subprocess.run(["php", ALIGN, *a], env=PENV, capture_output=True, text=True)
def maxid(): return q("select coalesce(max(id), 0) m from audit_log")[0]["m"]
def mqmax(): return q("select coalesce(max(id), 0) m from mail_queue")[0]["m"]
def audits(since, action, like="%"): return q("select * from audit_log where id > %s and action = %s and coalesce(detail, '') like %s order by id", since, action, like)
def mails(since, subject, like="%"): return q("select * from mail_queue where id > %s and subject = %s and coalesce(body_html, '') like %s order by id", since, subject, like)
def csrf_at(s, base, path): return re.search(r'name="_csrf" value="([^"]+)"', s.get(base + path).text).group(1)
def local(u): return re.sub(r"^https?://[^/]+", B, u)

# ---- everything this suite changes is put back at the end ----------------------------------------------------
SETTINGS = q("select * from settings")
MQ_START = mqmax()
made = {"clients": [], "contracts": [], "templates": [], "frameworks": [], "roadmap": [], "meetings": []}
cleanups = []
def restore():
    for f in reversed(cleanups):
        try:
            f()
        except Exception as e:
            print("cleanup:", e)
    q("delete from settings")
    for r in SETTINGS:
        q("insert into settings (" + ",".join(r) + ") values (" + ",".join(["%s"] * len(r)) + ")", *r.values())
    for i in made["contracts"]:
        q("delete from contract_events where contract_id=%s", i); q("delete from contracts where id=%s", i)
    for i in made["templates"]:
        q("delete from contract_templates where id=%s", i)
    for i in made["frameworks"]:
        q("delete from compliance_controls where framework_id=%s", i); q("delete from compliance_frameworks where id=%s", i)
    for i in made["roadmap"]:
        q("delete from roadmap_items where id=%s", i)
    for i in made["meetings"]:
        q("delete from meetings where id=%s", i)
    q("delete from portal_submissions where title like 'Auditq %%'")
    q("delete from service_requests where title like '%%Auditq%%' or submitted_name like 'Auditq%%'")
    q("delete from portal_users where email like '%%@auditq.example'")
    q("delete from users where email like 'auditq-%%@example.com'")
    q("delete from contacts where name like '%%Auditq%%' or email like '%%@auditq.example' or psa_id = 'auditq-77'")
    for i in made["clients"]:
        for t in ("contacts", "service_requests", "client_onboardings", "portal_users"):
            q(f"delete from {t} where client_id=%s", i)
        q("delete from clients where id=%s", i)
    q("delete from clients where name like 'Example Importco Auditq%%'")
    q("delete from api_ip_rate where ip like '2001:db8:%%'")
    q("delete from login_attempts")
    q("delete from mail_queue where id > %s", MQ_START)
atexit.register(restore)
q("delete from login_attempts")

ADMIN_PW = "LongPassword123!"
admin = login("admin@example.com", ADMIN_PW)
tech = login("tech@example.com", TECH_PASSWORD)


# ---- a second app server: 8 workers (real parallel requests), 127.0.0.1 trusted as a proxy -------------------
def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p
P2 = free_port()
B2 = f"http://127.0.0.1:{P2}"
CFG2 = WORK + "/auditq-config.php"
cfg = open(CONFIG).read()
assert '"trusted_proxies" => []' in cfg
open(CFG2, "w").write(cfg.replace('"trusted_proxies" => []', '"trusted_proxies" => ["127.0.0.1"]'))
srv2 = subprocess.Popen(["php", "-S", f"127.0.0.1:{P2}", "-t", "public", "tests/dev-router.php"], cwd=ROOT,
                        env=dict(PENV, ALIGN_CONFIG=CFG2, PHP_CLI_SERVER_WORKERS="8"), stdout=subprocess.DEVNULL,
                        stderr=open(WORK + "/server-auditq.log", "w"), start_new_session=True)
def stop_srv2():
    try:
        os.killpg(srv2.pid, signal.SIGTERM)
    except Exception:
        pass
cleanups.append(stop_srv2)
for _ in range(100):
    try:
        if requests.get(B2 + "/login", timeout=2).status_code == 200:
            break
    except Exception:
        time.sleep(0.1)


# ---- TOTP for this suite's own accounts (steps never reused; starts one step back so several sessions can sign in at once)
def code_at(secret, step):
    key = base64.b32decode(secret + "=" * ((8 - len(secret) % 8) % 8))
    h = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest(); o = h[-1] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7fffffff) % 1000000)
_steps = {}
def next_code(secret):
    while True:
        now = int(time.time()) // 30
        s = max(now - 1, _steps.get(secret, -1) + 1)
        if s <= now + 1:
            _steps[secret] = s
            return code_at(secret, s)
        time.sleep(2)
def wrong_code(secret):
    now = int(time.time()) // 30
    valid = {code_at(secret, now + i) for i in (-2, -1, 0, 1, 2)}
    return next(c for c in ("000000", "123456", "654321", "111111") if c not in valid)

PW = "Quartz-Lantern-Field-31"
def mkportal(email, secret, client=1, **perms):
    p = {"can_roadmap": 1, "can_budget": 1, "can_devices": 1, "can_documents": 1, "can_approve": 1, "can_contacts": 1, "can_submit": 1, **perms}
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{client},"email"=>"{email}","name"=>"Auditq Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        + ",".join(f'"{k}"=>{v}' for k, v in p.items()) + f',"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{secret}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    return q("select id from portal_users where email=%s", email)[0]["id"]
def plogin(email, secret, pw=PW, base=B, code=None):
    s = requests.Session()
    r = s.post(base + "/portal/login", data={"_csrf": csrf_at(s, base, "/portal/login"), "email": email, "password": pw})
    for _ in range(2 if secret else 0):
        if not r.url.endswith("/portal/login/2fa"):
            break
        r = s.post(base + "/portal/login/2fa", data={"_csrf": csrf_at(s, base, "/portal/login/2fa"), "code": code or next_code(secret)})
    return s, r
def mkstaff(email, secret, pw, role="tech"):
    php(f'Align\\DB::insert("users", ["email"=>"{email}","name"=>"Example Auditq","role"=>"{role}","password_hash"=>Align\\Security::hashPassword("{pw}"),'
        f'"totp_secret_enc"=>Align\\Crypto::encrypt("{secret}"),"totp_enabled"=>1,"is_active"=>1,"must_change_password"=>0,"password_changed_at"=>date("Y-m-d H:i:s")]);')
    return q("select id from users where email=%s", email)[0]["id"]
def slogin(email, pw, secret=None, base=B):
    s = requests.Session()
    r = s.post(base + "/login", data={"_csrf": csrf_at(s, base, "/login"), "email": email, "password": pw})
    if r.url.endswith("/login/2fa") and secret:
        r = s.post(base + "/login/2fa", data={"_csrf": csrf_at(s, base, "/login/2fa"), "code": next_code(secret)})
    return s, r

# A client of this suite's own (rate limits and onboarding saves counted per client never touch the shared ones)
CXN = "Auditq Example Dental %d" % int(time.time())  # unique per run
q("insert into clients (name, source) values (%s, 'manual')", CXN)
CX = q("select id from clients where name=%s", CXN)[0]["id"]; made["clients"].append(CX)


# =============================================================================================================
# 4. rate_ip(): IPv6 per /64 for rate limits and lockouts
out = phpv('foreach (["203.0.113.7", "::ffff:203.0.113.7", "2001:db8:1:2:aaaa:bbbb:cccc:dddd", "2001:db8:1:2::1", "2001:db8:1:3::1"] as $ip) { $_SERVER["REMOTE_ADDR"] = $ip; echo rate_ip(), "|"; }')
ok(out == "203.0.113.7|::ffff:203.0.113.7|2001:db8:1:2::/64|2001:db8:1:2::/64|2001:db8:1:3::/64|",
   "rate_ip(): IPv4 and IPv4-mapped IPv6 as they are, an IPv6 address as its /64 (2001:db8:1:2::/64): " + out)
q("delete from login_attempts")
for i in range(10):
    s = requests.Session(); h = {"X-Forwarded-For": "2001:db8:1:2::%x" % (i + 1)}
    tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B2 + "/login", headers=h).text).group(1)
    s.post(B2 + "/login", headers=h, data={"_csrf": tok, "email": "auditq-nobody%d@example.com" % i, "password": "Wrong-password-1"})
n64 = q("select count(*) n from login_attempts where ip = '2001:db8:1:2::/64' and success = 0")[0]["n"]
ok(n64 == 10, f"failed staff sign-ins from 10 addresses of one IPv6 /64 are counted under that /64 ({n64})")
s = requests.Session(); h = {"X-Forwarded-For": "2001:db8:1:2::ff"}
tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B2 + "/login", headers=h).text).group(1)
r = s.post(B2 + "/login", headers=h, data={"_csrf": tok, "email": "admin@example.com", "password": ADMIN_PW})
ok(not r.url.endswith("/login/2fa") and "Too many" in flash(r.text), "an 11th address in the same /64 is locked out even with the right password: " + flash(r.text)[:80])
s = requests.Session(); h = {"X-Forwarded-For": "2001:db8:9:9::1"}
tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B2 + "/login", headers=h).text).group(1)
r = s.post(B2 + "/login", headers=h, data={"_csrf": tok, "email": "auditq-nobody@example.com", "password": "Wrong-password-1"})
ok("Too many" not in flash(r.text), "another /64 isn't locked out: " + flash(r.text)[:80])
q("delete from login_attempts")
s = requests.Session(); h = {"X-Forwarded-For": "2001:db8:1:2::42"}
tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B2 + "/portal/login", headers=h).text).group(1)
s.post(B2 + "/portal/login", headers=h, data={"_csrf": tok, "email": "nobody@auditq.example", "password": "Wrong-password-1"})
ok(q("select count(*) n from login_attempts where ip = '2001:db8:1:2::/64'")[0]["n"] == 1, "portal sign-in attempts are counted per /64 too")
q("delete from login_attempts")
requests.get(B2 + "/api/v1/clients", headers={"X-Forwarded-For": "2001:db8:1:2::77", "Authorization": "Bearer not-a-real-key"})
ok(q("select hits from api_ip_rate where ip = '2001:db8:1:2::/64'"), "an API request with a bad key is rate-counted for the /64 (api_ip_rate)")


# =============================================================================================================
# 1. Email settings: validate all, check the SMTP password on final values, alert first, then write
def secret(k): return phpv(f'echo Align\\Settings::secret("{k}") ?? "(none)";')
setting("smtp_host", "smtp.example.com"); setting("smtp_pass", "Old-Smtp-Secret-1", secret=True)
m = maxid()
F = form(admin, "/integrations/email")
r = admin.post(B + "/integrations/email", data={**F, "smtp_host": "smtp2.example.net", "smtp_pass": "New-Smtp-Secret-2", "m365_cert_pem": "not a certificate"})
ok("certificate must be PEM" in flash(r.text), "a bad certificate later in the form is refused: " + flash(r.text)[:80])
ok(q("select value from settings where name='smtp_host'")[0]["value"] == "smtp.example.com" and secret("smtp_pass") == "Old-Smtp-Secret-1",
   "...and nothing earlier in the form was saved: SMTP server and password unchanged (2.2.0 saved the new server first)")
ok(not audits(m, "settings.email"), "...and no settings.email audit entry")
r = admin.post(B + "/integrations/email", data={**form(admin, "/integrations/email"), "smtp_host": "smtp3.example.net"})
ok("Enter the SMTP password again" in flash(r.text) and q("select value from settings where name='smtp_host'")[0]["value"] == "smtp.example.com",
   "a new SMTP server without the password typed again is still refused")
F2 = form(admin, "/integrations/email"); F2.pop("smtp_verify", None); F2["smtp_verify_present"] = "1"
r = admin.post(B + "/integrations/email", data=F2)
ok("Enter the SMTP password again" in flash(r.text) and [x["value"] for x in q("select value from settings where name='smtp_verify'")] in ([], ["1"]),
   "turning off the certificate check without the password is refused too (judged on the value the save leaves)")
# A sensitive change: the alert goes out through the settings trusted until now (Microsoft here), before the switch
q("delete from mail_queue where subject = 'Security: Email settings changed'")
dead = free_port()
graph_before = sum(1 for x in graph()["mail"] if x["message"]["subject"] == "Security: Email settings changed")
mq = mqmax(); m = maxid()
r = admin.post(B + "/integrations/email", data={**form(admin, "/integrations/email"), "mail_provider": "smtp", "smtp_host": "127.0.0.1", "smtp_port": str(dead),
                                                 "smtp_security": "none", "smtp_pass": "New-Smtp-Secret-3"})
al = mails(mq, "Security: Email settings changed")
graph_after = sum(1 for x in graph()["mail"] if x["message"]["subject"] == "Security: Email settings changed")
ok("Email settings saved" in flash(r.text) and q("select value from settings where name='mail_provider'")[0]["value"] == "smtp", "a valid change of provider is saved")
ok(al and al[0]["status"] == "sent" and graph_after == graph_before + 1,
   "its security alert was sent through the old (Microsoft) settings before the new SMTP server took over: " + str([(x["status"], x["last_error"]) for x in al]))
ok(audits(m, "settings.email", "%mail_provider%"), "...and the change is audited after the writes")
for k, v in (("mail_provider", "microsoft"), ("smtp_host", "smtp.example.com"), ("smtp_port", ""), ("smtp_security", "starttls")):
    setting(k, v)
q("delete from settings where name in ('smtp_pass', 'smtp_host')")


# =============================================================================================================
# 2. Switching security alerts off sends one last alert first
q("delete from mail_queue where subject = 'Security: Security alerts turned off'")
mq = mqmax()
d = form(admin, "/settings/notifications", 'action="/settings/notifications"')
had = "on[security]" in d
d.pop("on[security]", None)
r = admin.post(B + "/settings/notifications", data=d)
ok(had and q("select value from settings where name='notif_security'")[0]["value"] == "0", "security alerts can be switched off")
ok(mails(mq, "Security: Security alerts turned off", "%admin@example.com%"), "...and switching them off queued one last alert, naming who did it")
setting("notif_security", "1")


# =============================================================================================================
# 3. Wrong two-factor codes after a correct password
cols = {r["table_name"] if "table_name" in r else r["TABLE_NAME"] for r in q("select table_name from information_schema.columns where table_schema=%s and column_name='totp_failures'", DB_MAIN)}
ok(cols == {"users", "portal_users"}, "migration 051 added totp_failures to users and portal_users: %s" % sorted(cols))
SEC_S = "KNSWG5LSMFRGKZDJM5UXI43UMFZGK43U"
SPW = "Granite-Harbor-Meadow-47"
SE = "auditq-2fa@example.com"
sid = mkstaff(SE, SEC_S, SPW)
def sf(): return q("select totp_failures, session_version, password_hash from users where id=%s", sid)[0]
q("delete from login_attempts")
s, r = slogin(SE, SPW)
ok(r.url.endswith("/login/2fa"), "the staff test account reaches the code step")
WRONG2FA = "Security: Wrong two-factor codes after a correct password"
mq = mqmax()
for i in range(4):
    s.post(B + "/login/2fa", data={"_csrf": csrf_at(s, B, "/login/2fa"), "code": wrong_code(SEC_S)})
ok(sf()["totp_failures"] == 4 and not mails(mq, WRONG2FA, f"%{SE}%"), "each wrong code counts (4) and no alert yet")
s.post(B + "/login/2fa", data={"_csrf": csrf_at(s, B, "/login/2fa"), "code": wrong_code(SEC_S)})
ok(sf()["totp_failures"] == 5 and mails(mq, WRONG2FA, f"%{SE}%"), "the 5th wrong code in a row alerts admins")
q("delete from login_attempts")
r = s.post(B + "/login/2fa", data={"_csrf": csrf_at(s, B, "/login/2fa"), "code": next_code(SEC_S)})
ok(not r.url.endswith("/login") and not r.url.endswith("/login/2fa") and sf()["totp_failures"] == 0, f"a correct code signs in and resets the count to 0 ({r.url})")
q("update users set totp_failures = 49 where id=%s", sid)
before = sf(); q("delete from login_attempts")
mq = mqmax(); m = maxid()
s2, r = slogin(SE, SPW)
s2.post(B + "/login/2fa", data={"_csrf": csrf_at(s2, B, "/login/2fa"), "code": wrong_code(SEC_S)})
after = sf()
ok(after["password_hash"] != before["password_hash"] and after["session_version"] == before["session_version"] + 1 and after["totp_failures"] == 0,
   "the 50th wrong code in a row replaces the password, ends every session and starts the count again")
ok(audits(m, "login.2fa_password_reset", SE) and mails(mq, "Security: Password replaced after wrong two-factor codes", f"%{SE}%"),
   "...audited as login.2fa_password_reset, with an alert to admins")
ok(s.get(B + "/account", allow_redirects=False).status_code in (302, 303), "the session that had signed in is signed out")
q("delete from login_attempts")
s3, r = slogin(SE, SPW)
ok(not r.url.endswith("/login/2fa") and "incorrect" in flash(r.text).lower(), "the old password no longer gets to the code step: " + flash(r.text)[:60])

SEC_P = "MFZWIZTHNBVGW3DNNZXXA4LSON2HK5TW"
PE = "pat2fa@auditq.example"
pid = mkportal(PE, SEC_P, client=CX)
def pf(): return q("select totp_failures, session_version, password_hash from portal_users where id=%s", pid)[0]
q("delete from login_attempts")
ps, r = plogin(PE, None)
ok(r.url.endswith("/portal/login/2fa"), "the portal test account reaches the code step")
mq = mqmax()
for i in range(5):
    ps.post(B + "/portal/login/2fa", data={"_csrf": csrf_at(ps, B, "/portal/login/2fa"), "code": wrong_code(SEC_P)})
ok(pf()["totp_failures"] == 5 and mails(mq, WRONG2FA, f"%{PE}%"), "portal: 5 wrong codes are counted and alert admins")
q("delete from login_attempts")
r = ps.post(B + "/portal/login/2fa", data={"_csrf": csrf_at(ps, B, "/portal/login/2fa"), "code": next_code(SEC_P)})
ok(r.url.endswith("/portal") and pf()["totp_failures"] == 0, f"portal: a correct code resets the count ({r.url})")
q("update portal_users set totp_failures = 49 where id=%s", pid)
before = pf(); q("delete from login_attempts")
mq = mqmax(); m = maxid()
ps2, r = plogin(PE, None)
ps2.post(B + "/portal/login/2fa", data={"_csrf": csrf_at(ps2, B, "/portal/login/2fa"), "code": wrong_code(SEC_P)})
after = pf()
ok(after["password_hash"] != before["password_hash"] and after["session_version"] == before["session_version"] + 1 and after["totp_failures"] == 0,
   "portal: the 50th wrong code replaces the password and ends the sessions")
ok(audits(m, "portal.2fa_password_reset", PE) and mails(mq, "Security: Password replaced after wrong two-factor codes", f"%{PE}%"), "portal: audited as portal.2fa_password_reset, with an alert")
ok(ps.get(B + "/portal", allow_redirects=False).status_code in (302, 303), "portal: the signed-in session is signed out")
q("delete from login_attempts")
ps3, r = plogin(PE, None)
ok(not r.url.endswith("/portal/login/2fa"), "portal: the old password no longer gets to the code step")
q("delete from login_attempts")


# =============================================================================================================
# 5. Portal "Forgot password": at most 3 links an hour per email, even when posted in parallel
setting("notif_client_portal_reset", "1")
RE_ = "reset-race@auditq.example"
mkportal(RE_, "ONSWG4TFORZWK3DPONSWG4TFORZWK3DQ", client=CX)
q("delete from login_attempts")
fr = requests.get(B2 + "/portal/forgot")
if fr.url.endswith("/portal/forgot"):
    sessions = []
    for i in range(8):
        s = requests.Session(); sessions.append((s, csrf_at(s, B2, "/portal/forgot")))
    bar = threading.Barrier(8); res = []
    def fire(st):
        s, tok = st; bar.wait()
        res.append(s.post(B2 + "/portal/forgot", data={"_csrf": tok, "email": RE_}).status_code)
    ts = [threading.Thread(target=fire, args=(st,)) for st in sessions]
    [t.start() for t in ts]; [t.join(60) for t in ts]
    links = q("select count(*) n from mail_queue where kind='client_portal_reset' and recipients like %s", f"%{RE_}%")[0]["n"]
    claims = q("select count(*) n from login_attempts where email=%s", "reset:" + RE_)[0]["n"]
    ok(len(res) == 8 and all(c == 200 for c in res), "8 parallel reset requests from separate sessions all get the usual answer: %s" % res)
    ok(1 <= links <= 3, f"...but at most 3 reset emails are queued (claim first, then count) ({links})")
    ok(claims == links, f"...and the refused claims are removed again: one login_attempts row per email sent ({claims} rows, {links} emails)")
    for i in range(4):
        s = requests.Session(); s.post(B2 + "/portal/forgot", data={"_csrf": csrf_at(s, B2, "/portal/forgot"), "email": RE_})
    ok(q("select count(*) n from mail_queue where kind='client_portal_reset' and recipients like %s", f"%{RE_}%")[0]["n"] == 3
       and q("select count(*) n from login_attempts where email=%s", "reset:" + RE_)[0]["n"] == 3, "4 more requests in the hour top it up to exactly 3 emails and 3 rows, never more")
    # Counting only rows up to its own claim, the first 3 claims always win: exactly 3, never none, for each burst
    for n in range(3):
        em = f"reset-burst{n}@auditq.example"
        mkportal(em, "ONSWG4TFORZWK3DPONSWG4TFORZWK3D%s" % "RST"[n], client=CX)
        q("delete from login_attempts")  # (10 an hour per IP)
        sessions = []
        for i in range(8):
            s = requests.Session(); sessions.append((s, csrf_at(s, B2, "/portal/forgot")))
        bar = threading.Barrier(8); res = []
        def fire_b(st):
            s, tok = st; bar.wait()
            res.append(s.post(B2 + "/portal/forgot", data={"_csrf": tok, "email": em}).status_code)
        ts = [threading.Thread(target=fire_b, args=(st,)) for st in sessions]
        [t.start() for t in ts]; [t.join(60) for t in ts]
        links = q("select count(*) n from mail_queue where kind='client_portal_reset' and recipients like %s", f"%{em}%")[0]["n"]
        claims = q("select count(*) n from login_attempts where email=%s", "reset:" + em)[0]["n"]
        ok(res == [200] * 8 and links == 3 and claims == 3, f"burst {n + 1}: 8 parallel requests for a fresh address send exactly 3 reset emails and keep 3 rows ({links} emails, {claims} rows)")
else:
    ok(False, "Forgot password is available (portal reset emails on, mail ready)")
q("delete from login_attempts")


# =============================================================================================================
# 6. Portal sign-out: only a signed-in user is audited
m = maxid()
anon = requests.Session()
anon.post(B + "/portal/logout", data={"_csrf": csrf_at(anon, B, "/portal/login")})
ok(not audits(m, "portal.logout"), "an anonymous POST /portal/logout adds nothing to the audit log")
LG = "logout@auditq.example"; SEC_LG = "IFBEGRCFIZDUQSKKIFBEGRCFIZDUQSKL"
lgid = mkportal(LG, SEC_LG, client=CX)
ls, r = plogin(LG, SEC_LG)
m = maxid()
ls.post(B + "/portal/logout", data={"_csrf": csrf_at(ls, B, "/portal")})
ok(q("select id from audit_log where id > %s and action='portal.logout' and portal_user_id=%s", m, lgid), "a signed-in portal user's sign-out is audited")
q("delete from login_attempts")


# =============================================================================================================
# 7. Requests (portal and onboarding page): limits counted and submitted under the client's lock
REQ = {"first_name": "Auditq", "last_name": "Newhire", "job_title": "Assistant", "supervisor": "Pat Rivera", "start_date": "2026-11-20"}
def fill_requests(n, pu=None):
    with db.cursor() as c:
        c.executemany("insert into service_requests (client_id, kind, title, data, submitted_name, portal_user_id, via, delivery) values (%s,'new_user','Auditq filler','{}','Auditq Filler',%s,'portal','email')",
                      [(CX, pu)] * n)
def nreq(): return q("select count(*) n from service_requests where client_id=%s", CX)[0]["n"]
users = []
for i in range(4):
    sec = base64.b32encode(hashlib.sha1(b"auditq-req-%d" % i).digest()).decode()[:32]
    e = f"req{i}@auditq.example"; uid = mkportal(e, sec, client=CX)
    s, r = plogin(e, sec, base=B2)
    users.append((uid, s))
ok(all(s.get(B2 + "/portal/requests", allow_redirects=False).status_code == 200 for _, s in users), "four portal users of the client are signed in on the parallel server")
q("delete from service_requests where client_id=%s", CX)
fill_requests(23)
toks = [csrf_at(s, B2, "/portal/requests") for _, s in users]
bar = threading.Barrier(4); res = []
def fire_req(i):
    bar.wait()
    res.append(users[i][1].post(B2 + "/portal/requests/new_user", data={"_csrf": toks[i], **REQ}).text)
ts = [threading.Thread(target=fire_req, args=(i,)) for i in range(4)]
[t.start() for t in ts]; [t.join(120) for t in ts]
ok(nreq() == 25, f"4 parallel portal requests with 23 sent today: only 2 more get through (25 a day per client) ({nreq()})")
ok(sum("lot of requests" in flash(t) for t in res) == 2, "...the other two are told it's a lot of requests: %s" % [flash(t)[:50] for t in res])
q("delete from service_requests where client_id=%s", CX)
fill_requests(10, users[0][0])
r = users[0][1].post(B2 + "/portal/requests/new_user", data={"_csrf": csrf_at(users[0][1], B2, "/portal/requests"), **REQ})
ok("lot of requests" in flash(r.text) and nreq() == 10, "10 requests in an hour from one portal user: the next is refused with the message")
r = users[1][1].post(B2 + "/portal/requests/new_user", data={"_csrf": csrf_at(users[1][1], B2, "/portal/requests"), **REQ})
ok("Request sent" in flash(r.text) and nreq() == 11, "...while another user of the client can still send one: " + flash(r.text)[:60])
# the onboarding page shares the per-client daily limit
def new_link(cid):
    r = tech.post(B + f"/clients/{cid}/onboarding/send", data={"_csrf": csrf(tech, f"/clients/{cid}/onboarding"), "action": "link", "subject": "Welcome",
                                                              "body": "<p>{{onboarding_link}}</p>", "onsite_week": ""})
    return local(H.unescape(re.search(r'id="onb-link" readonly value="([^"]+)"', r.text).group(1)))
link = new_link(CX)
q("delete from service_requests where client_id=%s", CX)
fill_requests(25)
c = requests.Session()
r = c.post(link + "/request/new_user", data={"_csrf": csrf_at(c, "", link), **REQ, "by_name": "Auditq Visitor"})
ok("lot of requests in one day" in flash(r.text) and nreq() == 25, "the onboarding page refuses a 26th request in a day: " + flash(r.text)[:70])
q("delete from service_requests where client_id=%s", CX)
r = c.post(link + "/request/new_user", data={"_csrf": csrf_at(c, "", link), **REQ, "by_name": "Auditq Visitor"})
ok("Request sent" in flash(r.text) and nreq() == 1, "...and still sends one under the limit: " + flash(r.text)[:60])
q("delete from service_requests where client_id=%s", CX)


# =============================================================================================================
# 8. Portal suggestions: 20 an hour per user, counted and saved under the user's lock
SG = "suggest@auditq.example"; SEC_SG = "OBQXE5DBNRSGK4TPOBQXE5DBNRSGK4TQ"
sgid = mkportal(SG, SEC_SG, client=CX)
sess = []
for i in range(3):
    s, r = plogin(SG, SEC_SG, base=B2)
    sess.append(s)
signed = [s.get(B2 + "/portal/budget", allow_redirects=False).status_code == 200 for s in sess]
with db.cursor() as cur:
    cur.executemany("insert into portal_submissions (client_id, kind, title, data, portal_user_id, submitted_by_name) values (%s,'budget','Auditq filler','{}',%s,'Auditq')", [(CX, sgid)] * 19)
toks = [csrf_at(s, B2, "/portal/budget") for s in sess]
bar = threading.Barrier(3); res = []
def fire_sg(i):
    bar.wait()
    res.append(sess[i].post(B2 + "/portal/suggest/budget", data={"_csrf": toks[i], "name": f"Auditq Fiber {i}", "amount": "45"}).text)
ts = [threading.Thread(target=fire_sg, args=(i,)) for i in range(3)]
[t.start() for t in ts]; [t.join(60) for t in ts]
n = q("select count(*) n from portal_submissions where portal_user_id=%s", sgid)[0]["n"]
ok(all(signed) and n == 20, f"3 parallel suggestions from 3 sessions of one user with 19 this hour: only one is saved ({n}, signed in: {signed})")
ok(sum("lot of suggestions" in flash(t) for t in res) == 2, "...the other two get the limit message")
r = sess[0].post(B2 + "/portal/suggest/budget", data={"_csrf": csrf_at(sess[0], B2, "/portal/budget"), "name": "Auditq Fiber late", "amount": "45"})
ok("lot of suggestions" in flash(r.text) and not q("select id from portal_submissions where title='Auditq Fiber late'"), "the 21st in the hour is refused with the message")
q("delete from login_attempts")


# =============================================================================================================
# 9. Views of sensitive lists are audited
va = login("admin@example.com", ADMIN_PW)
for path, action, like, label in (("/portal-users", "view.portal_users", "all clients", "/portal-users"),
                                  (f"/clients/{CX}/portal", "view.portal_users", CXN, "a client's portal users"),
                                  ("/contracts", "view.contracts", "list (open)", "/contracts"),
                                  ("/documents", "view.documents", "list%", "/documents"),
                                  (f"/clients/{CX}/documents", "view.documents", f"list ({CXN})", "a client's documents"),
                                  ("/devices/unassigned", "view.devices", "unassigned hardware (all clients)", "Unassigned hardware")):
    m = maxid()
    st = va.get(B + path).status_code
    ok(st == 200 and audits(m, action, like), f"opening {label} writes {action} ({like}) ({st})")
m = maxid()
for i in range(2):
    requests.get(link)
ok(len(audits(m, "view.onboarding_page", CXN)) == 2, "every view of the onboarding page (one per visitor session) is audited as view.onboarding_page")


# =============================================================================================================
# 10 + 11. Contract signing: code_verified on the signature; a signed then cancelled contract counts as signed
r = admin.post(B + "/contracts/templates", data={"_csrf": csrf(admin, "/contracts/templates"), "start": "blank", "name": "Auditq template"})
TPL = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1)); made["templates"].append(TPL)
tdef = json.loads(q("select def from contract_templates where id=%s", TPL)[0]["def"])
tdef["blocks"][0]["html"] = "<p>Example terms for Auditq.</p>"
tdef["signing"]["verify_code"] = True; tdef["signing"]["countersign"] = "after"
admin.post(B + f"/contracts/templates/{TPL}", data={"_csrf": csrf(admin, f"/contracts/templates/{TPL}"), "name": "Auditq template", "is_active": "1", "def": json.dumps(tdef)})

def make_contract(verify):
    r = tech.post(B + "/contracts", data={"_csrf": csrf(tech, "/contracts"), "template_id": TPL, "for": "lead", "lead_company": "Example Auditq Lead"})
    kid = int(re.search(r"/contracts/(\d+)$", r.url).group(1)); made["contracts"].append(kid)
    tech.post(B + f"/contracts/{kid}", data={"_csrf": csrf(tech, f"/contracts/{kid}"), "then": "save", "client_id": "", "lead_company": "Example Auditq Lead",
                                             "signer_name": "Robin Example", "signer_email": "robin@auditq.example", **({"verify_code": "1"} if verify else {})})
    q("update contracts set verify_code=%s where id=%s", 1 if verify else 0, kid)
    r = tech.post(B + f"/contracts/{kid}/send", data={"_csrf": csrf(tech, f"/contracts/{kid}"), "action": "link"})
    m_ = re.search(r'id="ct-link" readonly value="([^"]+)"', r.text)
    return kid, (local(H.unescape(m_.group(1))) if m_ else None), flash(r.text)
SIGN = {"sig_kind": "type", "sig_typed": "Robin Example", "sig_name": "Robin Example", "consent": "1"}
kid, klink, fl = make_contract(True)
ok(klink is not None and q("select status, verify_code from contracts where id=%s", kid)[0] == {"status": "sent", "verify_code": 1}, "a contract with an emailed code is out for signature: " + fl[:80])
cs = requests.Session()
if klink:
    cs.post(klink + "/code", data={"_csrf": csrf_at(cs, "", klink)})
    q("update contracts set code_hash=%s, code_expires_at=now() + interval 10 minute, code_attempts=0 where id=%s",
      phpv('echo password_hash("246810", PASSWORD_DEFAULT);'), kid)  # the emailed code, known to the test
    r = cs.post(klink + "/verify", data={"_csrf": csrf_at(cs, "", klink), "code": "246810"})
    r = cs.post(klink, data={"_csrf": csrf_at(cs, "", klink), **SIGN})
k = q("select status, client_signature from contracts where id=%s", kid)[0]
sig = json.loads(k["client_signature"] or "{}")
ok(k["status"] == "client_signed" and sig.get("code_verified") is True, "signed after entering the emailed code: client_signature.code_verified is true (%s)" % sig.get("code_verified"))
kid2, klink2, fl = make_contract(False)
cs2 = requests.Session()
if klink2:
    cs2.post(klink2, data={"_csrf": csrf_at(cs2, "", klink2), **SIGN})
k2 = q("select status, client_signature from contracts where id=%s", kid2)[0]
sig2 = json.loads(k2["client_signature"] or "{}")
ok(k2["status"] == "client_signed" and sig2.get("code_verified") is False, "signed with no code asked: code_verified is false (%s)" % sig2.get("code_verified"))
# the signing certificate follows the flag, not just any code entered for the contract
import zlib, base64 as b64
def pdf_text(b):
    out = []
    for m_ in re.finditer(rb"/Length (\d+)[^>]*>>\s*stream\r?\n", b):
        st_ = b[m_.end():m_.end() + int(m_.group(1))]
        try: st_ = zlib.decompress(st_)
        except Exception: pass
        out += [bytes.fromhex(h_.decode()).decode("cp1252", "replace") for h_ in re.findall(rb"<([0-9a-f]+)> Tj", st_)]
    return re.sub(r"\s+", " ", " ".join(out))
def cert(k_):
    r_ = subprocess.run(["php", "-r", PHP + f'echo base64_encode(Align\\Contracts\\PdfRender::build(Align\\Contracts\\Contracts::load({k_}), true));'], env=PENV, capture_output=True, text=True)
    try:
        return pdf_text(b64.b64decode(r_.stdout.strip()))
    except Exception:
        return "(no pdf) " + (r_.stdout + r_.stderr)[:200]
t1, t2 = cert(kid), cert(kid2)
ok("entered the one-time code emailed to that address" in t1, "the certificate of the contract signed with the code says the code was entered: " + t1[t1.find("Identity"):][:120])
ok("Opened the private signing link sent to robin@auditq.example" in t2 and "one-time code" not in t2, "the one signed without a code says only that the link was opened")
sg = json.loads(q("select client_signature from contracts where id=%s", kid)[0]["client_signature"]); sg["code_verified"] = False
q("update contracts set client_signature=%s where id=%s", json.dumps(sg), kid)
t3 = cert(kid)
ok("one-time code" not in t3 and "Opened the private signing link" in t3,
   "with code_verified false on the signature, the certificate doesn't claim a code even though one was entered for the contract")
sg["code_verified"] = True; q("update contracts set client_signature=%s where id=%s", json.dumps(sg), kid)
# 10: staff cancel the first one after the client signed it; it still counts as signed
r = admin.post(B + f"/contracts/{kid}/void", data={"_csrf": csrf(admin, f"/contracts/{kid}"), "reason": "Example cancellation"})
k = q("select status, client_signed_at from contracts where id=%s", kid)[0]
ok(k["status"] == "void" and k["client_signed_at"], "the client-signed contract is cancelled by staff: " + str(k["status"]))
t = tech.get(B + f"/contracts/{kid}").text
ok(f'action="/contracts/{kid}/delete"' not in t, "a tech isn't offered the plain Delete for a cancelled contract the client had signed")
r = tech.post(B + f"/contracts/{kid}/delete", data={"_csrf": csrf(tech, f"/contracts/{kid}")})
ok(q("select id from contracts where id=%s", kid) and "Only an admin" in flash(r.text), "...and a tech's delete is refused (only an admin, with the typed number): " + flash(r.text)[:60])
t = admin.get(B + f"/contracts/{kid}").text
ok(f'action="/contracts/{kid}/delete"><' not in t.replace("\n", "") and 'data-bs-target="#modal-delete-contract"' in t, "an admin gets only the typed-confirmation Delete for it")
num = "C-%04d" % kid
admin.post(B + f"/contracts/{kid}/delete", data={"_csrf": csrf(admin, f"/contracts/{kid}"), "confirm": num})
ok(not q("select id from contracts where id=%s", kid) and audits(0, "contract.deleted_signed", f"{num}%"), "an admin deletes it with the number, audited as a signed contract")


# =============================================================================================================
# 12. PdfDoc: free xref entries count toward the entry limit; failed parse work is charged to the budget
PDIR = WORK + "/auditq_pdf"; os.makedirs(PDIR, exist_ok=True)
def pdf_try(path, mem=128, timeout=60):
    code = ('ini_set("memory_limit", "%dM"); $t = microtime(true);' % mem
            + f'try {{ new Align\\Pdf\\PdfDoc(file_get_contents("{path}")); echo "read"; }} catch (\\InvalidArgumentException $e) {{ echo "refused: ", $e->getMessage(); }}'
            + ' catch (\\Throwable $e) { echo "error: ", get_class($e); } printf(" |%.2fs %dMB", microtime(true) - $t, memory_get_peak_usage(true) >> 20);')
    t0 = time.time()
    try:
        r = subprocess.run(["php", "-r", PHP + code], env=PENV, capture_output=True, text=True, timeout=timeout)
        return (r.stdout + r.stderr).strip(), time.time() - t0
    except subprocess.TimeoutExpired:
        return "timed out", time.time() - t0
import zlib
zs = zlib.compress(b"\0" * 8388000, 9)
head = b"%PDF-1.5\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
x = len(head)
body = head + (b"3 0 obj\n<< /Type /XRef /Size 8388005 /W [1 0 0] /Index [5 8388000] /Root 1 0 R /Filter /FlateDecode /Length %d >>\nstream\n" % len(zs)) + zs + b"\nendstream\nendobj\n"
body += b"startxref\n%d\n%%%%EOF\n" % x
open(PDIR + "/free.pdf", "wb").write(body)
out, took = pdf_try(PDIR + "/free.pdf")
ok(len(body) < 16 * 1024 and "too many objects" in out and took < 20, "an %d KB PDF whose xref stream lists 8 million free objects is refused as too many objects, within 128 MB: %s" % (len(body) // 1024, out[-120:]))
# Objects that each start a hex string (or a string) that never ends: every read scans to the end of the file
N = 300000
hexpdf = b"%PDF-1.4\n" + b"".join(b"%d 0 obj\n<\n" % i for i in range(1, N))
open(PDIR + "/hex.pdf", "wb").write(hexpdf)
out, took = pdf_try(PDIR + "/hex.pdf", mem=512, timeout=40)
ok("too complex" in out and took < 15, "a %d MB file of objects with unterminated hex strings is refused as too complex in %.1fs: %s" % (len(hexpdf) >> 20, took, out[-120:]))
strpdf = b"%PDF-1.4\n" + b"".join(b"%d 0 obj\n(\n" % i for i in range(1, N))
open(PDIR + "/str.pdf", "wb").write(strpdf)
out, took = pdf_try(PDIR + "/str.pdf", mem=512, timeout=40)
ok("too complex" in out and took < 15, "...and one of unterminated (strings) too, in %.1fs: %s" % (took, out[-120:]))


# =============================================================================================================
# 13. Backup sync: an unreadable list of protected computers keeps them (and their job links); VMs are still pruned
class VeeamProxy(http.server.BaseHTTPRequestHandler):
    def _go(self, method):
        if "computersManagedBy" in self.path:
            self.send_response(403); self.send_header("Content-Type", "application/json"); self.end_headers()
            self.wfile.write(b'{"errors":[{"message":"Forbidden"}]}'); return
        n = int(self.headers.get("Content-Length") or 0)
        r = requests.request(method, M + self.path, data=self.rfile.read(n) if n else None,
                             headers={k: v for k, v in self.headers.items() if k.lower() in ("authorization", "content-type", "accept")})
        self.send_response(r.status_code); self.send_header("Content-Type", r.headers.get("Content-Type", "application/json")); self.end_headers()
        self.wfile.write(r.content)
    def do_GET(self): self._go("GET")
    def do_POST(self): self._go("POST")
    def log_message(self, *a): pass
proxy = http.server.ThreadingHTTPServer(("127.0.0.1", 0), VeeamProxy)
threading.Thread(target=proxy.serve_forever, daemon=True).start()
cleanups.append(proxy.shutdown)
def bsync(): return phpv('echo Align\\Sync\\BackupSync::run(Align\\Providers\\Providers::backup("veeam"), fn($m) => null);')
comp_before = q("select uid from backup_workloads where provider='veeam' and kind='computer' order by uid")
links_before = q("select x.workload_uid, x.job_uid from backup_workload_jobs x join backup_workloads w on w.uid=x.workload_uid where w.kind='computer' order by 1, 2")
for uid, kind in (("computer:auditq-old", "computer"), ("vm:auditq-old", "vm")):
    q("delete from backup_workloads where uid=%s", uid)
    q("insert into backup_workloads (uid, provider, kind, name, synced_at) values (%s, 'veeam', %s, 'Example old machine', now() - interval 2 day)", uid, kind)
cleanups.append(lambda: q("delete from backup_workloads where uid like '%%:auditq-old'"))
VEEAM_URL = q("select value from settings where name='veeam_url'")[0]["value"]
setting("veeam_url", "http://127.0.0.1:%d" % proxy.server_address[1])
out = bsync()
setting("veeam_url", VEEAM_URL)
comp_after = q("select uid from backup_workloads where provider='veeam' and kind='computer' and uid <> 'computer:auditq-old' order by uid")
links_after = q("select x.workload_uid, x.job_uid from backup_workload_jobs x join backup_workloads w on w.uid=x.workload_uid where w.kind='computer' and w.uid <> 'computer:auditq-old' order by 1, 2")
ok(comp_before and comp_after == comp_before and q("select uid from backup_workloads where uid='computer:auditq-old'"),
   "with both computer lists refused (403), every protected computer is kept, even one not seen for days: " + out[-160:])
ok(links_before and links_after == links_before, "...and so are their job links: %s" % [x["workload_uid"] for x in links_after])
ok(not q("select uid from backup_workloads where uid='vm:auditq-old'") and q("select count(*) n from backup_workloads where kind='vm'")[0]["n"] > 0,
   "the virtual machine list was readable, so a VM it no longer lists is pruned")
ok("computer list unavailable" in out, "the sync log says the computer list was unavailable: " + out[-160:])
out = bsync()
ok(not q("select uid from backup_workloads where uid='computer:auditq-old'") and q("select uid from backup_workloads where provider='veeam' and kind='computer' order by uid") == comp_before,
   "once the list is readable again, a computer it doesn't list is pruned as before")


# =============================================================================================================
# 14. The PSA poll is audited when it only changed contacts or licenses
FAKE = r'''
function aq_fake(array $over): Align\Providers\Psa\PsaProvider {
    $iface = Align\Providers\Psa\PsaProvider::class; $inner = Align\Providers\Providers::psa();
    $code = 'return new class($inner, $over) implements \\' . $iface . ' { public function __construct(private $i, private array $o) {}';
    foreach ((new ReflectionClass($iface))->getMethods() as $m) {
        $ps = []; $args = [];
        foreach ($m->getParameters() as $p) {
            $ps[] = ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName() . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
            $args[] = '$' . $p->getName();
        }
        $n = $m->getName(); $a = implode(', ', $args); $rt = $m->getReturnType();
        $code .= " public function $n(" . implode(', ', $ps) . ')' . ($rt ? ': ' . $rt : '')
            . " { return isset(\$this->o['$n']) ? (\$this->o['$n'])(" . ($a !== '' ? "$a, " : '') . "\$this->i) : \$this->i->$n($a); }";
    }
    return eval($code . ' };');
}
'''
def poll(): return cli("psa:poll")
two_way = q("select value from settings where name='psa_two_way'")
setting("psa_two_way", "1")
poll(); poll()
m = maxid(); poll()
quiet = not audits(m, "sync.psa_poll")
ok(quiet, "a PSA poll that changes nothing writes no audit entry (precondition)")
psa1 = q("select psa_id from clients where id=1")[0]["psa_id"]
m = maxid()
r = phpv(FAKE + 'echo Align\\Sync\\PsaAssetSync::run(aq_fake(["contacts" => fn($i) => array_merge($i->contacts(), [["id" => "auditq-77", "client_id" => "' + str(psa1) + '",'
         ' "name" => "Example Auditq Person", "title" => "Office", "department" => "", "email" => "person@auditq.example", "phone" => "", "extension" => "", "mobile" => "",'
         ' "location_id" => null, "primary" => false, "important" => false, "billing" => false, "technical" => false, "notes" => "", "archived" => false]])]));')
a = audits(m, "sync.psa_poll")
ok(q("select id from contacts where psa_id='auditq-77'") and a and "0 device changes, 1 client, contact, vendor or license change" in a[0]["detail"],
   "a poll that only added a contact is audited as sync.psa_poll: " + (a[0]["detail"][:110] if a else r[-120:]))
m = maxid(); poll()
a = audits(m, "sync.psa_poll")
ok(q("select archived_at from contacts where psa_id='auditq-77'")[0]["archived_at"] and a and "vendor or license change" in a[0]["detail"],
   "the next poll archives it (gone from the PSA) and that is audited too")
q("delete from contacts where psa_id='auditq-77'")
lic = q("select psa_id from licenses where psa_id is not null and retired_at is null limit 1")
if lic:
    m = maxid()
    phpv(FAKE + 'echo Align\\Sync\\PsaAssetSync::run(aq_fake(["licenses" => fn($i) => array_values(array_filter($i->licenses(), fn($l) => (string) ($l["id"] ?? "") !== "' + str(lic[0]["psa_id"]) + '"))]));')
    a = audits(m, "sync.psa_poll")
    ok(q("select retired_at from licenses where psa_id=%s", lic[0]["psa_id"])[0]["retired_at"] and a and "1 client, contact, vendor or license change" in a[0]["detail"],
       "a poll that only retired a license is audited: " + (a[0]["detail"][:100] if a else "none"))
    m = maxid(); poll()
    a = audits(m, "sync.psa_poll")
    ok(q("select retired_at from licenses where psa_id=%s", lic[0]["psa_id"])[0]["retired_at"] is None and a and "license change" in a[0]["detail"], "bringing it back is audited too")
else:
    ok(False, "a license from the PSA exists (test data)")
# a changed email on an existing PSA contact (not the client's primary contact details), then changed back
kc = q("select psa_id, email from contacts where source='psa' and psa_id is not null and archived_at is null and is_primary=0 and client_id is not null order by id limit 1")
if kc:
    kc = kc[0]
    m = maxid()
    phpv(FAKE + 'echo Align\\Sync\\PsaAssetSync::run(aq_fake(["contacts" => fn($i) => array_map(fn($c) => (string) $c["id"] === "' + str(kc["psa_id"])
         + '" ? ["email" => "changed@auditq.example"] + $c : $c, $i->contacts())]));')
    a = audits(m, "sync.psa_poll")
    ok(q("select email from contacts where psa_id=%s", kc["psa_id"])[0]["email"] == "changed@auditq.example" and a and "0 device changes, 1 client, contact, vendor or license change" in a[0]["detail"],
       "a poll that only changed an existing PSA contact's email is audited as sync.psa_poll: " + (a[0]["detail"][:100] if a else "none"))
    m = maxid(); poll()
    a = audits(m, "sync.psa_poll")
    ok(q("select email from contacts where psa_id=%s", kc["psa_id"])[0]["email"] == kc["email"] and a and "vendor or license change" in a[0]["detail"],
       "changing it back in the next poll is audited too")
else:
    ok(False, "a PSA contact that isn't a primary contact exists (test data)")
lic = q("select psa_id, seats from licenses where psa_id is not null and retired_at is null order by id limit 1")
if lic:
    m = maxid()
    phpv(FAKE + 'echo Align\\Sync\\PsaAssetSync::run(aq_fake(["licenses" => fn($i) => array_map(fn($l) => (string) ($l["id"] ?? "") === "' + str(lic[0]["psa_id"])
         + '" ? ["seats" => 777] + $l : $l, $i->licenses())]));')
    a = audits(m, "sync.psa_poll")
    ok(q("select seats from licenses where psa_id=%s", lic[0]["psa_id"])[0]["seats"] == 777 and a and "0 device changes, 1 client, contact, vendor or license change" in a[0]["detail"],
       "a poll that only changed a license's seats is audited: " + (a[0]["detail"][:100] if a else "none"))
    m = maxid(); poll()
    a = audits(m, "sync.psa_poll")
    ok(q("select seats from licenses where psa_id=%s", lic[0]["psa_id"])[0]["seats"] == lic[0]["seats"] and a, "changing the seats back is audited too")
m = maxid(); poll()
ok(not audits(m, "sync.psa_poll"), "and a poll that changes nothing still writes no audit entry (the new comparisons don't see changes that aren't there)")


# =============================================================================================================
# 15. Device "push now / pull latest" is audited when two-way sync is on
dv = q("select id, display_name from devices where source='psa' and psa_asset_id is not null and retired_at is null and removed_at is null order by id limit 1")[0]
m = maxid()
tech.post(B + f"/devices/{dv['id']}/push", data={"_csrf": csrf(tech, f"/devices/{dv['id']}")})
ok(audits(m, "device.psa_push", f"%{dv['display_name']}%"), "Push now on a device writes device.psa_push with the device name")
setting("psa_two_way", "0")
m = maxid()
tech.post(B + f"/devices/{dv['id']}/push", data={"_csrf": csrf(tech, f"/devices/{dv['id']}")})
ok(not audits(m, "device.psa_push"), "with two-way sync off nothing ran, so nothing is audited")
setting("psa_two_way", two_way[0]["value"] if two_way else "1")


# =============================================================================================================
# 16. CSV import: the audit entry names the records
def csv_import(kind, text):
    r = tech.post(B + "/clients/import", data={"_csrf": csrf(tech, "/clients/import"), "kind": kind}, files={"file": ("auditq.csv", text.encode(), "text/csv")})
    tok = re.search(r'name="token" value="([a-f0-9]{32})"', r.text)
    if not tok:
        return flash(r.text)
    m = maxid()
    tech.post(B + "/clients/import/run", data={"_csrf": csrf(tech, "/clients/import"), "token": tok.group(1)})
    a = audits(m, "import." + kind)
    return a[0]["detail"] if a else "(no audit entry)"
d = csv_import("clients", "Name,Industry\nExample Importco Auditq,Dental\n")
ok("+Example Importco Auditq" in d, "import.clients lists the client it added: " + d[:120])
d = csv_import("clients", "Name,Industry,Website\nExample Importco Auditq,Veterinary,https://importco.example\n")
ok(re.search(r"Example Importco Auditq \([a-z_, ]*industry", d), "...and a changed client with its fields: " + d[:120])
d = csv_import("contacts", "Client,Name,Email,Title\nExample Importco Auditq,Pat Auditq,pat@auditq.example,Owner\n")
ok("+Example Importco Auditq: Pat Auditq" in d, "import.contacts lists the contact it added, with its client: " + d[:120])
d = csv_import("contacts", "Client,Name,Email,Title\nExample Importco Auditq,Pat Auditq,pat@auditq.example,Manager\n")
ok(re.search(r"Example Importco Auditq: Pat Auditq \([a-z_, ]*title", d), "...and a changed contact with its fields: " + d[:120])


# =============================================================================================================
# 17. Ending a user's sessions also turns off their calendar feed link
SEC_I = "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJR"
IPW = "Copper-Willow-Summit-82"
IE = "auditq-ics@example.com"
iid = mkstaff(IE, SEC_I, IPW)
def ics(): return q("select ics_token from users where id=%s", iid)[0]["ics_token"]
def set_ics(): q("update users set ics_token=%s, ics_created_at=now() where id=%s", hashlib.sha256(b"auditq-feed").hexdigest(), iid)
q("delete from login_attempts")
si, r = slogin(IE, IPW, SEC_I)
set_ics()
r = si.post(B + "/account/password", data={"_csrf": csrf(si, "/account"), "current": IPW, "new": "Copper-Willow-Summit-83", "confirm": "Copper-Willow-Summit-83"})
ok("Password changed" in flash(r.text) and ics() is None, "changing your own password turns off your calendar feed link: " + flash(r.text)[:60])
set_ics()
admin.post(B + f"/users/{iid}", data={"_csrf": csrf(admin, "/users"), "action": "reset"})
ok(ics() is None, "an admin's password reset (which signs the user out everywhere) turns it off")
set_ics()
r = cli("user:reset-password", f"--email={IE}")
ok(r.returncode == 0 and ics() is None, "align user:reset-password turns it off too: " + (r.stdout + r.stderr)[-80:])


# =============================================================================================================
# 18. Framework names: a long one fits the slug column; one of only symbols still gets a slug
long_name = "Example Auditq Framework " + "Long " * 25
long_name = long_name[:150]
r = admin.post(B + "/frameworks", data={"_csrf": csrf(admin, "/frameworks"), "name": long_name})
fw = q("select id, slug, name from compliance_frameworks where name=%s", long_name)
if fw: made["frameworks"].append(fw[0]["id"])
ok(r.status_code == 200 and fw and len(fw[0]["slug"]) <= 60 and fw[0]["slug"].startswith("example-auditq-framework"), "a 150-character framework name is created, its slug cut to fit: " + (fw[0]["slug"] if fw else str(r.status_code)))
r = admin.post(B + "/frameworks", data={"_csrf": csrf(admin, "/frameworks"), "name": "!!! *** ???"})
fw = q("select id, slug from compliance_frameworks where name='!!! *** ???'")
if fw: made["frameworks"].append(fw[0]["id"])
ok(fw and re.fullmatch(r"framework-[0-9a-f]{4}", fw[0]["slug"]), "a name of only symbols gets a framework-xxxx slug: " + (fw[0]["slug"] if fw else "not created"))


# =============================================================================================================
# 19. The QBR's "Decisions" follow the Roadmap switch
q("insert into roadmap_items (client_id, title, category, cost, priority, status, target_quarter) values (2, 'Auditq Proposed Firewall', 'project', 4321, 'high', 'proposed', %s)",
  time.strftime("%Y-%m-01"))
made["roadmap"].append(q("select max(id) id from roadmap_items")[0]["id"])
t = admin.get(B + "/clients/2/report/qbr").text
ok("Decisions &amp; next steps" in t and "Auditq Proposed Firewall" in t, "with the roadmap on, the QBR asks for decisions on proposed projects")
t = admin.get(B + "/clients/2/report/qbr?s_roadmap=0").text
ok("Auditq Proposed Firewall" not in t and "Your team &amp; next steps" in t and "Decisions &amp; next steps" not in t,
   "with s_roadmap=0 the proposed projects are left out and the heading is 'Your team & next steps'")


# =============================================================================================================
# 20. Portal roadmap / QBR: meetings only with the documents & meetings permission
q("insert into meetings (uid, client_id, title, type, status, starts_at, ends_at) values (uuid(), %s, 'Auditq Strategy Session', 'qbr', 'scheduled', now() + interval 1 day, now() + interval 1 day + interval 1 hour)", CX)
made["meetings"].append(q("select max(id) id from meetings")[0]["id"])
RM = "roadmap@auditq.example"; SEC_RM = "NBSWY3DPNBSWY3DPNBSWY3DPNBSWY3DQ"
rmid = mkportal(RM, SEC_RM, client=CX, can_documents=0, can_contacts=0)
rs, r = plogin(RM, SEC_RM)
t = rs.get(B + "/portal/report/roadmap").text
ok(rs.get(B + "/portal/report/roadmap").status_code == 200 and "3-Year" in t and "Auditq Strategy Session" not in t,
   "a roadmap-only portal user's roadmap report shows no meeting titles")
t = rs.get(B + "/portal/report/qbr").text
ok("Auditq Strategy Session" not in t, "...nor does their QBR pack")
q("update portal_users set can_documents=1 where id=%s", rmid)
t = rs.get(B + "/portal/report/roadmap").text
ok("Auditq Strategy Session" in t, "with Documents, contacts & meetings the meeting is in the roadmap timeline")
q("delete from login_attempts")
# The roadmap page lists the devices to replace by name only with the devices permission
RD = "roadmap-dev@auditq.example"; SEC_RD = "NBSWY3DPNBSWY3DPNBSWY3DPNBSWY3DR"
rdid = mkportal(RD, SEC_RD, client=1, can_devices=1)
rd, r = plogin(RD, SEC_RD)
t = rd.get(B + "/portal/roadmap").text
groups = re.findall(r'<details class="rm-item rm-auto border-primary">(.*?)</details>', t, re.S)
names = [H.unescape(x).strip() for g in groups for x in re.findall(r"<li>([^<]+?) <span", g)]
ok(groups and names and re.search(r"Replace \d+ devices?", t), "with Devices, the roadmap page lists the devices to replace by name (%d groups, e.g. %s)" % (len(groups), names[:3]))
q("update portal_users set can_devices=0 where id=%s", rdid)
t = rd.get(B + "/portal/roadmap").text
groups2 = re.findall(r'<details class="rm-item rm-auto border-primary">(.*?)</details>', t, re.S)
ok(len(groups2) == len(groups) and re.search(r"Replace \d+ devices?", t) and names and not any(f"<li>{H2.escape(n)} " in t for n in names) and "<li>" not in "".join(groups2),
   "without Devices it still shows the 'Replace N devices' groups, but no device names")
q("delete from login_attempts")


# =============================================================================================================
# 21. Onboarding contacts: a repeated update is noted once, notes are capped, 30 saves a day per client
setting("psa_two_way", "0")
q("insert into contacts (client_id, source, psa_id, name, title, email) values (%s, 'psa', 'auditq-psa-1', 'Sam Auditq', 'Old title', 'sam@auditq.example')", CX)
oc = requests.Session()
def save_contacts(title, who="Jordan"):
    return oc.post(link + "/contacts", data={"_csrf": csrf_at(oc, "", link), "your_name": who,
                   "contacts_json": json.dumps([{"id": "", "first": "Sam", "last": "Auditq", "email": "sam@auditq.example", "title": title}])})
save_contacts("New title"); save_contacts("New title")
k = q("select title, align_notes from contacts where client_id=%s and email='sam@auditq.example'", CX)[0]
ok(k["title"] == "Old title" and (k["align_notes"] or "").count("Onboarding update from") == 1, "posting the same change to a PSA contact twice notes it once: " + repr((k["align_notes"] or "")[-80:]))
q("update contacts set align_notes=%s where client_id=%s and email='sam@auditq.example'", "x" * 7990, CX)
save_contacts("Newest title")
k = q("select align_notes from contacts where client_id=%s and email='sam@auditq.example'", CX)[0]
notes = k["align_notes"] or ""
ok(len(notes) == 8000 and notes.endswith("title = Newest title"), "the notes keep their last 8,000 characters, newest update included (%d)" % len(notes))
ok(q("select contact_saves n from client_onboardings where client_id=%s", CX)[0]["n"] == 3, "each save is counted on the onboarding (client_onboardings.contact_saves)")
q("update client_onboardings set contact_saves=29, contact_saves_day=curdate() where client_id=%s", CX)
r = save_contacts("Title 30")
ok("Your contacts have been saved" not in flash(r.text), "the 30th save of the day still goes through")
r = save_contacts("Title 31")
k = q("select align_notes from contacts where client_id=%s and email='sam@auditq.example'", CX)[0]
ok("saved many times today" in flash(r.text) and "Title 31" not in (k["align_notes"] or ""), "the 31st save in a day for the client is refused with a message: " + flash(r.text)[:70])
ok(q("select contact_saves n from client_onboardings where client_id=%s", CX)[0]["n"] == 30, "a refused save isn't counted (still 30)")
# Another client whose name starts with this one's plus ": " (the old count matched names with LIKE) has its own count
CXW = CXN + ": West"
q("insert into clients (name, source) values (%s, 'manual')", CXW)
CW = q("select id from clients where name=%s", CXW)[0]["id"]; made["clients"].append(CW)
wlink = new_link(CW)
wc = requests.Session()
r = wc.post(wlink + "/contacts", data={"_csrf": csrf_at(wc, "", wlink), "your_name": "Jordan",
            "contacts_json": json.dumps([{"id": "", "first": "West", "last": "Auditq", "email": "west@auditq.example"}])})
ok("saved many times today" not in flash(r.text) and q("select contact_saves n from client_onboardings where client_id=%s", CW)[0]["n"] == 1,
   "a client named \"<name>: West\" saves while \"<name>\" is at its limit, and has its own count of 1: " + flash(r.text)[:60])
r = save_contacts("Title 32")
ok("saved many times today" in flash(r.text), "...and \"<name>\" is still at its limit")
# A new day starts the count again
q("update client_onboardings set contact_saves=30, contact_saves_day=curdate() - interval 1 day where client_id=%s", CX)
r = save_contacts("Title next day")
row = q("select contact_saves, contact_saves_day = curdate() today from client_onboardings where client_id=%s", CX)[0]
ok("saved many times today" not in flash(r.text) and row["contact_saves"] == 1 and row["today"] == 1, "on a new day (30 saves yesterday) the save goes through and the count starts at 1: %s" % row)

done()
