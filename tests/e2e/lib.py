"""Shared settings and helpers for the end-to-end suites (see tests/README.md).

Everything that differs between machines comes from environment variables set by tests/e2e/run.sh:
ALIGN_TEST_WORK (scratch folder), ALIGN_TEST_DB / ALIGN_TEST_DB_FRESH (database names), ALIGN_TEST_SOCKET,
ALIGN_TEST_URL / ALIGN_TEST_URL_FRESH / ALIGN_TEST_MOCK (servers).
"""
import os, re, json, time, hmac, hashlib, base64, struct, subprocess, html as H
import requests, pymysql

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
WORK = os.environ.get("ALIGN_TEST_WORK", "/tmp/msp-align-tests")
DB_MAIN = os.environ.get("ALIGN_TEST_DB", "align_test")
DB_FRESH = os.environ.get("ALIGN_TEST_DB_FRESH", "align_test_fresh")
SOCKET = os.environ.get("ALIGN_TEST_SOCKET", "/run/mysqld/mysqld.sock")
B = os.environ.get("ALIGN_TEST_URL", "http://127.0.0.1:8080")
B_FRESH = os.environ.get("ALIGN_TEST_URL_FRESH", "http://127.0.0.1:8081")
M = os.environ.get("ALIGN_TEST_MOCK", "http://127.0.0.1:8099")
CONFIG = WORK + "/config.php"
FRESH_CONFIG = WORK + "/fresh.php"
UPLOADS = WORK + "/uploads"
SNAP = WORK + "/snap"          # databases from older releases (built by seed.py) for upgrade tests
SYS = WORK + "/sys"            # updates & backups agent folders (see sys_e2e)
ALIGN = ROOT + "/bin/align"
BOOTSTRAP = ROOT + "/src/bootstrap.php"
ENV = {"ALIGN_CONFIG": CONFIG, "PATH": "/usr/local/bin:/usr/bin:/bin"}
TECH_PASSWORD = "TechPassword123!"

def tz_offset():
    """This process's UTC offset as MariaDB wants it (run.sh sets TZ to the app's timezone)."""
    z = time.strftime("%z")
    return z[:3] + ":" + z[3:]


# Same session timezone as the app (src/DB.php), so now() here matches the times the app stored
db = pymysql.connect(unix_socket=SOCKET, user="root", database=DB_MAIN, autocommit=True, cursorclass=pymysql.cursors.DictCursor,
                     init_command=f"SET time_zone = '{tz_offset()}'")


def q(sql, *a):
    with db.cursor() as c:
        c.execute(sql, a or None)
        return c.fetchall()


fails = []


def ok(c, m):
    print(("PASS " if c else "FAIL ") + m)
    if not c:
        fails.append(m)


def done():
    print("FAILURES:", len(fails))
    [print(" -", f) for f in fails]


def errs(t): return re.findall(r'(Warning:|Notice:|Deprecated:|Fatal error|Uncaught|RuntimeException)[^<]{0,200}', t)
def csrf(s, p): return re.search(r'name="_csrf" value="([^"]+)"', s.get(B + p).text).group(1)
def flash(t): return " | ".join(H.unescape(x.strip()) for x in re.findall(r'alert alert-\w+[^>]*>(?:\s*<button[^>]*>[^<]*</button>)?\s*(?:<i[^>]*></i>)?([^<]+)', t))
def align(*a): return subprocess.run(["php", ALIGN, *a], env=ENV, capture_output=True, text=True).stdout
def php(code, env=None): return subprocess.run(["php", "-r", f'require "{BOOTSTRAP}"; ' + code], env=env or ENV, capture_output=True, text=True)
def graph(): return requests.get(M + "/mock/graph").json()


def setting(k, v, secret=False):
    if secret:
        php(f'Align\\Settings::setSecret({json.dumps(k)}, {json.dumps(v)});')
    else:
        q("insert into settings (name,value,is_secret) values (%s,%s,0) on duplicate key update value=values(value), is_secret=0", k, v)


_used = {}


def totp(secret):
    secret = secret.replace(" ", "").upper()
    while True:
        now = int(time.time()) // 30
        step = max(now, _used.get(secret, -1) + 1)
        if step <= now + 1:
            break
        time.sleep(2)
    _used[secret] = step
    key = base64.b32decode(secret + "=" * ((8 - len(secret) % 8) % 8))
    h = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest()
    o = h[-1] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7fffffff) % 1000000)


def login(e, p, base=None):
    s = requests.Session()
    base = base or B
    tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(base + "/login").text).group(1)
    s.post(base + "/login", data={"_csrf": tok, "email": e, "password": p})
    return s


class MD(dict):
    """dict for single fields + extra list for repeated [] fields"""


def form(s, p, formsel='action="/integrations/email"'):
    t = s.get(B + p).text
    f = t.split('<form method="post" ' + formsel)[1].split('</form>')[0]
    d = MD()
    for m in re.finditer(r'<input([^>]*)>', f):
        a = m.group(1)
        n = re.search(r'name="([^"]+)"', a)
        v = re.search(r'value="([^"]*)"', a)
        if not n or 'type="password"' in a:
            continue
        if ('type="checkbox"' in a or 'type="radio"' in a) and 'checked' not in a:
            continue
        nm = H.unescape(n.group(1))
        val = H.unescape(v.group(1)) if v else ""
        if nm.endswith("[]"):
            d.setdefault(nm, []).append(val)
        else:
            d[nm] = val
    for m in re.finditer(r'<select name="([^"]+)"[^>]*>(.*?)</select>', f, re.S):
        o = re.search(r'<option value="([^"]*)"[^>]*selected', m.group(2))
        d[m.group(1)] = H.unescape(o.group(1)) if o else ""
    return d


def mysql(sql, database=""):
    """Runs SQL with the mysql client as root (for creating and dropping test databases)."""
    return subprocess.run(["mysql", "-uroot", f"--socket={SOCKET}", *( [database] if database else [] ), "-e", sql], capture_output=True, text=True)


def load_snapshot(name, database):
    """Recreates `database` from snapshot `name` (see seed.py) and returns a config file for it."""
    mysql(f"drop database if exists {database}; create database {database}; grant all on {database}.* to 'align_test'@'localhost'")
    with open(f"{SNAP}/{name}.sql") as f:
        subprocess.run(["mysql", "-uroot", f"--socket={SOCKET}", database], stdin=f, check=True)
    cfg = f"{WORK}/{database}.php"
    open(cfg, "w").write(open(CONFIG).read().replace(f'"name" => "{DB_MAIN}"', f'"name" => "{database}"'))
    return cfg
