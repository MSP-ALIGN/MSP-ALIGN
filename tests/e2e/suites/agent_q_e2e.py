"""2.2.1 review of the root agent (scripts/agent.php) and its systemd units: request files the web user could swap,
the database login file a root client read options from, the check of a backup's uploaded files, the outside audit
checkpoint, hostile GitHub answers in the Docker update check, the release notes parser, and commit text in the git
check. The agent runs with its own folders and a config whose database doesn't exist, so nothing here writes to the
test database."""
from lib import *
import os, io, json, shutil, subprocess, tarfile, gzip, time, base64, pwd

W = WORK + "/agent_q"
shutil.rmtree(W, ignore_errors=True)
for d in ["data/uploads/sub", "data/downloads", "data/restore", "agent/jobs", "agent/safety", "agent/work", "run/requests", "run/keys",
          "bin", "fake", "elsewhere", "gh", "fakeapp/bin"]:
    os.makedirs(W + "/" + d)
CUR = open(ROOT + "/VERSION").read().strip()
APPKEY = "base64:" + base64.b64encode(os.urandom(32)).decode()
CONF = {"db": {"host": "localhost", "name": "agentq_none", "user": "agentq_none", "pass": "none"}, "app_key": APPKEY, "timezone": "UTC",
        "upload_path": W + "/data/uploads", "session_path": W + "/data/sessions", "fqdn": "agentq.example", "debug": False}
open(W + "/config.php", "w").write("<?php\nreturn json_decode(<<<'JSON'\n" + json.dumps(CONF) + "\nJSON, true);\n")
subprocess.run(f"age-keygen -o {W}/key.txt 2>/dev/null && age-keygen -y {W}/key.txt > {W}/recipient.txt", shell=True)
KEY = [l for l in open(W + "/key.txt") if l.startswith("AGE-SECRET")][0].strip()
REC = open(W + "/recipient.txt").read().strip()
open(W + "/data/uploads/a.txt", "w").write("a"); open(W + "/data/uploads/sub/b.txt", "w").write("b")
AENV = dict(ENV, ALIGN_CONFIG=W + "/config.php", ALIGN_APP_DIR=ROOT, ALIGN_DATA_DIR=W + "/data", ALIGN_AGENT_DIR=W + "/agent", ALIGN_RUN_DIR=W + "/run",
            ALIGN_RECIPIENT=W + "/recipient.txt", ALIGN_RUNAS="root", ALIGN_SYSTEMCTL="none", ALIGN_LEGACY_BACKUPS=W + "/legacy", ALIGN_RELEASE_SIGNERS="none",
            ALIGN_AGENT_TEST="1", ALIGN_UPDATE_CHECK_URL="http://127.0.0.1:9/x", ALIGN_PRIVKEY_FILE=W + "/nokey.txt", GIT_CEILING_DIRECTORIES=W,
            PATH="/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin")
def agent(*a, timeout=120, **env):
    e = dict(AENV, **env)
    for k in [k for k, v in e.items() if v is None]:
        del e[k]
    return subprocess.run(["php", ROOT + "/scripts/agent.php", *(a or ["run"])], env=e, capture_output=True, text=True, timeout=timeout)
def rid(n): return "20261003-12%04d-%06x" % (n, n)
def request(i, action, where=None, **extra):
    p = (where or W + "/run/requests") + f"/{i}.json"
    json.dump({"id": i, "action": action, "params": {}, "user": "agent_q", "created": "2026-10-03T12:00:00+00:00", **extra}, open(p, "w"))
    return p
def jobf(i):
    p = f"{W}/agent/jobs/{i}.json"
    return json.load(open(p)) if os.path.exists(p) else {}

# ---- request files: moved into root's keys folder before they are read; only a plain, single-link, small file counts
good, hard, fifo, big, folder = rid(1), rid(2), rid(3), rid(4), rid(5)
request(good, "keycheck", key=KEY)
os.link(request(hard, "keycheck", where=W + "/elsewhere", key=KEY), W + f"/run/requests/{hard}.json")
os.mkfifo(W + f"/run/requests/{fifo}.json")
p = request(big, "keycheck", key=KEY, pad="x" * 70000)
os.makedirs(W + f"/run/requests/{folder}.json/inner")
try:
    a = agent(timeout=60); out = a.stderr
except subprocess.TimeoutExpired:
    a = None; out = "timed out"
j = jobf(good)
ok(a is not None and j.get("state") == "succeeded" and j["result"].get("match") is True, "a request file is still read and run (key check): " + str(j.get("message")) + out[-200:])
ok(not jobf(hard) and f"Ignored invalid request {hard}.json" in out and os.path.exists(W + f"/elsewhere/{hard}.json"),
   "a request hard-linked to another file is ignored (and the other file left alone)")
ok(a is not None and not jobf(fifo) and f"Ignored invalid request {fifo}.json" in out, "a FIFO named like a request is ignored without waiting on it")
ok(not jobf(big), "a request over 64 KiB is ignored")
ok(not os.path.exists(W + f"/run/requests/{folder}.json"), "a folder named like a request is removed (it kept the watcher firing): " + str(os.listdir(W + "/run/requests")))
ok(not os.listdir(W + "/run/requests") and not os.listdir(W + "/run/keys"), "the request folder is empty and nothing (no request or key) is left in the keys folder: "
   + str(os.listdir(W + "/run/keys")))

# ---- the backup's database dump runs as the web user, and its login file is root's (the web user can't add options)
NOBODY = pwd.getpwnam("nobody")
for d in [WORK, W, W + "/bin", W + "/run", W + "/data/uploads", W + "/data/uploads/sub"]:
    os.chmod(d, 0o755)
for d in [W + "/fake", W + "/data", W + "/data/downloads", W + "/data/restore"]:
    os.chmod(d, 0o777)
for f in ["a.txt", "sub/b.txt"]:
    os.chmod(W + "/data/uploads/" + f, 0o644)
open(W + "/bin/mariadb-dump", "w").write("""#!/bin/sh
cnf=""
for a in "$@"; do case "$a" in --defaults-extra-file=*) cnf="${a#--defaults-extra-file=}";; esac; done
w=no; [ -w "$cnf" ] && w=yes
r=no; [ -r "$cnf" ] && r=yes
echo "uid=$(id -u) owner=$(stat -c %u "$cnf") mode=$(stat -c %a "$cnf") writable=$w readable=$r" >> """ + W + """/fake/dump.log
echo '-- fake dump'
""")
os.chmod(W + "/bin/mariadb-dump", 0o755)
bk = rid(10)
request(bk, "backup")
a = agent(ALIGN_RUNAS="nobody", PATH=W + "/bin:" + AENV["PATH"])
seen = open(W + "/fake/dump.log").read().strip() if os.path.exists(W + "/fake/dump.log") else ""
ok(f"uid={NOBODY.pw_uid} " in seen, "the database dump runs as the web user, not root: " + seen)
ok("owner=0 " in seen and "mode=640 " in seen and "writable=no" in seen and "readable=yes" in seen,
   "the database login file stays root's: the web user can read it but not change it (no options slipped into a client): " + seen)
j = jobf(bk)
ok(j.get("state") == "succeeded" and os.path.exists(f"{W}/data/downloads/{bk}.tar") and not [f for f in os.listdir(W + "/run") if f.startswith("db-")],
   "and the backup still works, the login file removed afterwards: " + str(j.get("message")) + a.stderr[-200:])

# ---- the check of a backup's uploaded files reads tar's listing strictly (owner names can't shift the columns)
def age(data):
    return subprocess.run(["age", "-r", REC], input=data, capture_output=True).stdout
def bundle(name, members):
    d = W + "/b-" + name; os.makedirs(d)
    man = {"format": 1, "app": "MSP-ALIGN", "version": CUR, "created": "2026-10-03T12:00:00+00:00", "tag": "download", "host": "agentq.example",
           "database": "x", "uploads_files": 2, "recipients": [REC]}
    open(d + "/manifest.json", "w").write(json.dumps(man))
    open(d + "/db.sql.gz.age", "wb").write(age(gzip.compress(b"CREATE TABLE `users` (id int);\nCREATE TABLE `schema_migrations` (v int);\n")))
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz", format=tarfile.GNU_FORMAT) as tf:
        for ti, data in members:
            tf.addfile(ti, io.BytesIO(data) if data is not None else None)
    open(d + "/uploads.tar.gz.age", "wb").write(age(buf.getvalue()))
    open(d + "/app-key.age", "wb").write(age(APPKEY.encode()))
    subprocess.run(["php", "-r", 'require "' + ROOT + '/src/System/Tar.php"; $d=$argv[1]; Align\\System\\Tar::write("$d/b.tar", ["manifest.json"=>"$d/manifest.json",'
                    '"db.sql.gz.age"=>"$d/db.sql.gz.age","uploads.tar.gz.age"=>"$d/uploads.tar.gz.age","app-key.age"=>"$d/app-key.age"]);', d])
    return d + "/b.tar"
def entry(name, kind=tarfile.REGTYPE, data=b"", uname="", link=""):
    ti = tarfile.TarInfo(name); ti.type = kind; ti.mtime = 1790000000; ti.uname = uname; ti.gname = uname; ti.linkname = link
    ti.mode = 0o755 if kind == tarfile.DIRTYPE else 0o644
    if kind == tarfile.REGTYPE:
        ti.size = len(data)
        return ti, data
    return ti, None
def verify(tarpath):
    token = os.urandom(16).hex(); i = rid(20 + len(os.listdir(W + "/agent/jobs")))
    shutil.copy(tarpath, f"{W}/data/restore/{token}.tar")
    request(i, "verify", key=KEY, params={"token": token})
    agent()
    return jobf(i)
plain = [entry("uploads", tarfile.DIRTYPE), entry("uploads/a.txt", data=b"a"), entry("uploads/sub", tarfile.DIRTYPE), entry("uploads/sub/b.txt", data=b"b")]
PLAIN = bundle("plain", plain)
j = verify(PLAIN)
ok(j.get("state") == "succeeded" and j["result"]["backup"]["uploads_files"] == 2, "a normal backup still checks out: plain files and folders, counted: " + str(j.get("message")))
# root only decrypts: decompressing and listing the backup's contents (tar's parser) run as the web user
for tool in ["tar", "gunzip"]:
    open(f"{W}/bin/{tool}", "w").write(f"#!/bin/sh\necho \"{tool} uid=$(id -u)\" >> {W}/fake/tools.log\nexec {shutil.which(tool, path='/usr/bin:/bin')} \"$@\"\n")
    os.chmod(f"{W}/bin/{tool}", 0o755)
token = os.urandom(16).hex(); i = rid(90)
os.chmod(W + "/data/restore", 0o777)   # the runs as root above made it root's 0750 again
shutil.copy(PLAIN, f"{W}/data/restore/{token}.tar"); os.chmod(f"{W}/data/restore/{token}.tar", 0o644)
request(i, "verify", key=KEY, params={"token": token})
agent(ALIGN_RUNAS="nobody", PATH=W + "/bin:" + AENV["PATH"])
for tool in ["tar", "gunzip"]:
    os.unlink(f"{W}/bin/{tool}")
seen = open(W + "/fake/tools.log").read().split("\n") if os.path.exists(W + "/fake/tools.log") else []
ok(jobf(i).get("state") == "succeeded" and f"gunzip uid={NOBODY.pw_uid}" in seen and f"tar uid={NOBODY.pw_uid}" in seen and not [l for l in seen if l.endswith("uid=0")],
   "a backup's database is decompressed and its files listed as the web user, never root: " + str(seen) + " " + str(jobf(i).get("message")))
spaced = "x 1 2026-01-01 00:00 uploads/ok"
j = verify(bundle("spaced", plain + [entry("../evil", data=b"bad", uname=spaced)]))
ok(j.get("state") == "failed" and "unsafe entry" in (j.get("message") or ""),
   "an entry ../evil with an owner name made of spaces and columns is refused (it was read as 'uploads/ok/...'): " + str(j.get("message")))
j = verify(bundle("link", plain + [entry("uploads/link", tarfile.SYMTYPE, link="/etc/passwd", uname=spaced)]))
ok(j.get("state") == "failed" and "unsafe entry" in (j.get("message") or ""), "a symlink in the uploaded files is refused: " + str(j.get("message")))
j = verify(bundle("hard", plain + [entry("uploads/hard", tarfile.LNKTYPE, link="uploads/a.txt")]))
ok(j.get("state") == "failed" and "unsafe entry" in (j.get("message") or ""), "a hard link in the uploaded files is refused: " + str(j.get("message")))

# ---- the outside audit checkpoint: kept when the check couldn't run (only a clean check moves it on)
FA = W + "/fakeapp"
open(FA + "/VERSION", "w").write(CUR + "\n")
open(FA + "/bin/align", "w").write("""<?php
$c = $argv[1] ?? '';
if ($c === 'audit:head') { echo json_encode(['id' => 999, 'hash' => str_repeat('b', 64)]), "\\n"; exit(0); }
if ($c === 'audit:checkpoint') { echo "stub checkpoint\\n"; exit((int) getenv('STUB_CHECKPOINT')); }
exit(0);
""")
HEAD = W + "/agent/audit-head.json"
def nightly(code):
    json.dump({"id": 5, "hash": "a" * 64, "at": "2026-10-02T02:30:00+00:00"}, open(HEAD, "w"))
    r = agent("nightly", ALIGN_APP_DIR=FA, STUB_CHECKPOINT=str(code))
    return r.stderr, json.load(open(HEAD)).get("id")
err, hid = nightly(255)
ok(hid == 5 and "ALERT" in err and "could not be checked" in err, "the checkpoint check failing to run (exit 255) alerts and keeps the old checkpoint: " + str(hid) + " " + err[-200:])
err, hid = nightly(1)
ok(hid == 5 and "ALERT" in err, "same for exit 1 (an error)")
err, hid = nightly(2)
ok(hid == 999 and "ALERT: audit log verification failed" in err, "tampering found (exit 2): alerted once, then the checkpoint moves on (as before)")
err, hid = nightly(0)
ok(hid == 999 and "ALERT" not in err, "a clean check moves the checkpoint to the newest entry: " + err[-200:])

# ---- Docker update check: GitHub's answers are untrusted (wrong JSON types, huge text, a hostile README)
GH = W + "/gh"
commits = ["junk", 5, None,
           {"sha": "a" * 40, "parents": "x", "commit": {"message": "S" * 5000 + "\n\nbody", "committer": {"date": "2026-10-02T10:00:00Z"}}},
           {"sha": ["x"], "parents": [{}], "commit": {"message": ["not", "text"], "committer": "x"}},
           {"sha": "c" * 40, "parents": [{}], "commit": "x"},
           {"sha": "e" * 40, "parents": [{}], "commit": {"message": "Fix a thing", "committer": {"date": ["x"]}}},
           {"sha": "d" * 40, "parents": [{}], "commit": {"message": "v%s: this release" % CUR}}]
json.dump(commits, open(GH + "/commits.json", "w"))
json.dump([{"name": "v99.0.0"}, "junk", 5, {"name": ["v100.0.0"]}, {"name": "v98.0.0"}, {"name": "v101.0.0-beta"}], open(GH + "/tags.json", "w"))
NOTE = "- **Real thing (99.0.0):** it works.\n"
open(GH + "/ok.md", "w").write("# X\n\n## What's new\n\n" + NOTE)
open(GH + "/bad.md", "w").write("# X\n\n## What's new\n\n- **" + " " * 1000000 + "x\n" + NOTE)
open(GH + "/router.php", "w").write('<?php $u = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); $d = __DIR__;'
    ' if (str_ends_with($u, "/tags")) { readfile("$d/tags.json"); return true; }'
    ' if (str_ends_with($u, "/commits")) { readfile("$d/commits.json"); return true; }'
    ' if (str_ends_with($u, "/v99.0.0/README.md")) { readfile($d . "/" . trim(file_get_contents("$d/mode")) . ".md"); return true; }'
    ' http_response_code(404); return true;')
open(GH + "/mode", "w").write("ok")
srv = subprocess.Popen(["php", "-S", "127.0.0.1:8093", GH + "/router.php"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1)
DK = dict(ALIGN_DOCKER="1", ALIGN_GITHUB_API="http://127.0.0.1:8093", ALIGN_GITHUB_RAW="http://127.0.0.1:8093", ALIGN_RELEASE_SIGNERS=None)
try:
    if os.path.exists(W + "/agent/update.json"): os.unlink(W + "/agent/update.json")
    r = agent("check", timeout=90, **DK)
    u = json.load(open(W + "/agent/update.json")) if os.path.exists(W + "/agent/update.json") else {}
    ok(r.returncode == 0 and "TypeError" not in r.stderr and "Fatal" not in r.stderr and u.get("latest") == "99.0.0" and u.get("mode") == "signed",
       "hostile JSON from GitHub (wrong types) doesn't stop the check; only a vX.Y.Z tag name counts: " + r.stderr[-200:] + json.dumps(u)[:200])
    ch = u.get("changes", [])
    ok([c["subject"] for c in ch] == ["S" * 300, "Fix a thing"] and ch[0]["sha"] == "aaaaaaa" and ch[1]["date"] == "" and "Array" not in json.dumps(u),
       "commits with fields of the wrong type are skipped or emptied, and a subject is cut to 300 characters: " + json.dumps([[c["subject"][:20], c["date"]] for c in ch]))
    open(GH + "/mode", "w").write("bad")
    t0 = time.time()
    try:
        r = agent("check", timeout=90, **DK); timed_out = False
    except subprocess.TimeoutExpired:
        timed_out = True
    took = time.time() - t0
    u = json.load(open(W + "/agent/update.json")) if os.path.exists(W + "/agent/update.json") else {}
    ok(not timed_out and took < 30 and u.get("notes") == [{"version": "99.0.0", "title": "Real thing", "text": "it works.", "items": []}],
       "a README with a 1 MB line of spaces is parsed in linear time (%.1fs), and the real note after it is still found: %s" % (took, json.dumps(u.get("notes"))[:150]))
finally:
    srv.terminate()

# ---- the git update check: a commit subject that isn't UTF-8, or is huge, can't empty update.json
G = "git -c user.name=Test -c user.email=test@example.com -c commit.gpgsign=false"
subprocess.run(f"git init -q --bare -b main {W}/remote.git && git clone -q {W}/remote.git {W}/work && cd {W}/work && git checkout -q -B main"
               f" && echo {CUR} > VERSION && {G} add VERSION && {G} commit -qm 'v{CUR}' && git push -q origin HEAD:main && git clone -q {W}/remote.git {W}/app",
               shell=True, capture_output=True)
# written as a raw commit object: "git commit" would turn the Latin-1 byte into UTF-8
open(W + "/commit", "wb").write(b"tree TREE\nparent PARENT\nauthor T <t@example.com> 1790000000 +0000\ncommitter T <t@example.com> 1790000000 +0000\n\nCaf\xe9 "
                                + b"y" * 1000 + b"\n")
subprocess.run(f"cd {W}/work && echo x > notes.txt && git add notes.txt && sed -i \"s/TREE/$(git write-tree)/; s/PARENT/$(git rev-parse HEAD)/\" {W}/commit"
               f" && git update-ref refs/heads/main $(git hash-object -t commit -w --stdin < {W}/commit) && git push -q origin main", shell=True, capture_output=True)
agent("check", ALIGN_APP_DIR=W + "/app")
try:
    u = json.load(open(W + "/agent/update.json"))
except Exception as e:
    u = {"unreadable": str(e)}
ch = u.get("changes") or [{}]
ok(u.get("mode") == "branch" and u.get("behind") == 1 and len(ch[0].get("subject", "")) == 300 and ch[0]["subject"].startswith("Caf? y"),
   "a commit subject that isn't UTF-8 and is 1000 characters long: update.json is still written, the subject cut to 300: " + json.dumps(u)[:200])

# ---- systemd units: the timer-run root services are sandboxed; the job service can't be (it runs the installer)
unit = lambda n: open(ROOT + "/deploy/systemd/" + n).read()
for n in ["msp-align-nightly.service", "msp-align-update-check.service"]:
    t = unit(n)
    ok(all(f"\n{o}\n" in t for o in ["NoNewPrivileges=yes", "PrivateTmp=yes", "ProtectSystem=full", "ProtectHome=read-only", "ProtectKernelModules=yes", "RestrictSUIDSGID=yes"])
       and "User=" not in t, f"{n}: runs as root, but can't change /usr, /etc or home folders, gain privileges or load kernel modules")
t = unit("msp-align-agent.service")
ok(not re.search(r"^(ProtectSystem|NoNewPrivileges|ProtectKernelModules)=", t, re.M) and "KillMode=process" in t, "msp-align-agent.service stays unsandboxed: an update runs the installer (packages, /etc, services)")

for d in [WORK, W]:
    os.chmod(d, 0o755)
shutil.rmtree(W, ignore_errors=True)
# ---- what a killed agent leaves behind is swept by the next run (a taken request can hold a restore key)
os.makedirs(W + "/run/keys", exist_ok=True)
open(W + "/config.php", "w").write("<?php\nreturn json_decode(<<<'JSON'\n" + json.dumps(CONF) + "\nJSON, true);\n")  # (earlier steps may have replaced it)
open(W + "/run/keys/request-deadbeefdeadbeef", "w").write('{"key":"AGE-SECRET-KEY-1LEFTOVER"}')
open(W + "/run/db-abcdef012345.cnf", "w").write("[client]\npassword=x\n")
agent("run")
ok(not os.path.exists(W + "/run/keys/request-deadbeefdeadbeef") and not os.path.exists(W + "/run/db-abcdef012345.cnf"),
   "a request file and a database login left by a killed agent are removed by the next run")
done()
