"""Times every page each role can reach on the performance install (tests/perf/build.sh).

    PYTHONPATH=tests/e2e ALIGN_TEST_WORK=/tmp/msp-align-perf python3 tests/perf/timing.py [--csv out.csv] [--runs 2]

Follows links like the crawl suite (up to 3 pages per page type), requests each page --runs times and keeps
the fastest, then prints the slowest page types per role. Exits 1 if a page errors or a page type is over
--limit seconds (default 1.0).
"""
import argparse, collections, csv, re, sys, time
from urllib.parse import urljoin, urlparse
import requests

ap = argparse.ArgumentParser()
ap.add_argument("--base", default="http://127.0.0.1:8085")
ap.add_argument("--csv")
ap.add_argument("--runs", type=int, default=2)
ap.add_argument("--limit", type=float, default=1.0)
ap.add_argument("--roles", default="admin,tech,viewer,portal")
a = ap.parse_args()
B = a.base
PORTAL_TOTP = "KRUGS4ZANFZSAYJAORSXG5BAONSWG4TF"  # tests/perf/plan.php
ROLES = {"admin": ("admin@example.com", "LongPassword123!"), "tech": ("tech@example.com", "TechPassword123!"),
         "viewer": ("viewer@example.com", "ViewerPassword123!")}
SKIP = re.compile(r'^/(logout|ics/|vendor/|assets/|branding/logo|settings/email/connect|integrations/email/connect|clients/\d+/logo|users/\d+/avatar|portal/logout)'
                  r'|\.(csv|png|jpg|svg|js|css|pdf|ics)$|/(export|download|pdf)$')


def pattern(p):
    return re.sub(r'\d+', 'N', p.split('?')[0]) + ('?' + '&'.join(sorted(k.split('=')[0] for k in p.split('?')[1].split('&'))) if '?' in p else '')


def session(role):
    s = requests.Session()
    if role == "portal":
        import pymysql
        db = pymysql.connect(unix_socket="/run/mysqld/mysqld.sock", user="root", database="align_test_perf")
        with db.cursor() as c:
            # the portal owner of the biggest client
            c.execute("select p.email, p.client_id from portal_users p join devices d on d.client_id = p.client_id or d.rmm_org_id in "
                      "(select external_id from client_links l where l.client_id = p.client_id) group by p.id order by count(*) desc limit 1")
            email, cid = c.fetchone()
        tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B + "/portal/login").text).group(1)
        r = s.post(B + "/portal/login", data={"_csrf": tok, "email": email, "password": f"PortalPass123!{cid}"})
        if r.url.endswith("/portal/login/2fa"):
            import base64, hashlib, hmac, struct
            h = hmac.new(base64.b32decode(PORTAL_TOTP), struct.pack(">Q", int(time.time()) // 30), hashlib.sha1).digest()
            code = "%06d" % ((struct.unpack(">I", h[h[-1] & 15:(h[-1] & 15) + 4])[0] & 0x7fffffff) % 1000000)
            tok = re.search(r'name="_csrf" value="([^"]+)"', r.text).group(1)
            r = s.post(B + "/portal/login/2fa", data={"_csrf": tok, "code": code})
        return s, "/portal" not in r.url or "/login" in r.url
    email, pw = ROLES[role]
    tok = re.search(r'name="_csrf" value="([^"]+)"', s.get(B + "/login").text).group(1)
    r = s.post(B + "/login", data={"_csrf": tok, "email": email, "password": pw})
    return s, "/login" in r.url


rows, failed = [], []
for role in a.roles.split(","):
    s, bad_login = session(role)
    if bad_login:
        print(f"{role}: sign-in failed"); failed.append((role, "sign-in")); continue
    start = ["/portal"] if role == "portal" else ["/", "/clients", "/settings", "/users", "/audit", "/frameworks", "/settings/os", "/sync"]
    queue, seen, per = list(start), set(), collections.Counter()
    times = collections.defaultdict(list)
    n = 0
    t0 = time.time()
    while queue and n < 1200:
        p = queue.pop(0)
        if p in seen:
            continue
        seen.add(p)
        pat = pattern(p)
        if per[pat] >= 3:
            continue
        per[pat] += 1
        best, r = None, None
        for _ in range(a.runs):
            t = time.time(); r = s.get(B + p, allow_redirects=False); dt = time.time() - t
            best = dt if best is None else min(best, dt)
        n += 1
        if r.status_code in (301, 302, 303, 403):
            continue
        if r.status_code >= 400 or re.search(r'(Warning:|Notice:|Deprecated:|Fatal error|Uncaught|Something went wrong)', r.text):
            failed.append((role, p, r.status_code)); continue
        times[pat].append((best, p, len(r.content)))
        if "text/html" not in r.headers.get("content-type", ""):
            continue
        for href in re.findall(r'href="([^"#]+)"', r.text):
            u = urlparse(urljoin(B + p, href))
            if u.netloc != urlparse(B).netloc:
                continue
            path = u.path + ("?" + u.query if u.query else "")
            if SKIP.search(u.path) or path in seen or (role == "portal") != u.path.startswith("/portal"):
                continue
            queue.append(path)
    worst = sorted(((max(v)[0], k, max(v)[1], max(v)[2]) for k, v in times.items()), reverse=True)
    total = sum(x[0] for v in times.values() for x in v)
    print(f"\n{role}: {n} pages, {len(times)} page types in {time.time() - t0:.0f}s (sum of fastest runs {total:.1f}s)")
    for dt, pat, p, size in worst[:15]:
        print(f"   {dt:6.2f}s  {size / 1024:7.0f} KB  {p}")
    for dt, pat, p, size in worst:
        rows.append((role, pat, p, round(dt, 3), size))
if a.csv:
    with open(a.csv, "w", newline="") as f:
        w = csv.writer(f); w.writerow(["role", "page type", "slowest url", "seconds", "bytes"]); w.writerows(rows)
over = [r for r in rows if r[3] > a.limit]
print(f"\n{len(over)} page types over {a.limit}s; {len(failed)} errors")
for f in failed[:20]:
    print("   ERROR", f)
sys.exit(1 if over or failed else 0)
