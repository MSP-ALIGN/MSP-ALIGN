"""2.2.1 review of the installer and its helper scripts (install.sh, scripts/move-install.sh, scripts/update.sh).

install.sh changes the system, so it is never run here. The checks read it, and run small pieces cut out of it in a
throwaway bash with stubbed commands, in a scratch folder. scripts/move-install.sh runs against a copy of a file
tree (ALIGN_ROOT), as in move_e2e.py."""
from lib import *
import os, re, shutil, stat, subprocess, glob

INST = open(ROOT + "/install.sh").read()
MOVER = ROOT + "/scripts/move-install.sh"
T = WORK + "/install_q"
shutil.rmtree(T, ignore_errors=True)
os.makedirs(T)
STUBS = 'log(){ echo "LOG $*"; }\nwarn(){ echo "WARN $*"; }\ndie(){ echo "DIE $*"; exit 1; }\n'


def piece(start, end, after=0):
    """The text of install.sh from the line holding `start` up to (not including) the next `end` ("" when missing)."""
    if start not in INST[after:]:
        return ""
    i = INST.index(start, after)
    i = INST.rindex("\n", 0, i) + 1
    return INST[i:INST.index(end, i + len(start))]


def bash(script, env=None, cwd=None):
    r = subprocess.run(["bash", "-c", script], capture_output=True, text=True, env=dict(os.environ, **(env or {})), cwd=cwd, timeout=60)
    return r.returncode, r.stdout + r.stderr


# ---- the scripts parse (and shellcheck finds no errors, where it is installed)
for f in ["install.sh", "scripts/move-install.sh", "scripts/update.sh"]:
    ok(subprocess.run(["bash", "-n", ROOT + "/" + f]).returncode == 0, f"{f} parses")
if shutil.which("shellcheck"):
    r = subprocess.run(["shellcheck", "-S", "error", ROOT + "/install.sh", MOVER, ROOT + "/scripts/update.sh"], capture_output=True, text=True)
    ok(r.returncode == 0, "shellcheck finds no errors: " + r.stdout[:300])
ok(INST.splitlines()[19] == "set -Eeuo pipefail" and "set -Eeuo pipefail" in open(MOVER).read() and "set -Eeuo pipefail" in open(ROOT + "/scripts/update.sh").read(),
   "every script stops at the first failure (set -Eeuo pipefail)")

# ---- fail2ban: the filter matches a whole Apache error-log line, not text anywhere in it (a hostile Referer)
flt = re.search(r"cat >/etc/fail2ban/filter.d/msp-align.conf <<'EOF'\n(.*?)\nEOF", INST, re.S).group(1)
fr = re.search(r"^failregex = (.*)$", flt, re.M).group(1)
ok(fr.startswith("^%(_align_client)s ") and fr.endswith("$") and "before = apache-common.conf" in flt,
   "fail2ban filter is anchored to Apache's line prefix and the line's end: " + fr)
ok("(?:msp|mountaineer)-align\\] auth failure kind=[a-z0-9-]+ ip=<ADDR>" in fr, "it takes the address from the app's ip= field, for both log tags")
D = "[Sat Oct 03 12:01:06.123456 2026] "
LINES = [   # (line, address fail2ban must take, or None)
    (D + "[php:notice] [pid 1234:tid 1234] [client 203.0.113.5:54321] [msp-align] auth failure kind=staff ip=203.0.113.5", "203.0.113.5"),
    (D + "[php:notice] [pid 1234:tid 1234] [client 203.0.113.6:54321] [msp-align] auth failure kind=portal-2fa ip=203.0.113.6, referer: https://align.example/portal/login", "203.0.113.6"),
    (D + "[php:notice] [pid 1234] [client 2001:db8::7:50000] [mountaineer-align] auth failure kind=api ip=2001:db8::7", "2001:db8::7"),
    (D + "[php:notice] [pid 1234:tid 1234] [client 198.51.100.9:1111] [msp-align] auth failure kind=staff ip=198.51.100.9, referer: x ip=192.0.2.14", "198.51.100.9"),
    (D + "[php:warn] [pid 1234:tid 1234] [client 198.51.100.9:1111] PHP Warning:  Undefined array key \"x\" in /opt/msp-align/src/X.php on line 3, referer: x [msp-align] auth failure kind=staff ip=192.0.2.10", None),
    (D + "[core:error] [pid 1234:tid 1234] [client 198.51.100.9:1111] AH10244: invalid URI path (/../), referer: [msp-align] auth failure kind=staff ip=192.0.2.16", None),
    (D + "[php:notice] [pid 1234:tid 1234] [client 198.51.100.9:1111] PHP Warning:  [msp-align] auth failure kind=staff ip=192.0.2.11", None),
    (D + "[php:notice] [pid 1234:tid 1234] [client 198.51.100.9:1111] [msp-align] import: bad [msp-align] auth failure kind=staff ip=192.0.2.12", None),
    (D + "[php:notice] [pid 1234:tid 1234] [client 198.51.100.9:1111] [msp-align] auth failure kind=staff ip=192.0.2.13 extra, referer: y", None),
    ("x " + D + "[php:notice] [pid 1234:tid 1234] [client 198.51.100.9:1111] [msp-align] auth failure kind=staff ip=192.0.2.15", None),
]
# The same pattern in Python (fail2ban cuts the date out, leaving "[]", and fills in <apache-prefix> and <ADDR>), so the
# check runs where fail2ban isn't installed too
client = (re.search(r"^_align_client = (.*)$", flt, re.M) or re.match("()", "")).group(1).replace("<apache-prefix>", r"\[\]\s")
py = re.compile(fr.replace("%(_align_client)s", client).replace("<ADDR>", r"(?P<ip>[0-9A-Fa-f:.]+)").replace("<HOST>", r"(?P<ip>[0-9A-Fa-f:.]+)"))
for line, want in LINES:
    m = py.search(re.sub(r"^\[[^\]]+\]", "[]", line))
    ok((m.group("ip") if m else None) == want, f"filter (pattern) takes {want} from: ...{line[35:150]}")
if shutil.which("fail2ban-regex") and os.path.exists("/etc/fail2ban/filter.d/apache-common.conf"):
    fd = T + "/filter.d"
    os.makedirs(fd)
    for f in ["apache-common.conf", "common.conf"]:
        shutil.copy("/etc/fail2ban/filter.d/" + f, fd)
    open(fd + "/msp-align.conf", "w").write(flt + "\n")
    for line, want in LINES:
        open(T + "/one.log", "w").write(line + "\n")
        r = subprocess.run(["fail2ban-regex", "-o", "ip", T + "/one.log", fd + "/msp-align.conf"], capture_output=True, text=True, timeout=60)
        got = r.stdout.strip() or None
        ok(r.returncode == 0 and got == want, f"fail2ban-regex takes {want} from: ...{line[35:150]} (got {got!r}) {r.stderr[-200:]}")
    # the installer's own check of the filter it wrote, on a line like the app's
    chk = piece("  # The filter must still read this fail2ban's", "\nelse\n")
    open(fd + "/never.conf", "w").write("[Definition]\nfailregex = ^never <HOST>$\n")
    res = [bash("set -Eeuo pipefail\n" + STUBS + chk.replace("/etc/fail2ban/filter.d/msp-align.conf", fd + "/" + f) + "\necho END")[1] for f in ["msp-align.conf", "never.conf"]]
    ok(chk and "WARN" not in res[0] and "END" in res[0] and "WARN" in res[1] and "END" in res[1],
       "the installer tries its filter on a sample line, and warns (without stopping) when it doesn't match: " + str(res))
ok("[msp-align]\nenabled  = true" in INST and 'if [[ "$ALIGN_TLS" != "proxy" ]]; then\n  cat >/etc/fail2ban/jail.d/msp-align.conf' in INST, "the jail is only on when Apache sees the client itself (not proxy mode)")
ok("error_log('[msp-align] auth failure kind=' . $kind . ' ip=' . client_ip());" in open(ROOT + "/src/Security.php").read(),
   "the app still writes the line the filter expects (src/Security.php)")

# ---- the mover next to the installer only runs when it is as trustworthy as the installer (not from /tmp)
fn = piece("root_trusted() {", "\nMOVER=\"\"")
ok('[[ -f "$m" ]] && root_trusted "$m" && { MOVER=$m; break; }' in INST, "the installer checks the mover it found before running it as root")
def trusted(layout_dir):
    """Runs root_trusted against LAYOUT_DIR/scripts/move-install.sh, from an installer saved in LAYOUT_DIR."""
    open(layout_dir + "/install.sh", "w").write("set -Eeuo pipefail\n" + fn + '\nroot_trusted "$1" && echo TRUSTED || echo REFUSED\n')
    return bash(f'bash {layout_dir}/install.sh {layout_dir}/scripts/move-install.sh')[1].strip()
for name, top, scripts in [("checkout", 0o755, 0o755), ("tmp", 0o1777, 0o755), ("groupw", 0o755, 0o775)]:
    d = T + "/mv-" + name
    os.makedirs(d + "/scripts")
    open(d + "/scripts/move-install.sh", "w").write("echo PWNED\n")
    os.chmod(d + "/scripts/move-install.sh", 0o644)
    os.chmod(d + "/scripts", scripts)
    os.chmod(d, top)
got = [trusted(T + "/mv-checkout"), trusted(T + "/mv-tmp"), trusted(T + "/mv-groupw")]
ok(got == ["TRUSTED", "REFUSED", "REFUSED"], "mover beside the installer: used from a normal checkout, refused in a world- or group-writable folder: " + str(got))
os.chmod(T + "/mv-checkout/scripts/move-install.sh", 0o666)
ok(trusted(T + "/mv-checkout") == "REFUSED", "and refused when the mover itself is writable by others")
r = bash("set -Eeuo pipefail\n" + fn + f'\nroot_trusted {T}/mv-tmp/scripts/move-install.sh && echo TRUSTED || echo REFUSED\n')
ok(r[1].strip() == "REFUSED", "under curl | bash (no installer file) only root-owned, non-writable folders count")
# curl | bash for real: the script comes on stdin, where BASH_SOURCE[0] is "main" (2.2.1: that made every path refused)
d = T + "/mv-rootowned"
os.makedirs(d + "/app/scripts", exist_ok=True)
for x in (d, d + "/app", d + "/app/scripts"): os.chmod(x, 0o755)
open(d + "/app/scripts/move-install.sh", "w").write("echo ok\n"); os.chmod(d + "/app/scripts/move-install.sh", 0o644)
r = subprocess.run(["bash"], input="set -Eeuo pipefail\n" + fn + f'\nroot_trusted {d}/app/scripts/move-install.sh && echo TRUSTED || echo REFUSED\n',
                   capture_output=True, text=True, timeout=60)
ok(os.geteuid() != 0 or r.stdout.strip() == "TRUSTED", "under curl | bash (script on stdin) a root-owned, non-writable mover is used: " + (r.stdout + r.stderr).strip()[-80:])

# ---- ALIGN_REPO is owner/name only
rp = piece('REPO="${ALIGN_REPO:-MSP-ALIGN/MSP-ALIGN}"', "OLD_REPO=")
ok(bash("set -Eeuo pipefail\n" + rp + "echo OK", {"ALIGN_REPO": "acme/msp-align"})[1].strip() == "OK"
   and "owner/name" in bash("set -Eeuo pipefail\n" + rp + "echo OK", {"ALIGN_REPO": "acme/x.git --upload-pack=touch"})[1],
   "ALIGN_REPO: owner/name accepted, anything else refused")

# ---- data folder: root changes an item's owner only where the kernel stops www-data making hard links to others' files
hl = piece("HARDLINKS_SAFE=0;", "\nrunuser -u www-data -- install -d -m 700")
os.makedirs(T + "/data/sessions")
def hardlinks(v):
    return bash("set -Eeuo pipefail\n" + STUBS + f'cat(){{ echo {v}; }}\nstat(){{ echo root; }}\nchown(){{ echo "CHOWN $*"; }}\nDATA_DIR={T}/data\n' + hl + "\necho END")[1]
out0, out1 = hardlinks(0), hardlinks(1)
ok("DIE" in out0 and "protected_hardlinks=0" in out0 and "CHOWN" not in out0, "fs.protected_hardlinks=0: a folder not owned by www-data is left alone, with a message: " + out0[-160:])
ok(f"CHOWN -h www-data:www-data {T}/data/sessions" in out1 and "END" in out1, "fs.protected_hardlinks=1: it is handed back to www-data with chown -h: " + out1[-160:])
ok(not re.search(r"chown -R [^\n]*\$DATA_DIR|chown [^-][^\n]*\$DATA_DIR", INST), "root never runs a recursive or link-following chown in the data folder")

# ---- backup key: private key saved before the public key; an old private key is kept, never overwritten
bk = piece('BACKUP_PRIV_SHOWN=""', '\nif [[ -n "${GH_TOKEN:-}" ]]; then')
def keyrun(prep=""):
    shutil.rmtree(T + "/bk", ignore_errors=True)
    os.makedirs(T + "/bk/conf"); os.makedirs(T + "/bk/root")
    return bash("set -Eeuo pipefail\numask 022\n" + STUBS + f"CONF_DIR={T}/bk/conf\nPRIVKEY_FILE={T}/bk/root/key.txt\nBACKUP_DIR={T}/bk/none\n" + prep + bk + "\necho END")
if shutil.which("age-keygen"):
    rc, out = keyrun()
    pub = open(T + "/bk/conf/backup-recipient.txt").read()
    ok(rc == 0 and pub.startswith("age1") and open(T + "/bk/root/key.txt").read().count("AGE-SECRET-KEY-") == 1
       and stat.S_IMODE(os.stat(T + "/bk/root/key.txt").st_mode) == 0o600 and stat.S_IMODE(os.stat(T + "/bk/conf/backup-recipient.txt").st_mode) == 0o640,
       "backup key pair made: private key 0600, public key 0640: " + out[-200:])
    ok("AGE-SECRET-KEY" not in out, "the private key isn't printed while it's made")
    rc, out = keyrun(f'printf "AGE-SECRET-KEY-1OLD\\n" >{T}/bk/root/key.txt\n')
    olds = glob.glob(T + "/bk/root/key.txt.*.old")
    ok(rc == 0 and len(olds) == 1 and open(olds[0]).read() == "AGE-SECRET-KEY-1OLD\n" and "AGE-SECRET-KEY-1OLD" not in open(T + "/bk/root/key.txt").read() and "WARN" in out,
       "a private key left in /root from an earlier pair is kept aside, not overwritten: " + str(olds))
    rc, out = keyrun(f"PRIVKEY_FILE={T}/bk/missing/key.txt\n")
    ok(rc != 0 and not os.path.exists(T + "/bk/conf/backup-recipient.txt"), "when the private key can't be saved, no public key is put in place (no backups nobody can open)")
ok("KEYTMP=$(mktemp -d)" in bk and 'rm -f "$KEYTMP"' not in bk, "the key is made in a private temporary folder, not a name freed in /tmp")

# ---- GitHub token, config.php and its secrets
ok("umask 077; printf '%s' \"$GH_TOKEN\" >\"$TOKEN_FILE\"" in INST and 'chmod 600 "$TOKEN_FILE"' in INST and "password=\\$(cat $TOKEN_FILE)" in INST,
   "GitHub token file is root-only and read by git's credential helper (never in a URL)")
ok('cat >"$CONF_FILE.new" <<PHP' in INST and 'mv -f "$CONF_FILE.new" "$CONF_FILE"' in INST and INST.index('chmod 640 "$CONF_FILE.new"') < INST.index('mv -f "$CONF_FILE.new" "$CONF_FILE"'),
   "config.php is written beside it, made 0640 root:www-data, then renamed into place")
ok("APP_KEY=$(head -c 32 /dev/urandom | base64)" in INST and "DB_PASS=$(rand 32)" in INST and "ADMIN_PASS=$(rand 20)" in INST, "secrets: 256-bit app_key, 32-character database password")
r = bash("set -Eeuo pipefail\n" + piece("rand() {", "\nas_www()") + "\nrand 32; rand 80")
ok(re.fullmatch(r"[A-Za-z0-9]{32}\n[A-Za-z0-9]{80}\n", r[1]) is not None, "rand gives the length asked for, letters and digits only")
ok(re.search(r"GRANT ALL PRIVILEGES ON \\`\$DB_NAME\\`\.\* TO '\$DB_USER'@'localhost';", INST) and "ON *.*" not in INST, "the database user has privileges on its own database only")
ok("printf '%s\\n' \"$ADMIN_PASS\" | as_www php" in INST and "--password-stdin" in INST, "the first admin password goes to the CLI on stdin, never as an argument")

# ---- MariaDB: settings that won't start are replaced by the previous ones, not deleted (that would drop encryption)
mc = piece("# When MariaDB won't start with new settings", "\nif [[ \"${ALIGN_DB_ENCRYPT:-1}\" == \"1\" && -f \"$MYCNF\" ]]; then")
def mariadb(prev, fail_first=True):
    shutil.rmtree(T + "/my", ignore_errors=True); os.makedirs(T + "/my")
    if prev is not None:
        open(T + "/my/60.cnf", "w").write(prev)
    open(T + "/my/60.cnf.new", "w").write("NEW\n")
    sysd = f'n=0\nsystemctl(){{ n=$((n+1)); echo "RESTART $n"; [[ $n -gt 1 || {0 if fail_first else 1} == 1 ]]; }}\n'
    rc, out = bash("set -Eeuo pipefail\n" + STUBS + sysd + f"MYCNF={T}/my/60.cnf\nNEED_RESTART=0\n" + mc + "\necho END")
    return rc, out, (open(T + "/my/60.cnf").read() if os.path.exists(T + "/my/60.cnf") else None), sorted(os.listdir(T + "/my"))
rc, out, now, files = mariadb("OLD encrypted\n")
ok(rc == 0 and now == "OLD encrypted\n" and files == ["60.cnf"] and "RESTART 2" in out, "MariaDB won't start: the previous settings (with encryption) are put back: " + str((now, files)))
rc, out, now, files = mariadb(None)
ok(rc == 0 and now is None and files == [], "no previous settings: the new file is removed, as before")
rc, out, now, files = mariadb("OLD\n", fail_first=False)
ok(rc == 0 and now == "NEW\n" and files == ["60.cnf"] and "RESTART 2" not in out, "it starts: the new settings stay and no copy is left behind")
de = piece('if [[ "${ALIGN_DB_ENCRYPT:-1}" != "1" ]] && grep -qs', "\nif [[ \"${ALIGN_DB_ENCRYPT:-1}\" == \"1\" ]]; then")
open(T + "/my/enc.cnf", "w").write("[mariadbd]\nplugin_load_add = file_key_management\n")
r = bash("set -Eeuo pipefail\n" + STUBS + f"MYCNF={T}/my/enc.cnf\nALIGN_DB_ENCRYPT=0\n" + de + '\necho "ENC=$ALIGN_DB_ENCRYPT"')
ok("ENC=1" in r[1] and "WARN" in r[1], "ALIGN_DB_ENCRYPT=0 on an install already encrypted is ignored (its tables would be unreadable)")
r = bash("set -Eeuo pipefail\n" + STUBS + f"MYCNF={T}/my/none.cnf\nALIGN_DB_ENCRYPT=0\n" + de + '\necho "ENC=$ALIGN_DB_ENCRYPT"')
ok("ENC=0" in r[1], "and still skips encryption on a new install")

# ---- server hardening still written (values the docs promise)
for line in ["ServerTokens Prod", "TraceEnable Off", "SSLProtocol -all +TLSv1.2 +TLSv1.3", "LimitRequestBody 10485760", "a2enmod -q reqtimeout",
             "expose_php = Off", "display_errors = Off", "allow_url_fopen = Off", "allow_url_include = Off", "session.use_strict_mode = 1",
             "disable_functions = passthru,shell_exec,system,proc_open,popen,pcntl_exec,dl", 'echo "bind-address = 127.0.0.1"', 'echo "local-infile = 0"',
             'APT::Periodic::Unattended-Upgrade "1";', "ufw default deny incoming", 'ufw limit "$p/tcp"', 'chmod -R u+rwX,go+rX,go-w "$APP_DIR"', 'chown -R root:root "$APP_DIR"']:
    ok(line in INST, "installer writes: " + line)
for pre in ["^/ics/", "^/portal/invite/", "^/portal/welcome/", "^/portal/sign/"]:
    ok(f'SetEnvIf Request_URI "{pre}" align_secret_url' in INST, f"secret links {pre} kept out of the access log")
ok('if [[ -t 1 ]]; then' in INST and INST.index('if [[ -t 1 ]]; then') < INST.index("grep '^AGE-SECRET-KEY' \"$PRIVKEY_FILE\""), "the backup private key is only shown on a terminal (not in an update log from the web page)")

# ---- move-install.sh: a file is only ever complete under its new name, even if a run stops half way
F = T + "/fakeroot"
def w(path, text, mode=0o644):
    os.makedirs(os.path.dirname(F + path), exist_ok=True); open(F + path, "w").write(text); os.chmod(F + path, mode)
def mover(extra_path=""):
    return subprocess.run(["bash", MOVER], env={"PATH": extra_path + "/usr/local/bin:/usr/bin:/bin", "ALIGN_ROOT": F}, capture_output=True, text=True)
site = "<VirtualHost *:443>\n    DocumentRoot /opt/mountaineer-align/public\n</VirtualHost>\n"
w("/etc/apache2/sites-available/mountaineer-align.conf", site)
w("/etc/msp-align/config.php", "<?php return ['session_path' => '/var/lib/msp-align/sessions'];\n", 0o640)
os.makedirs(T + "/badsed")
open(T + "/badsed/sed", "w").write("#!/bin/sh\nexit 1\n"); os.chmod(T + "/badsed/sed", 0o755)
r = mover(T + "/badsed:")
ok(r.returncode != 0 and not os.path.exists(F + "/etc/apache2/sites-available/msp-align.conf") and os.path.exists(F + "/etc/apache2/sites-available/mountaineer-align.conf"),
   "a move stopped while rewriting a file leaves no half-made file under the new name, and the old one in place")
r = mover()
new = open(F + "/etc/apache2/sites-available/msp-align.conf").read() if os.path.exists(F + "/etc/apache2/sites-available/msp-align.conf") else ""
ok(r.returncode == 0 and "/opt/msp-align/public" in new and "mountaineer" not in new and not glob.glob(F + "/etc/apache2/sites-available/*.pre-1.35")
   and not glob.glob(F + "/etc/apache2/sites-available/*.tmp"), "the next run finishes it properly (paths updated, nothing set aside, no temporary file left): " + new)
# config.php: rewritten by rename, keeping its mode
shutil.rmtree(F)
w("/etc/mountaineer-align/config.php", "<?php return ['session_path' => '/var/lib/mountaineer-align/sessions', 'app_key' => 'base64:x'];\n", 0o640)
w("/etc/mountaineer-align/config.php.tmp", "stale", 0o644)
r = mover()
c = open(F + "/etc/msp-align/config.php").read()
ok(r.returncode == 0 and "/var/lib/msp-align/sessions" in c and "app_key" in c and stat.S_IMODE(os.stat(F + "/etc/msp-align/config.php").st_mode) == 0o640
   and not os.path.exists(F + "/etc/msp-align/config.php.tmp"), "config.php paths updated by rename: mode kept, app_key kept, no temporary copy left")
m = open(MOVER).read()
ok('cat "$CONF.tmp" >"$CONF"' not in m and 'cat "$n.tmp" >"$n"' not in m and 'mv -f "$CONF.tmp" "$CONF"' in m and 'mv -f "$n.tmp" "$n"' in m,
   "the mover never rewrites config.php or a moved file in place")

# ---- update.sh: root only, no arguments passed on
u = open(ROOT + "/scripts/update.sh").read()
ok("[[ $EUID -eq 0 ]]" in u and u.rstrip().endswith('exec /usr/bin/php "$APP/scripts/agent.php" update-cli'), "update.sh: root only, runs the agent's update with no user input")

shutil.rmtree(T, ignore_errors=True)
done()
