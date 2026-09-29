"""1.35: an install moves from the mountaineer-align names to msp-align (scripts/move-install.sh), run against a
copy of a server's file tree (ALIGN_ROOT), so nothing on this machine changes."""
import os, shutil, stat
from lib import *

MOVER = ROOT + "/scripts/move-install.sh"
F = WORK + "/fakeroot"


def w(path, text, mode=0o644):
    p = F + path
    os.makedirs(os.path.dirname(p), exist_ok=True)
    open(p, "w").write(text)
    os.chmod(p, mode)


def link(path, target):
    os.makedirs(os.path.dirname(F + path), exist_ok=True)
    os.symlink(target, F + path)


def build():
    shutil.rmtree(F, ignore_errors=True)
    w("/opt/mountaineer-align/VERSION", "1.34.0\n")
    w("/opt/mountaineer-align/public/index.php", "<?php\n")
    w("/etc/mountaineer-align/config.php", "<?php return ['db' => ['name' => 'mountaineer_align'], 'session_path' => '/var/lib/mountaineer-align/sessions',\n"
      " 'upload_path' => '/var/lib/mountaineer-align/uploads', 'php_cli' => '/usr/bin/php'];\n", 0o640)
    w("/etc/mountaineer-align/github-token", "secret-token", 0o600)
    w("/var/lib/mountaineer-align/uploads/logo.png", "PNG")
    w("/var/lib/mountaineer-align/sessions/sess_abc", "session")
    w("/var/lib/mountaineer-align-agent/jobs/20260928-120000-abcdef.json", "{}")
    w("/var/lib/mountaineer-align-agent/agent.lock", "")
    w("/run/mountaineer-align/requests/.keep", "")
    w("/root/mountaineer-align-backup-key.txt", "AGE-SECRET-KEY-1TEST\n", 0o600)
    w("/etc/ssl/certs/mountaineer-align.crt", "CRT")
    w("/etc/ssl/private/mountaineer-align.key", "KEY", 0o600)
    site = ("<VirtualHost *:443>\n    DocumentRoot /opt/mountaineer-align/public\n    <Directory /opt/mountaineer-align/public>\n    </Directory>\n"
            "    SSLCertificateFile /etc/ssl/certs/mountaineer-align.crt\n    SSLCertificateKeyFile /etc/ssl/private/mountaineer-align.key\n"
            "    ErrorLog ${APACHE_LOG_DIR}/mountaineer-align-error.log\n    CustomLog ${APACHE_LOG_DIR}/mountaineer-align-access.log combined\n</VirtualHost>\n")
    w("/etc/apache2/sites-available/mountaineer-align.conf", site)
    link("/etc/apache2/sites-enabled/mountaineer-align.conf", "../sites-available/mountaineer-align.conf")
    w("/etc/apache2/sites-available/mountaineer-align-le-ssl.conf", "<IfModule mod_ssl.c>\n" + site + "Include /etc/letsencrypt/options-ssl-apache.conf\n</IfModule>\n")
    link("/etc/apache2/sites-enabled/mountaineer-align-le-ssl.conf", "../sites-available/mountaineer-align-le-ssl.conf")
    w("/etc/apache2/conf-available/mountaineer-align-hardening.conf", "<Directory /opt/mountaineer-align/public/assets>\n</Directory>\n"
      "Alias /extra /etc/mountaineer-align-extra/files\nAlias /two /var/lib/mountaineer-align2\nAlias /agent \"/var/lib/mountaineer-align-agent\"\n")
    link("/etc/apache2/conf-enabled/mountaineer-align-hardening.conf", "../conf-available/mountaineer-align-hardening.conf")
    w("/etc/php/8.4/apache2/conf.d/99-mountaineer-align.ini", "session.save_path = /var/lib/mountaineer-align/sessions\n")
    w("/etc/php/8.4/apache2/conf.d/99-mountaineer-align-performance.ini", "opcache.enable = 1\n")
    w("/etc/mysql/mariadb.conf.d/60-mountaineer-align.cnf", "[mariadbd]\nbind-address = 127.0.0.1\n")
    w("/etc/fail2ban/filter.d/mountaineer-align.conf", "[Definition]\nfailregex = \\[mountaineer-align\\] auth failure kind=\\S+ ip=<HOST>\n")
    w("/etc/fail2ban/jail.d/mountaineer-align.conf", "[mountaineer-align]\nenabled  = true\nfilter   = mountaineer-align\nlogpath  = /var/log/apache2/mountaineer-align-error.log\n")
    w("/etc/tmpfiles.d/mountaineer-align.conf", "d /run/mountaineer-align 0755 root root -\n")
    w("/var/backups/mountaineer-align/db-20260101-023000.sql.gz.age", "old")


def mover():
    return subprocess.run(["bash", MOVER], env={"PATH": "/usr/local/bin:/usr/bin:/bin", "ALIGN_ROOT": F}, capture_output=True, text=True)


def read(p): return open(F + p).read()
def mode(p): return stat.S_IMODE(os.stat(F + p).st_mode)


# ---- an old install moves
build()
r = mover()
ok(r.returncode == 0 and "now lives in /opt/msp-align" in r.stdout, "mover runs on an old install: " + (r.stdout + r.stderr)[-200:])
for new, old in [("/opt/msp-align", "/opt/mountaineer-align"), ("/etc/msp-align", "/etc/mountaineer-align"), ("/var/lib/msp-align", "/var/lib/mountaineer-align"),
                 ("/var/lib/msp-align-agent", "/var/lib/mountaineer-align-agent"), ("/run/msp-align", "/run/mountaineer-align")]:
    ok(os.path.isdir(F + new) and not os.path.islink(F + new) and os.path.islink(F + old) and os.readlink(F + old) == F + new, f"{old} moved to {new} and left as a link")
ok(read("/opt/mountaineer-align/VERSION") == "1.34.0\n" and read("/var/lib/msp-align/uploads/logo.png") == "PNG" and read("/var/lib/msp-align/sessions/sess_abc") == "session"
   and os.path.exists(F + "/var/lib/msp-align-agent/jobs/20260928-120000-abcdef.json"), "files kept, and reachable through the old paths")
c = read("/etc/msp-align/config.php")
ok("/var/lib/msp-align/sessions" in c and "/var/lib/msp-align/uploads" in c and "'mountaineer_align'" in c and mode("/etc/msp-align/config.php") == 0o640,
   "config paths updated, database name and file mode kept")
ok(read("/etc/msp-align/github-token") == "secret-token" and mode("/etc/msp-align/github-token") == 0o600, "GitHub token kept, still private")
ok(read("/root/msp-align-backup-key.txt").startswith("AGE-SECRET-KEY") and mode("/root/msp-align-backup-key.txt") == 0o600 and not os.path.exists(F + "/root/mountaineer-align-backup-key.txt"),
   "backup private key renamed, still private")
site = read("/etc/apache2/sites-available/msp-align.conf")
ok("DocumentRoot /opt/msp-align/public" in site and "<Directory /opt/msp-align/public>" in site and "msp-align-error.log" in site and "msp-align-access.log" in site
   and "/etc/ssl/certs/msp-align.crt" in site and "/etc/ssl/private/msp-align.key" in site and "mountaineer" not in site, "Apache site renamed with its paths, logs and certificate")
ok(os.readlink(F + "/etc/apache2/sites-enabled/msp-align.conf") == "../sites-available/msp-align.conf" and not os.path.lexists(F + "/etc/apache2/sites-enabled/mountaineer-align.conf")
   and not os.path.exists(F + "/etc/apache2/sites-available/mountaineer-align.conf"), "site still enabled, under the new name only")
le = read("/etc/apache2/sites-available/msp-align-le-ssl.conf")
ok("Include /etc/letsencrypt/options-ssl-apache.conf" in le and "/opt/msp-align/public" in le and os.path.lexists(F + "/etc/apache2/sites-enabled/msp-align-le-ssl.conf"),
   "certbot's HTTPS site moved too, its Let's Encrypt settings untouched")
ok(read("/etc/ssl/certs/msp-align.crt") == "CRT" and mode("/etc/ssl/private/msp-align.key") == 0o600, "self-signed certificate renamed")
ok("/opt/msp-align/public/assets" in read("/etc/apache2/conf-available/msp-align-hardening.conf") and os.path.lexists(F + "/etc/apache2/conf-enabled/msp-align-hardening.conf"),
   "hardening conf renamed and still enabled")
ok(read("/etc/php/8.4/apache2/conf.d/99-msp-align.ini") == "session.save_path = /var/lib/msp-align/sessions\n" and os.path.exists(F + "/etc/php/8.4/apache2/conf.d/99-msp-align-performance.ini")
   and not [x for x in os.listdir(F + "/etc/php/8.4/apache2/conf.d") if "mountaineer" in x], "PHP settings renamed")
ok(os.path.exists(F + "/etc/mysql/mariadb.conf.d/60-msp-align.cnf") and not os.path.exists(F + "/etc/mysql/mariadb.conf.d/60-mountaineer-align.cnf"), "MariaDB settings renamed")
jail = read("/etc/fail2ban/jail.d/msp-align.conf")
ok(jail.startswith("[msp-align]") and "filter   = msp-align" in jail and "msp-align-error.log" in jail and os.path.exists(F + "/etc/fail2ban/filter.d/msp-align.conf"),
   "fail2ban jail and filter renamed and pointing at each other")
ok(read("/etc/tmpfiles.d/msp-align.conf") == "d /run/msp-align 0755 root root -\nL /run/mountaineer-align - - - - /run/msp-align\n", "tmpfiles renamed, and the old /run path stays a link after a reboot")
ok("failregex = \\[(?:msp|mountaineer)-align\\] auth failure" in read("/etc/fail2ban/filter.d/msp-align.conf"), "fail2ban filter matches the new log tag (and the old one)")
cu = read("/etc/apache2/conf-available/msp-align-hardening.conf")
ok("/etc/mountaineer-align-extra/files" in cu and "/var/lib/mountaineer-align2" in cu and '"/var/lib/msp-align-agent"' in cu, "only whole old names change (other folders that start the same are left alone)")
ok(read("/var/backups/mountaineer-align/db-20260101-023000.sql.gz.age") == "old", "old nightly backups left where they are")
left = [os.path.join(d, x)[len(F):] for d, ds, fs in os.walk(F) for x in ds + fs if "mountaineer-align" in x and not os.path.islink(os.path.join(d, x))]
ok(left == ["/var/backups/mountaineer-align"], "nothing else keeps the old name: " + str(left))
ok(subprocess.run(["bash", MOVER, "--pending"], env={"PATH": "/usr/bin:/bin", "ALIGN_ROOT": F}).returncode == 1, "--pending: nothing left to move")

# ---- running it again changes nothing
before = sorted((d, tuple(sorted(ds)), tuple(sorted(fs))) for d, ds, fs in os.walk(F))
r = mover()
ok(r.returncode == 0 and r.stdout == "" and sorted((d, tuple(sorted(ds)), tuple(sorted(fs))) for d, ds, fs in os.walk(F)) == before, "a second run is a no-op: " + r.stdout[:120])

# ---- an install that never had the old names (fresh, or already moved) is left alone
shutil.rmtree(F); os.makedirs(F + "/opt/msp-align"); os.makedirs(F + "/etc/msp-align")
r = mover(); ok(r.returncode == 0 and r.stdout == "" and os.listdir(F + "/opt") == ["msp-align"], "a fresh msp-align install is left alone")

# ---- both folders there: stop, change nothing
build(); os.makedirs(F + "/etc/msp-align"); open(F + "/etc/msp-align/config.php", "w").write("other")
r = mover()
ok(r.returncode != 0 and "Both /etc/mountaineer-align and /etc/msp-align exist" in r.stderr and read("/etc/mountaineer-align/config.php").startswith("<?php")
   and read("/etc/msp-align/config.php") == "other", "both old and new config folders: it stops and overwrites nothing")
ok(os.path.islink(F + "/opt/mountaineer-align"), "(folders before the clash had already moved safely)")
ok(subprocess.run(["bash", MOVER, "--pending"], env={"PATH": "/usr/bin:/bin", "ALIGN_ROOT": F}).returncode == 0, "--pending after a stopped move: the next update runs the mover again")
os.rename(F + "/etc/msp-align", F + "/etc/msp-align.bak")
r = mover(); ok(r.returncode == 0 and os.path.islink(F + "/etc/mountaineer-align") and os.path.exists(F + "/etc/msp-align/github-token"), "after sorting it out, the next run finishes the move")

# ---- a run stopped half way finishes: a file already under its new name isn't overwritten
build()
w("/etc/mysql/mariadb.conf.d/60-msp-align.cnf", "[mariadbd]\nnewer = 1\n")
r = mover()
ok(r.returncode == 0 and read("/etc/mysql/mariadb.conf.d/60-msp-align.cnf").endswith("newer = 1\n") and not os.path.exists(F + "/etc/mysql/mariadb.conf.d/60-mountaineer-align.cnf")
   and read("/etc/mysql/mariadb.conf.d/60-mountaineer-align.cnf.pre-1.35").startswith("[mariadbd]"), "a file already moved keeps its newer contents; the old copy is kept aside as .pre-1.35 (not loaded)")

# ---- --pending also sees a single file left behind (a move stopped after the folders and the site)
build(); mover(); w("/etc/php/8.4/apache2/conf.d/99-mountaineer-align.ini", "x=1\n")
ok(subprocess.run(["bash", MOVER, "--pending"], env={"PATH": "/usr/bin:/bin", "ALIGN_ROOT": F}).returncode == 0, "--pending sees an old PHP settings file left behind")
mover(); ok(subprocess.run(["bash", MOVER, "--pending"], env={"PATH": "/usr/bin:/bin", "ALIGN_ROOT": F}).returncode == 1, "and the next run tidies it away")

# ---- the installer, the units and the code use the new names
inst = open(ROOT + "/install.sh").read()
ok("APP_DIR=/opt/msp-align" in inst and "CONF_DIR=/etc/msp-align" in inst and "DATA_DIR=/var/lib/msp-align" in inst and "SITE=msp-align" in inst, "installer uses the msp-align folders")
ok('bash "$MOVER"' in inst and inst.index('bash "$MOVER"') < inst.index("# ------------------------------------------------------------------ inputs --"), "installer moves an old install before anything else")
ok('bash "$MOVER" --pending' in inst and 'if [[ -d "$OLD_APP_DIR" && ! -L "$OLD_APP_DIR" ]]' not in inst, "installer runs the mover whenever anything is left to move (not only while /opt is a folder)")
ok("systemctl enable -q --now msp-align-agent.path" in inst[:inst.index("# ------------------------------------------------------------------ inputs --")], "the request watcher switches to /run/msp-align right after the move")
ok("L /run/mountaineer-align - - - - /run/msp-align" in inst and "(?:msp|mountaineer)-align\\] auth failure" in inst, "old /run path survives reboots; fail2ban matches both log tags")
ok('systemctl disable -q "mountaineer-align-$u.service"' in inst and 'systemctl disable -q --now "mountaineer-align-$u.service"' not in inst,
   "old services are disabled, never stopped (the update runs inside the old agent service)")
units = os.listdir(ROOT + "/deploy/systemd")
ok(units and all(u.startswith("msp-align-") for u in units) and not any("mountaineer" in open(ROOT + "/deploy/systemd/" + u).read() for u in units), "every unit is msp-align-* with new paths")
code = subprocess.run(["grep", "-rn", "mountaineer-align", ROOT + "/src", ROOT + "/views", ROOT + "/bin", ROOT + "/public/index.php"], capture_output=True, text=True).stdout.splitlines()
ok(all("msp-align" in l or "/var/backups/mountaineer-align" in l for l in code),
   "app code only mentions the old names as fallbacks: " + str([l[len(ROOT):][:90] for l in code])[:300])
out = subprocess.run(["php", "-r", f'require "{ROOT}/src/Config.php"; echo Align\\Config::path();'], env={"PATH": "/usr/bin:/bin"}, capture_output=True, text=True).stdout
ok(out == "/etc/msp-align/config.php", "config path is /etc/msp-align/config.php when neither file exists: " + out)

shutil.rmtree(F, ignore_errors=True)
done()
