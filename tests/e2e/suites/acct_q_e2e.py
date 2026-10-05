"""2.2.1 review, batch 5 (accounts, users, setup, settings, system pages and core helpers): a regression check for
each fix in AuthController, UserController, SettingsController, SystemController, ApiSettingsController,
AuditController, Demo, System\\Agent, System\\Tar, View, Migrator, Paging and the staff layout / help views."""
import atexit, os, json, time, socket, threading, subprocess, urllib.parse
import pymysql
from lib import *
import sitecustomize

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def cli(*a): return subprocess.run(["php", ALIGN, *a], env=ENV, capture_output=True, text=True)
def maxid(): return q("select coalesce(max(id), 0) m from audit_log")[0]["m"]
SERVER_LOG = WORK + "/server-8080.log"
def log_count(s): return open(SERVER_LOG, errors="replace").read().count(s) if os.path.exists(SERVER_LOG) else 0

KEYS = ("remember_2fa_days", "company_name", "company_email", "demo_clients", "warranty_warn_days", "cost_laptop", "backup_last_download", "backup_last_download_by")
saved = q("select * from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
TMP = WORK + "/acctq"
os.makedirs(TMP, exist_ok=True)
def restore():
    q("delete from settings where name in (" + ",".join(["%s"] * len(KEYS)) + ")", *KEYS)
    for r in saved:
        q("insert into settings (name,value,is_secret) values (%s,%s,%s)", r["name"], r["value"], r["is_secret"])
    q("delete from users where email like 'acctq-%%'")
    q("delete from os_support where label like 'Acctq%%'")
    q("delete from api_keys where name like 'acctq%%'")
atexit.register(restore)
q("delete from login_attempts")
admin = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)

# ---- sign-out: only a signed-in session writes a "logout" audit entry
m = maxid()
anon = requests.Session()
r = anon.post(B + "/logout", data={"_csrf": csrf(anon, "/login")})
ok(r.url.endswith("/login") and not q("select id from audit_log where id > %s and action = 'logout'", m),
   "an anonymous POST /logout (token from the sign-in page) adds nothing to the audit log")
t2 = login("tech@example.com", TECH_PASSWORD)
m = maxid()
t2.post(B + "/logout", data={"_csrf": csrf(t2, "/account")})
tid = q("select id from users where email = 'tech@example.com'")[0]["id"]
ok(q("select id from audit_log where id > %s and action = 'logout' and user_id = %s", m, tid), "signing out a real session is still audited, with the user")

# ---- settings: whole-number settings are stored as the whole number that is used
setting("remember_2fa_days", "14")
aid = q("select id, session_version from users where email = 'admin@example.com'")[0]
q("delete from remembered_browsers where token_hash = %s", "a" * 64)
q("insert into remembered_browsers (kind, user_id, token_hash, session_version, created_at, expires_at, last_used_at, ip) values ('staff', %s, %s, %s, now(), now() + interval 10 day, now(), '127.0.0.1')",
  aid["id"], "a" * 64, aid["session_version"])
r = admin.post(B + "/settings", data={"_csrf": csrf(admin, "/settings"), "_tab": "general", "remember_2fa_days": "0.4"})
v = q("select value from settings where name = 'remember_2fa_days'")[0]["value"]
ok(v == "0" and not q("select id from remembered_browsers where token_hash = %s", "a" * 64),
   f"remember days 0.4 is saved as 0 (what is used) and so forgets every remembered browser: {v}")
ok("forgotten" in (q("select detail from audit_log where action = 'settings.remember_2fa' order by id desc limit 1") or [{"detail": ""}])[0]["detail"], "...and the audit log says so")
admin.post(B + "/settings", data={"_csrf": csrf(admin, "/settings"), "_tab": "planning", "warranty_warn_days": "90.9"})
ok(q("select value from settings where name = 'warranty_warn_days'")[0]["value"] == "90", "other day counts are whole numbers too (90.9 -> 90, as Settings::int reads it)")
admin.post(B + "/settings", data={"_csrf": csrf(admin, "/settings"), "_tab": "planning", "cost_laptop": "1234.5"})
ok(q("select value from settings where name = 'cost_laptop'")[0]["value"] == "1234.5", "costs keep their cents")
setting("remember_2fa_days", "14")

# ---- settings: the company email is checked and text is cut to length
before = q("select value from settings where name = 'company_email'")
r = admin.post(B + "/settings", data={"_csrf": csrf(admin, "/settings"), "_tab": "general", "company_email": "not an address"})
ok("must be an email address" in flash(r.text) and q("select value from settings where name = 'company_email'") == before,
   "Settings → General refuses a company email that isn't an address, as the setup wizard does")
admin.post(B + "/settings", data={"_csrf": csrf(admin, "/settings"), "_tab": "general", "company_name": "Example MSP " + "x" * 300})
ok(len(q("select value from settings where name = 'company_name'")[0]["value"]) == 190, "a 300-character company name is cut to 190")

# ---- OS support dates: bad dates, long builds and lists in fields are skipped, never a server error
row = q("select id from os_support order by id limit 1")[0]["id"]
r = admin.post(B + "/settings/os", data={"_csrf": csrf(admin, "/settings/os"), "new[label]": "Acctq 26H9", "new[name_contains]": "Windows 11",
                                         "new[build]": "26999", "new[eos_date]": "2026-02-31"})
ok(r.status_code == 200 and "New row skipped" in flash(r.text) and not q("select id from os_support where label = 'Acctq 26H9'"), "a date that doesn't exist (Feb 31) is refused, not a server error")
r = admin.post(B + "/settings/os", data={"_csrf": csrf(admin, "/settings/os"), "new[label]": "Acctq long", "new[name_contains]": "Windows 11",
                                         "new[build]": "1" * 25, "new[eos_date]": "2030-01-01"})
ok(r.status_code == 200 and not q("select id from os_support where label = 'Acctq long'"), "a 25-digit build is refused (the column holds 20)")
was = q("select * from os_support where id = %s", row)[0]
r = admin.post(B + "/settings/os", data={"_csrf": csrf(admin, "/settings/os"), f"rows[{row}][label][]": "x", f"rows[{row}][name_contains]": "Windows",
                                         f"rows[{row}][build]": "1", f"rows[{row}][eos_date]": "2030-01-01"})
ok(r.status_code == 200 and "not saved" in flash(r.text) and q("select * from os_support where id = %s", row)[0] == was, "a field sent as a list skips the row (was a TypeError) and says so")
r = admin.post(B + "/settings/os", data={"_csrf": csrf(admin, "/settings/os"), "new[label]": "Acctq 26H8", "new[name_contains]": "Windows 11",
                                         "new[build]": "26998", "new[eos_date]": "2030-02-28"})
ok(q("select id from os_support where label = 'Acctq 26H8'") and "1 added" in q("select detail from audit_log where action = 'settings.os_support' order by id desc limit 1")[0]["detail"],
   "a valid row is still added, and the audit entry says what changed")

# ---- API keys: an expiry date that doesn't exist is refused
r = admin.post(B + "/settings/api/keys", data={"_csrf": csrf(admin, "/settings/api"), "name": "acctq bad date", "preset": "read_all", "client_scope": "all",
                                               "expires": "date", "expires_on": "2027-02-30", "rate_limit": "60"})
ok(r.status_code == 200 and "Pick when the key expires" in flash(r.text) and not q("select id from api_keys where name = 'acctq bad date'"),
   "API key expiring on Feb 30: refused with a message (was a server error)")

# ---- users: a name or email longer than the column, or with control characters, is refused
r = admin.post(B + "/users", data={"_csrf": csrf(admin, "/users"), "name": "N" * 200, "email": "acctq-long@example.com", "role": "tech"})
ok(r.status_code == 200 and "Enter a name" in flash(r.text) and not q("select id from users where email = 'acctq-long@example.com'"), "a 200-character name is refused (was a server error)")
r = admin.post(B + "/users", data={"_csrf": csrf(admin, "/users"), "name": "Line\none", "email": "acctq-ctl@example.com", "role": "tech"})
ok(not q("select id from users where email = 'acctq-ctl@example.com'"), "a name with a line break is refused")
r = admin.post(B + "/users", data={"_csrf": csrf(admin, "/users"), "name": "Acctq Example", "email": "acctq-ok@example.com", "role": "viewer"})
ok(q("select must_change_password from users where email = 'acctq-ok@example.com'") == [{"must_change_password": 1}] and "Temporary password for acctq-ok@example.com" in r.text,
   "a normal new user is still created, with a one-time password shown once")

# ---- audit log: a huge page number and LIKE wildcards
r = admin.get(B + "/audit?page=99999999999999999999")
ok(r.status_code == 200 and "Audit log" in r.text, "a huge ?page= is capped (was invalid SQL and a server error)")
phpv("Align\\Audit::log('acctq.test', 'acctq a_b'); Align\\Audit::log('acctq.test', 'acctq axb');")
t = admin.get(B + "/audit", params={"q": "acctq a_b"}).text
ok("acctq a_b" in t and "acctq axb" not in t, "searching for a_b finds a_b only (_ is no longer a wildcard)")
r = admin.get(B + "/audit", params={"q": "100%"})
ok(r.status_code == 200 and "acctq a_b" not in r.text, "a search for 100% runs and matches only a literal %")

# ---- demo data: removing it touches only the records made for the demo clients
q("delete from clients where name = 'Acctq Demo Example'")
q("insert into clients (name, source, is_demo) values ('Acctq Demo Example', 'manual', 1)")
cid = q("select id from clients where name = 'Acctq Demo Example'")[0]["id"]
setting("demo_clients", json.dumps([cid]))
for uid in ("demo-acctq-co", f"demo-co-{cid}"):
    q("delete from backup_companies where uid = %s", uid)
    q("insert into backup_companies (provider, uid, name, synced_at) values ('veeam', %s, 'Example Co', now())", uid)
for uid, co in (("demo-acctq-job", "demo-acctq-co"), (f"demo-job-{cid}-srv", f"demo-co-{cid}")):
    q("delete from backup_jobs where uid = %s", uid); q("delete from backup_job_runs where job_uid = %s", uid)
    q("insert into backup_jobs (uid, provider, company_uid, source, name, synced_at) values (%s, 'veeam', %s, 'server', 'Example job', now())", uid, co)
    q("insert into backup_job_runs (job_uid, run_at, company_uid, status) values (%s, now(), %s, 'success')", uid, co)
q("delete from backup_workloads where uid = 'demo-acctq-wl'")
q("insert into backup_workloads (uid, provider, company_uid, kind, name, synced_at) values ('demo-acctq-wl', 'veeam', 'demo-acctq-co', 'vm', 'Example VM', now())")
out = phpv("echo Align\\Demo\\Demo::remove();")
ok(out == "1" and not q("select id from clients where id = %s", cid) and not q("select uid from backup_companies where uid = %s", f"demo-co-{cid}")
   and not q("select uid from backup_jobs where uid = %s", f"demo-job-{cid}-srv"), "demo remove deletes the demo client and its own backup records: " + out)
ok(q("select uid from backup_companies where uid = 'demo-acctq-co'") and q("select uid from backup_jobs where uid = 'demo-acctq-job'")
   and q("select job_uid from backup_job_runs where job_uid = 'demo-acctq-job'") and q("select uid from backup_workloads where uid = 'demo-acctq-wl'"),
   "...but keeps backup records of anyone else whose id merely starts with demo-")
q("delete from backup_companies where uid = 'demo-acctq-co'"); q("delete from backup_jobs where uid = 'demo-acctq-job'")
q("delete from backup_job_runs where job_uid = 'demo-acctq-job'"); q("delete from backup_workloads where uid = 'demo-acctq-wl'")

# ---- uploaded backups: the manifest's fields are never trusted to be text, and tar numbers must be octal
out = phpv(f"""$d = {json.dumps(TMP)};
file_put_contents("$d/manifest.json", json_encode(['format' => 1, 'version' => ['9.9.9'], 'host' => ['x'], 'created' => '2026-01-01T00:00:00', 'uploads_files' => [1, 2]]));
file_put_contents("$d/db", str_repeat('a', 700)); @unlink("$d/b.tar");
Align\\System\\Tar::write("$d/b.tar", ['manifest.json' => "$d/manifest.json", 'db.sql.gz.age' => "$d/db"]);
echo json_encode(Align\\System\\Agent::describeUpload("$d/b.tar"));""")
try:
    info = json.loads(out.splitlines()[-1])
except Exception:
    info = {}
ok(info.get("kind") == "bundle" and info.get("version") == "" and info.get("host") == "" and info.get("uploads_files") == 0,
   "a manifest with lists for its text fields gives empty values (was 'Array' and a PHP warning): " + out[-160:])
out = phpv(f"""$d = {json.dumps(TMP)}; $t = file_get_contents("$d/b.tar"); $m = Align\\System\\Tar::members("$d/b.tar"); $o = $m['db.sql.gz.age']['offset'] - 512;
$h = substr($t, $o, 512); $h = substr_replace($h, "0000000127x4", 124, 12); $h = substr_replace($h, '        ', 148, 8);
$s = 0; for ($i = 0; $i < 512; $i++) $s += ord($h[$i]);
$h = substr_replace($h, str_pad(decoct($s), 6, '0', STR_PAD_LEFT) . "\\0 ", 148, 8); file_put_contents("$d/c.tar", substr_replace($t, $h, $o, 512));
try {{ Align\\System\\Tar::members("$d/c.tar"); echo 'accepted'; }} catch (RuntimeException $e) {{ echo 'refused: ' . $e->getMessage(); }}""")
ok(out.endswith("refused: This is not an MSP Align backup (bad tar header)."), "a tar size field with a non-octal character is refused (octdec skipped it): " + out[-120:])

# ---- backup download cut off part-way: audited as interrupted, and the backup kept so it can be downloaded again
jid = "20260101-000000-acc0a1"
os.makedirs(SYS + "/agent/jobs", exist_ok=True); os.makedirs(SYS + "/data/downloads", exist_ok=True)
jf, df = f"{SYS}/agent/jobs/{jid}.json", f"{SYS}/data/downloads/{jid}.tar"
json.dump({"id": jid, "action": "backup", "state": "succeeded", "step": "Done", "user": "Example Admin", "started": "2026-01-01 00:00:00", "finished": "2026-01-01 00:01:00",
           "result": {"filename": "msp-align-backup-acctq.tar", "size": 64 << 20, "sha256": "0" * 64}}, open(jf, "w"))
with open(df, "wb") as f:
    f.truncate(64 << 20)  # 64 MB (sparse): far more than the socket buffers hold
m = maxid()
tok = csrf(admin, "/settings/system")
u = urllib.parse.urlparse(B)
body = urllib.parse.urlencode({"_csrf": tok})
req = (f"POST /settings/system/download/{jid} HTTP/1.1\r\nHost: {u.netloc}\r\nCookie: " + "; ".join(f"{c.name}={c.value}" for c in admin.cookies)
       + f"\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: {len(body)}\r\nConnection: close\r\n\r\n{body}")
sk = socket.create_connection((u.hostname, u.port or 80)); sk.sendall(req.encode()); first = sk.recv(65536); sk.close()
for _ in range(40):
    if q("select id from audit_log where id > %s and action like 'backup.download%%' and detail like %s", m, "msp-align-backup-acctq.tar%"):
        break
    time.sleep(0.25)
ok(b"application/x-tar" in first and os.path.exists(df), "a download the browser abandoned after the first bytes keeps the backup, to try again")
ok(q("select id from audit_log where id > %s and action = 'backup.download_interrupted' and detail like %s", m, "msp-align-backup-acctq.tar%")
   and not q("select id from audit_log where id > %s and action = 'backup.downloaded' and detail like %s", m, "msp-align-backup-acctq.tar%"),
   "...and the audit log says it was interrupted, not downloaded")
for f in (jf, df):
    if os.path.exists(f):
        os.remove(f)

# ---- arrays where a string is expected: no PHP warnings
n0 = log_count("Array to string conversion")
admin.get(B + "/settings/system", params={"job[]": "x"})
admin.get(B + "/clients", params={"q[]": "x"})
ok(log_count("Array to string conversion") == n0, "?job[]= on Updates & backups and ?q[]= in the top search bar make no PHP warning")

# ---- help: the What's new list shows its formatting instead of escaped tags
t = admin.get(B + "/help").text
ok("&lt;b&gt;" not in t and "A new <b>Onboarding</b> section" in t, "What's new shows <b> as bold, not as text")

# ---- View: names are plain paths under views/, and a failing view leaves no buffer behind
out = phpv("try { Align\\View::fetch('../db/migrations/003_compliance_frameworks'); echo 'ran'; } catch (InvalidArgumentException $e) { echo 'refused'; }")
ok(out.endswith("refused"), "View::fetch refuses ../ in a view name (it included any PHP file under the app): " + out[-60:])
out = phpv("try { Align\\View::fetch('settings/api_key', []); } catch (Throwable $e) { echo get_class($e) . ' '; } echo 'level=' . ob_get_level();")
ok("TypeError level=0" in out, "a view that throws closes its output buffer (its half page was printed later): " + out[-60:])
out = phpv("echo strlen(Align\\View::fetch('error', ['title' => 'T', 'message' => 'Acctq message', 'name' => 'x', 'file' => 'y']));")
ok(out.isdigit() and int(out) > 0, "views still get their variables (a var called name or file no longer clashes with fetch()'s own)")

# ---- Paging: a search word "0" counts
out = phpv("echo count(Align\\Paging::search([['n' => 'a0'], ['n' => 'b']], '0', ['n'])), count(Align\\Paging::search([['n' => 'a'], ['n' => 'b']], '  ', ['n']));")
ok(out == "12", "searching for 0 finds only rows with a 0 (it showed every row); blank still shows all: " + out)

# ---- Migrator: a second `align migrate` waits for one already running
lockdb = pymysql.connect(unix_socket=SOCKET, user="root", database=DB_MAIN, autocommit=True)
import hashlib
MLOCK = "msp_align_migrate:" + hashlib.sha256(DB_MAIN.encode()).hexdigest()[:32]  # named per database
with lockdb.cursor() as c:
    c.execute("SELECT GET_LOCK(%s, 0)", (MLOCK,))
def release():
    with lockdb.cursor() as c:
        c.execute("SELECT RELEASE_LOCK(%s)", (MLOCK,))
threading.Timer(3, release).start()
t0 = time.time(); out = cli("migrate"); took = time.time() - t0
ok(took >= 2.5 and out.returncode == 0 and "up to date" in out.stdout, f"migrate waits for the one holding the lock ({took:.1f}s), then finds nothing to do")
lockdb.close()

done()
