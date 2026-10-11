"""Builds everything the suites start from, from scratch (run.sh calls this before the suites):

- two databases: the main test install (users, integration settings pointing at the mock server, one
  sync) and a fresh install (only an admin), with config files in $ALIGN_TEST_WORK
- databases from older releases for the upgrade tests, made by running that release's own code
  (git tags v1.27.1, v1.28.0, v1.29.0) against the same mock server
- keys and a small git "remote" for the updates & backups suite

All data is fictional; it comes from tests/mock-server.php.
"""
import os, sys, json, shutil, subprocess
sys.path.insert(0, os.path.dirname(__file__))
os.environ.setdefault("ALIGN_TEST_WORK", "/tmp/msp-align-tests")
WORK = os.environ["ALIGN_TEST_WORK"]
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
DB_MAIN = os.environ.get("ALIGN_TEST_DB", "align_test")
DB_FRESH = os.environ.get("ALIGN_TEST_DB_FRESH", "align_test_fresh")
SOCKET = os.environ.get("ALIGN_TEST_SOCKET", "/run/mysqld/mysqld.sock")
M = os.environ.get("ALIGN_TEST_MOCK", "http://127.0.0.1:8099")
APP_KEY = "base64:CYybsohQO/i2dS53LuIOYWLxKWBBJ8t492ZEHKwSN1g="  # test-only key
CLEAN_ENV = {"PATH": "/usr/local/bin:/usr/bin:/bin"}              # no proxies: the mock is local


for name in (DB_MAIN, DB_FRESH):
    if not name.startswith("align_test"):
        sys.exit(f"seed: refusing to use database {name!r}: test database names must start with align_test")


def sh(cmd, **kw):
    r = subprocess.run(cmd, shell=isinstance(cmd, str), capture_output=True, text=True, **kw)
    if r.returncode != 0:
        sys.exit(f"seed: command failed: {cmd}\n{r.stdout}\n{r.stderr}")
    return r.stdout


def mysql(sql, database=""):
    return sh(["mysql", "-uroot", f"--socket={SOCKET}", *([database] if database else []), "-e", sql])


def config(path, database):
    open(path, "w").write("<?php\nreturn " + php_array({
        "db": {"host": "localhost", "name": database, "user": "align_test", "pass": "testpass"},
        "app_key": APP_KEY, "timezone": os.environ.get("TZ") or "America/Los_Angeles", "trusted_proxies": [],
        "upload_path": WORK + "/uploads", "session_path": WORK + "/sessions", "php_cli": shutil.which("php") or "/usr/bin/php",
        "data_dir": WORK + "/sys/data", "agent_dir": WORK + "/sys/agent", "run_dir": WORK + "/sys/run",
        "fqdn": "align.test", "debug": True, "allow_insecure_integrations": True,
    }) + ";\n")


def php_array(v):
    if isinstance(v, dict):
        return "[" + ", ".join(f"{json.dumps(k)} => {php_array(x)}" for k, x in v.items()) + "]"
    if isinstance(v, list):
        return "[" + ", ".join(php_array(x) for x in v) + "]"
    if isinstance(v, bool):
        return "true" if v else "false"
    return json.dumps(v)


def php(code, cfg, cwd=ROOT):
    return sh(["php", "-r", f'require "{cwd}/src/bootstrap.php"; ' + code], env={**CLEAN_ENV, "ALIGN_CONFIG": cfg})


def align(cfg, *args, code=ROOT):
    return sh(["php", code + "/bin/align", *args], env={**CLEAN_ENV, "ALIGN_CONFIG": cfg})


def fresh_db(name):
    mysql(f"drop database if exists {name}; create database {name} character set utf8mb4 collate utf8mb4_unicode_ci; grant all on {name}.* to 'align_test'@'localhost'")


# ---- folders and databases
for d in ["uploads", "sessions", "snap", "shots", "sys/data", "sys/agent", "sys/run", "sys/legacy"]:
    os.makedirs(f"{WORK}/{d}", exist_ok=True)
mysql("create user if not exists 'align_test'@'localhost' identified by 'testpass'; alter user 'align_test'@'localhost' identified by 'testpass'")
CFG, FRESH = WORK + "/config.php", WORK + "/fresh.php"
config(CFG, DB_MAIN)
config(FRESH, DB_FRESH)
for name, cfg in [(DB_MAIN, CFG), (DB_FRESH, FRESH)]:
    fresh_db(name)
    align(cfg, "migrate")
print("seed: databases migrated")

# ---- users (passwords and 2FA secrets match sitecustomize.py)
USERS = """
$add = function (string $email, string $name, string $role, string $pw, ?string $totp) {
    Align\\DB::insert('users', ['email' => $email, 'name' => $name, 'role' => $role, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
        'totp_secret_enc' => $totp ? Align\\Crypto::encrypt($totp) : null, 'totp_enabled' => $totp ? 1 : 0, 'is_active' => 1,
        'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s')]);
};
"""
php(USERS + """
$add('admin@example.com', 'Alex Admin', 'admin', 'LongPassword123!', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXA');
$add('tech@example.com', 'Terry Tech', 'tech', 'TechPassword123!', 'KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU');
$add('viewer@example.com', 'Vic Viewer', 'viewer', 'ViewerPassword123!', 'MFRGGZDFMZTWQ2LKMFRGGZDFMZTWQ2LK');
""", CFG)
php(USERS + "$add('new@example.com', 'New Admin', 'admin', 'FreshAdminPass123!', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');", FRESH)

# ---- keys for the email connectors (made here, never stored in the repo)
sh(f"openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj /CN=AlignTest -keyout {WORK}/m365.key -out {WORK}/m365.crt")
sh(f"openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out {WORK}/sa.key")
json.dump({"type": "service_account", "project_id": "align-test", "private_key_id": "0123456789abcdef",
           "private_key": open(f"{WORK}/sa.key").read(), "client_email": "align@align-test.iam.gserviceaccount.com",
           "client_id": "109876543210987654321", "token_uri": M + "/google/token"}, open(f"{WORK}/sa.json", "w"), indent=2)

# ---- settings: integrations point at the mock server (tests/mock-server.php)
plain = {
    "api_enabled": "1", "backup_stale_hours": "48", "budget_msp_estimate": "1", "client_requests": "1",
    "company_email": "service@examplemsp.example", "company_name": "Example MSP Inc.",
    "dell_api_base": M, "dell_client_id": "dell-id", "lenovo_api_base": M,
    "g_auth_url": M + "/google/auth", "g_calendar_base": M + "/google/calendar", "g_gmail_base": M + "/google/gmail",
    "g_revoke_url": M + "/google/revoke", "g_token_url": M + "/google/token", "g_client_id": "123456789012-abcdef.apps.googleusercontent.com",
    "itflow_url": M, "ninja_instance": M, "ninja_client_id": "ninja-id", "veeam_url": M, "veeam_hosting_companies": "[]",
    "m365_auth": "secret", "m365_client_id": "11111111-2222-3333-4444-555555555555", "m365_graph_base": M + "/graph/v1.0",
    "m365_login_base": M + "/login", "m365_tenant": "examplemsp.onmicrosoft.com",
    "mail_from": "alerts@examplemsp.example", "mail_from_name": "Example MSP", "mail_log_days": "30", "mail_meeting_mode": "calendar",
    "mail_meeting_organizer": "owner", "mail_mode": "app", "mail_provider": "microsoft", "mail_teams_links": "1",
    "notif_backup_failed_extra": "tickets@examplemsp.example", "psa_provider": "itflow", "psa_create_assets": "1",
    "psa_import_types": "network,printer,ups,storage", "psa_sla_supported": "1", "psa_sla_sync": "1", "psa_two_way": "1",
    "psa_writeback": "fill_empty", "sla_target": "90", "source_url": "", "stale_days": "45",
    "rdap_bootstrap_url": M + "/rdap/dns.json",  # 2.10.0 domain registrations from the mock
}
secrets = {"dell_client_secret": "dsecret", "g_client_secret": "g-secret", "itflow_api_key": "itflow-key", "lenovo_client_id": "lkey",
           "m365_client_secret": "m365-secret", "ninja_client_secret": "ninja-secret", "veeam_api_key": "veeam-key",
           "g_sa_json": open(f"{WORK}/sa.json").read(), "m365_cert_pem": open(f"{WORK}/m365.crt").read(), "m365_key_pem": open(f"{WORK}/m365.key").read()}
json.dump({"plain": plain, "secret": secrets}, open(f"{WORK}/settings.json", "w"))
php(f"$s = json_decode(file_get_contents('{WORK}/settings.json'), true); foreach ($s['plain'] as $k => $v) {{ Align\\Settings::set($k, $v); }}"
    " foreach ($s['secret'] as $k => $v) { Align\\Settings::setSecret($k, $v); }", CFG)
os.unlink(f"{WORK}/settings.json")
print("seed: users and settings")

# ---- first sync (clients, contacts, assets, devices, tickets, backups from the mock)
align(CFG, "sync", "--quiet")
with open(os.path.join(os.path.dirname(__file__), "fixtures", "planning.sql")) as f:
    subprocess.run(["mysql", "-uroot", f"--socket={SOCKET}", DB_MAIN], stdin=f, check=True)
print("seed: synced from the mock server; planning fixtures loaded")

# ---- databases from older releases, made by that release's own code (upgrade tests)
SNAPS = [("v1.27.1", "fresh_1271", False), ("v1.28.0", "main_128", True), ("v1.29.0", "main_129", True), ("v1.29.0", "fresh_129", False)]
old = WORK + "/oldcode"
for tag, name, data in SNAPS:
    shutil.rmtree(old, ignore_errors=True)
    subprocess.run(["git", "-C", ROOT, "worktree", "prune"], capture_output=True)
    sh(["git", "-C", ROOT, "worktree", "add", "-f", "--detach", old, tag])
    fresh_db("align_snap")
    cfg = WORK + "/snap.php"
    config(cfg, "align_snap")
    align(cfg, "migrate", code=old)
    if data:
        dump = sh(["mysqldump", "-uroot", f"--socket={SOCKET}", "--no-create-info", "--replace", DB_MAIN, "settings"])
        sh(["mysql", "-uroot", f"--socket={SOCKET}", "align_snap"], input=dump)
        align(cfg, "sync", "--quiet", code=old)
    sh(f"mysqldump -uroot --socket={SOCKET} align_snap > {WORK}/snap/{name}.sql")
subprocess.run(["git", "-C", ROOT, "worktree", "remove", "--force", old], capture_output=True)
mysql("drop database if exists align_snap")
print("seed: older releases", ", ".join(t for t, _, _ in SNAPS))

# ---- updates & backups: a backup key and a git remote one release ahead of this code
S = WORK + "/sys"
if not os.path.exists(S + "/key.txt"):
    sh(f"age-keygen -o {S}/key.txt 2>/dev/null")
sh(f"age-keygen -y {S}/key.txt > {S}/recipient.txt")
for d in ["remote.git", "work"]:
    shutil.rmtree(f"{S}/{d}", ignore_errors=True)
sh(["git", "clone", "-q", "--bare", "--no-local", "--single-branch", ROOT, f"{S}/remote.git"])
sh(["git", "clone", "-q", f"{S}/remote.git", f"{S}/work"])
g = f"cd {S}/work && git -c user.name=Test -c user.email=test@example.com"
sh(f"{g} checkout -q -B main")
# the release notes, as a release adds them to the README's What's new
rd = open(f"{S}/work/README.md").read()
open(f"{S}/work/README.md", "w").write(rd.replace("## What's new\n\n", "## What's new\n\n- **Shiny new thing (9.99.0):** adds a **thing** <i>for you</i>, see [the docs](docs/FAQ.md).\n"
                                                  "  - **Part one:** the first part.\n- **Not yet (9.99.1):** after this release.\n", 1))
sh(f"echo 9.99.0 > {S}/work/VERSION && {g} commit -qam 'v9.99.0: Shiny new thing' -m 'Adds a thing.' -m 'Co-Authored-By: X <x@y>'"
   f" && echo '# Typo fixed' >> README.md && {g} commit -qam 'Fix a typo' && git push -q -f origin HEAD:main")
# Clones check out main, like GitHub's default branch. Without this they'd get whatever the tests run from:
# on a pull request that's GitHub's merge commit (a detached HEAD), not main.
sh(["git", f"--git-dir={S}/remote.git", "symbolic-ref", "HEAD", "refs/heads/main"])
print("seed: done")
