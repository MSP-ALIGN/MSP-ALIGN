#!/usr/bin/env python3
"""Smoke test for the Docker image (1.44), run against a stack started with compose.yaml.

    ALIGN_TEST_URL=http://127.0.0.1:8080 ALIGN_ADMIN_EMAIL=... ALIGN_ADMIN_PASSWORD=... \
    DC="docker compose -f compose.yaml" python3 tests/docker/smoke.py

Signs in (setting up 2FA the way a new admin does), checks the pages and the Docker wording, makes a backup through
the agent, restarts the container and checks the data and the encryption key survived, then restores the backup.
Exits non-zero on the first failure summary.
"""
import base64, hashlib, hmac, io, json, os, re, shlex, struct, subprocess, sys, tarfile, time

import requests

B = os.environ.get("ALIGN_TEST_URL", "http://127.0.0.1:8080").rstrip("/")
EMAIL = os.environ["ALIGN_ADMIN_EMAIL"]
PASSWORD = os.environ["ALIGN_ADMIN_PASSWORD"]
DC = shlex.split(os.environ.get("DC", "docker compose"))
fails = []


def ok(cond, name):
    print(("PASS " if cond else "FAIL ") + name, flush=True)
    if not cond:
        fails.append(name)


def dc(*args, check=True, input=None):
    return subprocess.run(DC + list(args), capture_output=True, text=True, check=check, input=input)


def app(*cmd):
    return dc("exec", "-T", "app", *cmd).stdout


def totp(secret, t=None):
    key = base64.b32decode(secret.replace(" ", "").upper() + "=" * (-len(secret.replace(" ", "")) % 8))
    h = hmac.new(key, struct.pack(">Q", int((t or time.time()) // 30)), hashlib.sha1).digest()
    o = h[-1] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def csrf(s, path):
    m = re.search(r'name="_csrf" value="([^"]+)"', s.get(B + path).text)
    return m.group(1) if m else ""


def wait_up(timeout=180):
    end = time.time() + timeout
    while time.time() < end:
        try:
            if requests.get(B + "/login", timeout=5).status_code == 200:
                return True
        except requests.RequestException:
            pass
        time.sleep(2)
    return False


def fresh_code(secret, used):
    # each code works once; wait for the next 30-second step if this one was used
    while True:
        c = totp(secret)
        if c not in used:
            used.add(c)
            return c
        time.sleep(2)


used = set()


def sign_in(secret=None):
    s = requests.Session()
    r = s.post(B + "/login", data={"_csrf": csrf(s, "/login"), "email": EMAIL, "password": PASSWORD})
    if r.url.endswith("/login/2fa") and secret:
        r = s.post(B + "/login/2fa", data={"_csrf": csrf(s, "/login/2fa"), "code": fresh_code(secret, used)})
    return s, r


def job(s, i, timeout=120):
    end = time.time() + timeout
    j = {}
    while time.time() < end:
        r = s.get(B + "/settings/system/jobs/" + i, headers={"Accept": "application/json"})
        if r.status_code == 200:
            j = r.json()
            if j.get("state") in ("succeeded", "failed"):
                return j
        time.sleep(2)
    return j


def jid(r):
    m = re.search(r"job=([0-9a-f-]+)", r.url)
    return m.group(1) if m else None


# ---------------------------------------------------------------- up and signed in
ok(wait_up(), "the app answers on " + B)
r = requests.get(B + "/login")
h = r.headers
ok("default-src" in h.get("Content-Security-Policy", "") and h.get("X-Frame-Options", "").upper() in ("DENY", "SAMEORIGIN") and "X-Powered-By" not in h and h.get("Server", "") in ("Apache", ""),
   "security headers, no version banners: " + h.get("Server", ""))
ok(requests.get(B + "/.git/config").status_code in (403, 404) and requests.get(B + "/../config.php").status_code in (400, 403, 404), "no dot-files or config outside the web root")

s, r = sign_in()
ok("/account" in r.url, "the first admin starts on Account")
# the first admin's password was issued by the installer, so it must be changed first (as on a dedicated server)
NEWPASS = "Docker-Smoke-" + base64.b32encode(os.urandom(10)).decode()
r = s.post(B + "/account/password", data={"_csrf": csrf(s, "/account"), "current": PASSWORD, "new": NEWPASS, "confirm": NEWPASS})
ok("Password changed" in r.text, "temporary password changed")
PASSWORD = NEWPASS
page = s.get(B + "/account").text
if 'value="begin"' in page:
    s.post(B + "/account/2fa", data={"_csrf": csrf(s, "/account"), "action": "begin"})
    page = s.get(B + "/account").text
m = re.search(r'Key: <code class="select-all">([A-Z2-7 ]+)</code>', page)
ok(m is not None, "first sign-in asks the new admin to set up 2FA")
SECRET = m.group(1).replace(" ", "") if m else ""
s.post(B + "/account/2fa", data={"_csrf": csrf(s, "/account"), "action": "confirm", "code": fresh_code(SECRET, used)})
s, r = sign_in(SECRET)
ok(r.status_code == 200 and "/login" not in r.url, "signed in with the password and a 2FA code")

for p in ["/", "/clients", "/devices", "/settings", "/help", "/account"]:
    r = s.get(B + p)
    ok(r.status_code == 200 and r.url.rstrip("/") == (B + p).rstrip("/") and "Fatal error" not in r.text and "Warning:" not in r.text, "page " + p)
t = s.get(B + "/settings/system").text
ok("docker compose pull &amp;&amp; docker compose up -d" in t and "sudo msp-align-update" not in t, "Updates & backups speaks Docker")
ok("isn't running in this container" not in t and "isn't installed on this server" not in t, "the backup agent is available")

# ---------------------------------------------------------------- update check and refusal
r = s.post(B + "/settings/system/check", data={"_csrf": csrf(s, "/settings/system")})
i = jid(r)
j = job(s, i) if i else {}
ok(j.get("state") == "succeeded" or "Could not reach GitHub" in (j.get("message") or ""), "update check runs through the agent: " + str(j.get("message")))
r = s.post(B + "/settings/system/update", data={"_csrf": csrf(s, "/settings/system"), "confirm": "1"})
ok("docker compose pull" in r.text and jid(r) is None, "Update now is refused with the Docker instructions")
t = s.get(B + "/settings/system").text
ok("private backup key is still on the server" in t and "docker compose exec app cat /etc/msp-align/backup-key.txt" in t, "reminds to move the private backup key off the server")

# ---------------------------------------------------------------- a marker, then a backup
MARK = "zz_docker_smoke_%d" % int(time.time())
subprocess.run(DC + ["exec", "-T", "app", "align", "user:create", "--email=%s@example.com" % MARK, "--name=Before backup", "--role=viewer", "--password-stdin"],
               input="MarkerPassword-123456\n", text=True, capture_output=True)
r = s.post(B + "/settings/system/backup", data={"_csrf": csrf(s, "/settings/system")})
i = jid(r)
j = job(s, i) if i else {}
ok(j.get("state") == "succeeded", "backup built by the agent in the container: " + str(j.get("message")))
r = s.post(B + "/settings/system/download/" + (i or "x"), data={"_csrf": csrf(s, "/settings/system")})
bk = r.content
names = tarfile.open(fileobj=io.BytesIO(bk)).getnames() if r.headers.get("Content-Type") == "application/x-tar" else []
ok(names[:2] == ["manifest.json", "db.sql.gz.age"] and "app-key.age" in names, "backup downloaded: " + str(names))
man = json.loads(tarfile.open(fileobj=io.BytesIO(bk)).extractfile("manifest.json").read()) if names else {}
ok(man.get("app") == "MSP-ALIGN" and man.get("database"), "backup manifest")

# ---------------------------------------------------------------- the scheduler runs the timed jobs
end = time.time() + 150
sched = {}
while time.time() < end:
    try:
        sched = json.loads(app("cat", "/run/msp-align/scheduler.json") or "{}")
    except Exception:
        sched = {}
    if sched.get("mail") and sched.get("psa"):
        break
    time.sleep(5)
ok(sched.get("mail", {}).get("exit") == 0 and sched.get("psa", {}).get("exit") == 0 and sched.get("agent", {}).get("exit") == 0, "scheduler ran mail, PSA check and the agent: " + json.dumps(sched))

# ---------------------------------------------------------------- restart: data and key survive
key_before = app("cat", "/etc/msp-align/app-key")
dc("restart", "app")
ok(wait_up(), "back up after a restart")
ok(app("cat", "/etc/msp-align/app-key") == key_before, "encryption key kept in the config volume")
s, r = sign_in(SECRET)
ok(r.status_code == 200 and "/login" not in r.url, "signed in after the restart (2FA secret still decrypts)")

# ---------------------------------------------------------------- restore the backup
subprocess.run(DC + ["exec", "-T", "app", "align", "user:create", "--email=zz_after_backup@example.com", "--name=After backup", "--role=viewer", "--password-stdin"],
               input="AfterPassword-1234567\n", text=True, capture_output=True)
PRIV = [l for l in app("cat", "/etc/msp-align/backup-key.txt").splitlines() if l.startswith("AGE-SECRET-KEY")][0].strip()
r = s.post(B + "/settings/system/upload", data={"_csrf": csrf(s, "/settings/system")}, files={"backup": ("smoke.tar", bk)}, headers={"Accept": "application/json"})
ok(r.status_code == 200 and r.json().get("ok"), "backup uploaded for restore: " + r.text[:120])
r = s.post(B + "/settings/system/restore", data={"_csrf": csrf(s, "/settings/system"), "key": PRIV, "restore_db": "1", "restore_uploads": "1",
                                                  "code": fresh_code(SECRET, used), "confirm": "RESTORE"})
i = jid(r)
time.sleep(3)
end = time.time() + 300
j = {}
while time.time() < end:
    try:
        out = app("cat", "/var/lib/msp-align-agent/jobs/%s.json" % i) if i else ""
        j = json.loads(out) if out else {}
    except Exception:
        j = {}
    if j.get("state") in ("succeeded", "failed"):
        break
    time.sleep(3)
ok(j.get("state") == "succeeded", "restore finished: " + str(j.get("message")))
users = dc("exec", "-T", "db", "sh", "-c", 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -N -e "select email from users"', check=False).stdout
ok(MARK in users and "zz_after_backup" not in users, "the database is back to the backup")
enc = dc("exec", "-T", "db", "sh", "-c", 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -N -e "select @@innodb_encrypt_tables, @@innodb_encrypt_log, @@aria_encrypt_tables"', check=False).stdout.split()
ok(enc[:3] == ["ON", "1", "1"] or enc[:3] == ["ON", "ON", "ON"], "the database is encrypted at rest: " + " ".join(enc))
ok(wait_up(), "the app answers after the restore")

# ---------------------------------------------------------------- move to another install (optional)
# ALIGN_TEST_URL2 / DC2: a second, fresh stack (its own volumes, so its own encryption key). The backup from the first
# restores into it; after a restart the first install's admin still signs in, so the restored key was kept.
if os.environ.get("ALIGN_TEST_URL2"):
    s1_password, s1_secret = PASSWORD, SECRET
    B = os.environ["ALIGN_TEST_URL2"].rstrip("/")
    DC = shlex.split(os.environ["DC2"])
    PASSWORD = os.environ["ALIGN_ADMIN_PASSWORD"]
    ok(wait_up(), "second install answers on " + B)
    s, r = sign_in()
    PASSWORD = "Docker-Smoke2-" + base64.b32encode(os.urandom(10)).decode()
    s.post(B + "/account/password", data={"_csrf": csrf(s, "/account"), "current": os.environ["ALIGN_ADMIN_PASSWORD"], "new": PASSWORD, "confirm": PASSWORD})
    s.post(B + "/account/2fa", data={"_csrf": csrf(s, "/account"), "action": "begin"})
    m = re.search(r'Key: <code class="select-all">([A-Z2-7 ]+)</code>', s.get(B + "/account").text)
    sec2 = m.group(1).replace(" ", "") if m else ""
    s.post(B + "/account/2fa", data={"_csrf": csrf(s, "/account"), "action": "confirm", "code": fresh_code(sec2, used)})
    s, r = sign_in(sec2)
    ok(app("cat", "/etc/msp-align/app-key") != key_before, "the second install has its own encryption key")
    r = s.post(B + "/settings/system/upload", data={"_csrf": csrf(s, "/settings/system")}, files={"backup": ("from-first.tar", bk)}, headers={"Accept": "application/json"})
    ok(r.status_code == 200 and r.json().get("ok"), "the first install's backup uploads into the second")
    r = s.post(B + "/settings/system/restore", data={"_csrf": csrf(s, "/settings/system"), "key": PRIV, "restore_db": "1", "restore_uploads": "1",
                                                      "code": fresh_code(sec2, used), "confirm": "RESTORE"})
    i = jid(r)
    end = time.time() + 300
    j = {}
    while time.time() < end:
        try:
            out = app("cat", "/var/lib/msp-align-agent/jobs/%s.json" % i) if i else ""
            j = json.loads(out) if out else {}
        except Exception:
            j = {}
        if j.get("state") in ("succeeded", "failed"):
            break
        time.sleep(3)
    ok(j.get("state") == "succeeded", "restored into the second install: " + str(j.get("message")))
    ok(app("cat", "/etc/msp-align/app-key") == key_before, "the restored encryption key is saved in the config volume")
    dc("restart", "app")
    ok(wait_up(), "second install back up after a restart")
    PASSWORD = s1_password
    s, r = sign_in(s1_secret)
    ok(r.status_code == 200 and "/login" not in r.url, "the first install's admin signs in after the restart (restored key kept)")

print("\n%d failure(s)" % len(fails))
for f in fails:
    print(" - " + f)
sys.exit(1 if fails else 0)
