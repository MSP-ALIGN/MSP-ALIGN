"""2.2.1 review of the Docker image, the command line (bin/align) and CI. The entrypoint, scheduler, Apache config,
compose files and workflows are checked without Docker: pieces of them are run on their own in a scratch folder, the
rest is read. The command-line checks at the top need no database; the last ones use a throwaway user."""
import os, re, json, subprocess, tempfile, shutil, time
from lib import *

tmp = tempfile.mkdtemp(prefix="align-dockerq-")
ent = open(ROOT + "/docker/entrypoint.sh").read()


def bash(script, env=None):
    return subprocess.run(["bash", "-c", script], env={"PATH": "/usr/bin:/bin", **(env or {})}, capture_output=True, text=True, timeout=60)


def func(src, name):
    """A bash function's whole definition from the entrypoint (one line or a block ending in a line '}')."""
    n = re.escape(name)
    m = re.search(r"^%s\(\) *\{[^\n]*\}[ \t]*\n" % n, src, re.M) or re.search(r"^%s\(\) *\{\n.*?\n\}\n" % n, src, re.M | re.S)
    return m.group(0) if m else ""


STUBS = "die() { printf 'DIE: %s\\n' \"$*\" >&2; exit 1; }\nlog() { printf 'LOG: %s\\n' \"$*\"; }\n" + func(ent, "php_str") + func(ent, "env_bool")

# ---- trusted proxies: a prefix with a leading zero was read as octal and slipped past the width check
pblock = re.search(r"^PROXIES=\(\)\n.*?^done\n", ent, re.M | re.S)
ok(pblock is not None, "entrypoint: the trusted-proxy block is there")
def proxies(v):
    r = bash("set -euo pipefail\n" + STUBS + pblock.group(0) + 'printf "OK %s\\n" "${PROXIES[@]}"', {"ALIGN_TRUSTED_PROXIES": v})
    return r.stdout + r.stderr
ok("DIE" in proxies("::/09") and "DIE" in proxies("fd00::/008") and "DIE" not in proxies("fd00::/019"), "an IPv6 range wider than /16 is refused even written with a leading zero (::/09)")
ok("DIE" in proxies("10.0.0.0/7") and "DIE" in proxies("::/8"), "ranges wider than /8 (IPv4) and /16 (IPv6) are still refused")
out = proxies("172.30.57.10, 10.1.0.0/16 fd00::/64,192.168.1.0/024")
ok("DIE" not in out and "OK 172.30.57.10" in out and "OK 192.168.1.0/024" in out and "OK fd00::/64" in out, "proxy addresses and narrow ranges still pass: " + out.replace("\n", " "))

# ---- ALIGN_STAGING: "true" left a test server in normal mode (it emails real clients); a typo now stops the start
ok(func(ent, "env_bool") != "", "entrypoint: env_bool exists")
def boolv(v):
    r = bash("set -euo pipefail\n" + STUBS + "X=$(env_bool ALIGN_STAGING); echo \"VAL $X\"", {"ALIGN_STAGING": v})
    return (r.stdout + r.stderr).strip()
ok(all(boolv(v) == "VAL 1" for v in ["1", "true", "TRUE", "yes", "on"]), "ALIGN_STAGING=1/true/yes/on turn staging on")
ok(all(boolv(v) == "VAL 0" for v in ["0", "false", "no", "off", ""]), "0/false/no/off/empty leave it off")
ok("DIE" in boolv("ture") and "VAL" not in boolv("ture") and "DIE" in boolv("2"), "anything else stops the container with a message: " + boolv("ture"))
ok("STAGING=$(env_bool ALIGN_STAGING)" in ent and "'staging' => $( [[ $STAGING == 1 ]]" in ent, "config.php's staging switch comes from the strict reading")

# ---- config.php: hostile environment values stay inside their PHP strings
cblock = re.search(r'^proxies_php="\["\n.*?^PHP\n\)\n', ent, re.M | re.S)
ok(cblock is not None, "entrypoint: the config.php block is there")
if cblock:
    nasty = "pa'ss\\\\'];system('id');//\n$(id)`id`"
    conf = tmp + "/config.php"
    script = "set -euo pipefail\n" + STUBS + 'CONF=%s; DATA=/var/lib/msp-align; RUN=/run/msp-align; AGENT=/var/lib/msp-align-agent\n' % conf \
        + 'PROXIES=(10.0.0.1); DB_HOST=db; DB_NAME=msp_align; DB_USER="$U"; DB_PASS="$P"; APP_KEY=base64:x; ALIGN_URL=https://align.example\n' \
        + 'TZ_NAME=UTC; FQDN=align.example; BRANCH=main; STAGING=$(env_bool ALIGN_STAGING); STAGING_MAIL_TO="$M"\n' + cblock.group(0) + 'mv "$CONF.tmp" "$CONF"\n'
    r = bash(script, {"U": "u'ser", "P": nasty, "M": "test'@example.com", "ALIGN_STAGING": "yes"})
    got = subprocess.run(["php", "-r", 'echo json_encode(require $argv[1]);', conf], capture_output=True, text=True).stdout if os.path.exists(conf) else ""
    cfg = json.loads(got or "{}")
    ok(cfg.get("db", {}).get("pass") == nasty and cfg["db"].get("user") == "u'ser" and cfg.get("staging_mail_to") == "test'@example.com",
       "quotes, backslashes, newlines and shell syntax in env values come back unchanged from config.php: " + (r.stderr or got)[-200:])
    ok(cfg.get("staging") is True and cfg.get("trusted_proxies") == ["10.0.0.1"] and cfg.get("install_type") == "docker", "staging on from ALIGN_STAGING=yes; the other values as given")
    mode = oct(os.stat(conf).st_mode & 0o777) if os.path.exists(conf) else "missing"
    ok(mode == "0o640", "config.php is written 640 (umask 027): " + mode)
ok(not re.search(r"log .*\$(DB_PASS|APP_KEY|ALIGN_DB_PASSWORD|ALIGN_APP_KEY)", ent), "the entrypoint never logs the database password or the app key")

# ---- scheduler: each job has its systemd unit's time limit
try:
    r = subprocess.run(["php", ROOT + "/docker/scheduler.php", "--list"], capture_output=True, text=True, timeout=20)
    listed = r.stdout + r.stderr
    jobs = json.loads(r.stdout)
except (ValueError, subprocess.TimeoutExpired) as e:
    listed, jobs = str(e), {}
ok(set(jobs) == {"mail", "psa", "sync", "check", "nightly", "agent"}, "scheduler --list prints its jobs without running them: " + listed[:160])
ok(jobs.get("agent", {}).get("timeout", 0) is None and jobs["agent"]["cmd"][0] == "php",
   "the agent job has no time limit (a restore stopped mid-import would skip putting the safety copy back)")
for unit in sorted(f for f in os.listdir(ROOT + "/deploy/systemd") if f.endswith(".service") and f != "msp-align-agent.service"):
    u = open(ROOT + "/deploy/systemd/" + unit).read()
    cmd = re.search(r"^ExecStart=\S+ (\S+) (.+)$", u, re.M)
    lim = re.search(r"^TimeoutStartSec=(\d+)(min|s)?$", u, re.M)
    secs = (int(lim.group(1)) * (60 if lim.group(2) == "min" else 1)) if lim else None
    want = [cmd.group(1)] + cmd.group(2).split() if cmd else []
    job = next((j for j in jobs.values() if j["cmd"][-len(want):] == want), None) if want else None
    ok(job is not None and job["timeout"] == secs and (secs is None or job["cmd"][:3] == ["timeout", "--kill-after=30", str(secs)]),
       f"the scheduler stops {' '.join(want[1:])} after {secs}s like {unit}: " + json.dumps(job)[:160])
ok(all(j["cmd"][j["cmd"].index("php") - 4:j["cmd"].index("php")] == ["runuser", "-u", "www-data", "--"] for j in jobs.values() if any("bin/align" in c for c in j["cmd"])),
   "app commands run as www-data")
sched = open(ROOT + "/docker/scheduler.php").read()
ok("in_array($code, [124, 137], true)" in sched, "a job stopped at its limit says so in the log")

# ---- Apache: secret links stay out of the access log, also as the Referer of the page's own assets
ap = open(ROOT + "/docker/apache.conf").read()
ref = re.search(r'^RequestHeader edit Referer "\(\?i\)([^"]+)" "\$1\(hidden\)"$', ap, re.M)
ok(ref is not None and "SetEnvIfNoCase Referer" not in ap, "apache.conf cuts the token from a secret link's Referer (and doesn't drop those requests from the log)")
if ref:
    rx = re.compile(ref.group(1), re.I)
    hits = ["https://align.example/portal/invite/" + "a" * 43, "https://align.example:8443/portal/welcome/tok/guide/3",
            "HTTPS://align.example/portal/sign/tok", "http://align.example/ics/tok"]
    miss = ["https://align.example/clients/portal/invite/x", "https://align.example/portal/login", "https://other.example/x?u=/portal/invite/x"]
    ok(all(rx.search(h) for h in hits) and not any(rx.search(m) for m in miss), "the Referer rule matches the four secret paths and nothing else")
    ok(rx.sub(r"\1(hidden)", hits[0]) == "https://align.example/portal/invite/(hidden)", "...and keeps only the path's start: " + rx.sub(r"\1(hidden)", hits[0]))
ins = open(ROOT + "/install.sh").read()
ok('RequestHeader edit Referer "(?i)^(https?://[^/]+/(?:ics|portal/(?:invite|welcome|sign))/).*$" "$1(hidden)"' in ins, "install.sh has the same Referer rule")
for p in ["^/ics/", "^/portal/invite/", "^/portal/welcome/", "^/portal/sign/"]:
    ok(f'SetEnvIf Request_URI "{p}" align_secret_url' in ap, f"{p} is still kept out of the access log")
ok("reqenv('align_secret_url')" in ap and "AllowOverride None" in ap and "Options -Indexes" in ap and "ServerTokens Prod" in ap and "TraceEnable Off" in ap,
   "apache.conf: secret-link filter on the access log, no .htaccess, no listings, no version, no TRACE")
if shutil.which("apache2"):
    ac = tmp + "/apache-test.conf"
    mods = "".join(f"LoadModule {m}_module /usr/lib/apache2/modules/mod_{m}.so\n" for m in ["mpm_prefork", "authz_core", "headers", "setenvif", "dir"])
    open(ac, "w").write(f"ServerRoot /etc/apache2\n{mods}PidFile {tmp}/a.pid\nErrorLog /dev/null\nListen 127.0.0.1:65080\nInclude {ROOT}/docker/apache.conf\n")
    r = subprocess.run(["apache2", "-t", "-f", ac], env={"PATH": "/usr/sbin:/usr/bin:/bin", "APACHE_RUN_DIR": tmp}, capture_output=True, text=True)
    ok("Syntax OK" in r.stdout + r.stderr, "Apache accepts docker/apache.conf: " + (r.stdout + r.stderr)[-200:])

# ---- compose: no new privileges through setuid files; the database never on a host port
def services(path):
    m = re.search(r"\nservices:\n(.*?)(?=\n\S|\Z)", open(path).read(), re.S)
    body = m.group(1) if m else ""
    return {m.group(1): m.group(2) for m in re.finditer(r"^  ([a-z]+):\n(.*?)(?=^  [a-z]+:\n|\Z)", body, re.M | re.S)}
svc = {**services(ROOT + "/compose.yaml")}
caddy = services(ROOT + "/compose.caddy.yaml")
ok(all("security_opt: [no-new-privileges:true]" in svc.get(s, "") for s in ["db", "app"]) and "security_opt: [no-new-privileges:true]" in caddy.get("caddy", ""),
   "compose: db, app and caddy run with no-new-privileges")
ok("ports:" not in svc.get("db", "x ports:") and '"${ALIGN_BIND:-127.0.0.1}:${ALIGN_PORT:-8080}:80"' in svc.get("app", ""), "compose: the database has no host port; the app's is on 127.0.0.1 by default")

# ---- GitHub Actions
WF = ROOT + "/.github/workflows/"
wfs = {f: open(WF + f).read() for f in sorted(os.listdir(WF)) if f.endswith(".yml")}
ok(all(re.fullmatch(r"[\w.-]+/[\w./-]+@[0-9a-f]{40}", u) for t in wfs.values() for u in re.findall(r"uses:\s*(\S+)", t)), "every action is pinned to a commit")
ok(not any(re.search(r"^\s*pull_request_target\s*:", t, re.M) for t in wfs.values()), "no workflow uses pull_request_target")
def run_blocks(t):
    """Every run: script (one line or a | block) of a workflow."""
    out, lines = [], t.split("\n")
    for i, l in enumerate(lines):
        m = re.match(r"^(\s*)(- )?run:\s*(.*)$", l)
        if not m:
            continue
        ind = len(m.group(1)) + (2 if m.group(2) else 0)
        if m.group(3).strip() not in ("|", ">"):
            out.append(m.group(3)); continue
        blk = []
        for l2 in lines[i + 1:]:
            if l2.strip() and len(l2) - len(l2.lstrip()) <= ind:
                break
            blk.append(l2)
        out.append("\n".join(blk))
    return out
ok(not [b for t in wfs.values() for b in run_blocks(t) if "${{" in b], "no ${{ }} expression is pasted into a run: script (values go through env:)")
dk = wfs["docker.yml"]
jobs_txt = {m.group(1): m.group(2) for m in re.finditer(r"^  ([a-z]+):\n(.*?)(?=^  [a-z]+:\n|\Z)", dk.split("\njobs:\n", 1)[1], re.M | re.S)}
ok(all("persist-credentials: false" in jobs_txt[j] for j in ["image", "install"]) and all("persist-credentials: false" in wfs[f] for f in ["tests.yml", "docs.yml", "dco.yml"]),
   "checkouts that run pull-request code don't keep the token in .git/config")
ok("permissions:\n  contents: read" in dk and "packages: write" in jobs_txt.get("publish", "") and "packages: write" not in dk.split("\njobs:\n", 1)[0],
   "docker.yml: read-only by default; only publish can push packages")
ok('DIGEST: ${{ steps.build.outputs.digest }}' in jobs_txt.get("publish", "") and '^sha256:[0-9a-f]{64}$' in jobs_txt.get("publish", ""), "the image signature step takes the digest from env and checks its form")
ok("flavor: latest=${{ steps.tag.outputs.latest }}" in dk and "latest=auto" not in dk, ":latest is decided by the tag step, not metadata-action's auto")
lblock = re.search(r"^( +)latest=true\n.*?echo \"latest=\$latest\" >> \"\$GITHUB_OUTPUT\"\n", dk, re.M | re.S)
ok(lblock is not None, "docker.yml: the :latest block is there")
if lblock:
    repo = tmp + "/tags"
    os.makedirs(repo)
    g = lambda *a: subprocess.run(["git", "-C", repo, *a], capture_output=True, text=True)
    g("init", "-q"); g("-c", "user.name=t", "-c", "user.email=t@example.com", "commit", "-q", "--allow-empty", "-m", "x")
    for t in ["v2.0.0", "v2.1.0", "v2.2.0", "v2.1.5", "v2.3.0-beta.1", "v2.10.0-rc.1"]:
        g("tag", t)
    code = re.sub(r"^" + lblock.group(1), "", lblock.group(0), flags=re.M)
    def latest(tag):
        out = tmp + "/gh-out-" + tag
        open(out, "w").close()
        bash(f"set -euo pipefail\ncd {repo}\n" + code, {"GITHUB_REF_NAME": tag, "GITHUB_OUTPUT": out})
        return open(out).read().strip()
    ok(latest("v2.2.0") == "latest=true", "the newest release gets :latest")
    ok(latest("v2.1.5") == "latest=false", "a fix to an older line (v2.1.5 after v2.2.0) doesn't move :latest back")
    ok(latest("v2.3.0-beta.1") == "latest=false", "a pre-release doesn't get :latest")

# ---- bin/align without a database: refusals happen before anything changes
nodb = tmp + "/nodb.php"
open(nodb, "w").write("<?php return ['db' => ['host' => '/nonexistent/align-q.sock', 'name' => 'none', 'user' => 'none', 'pass' => ''], "
                      "'app_key' => 'base64:" + "a" * 43 + "=', 'base_url' => 'https://align.example', 'timezone' => 'UTC'];")
NENV = {"PATH": "/usr/bin:/bin", "ALIGN_CONFIG": nodb}
def cli0(*a, env=None):
    return subprocess.run(["php", ALIGN, *a], env=env or NENV, capture_output=True, text=True, input="", timeout=60)
r = cli0("nosuchcommand")
ok(r.returncode == 1 and "unknown command" in r.stderr and "Usage:" in r.stdout, "an unknown command exits 1 (a typo in a timer or script no longer looks like success)")
ok(cli0("help").returncode == 0 and cli0().returncode == 0 and cli0("version").stdout.strip() == open(ROOT + "/VERSION").read().strip(), "help and no command exit 0; version prints VERSION")
r = cli0("user:reset-password", "--email=nobody@example.com", "--clear-2FA")
ok(r.returncode == 1 and "--clear-2FA" in r.stderr and "--clear-2fa" in r.stderr, "a mistyped option is refused, naming it and the right ones: " + r.stderr.strip()[:120])
r = cli0("user:reset-password", "--email=nobody@example.com", "-clear-2fa")
ok(r.returncode == 1 and "-clear-2fa" in r.stderr and "No user" not in r.stderr, "a single-dash option is refused, not ignored")
r = cli0("migrate", "--force")
ok(r.returncode == 1 and "migrate doesn't take --force" in r.stderr, "a command without options refuses one")
r = cli0("user:create", "--email=q-long@example.com", "--name=" + "x" * 191, "--password-stdin")
ok(r.returncode == 1 and "--name" in r.stderr, "user:create: a name longer than the column is refused before the database: " + r.stderr.strip()[:100])
r = cli0("user:create", "--email=q-ctrl@example.com", "--name=\x07\x1b", "--password-stdin")
ok(r.returncode == 1 and "--name" in r.stderr, "user:create: a name of only control characters is refused")
r = cli0("user:create", "--email=" + "a" * 185 + "@example.com", "--password-stdin")
ok(r.returncode == 1 and "190" in r.stderr, "user:create: an email longer than the column is refused before the database")
if os.geteuid() == 0:
    r = cli0("version", env={"PATH": "/usr/bin:/bin"})
    ok(r.returncode == 1 and "web user" in r.stderr and r.stdout == "", "as root with the server's own config, the command line refuses and points to `sudo align` (www-data)")

# ---- bin/align with the test database
def cli(*a, inp=None):
    return subprocess.run(["php", ALIGN, *a], env=ENV, capture_output=True, text=True, input=inp, timeout=120)
r = cli("system:audit", "--event=system.q_multiline", "--detail=first line\nsecond line")
row = q("select detail from audit_log where action='system.q_multiline' order by id desc limit 1")
ok(r.returncode == 0 and row and row[0]["detail"] == "first line\nsecond line", "system:audit keeps a multi-line detail (it was dropped): " + repr(row[0]["detail"] if row else r.stderr[-120:]))
EM = "zz-q-cli-reset-%d@example.com" % int(time.time())   # new each run: identical alerts within 10 minutes are sent once
q("delete from users where email=%s", EM)
r = cli("user:create", f"--email={EM}", "--name=Example Reset", "--role=viewer", "--password-stdin", inp="Example-Password-123\n")
ok(r.returncode == 0 and q("select must_change_password from users where email=%s", EM)[0]["must_change_password"] == 1, "user:create still works: " + (r.stdout + r.stderr)[-120:])
q("update users set totp_enabled=1, totp_secret_enc='x', is_active=0 where email=%s", EM)
before = q("select session_version from users where email=%s", EM)[0]["session_version"]
mq = q("select coalesce(max(id),0) m from mail_queue")[0]["m"]
alerts_on = php('echo (int) Align\\Mail\\Notifications::enabled("security");').stdout.strip() == "1"
r = cli("user:reset-password", f"--email={EM}", "--clear-2fa")
u = q("select is_active, totp_enabled, totp_secret_enc, must_change_password, session_version from users where email=%s", EM)[0]
ok(r.returncode == 0 and "New password for " + EM in r.stdout and u["is_active"] == 1 and u["totp_enabled"] == 0 and u["totp_secret_enc"] is None
   and u["must_change_password"] == 1 and u["session_version"] == before + 1, "user:reset-password --clear-2fa: one-time password, 2FA removed, enabled, sessions ended")
a = q("select action, detail from audit_log where detail like %s order by id desc limit 1", EM + " via CLI%")
ok(a and a[0]["action"] == "user.reset_2fa" and "two-factor removed" in a[0]["detail"] and "account enabled again" in a[0]["detail"],
   "the audit entry says two-factor was removed and the account enabled again: " + json.dumps(a[0] if a else None))
sent = q("select subject from mail_queue where id > %s and kind='security'", mq)
ok(not alerts_on or any(s["subject"] == "Security: Staff two-factor reset" for s in sent), "admins get the same security alert as for a reset on the Users page (alerts on: %s)" % alerts_on)
q("delete from users where email=%s", EM)

shutil.rmtree(tmp, ignore_errors=True)
# leave no queued alert behind for the suites after this one
try:
    q("delete from mail_queue where id > %s", mq)
except NameError:
    pass
done()
