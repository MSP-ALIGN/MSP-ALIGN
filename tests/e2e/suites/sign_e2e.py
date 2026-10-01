"""2.0: signed releases. With a release key, servers take only release tags signed with it (scripts/release.sh), never
the branch head, an unsigned, lightweight, renamed or moved tag, a tag signed with another key or one whose VERSION
doesn't match. The key file is the installed one: a release adds a key only when signed by a key trusted now.
Also: install.sh's checkout (the first update from 1.x) and the Docker publish workflow's check."""
from lib import *
import os, re, shutil, subprocess, json

W = WORK + "/sign"
shutil.rmtree(W, ignore_errors=True); os.makedirs(W)
def sh(cmd, cwd=None, env=None):
    r = subprocess.run(cmd, shell=True, cwd=cwd, env=env, capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr
G = "git -c gpg.ssh.program=ssh-keygen -c user.name=Release -c user.email=releases@mspalign.org -c commit.gpgsign=false -c tag.gpgsign=false"
def line(k):
    pub = open(f"{W}/{k}.pub").read().split()
    return f'releases@mspalign.org namespaces="git" {pub[0]} {pub[1]}\n'
def fp(k): return sh(f"ssh-keygen -lf {W}/{k}.pub")[1].split()[1]
for k in ["key", "other", "new"]:
    sh(f"ssh-keygen -q -t ed25519 -N '' -C releases@mspalign.org -f {W}/{k}")
sh(f"git init -q --bare {W}/remote.git && git clone -q {W}/remote.git {W}/work")
WK = W + "/work"
def release(ver, sign=None, version_file=None, tag=True):
    open(WK + "/VERSION", "w").write((version_file or ver) + "\n")
    sh(f"{G} add -A && {G} commit -q --allow-empty -m 'v{ver}'", cwd=WK)
    if sign:
        return sh(f"{G} -c gpg.format=ssh -c user.signingkey={W}/{sign} tag -f -s v{ver} -m 'v{ver}'", cwd=WK)
    if tag:
        return sh(f"{G} tag -f -a v{ver} -m 'v{ver} (unsigned)'", cwd=WK)
    return (0, "")
def push(): sh(f"{G} push -q -f origin HEAD:main --tags", cwd=WK)
# the app, with release.sh, and the test key as its release key
sh(f"rsync -a --exclude .git --exclude tests {ROOT}/ {WK}/", cwd=WK)
open(WK + "/deploy/release-signers", "w").write("# test release key\n" + line("key"))
sh(f"{G} add -A && {G} commit -q -m code", cwd=WK)
release("2.0.0", "key")
release("2.0.1", "key")
release("2.0.2")                                     # annotated, not signed
release("2.0.3", "other")                            # signed, but not with a release key
release("2.0.4", "key", version_file="2.0.9")        # signed, but VERSION doesn't match the tag
release("2.0.5", tag=False)                          # the branch head: no tag at all
push()
sh(f"git clone -q {W}/remote.git {W}/app && git -C {W}/app reset -q --hard v2.0.0")
for d in ["data/downloads", "data/restore", "agent/jobs", "agent/safety", "agent/work", "run/requests", "run/keys"]:
    os.makedirs(W + "/" + d, exist_ok=True)
AENV = dict(ENV, ALIGN_APP_DIR=W + "/app", ALIGN_DATA_DIR=W + "/data", ALIGN_AGENT_DIR=W + "/agent", ALIGN_RUN_DIR=W + "/run", ALIGN_RECIPIENT=W + "/none.txt",
            ALIGN_RUNAS="root", ALIGN_SYSTEMCTL="none", ALIGN_INSTALL_CMD="true", ALIGN_UPDATE_CHECK_URL="http://127.0.0.1:9/x", ALIGN_AGENT_TEST="1",
            PATH="/usr/local/bin:/usr/bin:/bin")
def agent(*a, **env): return subprocess.run(["php", ROOT + "/scripts/agent.php", *a], env=dict(AENV, **env), capture_output=True, text=True, timeout=180)
def check(**env):
    agent("check", **env); return json.load(open(W + "/agent/update.json"))
def rel(*a): return sh(f"bash {W}/app/scripts/release.sh {W}/app {W}/app/deploy/release-signers " + " ".join(a))
def commit_of(ref, repo="app"): return sh(f"git -C {W}/{repo} rev-parse '{ref}^{{commit}}'")[1].strip()
def head(): return sh(f"git -C {W}/app rev-parse HEAD")[1].strip()
def version(): return open(W + "/app/VERSION").read().strip()
sh(f"git -C {W}/app fetch -q --force --tags origin")

# ---- release.sh on its own
rc, out = rel("2.0.0", "2>&1")
last = out.strip().splitlines()[-1] if out.strip() else ""
ok(rc == 0 and last == "v2.0.1 " + commit_of("v2.0.1"), "the newest release signed with the release key is v2.0.1, with its commit: " + out.replace("\n", " | "))
ok(all(f"UNSIGNED v{v}" in out for v in ["2.0.2", "2.0.3", "2.0.4"]), "unsigned, other-key and VERSION-mismatch tags are named, not picked")
rc, out = rel("2.0.1", "2>/dev/null")
ok(rc == 3 and out == "", "nothing newer than 2.0.1 is signed: exit 3")
ok(rel("--head")[0] == 0, "--head: v2.0.0's commit is a signed release")
rc, out = sh(f"bash {W}/app/scripts/release.sh {W}/app {W}/nothing 0")
ok(rc == 2, "no signers file: exit 2 (never 'no key, so anything goes')")

# ---- the update check and the update (the installed key file; no test override)
u = check()
ok(u.get("mode") == "signed" and u.get("latest") == "2.0.1" and u.get("release_tag") == "v2.0.1" and u.get("available") is True and u.get("head_signed") is True,
   "the check offers the signed v2.0.1, not the branch head (2.0.5): " + json.dumps({k: u.get(k) for k in ["mode", "latest", "release_tag", "available", "head_signed", "error"]}))
ok(u.get("signers") == [fp("key")] and sorted(u.get("unsigned", [])) == ["v2.0.2", "v2.0.3", "v2.0.4"] and "signed with the MSP-ALIGN release key" in u.get("warning", ""), "the check shows the key's fingerprint, names the unsigned tags and warns")
ok(q("select id from audit_log where action='system.update_unsigned' order by id desc limit 1"), "an unsigned release tag is in the audit log (and raises a security alert)")
ok(check(ALIGN_RELEASE_SIGNERS="none", ALIGN_AGENT_TEST="0").get("mode") == "signed", "the test override is ignored outside tests (ALIGN_AGENT_TEST)")
r = agent("update-cli")
ok(r.returncode == 0 and head() == commit_of("v2.0.1") and version() == "2.0.1", "the update installs exactly the signed v2.0.1 commit: " + (r.stdout + r.stderr)[-200:])
r = agent("update-cli")
ok(r.returncode == 0 and version() == "2.0.1" and head() == commit_of("v2.0.1") and "installing 2.0.1 again" in r.stdout + r.stderr,
   "with no newer signed release, the same signed release is installed again (a repair), nothing else")
ok(q("select id from audit_log where action='system.reinstalled' order by id desc limit 1"), "a repair is audited as a reinstall, not an update")

# ---- tags that look like releases but aren't
sh(f"{G} tag v2.0.7", cwd=WK)                                               # lightweight
tag_obj = sh(f"git -C {WK} rev-parse v2.0.1")[1].strip()
sh(f"git -C {WK} update-ref refs/tags/v2.0.10 {tag_obj}")                   # v2.0.1's signed tag, under another name
push()
u = check()
ok(u.get("latest") == "2.0.1" and u.get("available") is False and {"v2.0.7", "v2.0.10"} <= set(u.get("unsigned", [])), "a lightweight tag and a signed tag under another name are refused: " + json.dumps(u.get("unsigned")))

# a new signed release is picked up; one force-moved to other code afterwards is refused
release("2.0.6", "key"); push()
u = check()
ok(u.get("latest") == "2.0.6" and u.get("available") is True, "a newly signed release is offered")
good = commit_of("v2.0.6", "work")
open(WK + "/evil.txt", "w").write("x\n"); release("2.0.6")                  # same name, moved to unsigned code
push()
u = check()
ok(u.get("available") is False and "v2.0.6" in u.get("unsigned", []), "a release tag moved to other code (unsigned) is refused")
r = agent("update-cli")
ok(version() == "2.0.1" and head() == commit_of("v2.0.1") and not os.path.exists(W + "/app/evil.txt") and "v2.0.6" in r.stdout + r.stderr and "Refused because not signed" in r.stdout + r.stderr,
   "and not installed: only the signed 2.0.1 is installed again, and the refusal is said: " + (r.stdout + r.stderr)[-200:])
sh(f"git -C {WK} rm -q evil.txt && {G} commit -q -m 'drop'", cwd=WK)
release("2.0.6", "key"); push()
r = agent("update-cli")
ok(r.returncode == 0 and version() == "2.0.6" and head() == commit_of("v2.0.6"), "re-signed, it installs: " + (r.stdout + r.stderr)[-200:])

# ---- pre-releases are never offered, and a pre-release VERSION is older than its release
release("2.1.0-beta.1", "key"); push()
ok(check().get("available") is False, "a signed pre-release tag (v2.1.0-beta.1) is not offered")
sh(f"git -C {W}/app fetch -q --force --tags origin")

# ---- key rotation: a release signed with the key trusted now adds a new one; the next is signed with the new one
open(WK + "/deploy/release-signers", "w").write("# test release keys\n" + line("key") + line("new"))
release("2.1.0", "key")
open(WK + "/deploy/release-signers", "w").write("# test release key\n" + line("new"))
release("2.1.1", "new")
push()
sh(f"git -C {W}/app fetch -q --force --tags origin")
rc, out = rel("2.1.0-beta.1", "2>/dev/null")
ok(rc == 0 and out.startswith("v2.1.0 "), "2.1.0 is newer than 2.1.0-beta.1, and v2.1.1 (new key, not trusted yet) is skipped: " + out)
u = check()
ok(u.get("latest") == "2.1.0" and "v2.1.1" in u.get("unsigned", []), "before the rotation, the new key's release isn't trusted: " + json.dumps({k: u.get(k) for k in ["latest", "unsigned"]}))
r = agent("update-cli")
ok(r.returncode == 0 and version() == "2.1.0", "the release adding the new key installs (signed with the old one)")
u = check()
ok(u.get("latest") == "2.1.1" and u.get("available") is True and sorted(u.get("signers", [])) == sorted([fp("key"), fp("new")]), "then the new key's release is offered, from the installed key file")
r = agent("update-cli")
ok(r.returncode == 0 and version() == "2.1.1" and check().get("signers") == [fp("new")], "and installs; the old key is no longer trusted")
open(WK + "/deploy/release-signers", "w").write("# test release key\n" + line("other"))
release("2.1.2", "other"); push()                                           # another key vouching for itself
u = check()
ok(u.get("available") is False and "v2.1.2" in u.get("unsigned", []), "a release signed with an unknown key that adds that key is refused")

# ---- a head that isn't a signed release (the first update from 1.x took the branch head): the same version counts
sh(f"git -C {W}/app reset -q --hard v2.1.1")                                # 2.1.1's content, on a commit that isn't the tagged one
sh(f"git -C {W}/app -c user.name=x -c user.email=x@x -c commit.gpgsign=false commit -q --allow-empty -m head")
u = check()
ok(u.get("head_signed") is False and u.get("release_tag") == "v2.1.1" and u.get("available") is True, "an unsigned head of the same version is offered the signed release: " + json.dumps({k: u.get(k) for k in ["head_signed", "release_tag", "available", "latest"]}))
# the Updates page, showing this check (the web app reads the agent state in sys/agent)
WEB = WORK + "/sys/agent/update.json"; saved = open(WEB).read() if os.path.exists(WEB) else None
os.makedirs(os.path.dirname(WEB), exist_ok=True); shutil.copy(W + "/agent/update.json", WEB)
t = login("admin@example.com", "LongPassword123!").get(B + "/settings/system").text
if saved is None: os.unlink(WEB)
else: open(WEB, "w").write(saved)
ok(not errs(t) and fp("new") in t and "isn&#039;t a signed release yet" in t.replace("'", "&#039;"), "the Updates page shows the full key fingerprint and that the code isn't a signed release yet")
r = agent("update-cli")
ok(r.returncode == 0 and head() == commit_of("v2.1.1"), "and the update moves it onto the signed commit")

# ---- install.sh's checkout (sliced out of install.sh: the part between the lock and the code download)
src = open(ROOT + "/install.sh").read()
body = src[src.index("TRUST=$(mktemp -d)"):src.index('if [[ -d "$APP_DIR/.git" ]]; then', src.index("TRUST=$(mktemp -d)"))]
open(W + "/co.sh", "w").write("set -Eeuo pipefail\nlog(){ echo \"LOG $*\"; }\nwarn(){ echo \"WARN $*\"; }\ndie(){ echo \"DIE $*\"; exit 1; }\n"
                              "REPO=test\nAPP_DIR=$1\nMODE=${3:-install}\nBRANCH=main\nexec() { echo \"EXEC $*\"; exit 0; }\n" + body + "\ntrust_now\nrelease_checkout $2\n")
sh(f"git -C {W}/app reset -q --hard v2.1.0")
rc, out = sh(f"bash {W}/co.sh {W}/app update")
ok(rc == 0 and version() == "2.1.1" and head() == commit_of("v2.1.1") and fp("new") in out and "v2.1.2" in out, "install.sh: updates to the newest signed release, names the key and the refused tags: " + out[-300:])
rc, out = sh(f"bash {W}/co.sh {W}/app update")
ok(rc == 0 and "EXEC" not in out, "install.sh: a new install goes on with the installer it runs")
sh(f"git -C {W}/app reset -q --hard v2.1.0")
rc, out = sh(f"bash {W}/co.sh {W}/app update upgrade")
ok(rc == 0 and version() == "2.1.1" and f"EXEC env ALIGN_CODE_READY=1 ALIGN_REEXEC=1 ALIGN_BRANCH=main bash {W}/app/install.sh --upgrade" in out, "install.sh: an upgrade goes on with the new release's own installer: " + out[-200:])
rc, out = sh(f"bash {W}/co.sh {W}/app update upgrade")
ok(rc == 0 and "keeping 2.1.1" in out and version() == "2.1.1", "install.sh: nothing newer, keeps it")
sh(f"git -C {W}/app reset -q --hard v2.1.1 && git -C {W}/app -c user.name=x -c user.email=x@x -c commit.gpgsign=false commit -q --allow-empty -m head")   # like 1.x's update: the branch head
rc, out = sh(f"bash {W}/co.sh {W}/app update")
ok(rc == 0 and head() == commit_of("v2.1.1"), "install.sh: an unsigned head moves onto the signed release of its version: " + out[-300:])
sh(f"rm -rf {W}/fresh && git clone -q -b main {W}/remote.git {W}/fresh")
rc, out = sh(f"bash {W}/co.sh {W}/fresh fresh")
ok(rc == 0 and commit_of("HEAD", "fresh") == commit_of("v2.1.2", "work"), "install.sh fresh: trusts the key file it downloads (a new install trusts GitHub once; here the other key's 2.1.2): " + out[-200:])
os.remove(W + "/fresh/scripts/release.sh")
rc, out = sh(f"bash {W}/co.sh {W}/fresh fresh")
ok(rc != 0 and "no scripts/release.sh" in out, "install.sh: a key without the checker stops instead of installing unchecked code")

# ---- install.sh's lock: the update service holds it and runs the installer (1.x without ALIGN_CODE_READY): no wait
lk = src[src.index("lock_held_by_caller() {"):src.index("# Signed releases (2.0): on the main branch, with a key")]
open(W + "/lock.sh", "w").write("set -Eeuo pipefail\ndie(){ echo \"DIE $*\"; exit 1; }\nAGENT_DIR=$1\n" + lk + "echo LOCKED\n")
os.makedirs(W + "/lk", exist_ok=True)
holder = f"import fcntl,subprocess,sys; f=open('{W}/lk/agent.lock','a'); fcntl.flock(f,fcntl.LOCK_EX); r=subprocess.run(sys.argv[1:],capture_output=True,text=True,timeout=60); print(r.returncode, r.stdout, r.stderr)"
r = subprocess.run(["python3", "-c", holder, "bash", "-c", f"bash {W}/lock.sh {W}/lk"], capture_output=True, text=True, timeout=90)
ok(r.stdout.startswith("0 ") and "LOCKED" in r.stdout, "install.sh run by the process holding the agent lock doesn't wait for it: " + r.stdout[-200:])
hold = subprocess.Popen(["python3", "-c", f"import fcntl,time; f=open('{W}/lk/agent.lock','a'); fcntl.flock(f,fcntl.LOCK_EX); print('held',flush=True); time.sleep(60)"], stdout=subprocess.PIPE, text=True)
hold.stdout.readline()
open(W + "/lock2.sh", "w").write(open(W + "/lock.sh").read().replace("flock -w 1800", "flock -w 2"))
rc, out = sh(f"bash {W}/lock2.sh {W}/lk")
hold.kill(); hold.wait()
ok(rc != 0 and "DIE" in out and "LOCKED" not in out, "one run by anything else waits for it (and gives up after the time limit): " + out[-200:])

# ---- the Docker publish workflow's check (same script, against the previous release's keys)
wf = open(ROOT + "/.github/workflows/docker.yml").read()
m = re.search(r"- name: Check the release tag is signed with the release key\n        run: \|\n((?:          .*\n|\s*\n)+)", wf)
step = "\n".join(l[10:] for l in m.group(1).splitlines()).replace("sudo apt-get install", "true || sudo apt-get install")
open(W + "/wf.sh", "w").write(step)
def publish(tag):
    sh(f"rm -rf {W}/ci && git clone -q -b main {W}/remote.git {W}/ci && git -C {W}/ci checkout -q {tag}")
    return sh(f"bash {W}/wf.sh", cwd=W + "/ci", env=dict(os.environ, GITHUB_REF_NAME=tag, GITHUB_SHA=commit_of(tag, "work"), RUNNER_TEMP=W))
rc, out = publish("v2.1.1")
ok(rc == 0 and "keys of v2.1.0" in out, "Docker publish: v2.1.1 is checked against v2.1.0's keys, and passes: " + out[-200:])
rc, out = publish("v2.1.2")
ok(rc != 0, "Docker publish: v2.1.2 (a key vouching for itself) is refused")
rc, out = publish("v2.0.2")
ok(rc != 0, "Docker publish: an unsigned tag is refused")
rc, out = publish("v2.1.0-beta.1")
ok(rc == 0, "Docker publish: a signed pre-release tag passes (servers never take it): " + out[-200:])

# ---- an unsigned head with no signed release of its version: the update fails and changes nothing (no repair)
sh(f"git -C {W}/app checkout -q -B scratch v2.1.1 && git -C {W}/app -c user.name=x -c user.email=x@x -c commit.gpgsign=false commit -q --allow-empty -m local && printf '2.1.9\\n' > {W}/app/VERSION && git -C {W}/app -c user.name=x -c user.email=x@x -c commit.gpgsign=false commit -qam 'v2.1.9 local'")
before = head()
r = agent("update-cli")
ok(r.returncode != 0 and head() == before and "no newer release signed" in (r.stdout + r.stderr), "an unsigned head with nothing signed to move to: refused, nothing reset or reinstalled")
sh(f"git -C {W}/app checkout -q --detach v2.1.1")

# ---- the checker missing: an error, never an unchecked update
os.rename(W + "/app/scripts/release.sh", W + "/release.sh.bak")
u = check()
r = agent("update-cli")
os.rename(W + "/release.sh.bak", W + "/app/scripts/release.sh")
ok(u.get("mode") == "signed" and u.get("error") and u.get("available") is False and r.returncode != 0 and head() == commit_of("v2.1.1"), "without scripts/release.sh: the check shows an error and nothing is installed: " + str(u.get("error")))

# ---- branch mode: a fork or a development copy without a key, and a test server on another branch
u = check(ALIGN_RELEASE_SIGNERS="none")
ok(u.get("mode") == "branch", "without a release key: branch mode")
sh(f"{G} push -q origin HEAD:develop", cwd=WK)
u = check(ALIGN_BRANCH="develop")
ok(u.get("mode") == "branch" and u.get("branch") == "develop", "a test server's own branch: branch mode")
shutil.rmtree(W, ignore_errors=True)
done()
