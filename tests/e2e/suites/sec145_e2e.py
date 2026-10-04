"""1.45 security audit: a regression check for each fix (see docs/SECURITY.md, "Security audit 1.45")."""
from lib import *
import os, io, json, shutil, stat, threading, subprocess, time, struct, zlib, http.server, socketserver

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def cli(*a): return subprocess.run(["php", ALIGN, *a], env=ENV, capture_output=True, text=True)
def new_user(email, role, pw, secret):
    q("delete from users where email=%s", email)
    phpv(f"Align\\DB::insert('users', ['email' => {json.dumps(email)}, 'name' => 'Sec Test', 'role' => {json.dumps(role)}, 'password_hash' => Align\\Security::hashPassword({json.dumps(pw)}),"
         f" 'totp_secret_enc' => Align\\Crypto::encrypt({json.dumps(secret)}), 'totp_enabled' => 1, 'is_active' => 1, 'must_change_password' => 0]);")
    return q("select id from users where email=%s", email)[0]["id"]
def signin(email, pw, secret):
    s = requests.Session()
    s.post(B + "/login", data={"_csrf": csrf(s, "/login"), "email": email, "password": pw})
    s.post(B + "/login/2fa", data={"_csrf": csrf(s, "/login/2fa"), "code": totp(secret)})
    return s
SEC_A = "ONSWG4TFORRWK5DFONSWG4TFORRWK5DF"
SEC_B = "MZXW6YTBOIQHEZLTMZXW6YTBOIQHEZLT"
q("delete from login_attempts")
admin = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)

# ---- audit chain: the start and end markers are sealed (migration 044)
ok(cli("audit:verify").returncode == 0 and q("select seal from audit_chain")[0]["seal"], "audit chain sealed after migrating, and intact")
chain = q("select * from audit_chain")[0]
last2 = q("select id, row_hash from audit_log order by id desc limit 2")
saved = q("select * from audit_log where id=%s", last2[0]["id"])[0]
q("delete from audit_log where id=%s", last2[0]["id"]); q("update audit_chain set last_id=%s, last_hash=%s", last2[1]["id"], last2[1]["row_hash"])
out = cli("audit:verify"); ok(out.returncode == 2 and "start or end" in out.stdout, "newest entry cut off and the head moved to match: detected: " + out.stdout.strip()[:100])
cols = list(saved.keys()); q("insert into audit_log (" + ",".join(cols) + ") values (" + ",".join(["%s"] * len(cols)) + ")", *[saved[c] for c in cols])
q("update audit_chain set last_id=%s, last_hash=%s", chain["last_id"], chain["last_hash"])
first = q("select id, row_hash from audit_log order by id limit 3")
gone = q("select * from audit_log where id <= %s", first[1]["id"])
q("delete from audit_log where id <= %s", first[1]["id"]); q("update audit_chain set anchor_id=%s, anchor_hash=%s", first[1]["id"], first[1]["row_hash"])
out = cli("audit:verify"); ok(out.returncode == 2, "oldest entries cut off and the anchor moved to match: detected: " + out.stdout.strip()[:100])
for g in gone:
    q("insert into audit_log (" + ",".join(g.keys()) + ") values (" + ",".join(["%s"] * len(g)) + ")", *g.values())
q("update audit_chain set anchor_id=%s, anchor_hash=%s", chain["anchor_id"], chain["anchor_hash"])
ok(cli("audit:verify").returncode == 0, "put back: intact again")
q("update audit_chain set seal=NULL"); ok(cli("audit:verify").returncode == 2, "a removed seal is detected too")
q("update audit_chain set seal=%s", chain["seal"]); ok(cli("audit:verify").returncode == 0, "seal back: intact")
phpv("Align\\Audit::log('sec.test', \"a\\x1fb\\x00c\\nd\");")
ok(q("select detail from audit_log order by id desc limit 1")[0]["detail"] == "abc\nd" and cli("audit:verify").returncode == 0, "control characters are dropped from audit entries (the HMAC's field separator can't appear)")

# ---- an outside checkpoint catches an old copy of the chain's markers written back (review of 1.45)
head = json.loads(cli("audit:head").stdout)
ok(cli("audit:checkpoint", f"--id={head['id']}", f"--hash={head['hash']}").returncode == 0, "checkpoint: the head seen last night is still there")
phpv("Align\\Audit::log('sec.test', 'one'); Align\\Audit::log('sec.test', 'two');")
old_chain = q("select * from audit_chain")[0]
newer = q("select * from audit_log where id > %s order by id", head["id"])
phpv("Align\\Audit::log('sec.test', 'evidence');")
ev = q("select * from audit_log order by id desc limit 1")[0]
q("delete from audit_log where id=%s", ev["id"]); q("update audit_chain set last_id=%s, last_hash=%s, seal=%s", old_chain["last_id"], old_chain["last_hash"], old_chain["seal"])
ok(cli("audit:verify").returncode == 0, "(an old copy of the markers written back after removing an entry passes the in-database check)")
ok(cli("audit:checkpoint", f"--id={ev['id']}", f"--hash={ev['row_hash']}").returncode == 2, "...but the outside checkpoint catches it")
ok(q("select id from audit_log where action='audit.checkpoint_failed' and id > %s", ev["id"]), "...and records that in the audit log")
q("delete from audit_log where id > %s", ev["id"])  # (the test undoes its own tampering: that entry was chained after the old markers)
q("insert into audit_log (" + ",".join(ev.keys()) + ") values (" + ",".join(["%s"] * len(ev)) + ")", *ev.values())
phpv("Align\\DB::run('UPDATE audit_chain SET last_id = ?, last_hash = ? WHERE id = 1', [" + str(ev["id"]) + ", '" + ev["row_hash"] + "']); Align\\AuditChain::reseal();")
ok(cli("audit:verify").returncode == 0, "put back: intact")

# ---- the database helper refuses anything but plain names as columns
out = phpv("try { Align\\DB::insert('settings', ['name' => 'x', 'value` = 1, is_secret = 1 -- ' => 'y']); echo 'inserted'; } catch (InvalidArgumentException $e) { echo 'refused'; }")
ok(out == "refused", "DB::insert refuses a column name that isn't a plain name: " + out)
out = phpv("try { Align\\DB::upsert('settings', ['name' => 'x', 'bad name' => 'y'], ['name']); echo 'done'; } catch (InvalidArgumentException $e) { echo 'refused'; }")
ok(out == "refused", "DB::upsert too")

# ---- sign-in: parallel guesses can't get past the lockout
new_user("sec145-a@example.com", "tech", "Sec145-Password-One", SEC_A)
res = []
def guess():
    s = requests.Session(); t = csrf(s, "/login")
    res.append(s.post(B + "/login", data={"_csrf": t, "email": "sec145-a@example.com", "password": "wrong-password-x"}).text)
th = [threading.Thread(target=guess) for _ in range(15)]
[t.start() for t in th]; [t.join() for t in th]
checked = sum(1 for r in res if "Too many" not in r)
ok(checked <= 5, f"15 parallel wrong passwords: at most 5 were checked ({checked}), the rest locked out")
q("delete from login_attempts")
# the same TOTP code in parallel sessions signs in once
sess = []
for _ in range(6):
    s = requests.Session(); s.post(B + "/login", data={"_csrf": csrf(s, "/login"), "email": "sec145-a@example.com", "password": "Sec145-Password-One"}); sess.append((s, csrf(s, "/login/2fa")))
code = totp(SEC_A); wins = []
def use(s, t): wins.append(s.post(B + "/login/2fa", data={"_csrf": t, "code": code}).url.endswith("/"))
th = [threading.Thread(target=use, args=p) for p in sess]
[t.start() for t in th]; [t.join() for t in th]
ok(sum(wins) == 1, f"one TOTP code used by 6 parallel sessions signs in once ({sum(wins)})")
q("delete from login_attempts")

# ---- account: the current-password check is locked out like a sign-in; replacing 2FA needs the old code
a = signin("sec145-a@example.com", "Sec145-Password-One", SEC_A)
for i in range(6):
    r = a.post(B + "/account/password", data={"_csrf": csrf(a, "/account"), "current": "not-it-%d" % i, "new": "Whatever-New-Pass-9", "confirm": "Whatever-New-Pass-9"})
ok("Too many wrong passwords" in flash(r.text), "6 wrong current passwords on the account page: locked out")
q("delete from login_attempts")
r = a.post(B + "/account/2fa", data={"_csrf": csrf(a, "/account"), "action": "begin"})
newsec = re.search(r'<code class="select-all">([A-Z2-7 ]+)</code>', r.text).group(1).replace(" ", "")
ok('name="current_code"' in r.text, "replacing the authenticator asks for a code from the current one")
r = a.post(B + "/account/2fa", data={"_csrf": csrf(a, "/account"), "action": "confirm", "code": totp(newsec), "current_code": "000000"})
ok("current authenticator did not match" in flash(r.text) and phpv("echo Align\\Crypto::decrypt(Align\\DB::value(\"SELECT totp_secret_enc FROM users WHERE email='sec145-a@example.com'\"));") == SEC_A, "a wrong current code: the old authenticator stays")
r = a.post(B + "/account/2fa", data={"_csrf": csrf(a, "/account"), "action": "confirm", "code": totp(newsec), "current_code": totp(SEC_A)})
ok("new authenticator is set up" in flash(r.text), "with both codes it's replaced")
q("delete from login_attempts")

# ---- admin "Remove 2FA" also issues a one-time password
uid = new_user("sec145-b@example.com", "tech", "Sec145-Password-Two", SEC_B)
r = admin.post(B + f"/users/{uid}", data={"_csrf": csrf(admin, "/users"), "action": "reset_2fa"})
u = q("select * from users where id=%s", uid)[0]
temp = re.search(r'<code class="h5 select-all">([^<]+)</code>', r.text)
ok(u["totp_enabled"] == 0 and u["must_change_password"] == 1 and temp, "Remove 2FA: one-time password shown, must be changed")
s = requests.Session(); r = s.post(B + "/login", data={"_csrf": csrf(s, "/login"), "email": "sec145-b@example.com", "password": "Sec145-Password-Two"})
ok("/account" not in r.url and s.get(B + "/account", allow_redirects=False).status_code == 302, "the old password no longer works, so whoever knew it can't enrol their own authenticator")
q("delete from login_attempts")

my = q("select id from users where email='admin@example.com'")[0]["id"]
r = admin.post(B + f"/users/{my}", data={"_csrf": csrf(admin, "/users"), "action": "reset_2fa"})
ok("on your Account page" in flash(r.text) and q("select totp_enabled from users where id=%s", my)[0]["totp_enabled"] == 1, "an admin can't Remove 2FA from their own account (it would sign them out before the password is shown)")

# ---- half signed-in (temporary password): the page shows no client or staff lists
s = requests.Session(); r = s.post(B + "/login", data={"_csrf": csrf(s, "/login"), "email": "sec145-b@example.com", "password": H.unescape(temp.group(1))})
t = r.text
cname = q("select name from clients where is_archived=0 and planning_excluded=0 order by name limit 1")[0]["name"]
ok("Set a new password" in H.unescape(t) and H.escape(cname) not in t and "meeting-modal" not in t, "before the password is changed and 2FA set up, pages carry no client names or meeting form")

# ---- documents: deleting (and its whole history) is for admins
did = q("select id from documents order by id limit 1")[0]["id"]
r = tech.post(B + f"/documents/{did}/delete", data={"_csrf": csrf(tech, f"/documents/{did}"), "confirm": "DELETE"})
ok(r.status_code == 403 and q("select id from documents where id=%s", did), "a tech can't delete a document")
ok("Only an admin can delete it" in tech.get(B + f"/documents/{did}").text, "...and the page says to archive it instead")

# ---- views of client records are in the audit log
n = len(q("select id from audit_log where action='view.devices'"))
tech.get(B + "/clients/2/devices"); tech.get(B + "/contacts"); tech.get(B + "/clients/2/compliance")
ok(len(q("select id from audit_log where action='view.devices'")) > n and q("select id from audit_log where action='view.contacts' and detail like 'all clients%%'")
   and q("select id from audit_log where action='view.compliance' and detail like '#2 %%'"), "device list, all-contacts and compliance views are logged")

# ---- the brand logo is re-encoded (only pixels are published)
def png(w, h):
    raw = b"".join(b"\x00" + b"\x10\x80\xc0\xff" * w for _ in range(h))
    ch = lambda t, d: struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + ch(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 6, 0, 0, 0)) + ch(b"tEXt", b"Comment\x00SECRET-IN-METADATA") + ch(b"IDAT", zlib.compress(raw)) + ch(b"IEND", b"")
prev_logo = q("select value from settings where name='brand_logo'")
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding")}, files={"logo": ("logo.png", png(40, 40) + b"APPENDED-SECRET", "image/png")})
name = q("select value from settings where name='brand_logo'")[0]["value"]
data = open(UPLOADS + "/" + name, "rb").read() if name and os.path.exists(UPLOADS + "/" + name) else b""
ok(data.startswith(b"\x89PNG") and b"SECRET" not in data, "uploaded logo re-encoded: metadata and appended bytes are gone")
if name and os.path.exists(UPLOADS + "/" + name): os.unlink(UPLOADS + "/" + name)
q("delete from settings where name='brand_logo'")
if prev_logo and prev_logo[0]["value"]: q("insert into settings (name, value, is_secret) values ('brand_logo', %s, 0)", prev_logo[0]["value"])

# ---- integrations: a saved key only goes to the server it was entered for
old_url = q("select value from settings where name='itflow_url'")[0]["value"]
r = admin.post(B + "/integrations/itflow", data={"_csrf": csrf(admin, "/integrations/itflow"), "itflow_url": "https://attacker.example"})
ok("Enter the API key again" in flash(r.text) and q("select value from settings where name='itflow_url'")[0]["value"] == old_url, "changing the ITFlow address to another server needs the key typed again")
r = admin.post(B + "/integrations/itflow", data={"_csrf": csrf(admin, "/integrations/itflow"), "itflow_url": old_url + "/"})
ok("Enter the API key again" not in flash(r.text), "the same server (a trailing slash) is fine")

# ---- the HTTP client refuses a response larger than its limit (no Content-Length either)
class Big(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.end_headers()
        chunk = b" " * (1 << 20)
        try:
            for _ in range(140): self.wfile.write(chunk)
        except Exception: pass
    def log_message(self, *a): pass
srv = socketserver.TCPServer(("127.0.0.1", 8097), Big); threading.Thread(target=srv.serve_forever, daemon=True).start()
out = phpv("try { (new Align\\Http\\HttpClient(60, 1))->request('GET', 'http://127.0.0.1:8097/'); echo 'read'; } catch (Align\\Http\\HttpException $e) { echo $e->getMessage(); }")
srv.shutdown(); srv.server_close()
ok("larger than 128 MB" in out, "a 140 MB response is refused: " + out[:80])

# ---- portal: QBR contacts need the contacts permission; resets clear the password; links from forgot use the configured name
q("delete from portal_users where email='sec145@client.example'")
cid = 1
r = tech.post(B + f"/clients/{cid}/portal", data={"_csrf": csrf(tech, f"/clients/{cid}/portal"), "email": "sec145@client.example", "name": "Sec Portal", "send_email": "0",
    "can_devices": "1", "can_roadmap": "1"})
link = H.unescape(re.search(r'id="portal-link" value="([^"]+)"', r.text).group(1))
p = requests.Session(); p.post(link, data={"_csrf": csrf(p, link.replace(B, "")), "password": "Cobalt-River-Stone-42!", "confirm": "Cobalt-River-Stone-42!"})
PSEC = "KVKFKRCPNZQUYMLXOVYDSQKJKZDTSRLD"
p.post(B + "/portal/account/2fa", data={"_csrf": csrf(p, "/portal/account"), "action": "begin"})
q("update portal_users set totp_secret_enc=%s, totp_enabled=1 where email='sec145@client.example'", phpv(f"echo Align\\Crypto::encrypt('{PSEC}');"))
q("update portal_users set can_documents=0, can_devices=1 where email='sec145@client.example'")
p = requests.Session(); p.post(B + "/portal/login", data={"_csrf": csrf(p, "/portal/login"), "email": "sec145@client.example", "password": "Cobalt-River-Stone-42!"})
p.post(B + "/portal/login/2fa", data={"_csrf": csrf(p, "/portal/login/2fa"), "code": totp(PSEC)})
t = p.get(B + "/portal/report/qbr").text
kc = q("select name from contacts where client_id=%s and archived_at is null and (qbr=1 or decision_maker=1 or is_primary=1) limit 1", cid)
ok(p.get(B + "/portal", allow_redirects=False).status_code == 200 and "Key contacts" not in t and (not kc or H.escape(kc[0]["name"]) not in t), "portal QBR without the contacts permission: no key contacts or meetings")
puid = q("select id from portal_users where email='sec145@client.example'")[0]["id"]
q("update portal_users set invite_token_hash=%s, invite_expires_at=now() + interval 1 day where id=%s", "a" * 64, puid)
p.post(B + "/portal/account/password", data={"_csrf": csrf(p, "/portal/account"), "current": "Cobalt-River-Stone-42!", "new": "Maple-Harbor-Lamp-88!", "confirm": "Maple-Harbor-Lamp-88!"})
ok(q("select invite_token_hash from portal_users where id=%s", puid)[0]["invite_token_hash"] is None, "a portal password change voids any outstanding reset link")
r = tech.post(B + f"/portal-users/{puid}", data={"_csrf": csrf(tech, f"/clients/{cid}/portal"), "action": "reset2fa", "send_email": "0"})
pu = q("select * from portal_users where id=%s", puid)[0]
ok(pu["totp_enabled"] == 0 and pu["password_hash"] is None and 'id="portal-link"' in r.text, "portal 2FA reset clears the password and gives a new link")
out = phpv("$_SERVER['HTTP_HOST'] = 'evil.example'; echo Align\\Portal\\PortalAuth::baseUrl();")
ok("evil.example" not in out and out == "https://align.test", "without base_url, links for anyone but signed-in staff use the configured name, never the Host header: " + out)
q("delete from portal_users where id=%s", puid)

# ---- REST API
for t_ in ["api_keys", "api_requests", "api_idempotency", "api_rate", "api_ip_rate"]: q(f"delete from {t_}")
q("update settings set value='1' where name='api_enabled'")
API = B + "/api/v1"
def mk(name, scopes, clients=None, created_by=1):
    return phpv(f'[$i,$t]=Align\\Api\\Keys::create({json.dumps(name)}, json_decode({json.dumps(json.dumps(scopes))},true), {("json_decode("+json.dumps(json.dumps(clients))+",true)") if clients is not None else "null"}, null, 5000, null, {created_by}); echo $t;')
def call(k, m, path, body=None):
    return requests.request(m, API + path, headers={"Authorization": "Bearer " + k, **({"Content-Type": "application/json"} if body is not None else {})}, data=json.dumps(body) if body is not None else None)
lim = mk("sec145 limited", ["meetings:read", "meetings:write"], clients=[1])
mid = q("select id from meetings where client_id=1 order by id limit 1")[0]["id"]
q("update meetings set invites_sent_at=now(), status='scheduled', attendees='someone@client.example' where id=%s", mid)
r = call(lim, "PATCH", f"/meetings/{mid}", {"title": "Urgent: reset your password at evil.example"})
ok(r.status_code == 422 and "title" in r.json()["error"].get("fields", {}), "client-limited key: can't reword a meeting whose invitations went out")
r = call(lim, "PATCH", f"/meetings/{mid}", {"status": "cancelled"}); ok(r.status_code == 422, "...or cancel it (the cancellation email comes from a staff mailbox)")
r = call(lim, "DELETE", f"/meetings/{mid}"); ok(r.status_code == 422 and q("select id from meetings where id=%s", mid), "...or delete it")
r = call(lim, "PATCH", f"/meetings/{mid}", {"notes": "fine"}); ok(r.status_code == 200, "notes are still fine")
r = call(lim, "PATCH", f"/meetings/{mid}", {"status": "completed"}); r = call(lim, "DELETE", f"/meetings/{mid}")
ok(r.status_code == 422 and q("select id from meetings where id=%s", mid), "...and marking it completed first doesn't make it deletable")
q("update meetings set status='scheduled' where id=%s", mid)
q("update meetings set invites_sent_at=NULL where id=%s", mid)
tid = q("select id from users where email='tech@example.com'")[0]["id"]
q("update users set role='admin' where id=%s", tid)
tk = mk("sec145 by tech", ["clients:read"], created_by=tid)
ok(call(tk, "GET", "/clients").status_code == 200, "a key made by an admin works")
q("update users set role='tech' where id=%s", tid)
ok(call(tk, "GET", "/clients").json()["error"]["code"] == "key_owner_inactive", "...and stops when that person is no longer an admin")
codes = [requests.get(API + "/openapi.json").status_code for _ in range(34)]
ok(codes[-1] == 429 and codes[0] == 200, "anonymous requests (the spec, unknown paths) are rate limited per address like failed ones")
q("delete from api_ip_rate")

# ---- onboarding: a finished onboarding's link stops a week later; typed-in requesters are marked unverified
q("update client_onboardings set token_expires_at = now() + interval 60 day, completed_at=NULL where client_id=1")
if q("select id from client_onboardings where client_id=1"):
    tech.post(B + "/clients/1/onboarding/status", data={"_csrf": csrf(tech, "/clients/1/onboarding"), "action": "complete"})
    ok(q("select token_expires_at <= now() + interval 7 day + interval 1 minute as soon from client_onboardings where client_id=1")[0]["soon"] == 1, "marking onboarding complete shortens the link to 7 days")

# ---- printed reports: a client name can't break out of the CSS footer string
q("update clients set name=concat(name, %s) where id=3", "\r}body{display:none}")
rr = admin.get(B + "/clients/3/report/assets"); t = rr.text if rr.status_code == 200 else ""
style = "".join(re.findall(r"<style[^>]*>(.*?)</style>", t, re.S))
ok(t and "\r}body{display:none}" not in style, "a carriage return in a client name doesn't end the footer's CSS string")
q("update clients set name=replace(name, %s, '') where id=3", "\r}body{display:none}")

# ---- root agent: never follows a symlink the web user planted in its data folder
AT = WORK + "/sec145-agent"; shutil.rmtree(AT, ignore_errors=True)
for d in ["data", "agent", "run/requests", "target"]: os.makedirs(AT + "/" + d)
os.chmod(WORK, 0o755); os.chmod(AT, 0o755); os.chmod(AT + "/data", 0o777)
os.makedirs(AT + "/agent/safety"); open(AT + "/agent/safety/20260101-000000-abcdef.tar", "w").write("x"); os.utime(AT + "/agent/safety/20260101-000000-abcdef.tar", (time.time() - 20 * 86400,) * 2)
open(AT + "/target/old-file", "w").write("keep"); os.utime(AT + "/target/old-file", (time.time() - 3 * 86400,) * 2); os.chmod(AT + "/target", 0o700)
os.symlink(AT + "/target", AT + "/data/downloads"); os.symlink(AT + "/target", AT + "/data/restore")
env = dict(ENV, PATH="/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin", ALIGN_APP_DIR=ROOT, ALIGN_DATA_DIR=AT + "/data", ALIGN_AGENT_DIR=AT + "/agent", ALIGN_RUN_DIR=AT + "/run", ALIGN_RUNAS="nobody", ALIGN_SYSTEMCTL="none",
           ALIGN_RECIPIENT=AT + "/none.txt")
r = subprocess.run(["php", ROOT + "/scripts/agent.php", "run"], env=env, capture_output=True, text=True, timeout=120)
ok(r.returncode == 0 and "Error" not in r.stderr and not os.path.exists(AT + "/agent/safety/20260101-000000-abcdef.tar"), "the agent's clean-up runs (an old safety copy removed): " + r.stderr[-150:])
st_ = os.stat(AT + "/target")
ok(st_.st_uid == 0 and stat.S_IMODE(st_.st_mode) == 0o700 and os.path.exists(AT + "/target/old-file"),
   "a symlinked downloads/restore folder: its target isn't handed to the web user or emptied (" + oct(stat.S_IMODE(st_.st_mode)) + ", uid " + str(st_.st_uid) + ") " + r.stderr[-200:])
shutil.rmtree(AT, ignore_errors=True)

q("delete from users where email like 'sec145-%%'"); q("delete from login_attempts")
done()
