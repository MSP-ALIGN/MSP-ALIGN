"""1.44 Docker support, the parts that run without Docker (the image itself is tested by tests/docker/smoke.py in CI):
trusted proxy ranges, the agent's Docker mode, and the image's files staying in step with install.sh."""
import os, re, json, shutil, subprocess, tempfile, time
from lib import *

# ---- trusted proxies: exact addresses as before; ranges only in the Docker image
tmp = tempfile.mkdtemp(prefix="align-docker-")
LIST = "['10.1.2.3', '172.30.0.0/16', 'fd00::/8', '192.168.1.0/25', 'bad/99', ' 10.9.9.9']"
def write_cfg(extra):
    p = tmp + "/config-%d.php" % len(extra)
    open(p, "w").write("<?php return array_merge(require %r, ['trusted_proxies' => %s%s]);" % (CONFIG, LIST, extra))
    return p
probe = r'''require $argv[1]; $out = [];
foreach (array_slice($argv, 2) as $ip) { $out[$ip] = trusted_proxy($ip); }
$_SERVER["REMOTE_ADDR"] = "172.30.9.9"; $_SERVER["HTTP_X_FORWARDED_FOR"] = "203.0.113.7"; $_SERVER["HTTP_X_FORWARDED_PROTO"] = "https";
$out["client_ip"] = client_ip(); $out["https"] = is_https();
$_SERVER["REMOTE_ADDR"] = "8.8.8.8"; $out["client_ip_untrusted"] = client_ip(); $out["https_untrusted"] = is_https();
echo json_encode($out);'''
IPS = ["10.1.2.3", "10.1.2.4", "172.30.200.1", "172.31.0.1", "fd12::1", "fe80::1", "192.168.1.127", "192.168.1.128", "nonsense", "10.9.9.9", "::ffff:10.1.2.3"]
def run_probe(cfg):
    r = subprocess.run(["php", "-r", probe, BOOTSTRAP, *IPS], env=dict(ENV, ALIGN_CONFIG=cfg), capture_output=True, text=True)
    return json.loads(r.stdout or "{}"), r.stderr[-200:]
got, err = run_probe(write_cfg(", 'install_type' => 'docker'"))
ok(got.get("10.1.2.3") is True and got.get("10.1.2.4") is False, "Docker: an exact proxy address matches exactly: " + err)
ok(got.get("172.30.200.1") is True and got.get("172.31.0.1") is False and got.get("fd12::1") is True and got.get("fe80::1") is False, "Docker: IPv4 and IPv6 ranges")
ok(got.get("192.168.1.127") is True and got.get("192.168.1.128") is False and got.get("nonsense") is False and got.get("::ffff:10.1.2.3") is False, "Docker: a /25 boundary; junk and mapped addresses never match")
ok(got.get("client_ip") == "203.0.113.7" and got.get("https") is True, "Docker: a proxy inside a range passes on the visitor's address and https")
ok(got.get("client_ip_untrusted") == "8.8.8.8" and got.get("https_untrusted") is False, "anyone else's forwarded headers are ignored")
got, err = run_probe(write_cfg(""))
ok(got.get("10.1.2.3") is True and got.get("172.30.200.1") is False and got.get("fd12::1") is False and got.get("10.9.9.9") is False and got.get("client_ip") == "172.30.9.9",
   "dedicated server: exact addresses only, exactly as before (ranges and padded entries don't match): " + err)
ok(got.get("https") is False, "dedicated server: a range doesn't make a request https")

# ---- the agent in Docker mode: no git, no self-update
T = tmp + "/agent"
for d in ["agent/jobs", "agent/safety", "agent/work", "data/downloads", "data/restore", "run/requests", "run/keys"]:
    os.makedirs(T + "/" + d)
AENV = dict(ENV, ALIGN_APP_DIR=ROOT, ALIGN_DATA_DIR=T + "/data", ALIGN_AGENT_DIR=T + "/agent", ALIGN_RUN_DIR=T + "/run", ALIGN_RECIPIENT=SYS + "/recipient.txt",
            ALIGN_RUNAS="root", ALIGN_SYSTEMCTL="none", ALIGN_DOCKER="1", ALIGN_UPDATE_CHECK_URL="http://127.0.0.1:9/nothing", ALIGN_PRIVKEY_FILE=T + "/nokey.txt")
req = {"id": "20260929-120000-abcdef", "action": "update", "user": "docker_e2e", "params": {}, "created": "2026-09-29T12:00:00-07:00"}
json.dump(req, open(T + "/run/requests/" + req["id"] + ".json", "w"))
os.chmod(T + "/run/requests/" + req["id"] + ".json", 0o600)
a = subprocess.run(["php", ROOT + "/scripts/agent.php", "run"], env=AENV, capture_output=True, text=True, timeout=120)
j = json.load(open(T + "/agent/jobs/" + req["id"] + ".json")) if os.path.exists(T + "/agent/jobs/" + req["id"] + ".json") else {}
ok(j.get("state") == "failed" and "docker compose pull" in (j.get("message") or ""), "in Docker, an update request is refused with the pull command: " + str(j.get("message")) + a.stderr[-200:])
ok(not os.path.exists(T + "/agent/maintenance.json") and not os.listdir(T + "/agent/safety"), "no maintenance mode or safety copy for a refused update")
c = subprocess.run(["php", ROOT + "/scripts/agent.php", "check"], env=AENV, capture_output=True, text=True, timeout=120)
u = json.load(open(T + "/agent/update.json")) if os.path.exists(T + "/agent/update.json") else {}
ok(u.get("docker") is True and u.get("source") == "github" and u.get("current") == open(ROOT + "/VERSION").read().strip(), "in Docker, the update check asks GitHub over HTTPS (no git): " + json.dumps(u)[:200])
ok(u.get("error") is None or u["error"].startswith("Could not reach GitHub to check for updates."), "an offline check says what to fix")
# ... and with GitHub answering (a local stand-in): the newer version and what changed since this one's release commit
CUR = open(ROOT + "/VERSION").read().strip()
fake = tmp + "/gh"
os.makedirs(fake)
commits = [
    {"sha": "a" * 40, "parents": [{}], "commit": {"message": "Add a shiny thing\n\nLonger text.\n\nCo-Authored-By: Someone <x@y>", "committer": {"date": "2026-10-02T10:00:00Z"}}},
    {"sha": "b" * 40, "parents": [{}, {}], "commit": {"message": "Merge pull request #99 from x/develop", "committer": {"date": "2026-10-02T09:00:00Z"}}},
    {"sha": "c" * 40, "parents": [{}], "commit": {"message": "Fix another thing", "committer": {"date": "2026-10-01T10:00:00Z"}}},
    {"sha": "d" * 40, "parents": [{}], "commit": {"message": "v%s: this release" % CUR, "committer": {"date": "2026-09-30T10:00:00Z"}}},
    {"sha": "e" * 40, "parents": [{}], "commit": {"message": "Older, already installed", "committer": {"date": "2026-09-01T10:00:00Z"}}},
]
open(fake + "/router.php", "w").write('<?php $u = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
    ' if (str_ends_with($u, "/main/VERSION")) { echo "9.9.8\\n"; return true; }'
    ' if (str_ends_with($u, "/tags")) { header("Content-Type: application/json"); echo json_encode([["name" => "v9.9.10-beta.1"], ["name" => "v9.9.9"], ["name" => "v1.0.0"], ["name" => "junk"]]); return true; }'
    ' if (str_ends_with($u, "/v9.9.9/README.md")) { readfile(__DIR__ . "/README.md"); return true; }'
    ' if (str_contains($u, "/commits")) { header("Content-Type: application/json"); readfile(__DIR__ . "/commits.json"); return true; }'
    ' http_response_code(404); return true;')
json.dump(commits, open(fake + "/commits.json", "w"))
open(fake + "/README.md", "w").write("# MSP-ALIGN\n\n## What's new\n\n- **Later (9.9.10):** not yet.\n- **Big thing (9.9.9):** it does more.\n  - **One:** a part.\n"
                                     "- **Old (%s):** installed already.\n- **A feature:** no version.\n\n## Install\n\n- **Step (9.9.9):** not notes.\n" % CUR)
srv = subprocess.Popen(["php", "-S", "127.0.0.1:8094", fake + "/router.php"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1)
subprocess.run(["php", ROOT + "/scripts/agent.php", "check"], env=dict(AENV, ALIGN_GITHUB_API="http://127.0.0.1:8094", ALIGN_GITHUB_RAW="http://127.0.0.1:8094"),
               capture_output=True, text=True, timeout=120)
u = json.load(open(T + "/agent/update.json"))
# without a release key (a fork), the branch's VERSION
subprocess.run(["php", ROOT + "/scripts/agent.php", "check"], env=dict(AENV, ALIGN_GITHUB_API="http://127.0.0.1:8094", ALIGN_GITHUB_RAW="http://127.0.0.1:8094", ALIGN_RELEASE_SIGNERS="none", ALIGN_AGENT_TEST="1"),
               capture_output=True, text=True, timeout=120)
srv.terminate()
ub = json.load(open(T + "/agent/update.json"))
ok(ub.get("latest") == "9.9.8" and ub.get("mode") is None, "Docker check without a release key: the branch's version")
ok(u.get("latest") == "9.9.9" and u.get("mode") == "signed" and u.get("available") is True and u.get("error") is None, "Docker check: the newest release tag on GitHub is offered (images are built from release tags; pre-releases left out): " + json.dumps(u)[:160])
ok([c["subject"] for c in u.get("changes", [])] == ["Add a shiny thing", "Fix another thing"] and u.get("behind") == 2, "Docker check: what's new since this release, without merges or older commits")
ok("Co-Authored" not in json.dumps(u) and u["changes"][0]["body"] == "Longer text.", "Docker check: commit trailers left out")
ok(u.get("notes") == [{"version": "9.9.9", "title": "Big thing", "text": "it does more.", "items": [{"level": 1, "text": "**One:** a part."}]}],
   "Docker check: the release's notes from its README, only for the versions after this one: " + json.dumps(u.get("notes"))[:200])
env_no = {k: v for k, v in AENV.items() if k != "ALIGN_DOCKER"}
subprocess.run(["php", ROOT + "/scripts/agent.php", "check"], env=dict(env_no, ALIGN_APP_DIR=SYS + "/work"), capture_output=True, text=True, timeout=120)
u2 = json.load(open(T + "/agent/update.json"))
ok("docker" not in u2 and u2.get("branch"), "without Docker the check is the git one, as before")
shutil.rmtree(tmp, ignore_errors=True)

# ---- the image's files stay in step with the dedicated install
inst = open(ROOT + "/install.sh").read()
block = re.search(r'cat >"/etc/php/\$PHPV/apache2/conf.d/99-msp-align.ini" <<\'INI\'\n(.*?)\nINI', inst, re.S).group(1)
dini = "\n".join(l for l in open(ROOT + "/docker/php.ini").read().splitlines() if not l.startswith(";"))
ok(dini.strip() == block.strip(), "docker/php.ini matches the PHP settings install.sh writes")
perf = re.search(r'99-msp-align-performance.ini" <<\'INI\'\n(.*?)\nINI', inst, re.S).group(1)
ok(open(ROOT + "/docker/php-performance.ini").read().strip() == perf.strip(), "docker/php-performance.ini matches install.sh")
units = sorted(f for f in os.listdir(ROOT + "/deploy/systemd") if f.endswith(".service"))
sched = open(ROOT + "/docker/scheduler.php").read()
for unit in units:
    cmd = re.search(r"ExecStart=\S+ \S+?/(bin/align|scripts/agent\.php) (\S+)", open(ROOT + "/deploy/systemd/" + unit).read())
    ok(cmd and (f"'{cmd.group(2)}'" in sched), f"the scheduler runs what {unit} runs ({cmd.group(2) if cmd else '?'})")
ok(subprocess.run(["bash", "-n", ROOT + "/docker/entrypoint.sh"]).returncode == 0 and subprocess.run(["php", "-l", ROOT + "/docker/scheduler.php"], capture_output=True).returncode == 0,
   "entrypoint and scheduler parse")
compose = open(ROOT + "/compose.yaml").read()
envx = open(ROOT + "/.env.example").read()
ent = open(ROOT + "/docker/entrypoint.sh").read()
for var in re.findall(r"^\s+(ALIGN_[A-Z_]+):", compose, re.M):
    ok(var in ent, f"compose passes {var}, which the entrypoint reads")
caddy = open(ROOT + "/compose.caddy.yaml").read()
for var in re.findall(r"^(ALIGN_[A-Z_]+)=", envx, re.M):
    ok(var in compose or var in caddy, f".env.example's {var} is used by the compose files")
ok("install_type" not in inst, "install.sh writes nothing Docker-specific")
done()
