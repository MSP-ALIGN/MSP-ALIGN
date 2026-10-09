"""1.41 demo data, on the fresh install: four made-up clients with everything the app can show, only on an install
without clients, loaded from Settings or the setup wizard, and removed completely with one click."""
import atexit, pymysql
from lib import *
import sitecustomize

FB = B_FRESH
FENV = dict(ENV, ALIGN_CONFIG=FRESH_CONFIG)
fdb = pymysql.connect(unix_socket=SOCKET, user="root", database=DB_FRESH, autocommit=True, cursorclass=pymysql.cursors.DictCursor)
def fq(sql, *a):
    with fdb.cursor() as c:
        c.execute(sql, a or None)
        return c.fetchall()
def fcsrf(s, p): return re.search(r'name="_csrf" value="([^"]+)"', s.get(FB + p).text).group(1)
def n(sql, *a): return list(fq(sql, *a)[0].values())[0]

# An install with nothing connected yet (the fresh install's RMM from nopsa_e2e is put back afterwards)
KEEP = fq("select * from settings where name like 'ninja\\_%%' or name in ('setup_state')")
fq("delete from settings where name like 'ninja\\_%%'")
def cleanup():
    fq("delete from clients where name = 'Real Co'")
    # leave the demo loaded, so crawl_fresh walks through its pages
    php("Align\\Demo\\Demo::remove(); Align\\Demo\\Demo::load((int) Align\\DB::value('SELECT id FROM users ORDER BY id LIMIT 1'));", env=FENV)
    for r in KEEP:
        fq("insert into settings (name,value,is_secret) values (%s,%s,%s) on duplicate key update value=values(value), is_secret=values(is_secret)", r["name"], r["value"], r["is_secret"])
atexit.register(cleanup)
php("Align\\Demo\\Demo::remove();", env=FENV); fq("delete from clients"); fq("delete from settings where name='demo_clients'")
fq("delete from meetings where client_id is not null"); fq("delete from devices where client_id is not null")  # what went with the clients deleted above
fq("insert into settings (name,value,is_secret) values ('setup_state','done',0) on duplicate key update value='done'")

st = login("new@example.com", "FreshAdminPass123!", FB)
t = st.get(FB + "/settings").text
ok('id="demo-data"' in t and 'action="/demo/load"' in t and not errs(t), "Settings → General offers demo data on an empty install")
t = st.get(FB + "/setup/clients").text; ok("Try it with demo data" in t, "and so does the wizard's Clients step")

# ---- only admins
tech = login("tech@example.com", TECH_PASSWORD)
r = tech.post(B + "/demo/load", data={"_csrf": csrf(tech, "/clients")}); ok(r.status_code == 403, "techs can't load it")
r = tech.post(B + "/demo/remove", data={"_csrf": csrf(tech, "/clients")}); ok(r.status_code == 403, "or remove it")

# ---- load from the wizard, back on the step
r = st.post(FB + "/demo/load", data={"_csrf": fcsrf(st, "/setup/clients"), "return": "/setup/clients"})
ids = json.loads(fq("select value from settings where name='demo_clients'")[0]["value"])
ok(r.url.endswith("/setup/clients") and "Added 4 demo clients" in flash(r.text) and len(ids) == 4 and n("select count(*) from clients") == 4, "loaded from the wizard: 4 clients, back on the step")
ok("Demo data is loaded" in r.text and "Remove demo data" in r.text, "wizard now offers to remove it")
inn = ",".join(map(str, ids))
counts = {k: n(sql) for k, sql in {
    "contacts": f"select count(*) from contacts where client_id in ({inn})", "devices": f"select count(*) from devices where client_id in ({inn})",
    "licenses": f"select count(*) from licenses where client_id in ({inn})", "budget": f"select count(*) from budget_lines where client_id in ({inn})",
    "roadmap": f"select count(*) from roadmap_items where client_id in ({inn})", "meetings": f"select count(*) from meetings where client_id in ({inn})",
    "controls": f"select count(*) from client_control_status where client_id in ({inn})", "documents": f"select count(*) from documents where client_id in ({inn})",
    "backups": f"select count(*) from backup_job_runs where job_uid like 'demo-%%'", "portal": f"select count(*) from portal_users where client_id in ({inn})",
    "suggestions": f"select count(*) from portal_submissions where client_id in ({inn}) and status='pending'",
    "training": f"select count(*) from sat_results where client_id in ({inn})"}.items()}
ok(all(v > 0 for v in counts.values()), "every part of the app gets data: " + str(counts))
ok(n(f"select count(*) from meetings where client_id in ({inn}) and status='scheduled' and starts_at > now()") >= 4, "upcoming meetings are in the future (dates follow today)")
ok(n(f"select count(*) from devices where client_id in ({inn}) and (serial like 'DEMO%%' or serial is null)") == counts["devices"] and n(f"select count(*) from contacts where client_id in ({inn}) and email not like '%%.example'") == 0, "made-up serials and .example addresses only")
r = st.post(FB + "/demo/load", data={"_csrf": fcsrf(st, "/settings")}); ok("already loaded" in flash(r.text) and n("select count(*) from clients") == 4, "can't be loaded twice")
ok(n(f"select count(*) from clients where id in ({inn}) and is_demo = 1") == 4, "demo clients are marked as demo")
# lifecycle: a mix, not everything in trouble
php_out = php(f'$l = new Align\\Lifecycle\\Lifecycle(); $d = array_filter($l->devices({ids[0]}), fn($x) => $x["is_hardware"]); $s = array_count_values(array_column($d, "status")); ksort($s); echo json_encode($s);', env=FENV).stdout
mix = json.loads(php_out or "{}")
ok(mix.get("ok", 0) >= len(sum([[1] * v for v in mix.values()], [])) // 2 and (mix.get("replace", 0) + mix.get("os_eos", 0)) >= 1, "devices: mostly healthy, some to replace or out of OS support: " + php_out)
# backups stay attached when backup matching runs again (mapping saves, backup syncs)
r_as = php("Align\\Sync\\BackupSync::assign();", env=FENV); ok(r_as.returncode == 0, "backup matching runs: " + r_as.stderr[:120])
t = st.get(FB + f"/clients/{ids[0]}/backups").text; ok("Servers nightly" in t, "backups still attached after matching runs again")
# nothing can be connected while it's there
r = st.post(FB + "/integrations/ninjaone", data={"_csrf": fcsrf(st, "/integrations/ninjaone"), "ninja_instance": M, "ninja_client_id": "x"})
ok("Remove the demo data first" in flash(r.text) and not fq("select 1 from settings where name='ninja_client_id'"), "an RMM can't be connected while demo data is loaded")
t = st.get(FB + "/setup/clients").text; ok("Clients" in t and "Remove demo data" in t, "the wizard's Clients step offers to remove it (and demo clients don't tick the step)")
# two clicks at once make one set
php("Align\\Demo\\Demo::remove();", env=FENV)
import threading
res = []
def go():
    s2 = requests.Session(); s2.cookies.update(st.cookies)
    res.append(s2.post(FB + "/demo/load", data={"_csrf": fcsrf(st, "/settings")}).status_code)
th = [threading.Thread(target=go) for _ in range(2)]; [x.start() for x in th]; [x.join() for x in th]
ok(n("select count(*) from clients") == 4 and len(json.loads(fq("select value from settings where name='demo_clients'")[0]["value"])) == 4, "a double click loads it once")
ids = json.loads(fq("select value from settings where name='demo_clients'")[0]["value"]); inn = ",".join(map(str, ids))
# a demo client deleted by hand: the rest still count, the gone one isn't reported
fq("delete from clients where id = %s", ids[3]); fq("delete from devices where client_id = %s", ids[3]); fq("delete from meetings where client_id = %s", ids[3])  # as deleting it on the client page does
ok(php("echo count(Align\\Demo\\Demo::clientIds());", env=FENV).stdout == "3", "a demo client deleted by hand drops out of the list")
fq("update settings set value='5' where name='demo_clients'"); ok(php("echo count(Align\\Demo\\Demo::clientIds());", env=FENV).stdout == "0" and st.get(FB + "/").status_code == 200, "a broken setting doesn't break pages")
fq("update settings set value=%s where name='demo_clients'", json.dumps(ids))

# ---- every page shows it
bad = []
pages = ["/", "/clients", "/budget", "/licenses", "/renewals", "/meetings", "/reports", "/projects", "/compliance", "/documents", "/portal-users"]
for i in ids[:2]:
    pages += [f"/clients/{i}" + p for p in ["", "/devices", "/licenses", "/budget", "/roadmap", "/meetings", "/compliance", "/documents", "/backups", "/contacts", "/portal", "/report/qbr", "/report/budget", "/report/assets", "/report/backup"]]
for p in pages:
    r = st.get(FB + p)
    if r.status_code != 200 or errs(r.text):
        bad.append((p, r.status_code, errs(r.text)[:1]))
ok(not bad, f"{len(pages)} pages render with the demo: " + str(bad[:3]))
t = st.get(FB + "/").text
ok("Demo data." in t and "suggested license to review" in t and "past end of life" in t, "dashboard: demo banner and things to act on")
t = st.get(FB + f"/clients/{ids[0]}/devices").text; ok("Windows 10" in t and "DEMO1" in t, "devices of every age, including an unsupported OS")
t = st.get(FB + f"/clients/{ids[0]}/backups").text; ok("Servers nightly" in t and "Workstations" in t, "backups with 30 days of results")
t = st.get(FB + f"/clients/{ids[0]}/portal").text; ok("Invited" in t, "a client portal user ready to invite")

# ---- only on an empty install; remove keeps anything real
fq("insert into clients (source, name) values ('manual', 'Real Co')")
r = st.post(FB + "/demo/remove", data={"_csrf": fcsrf(st, "/settings")})
ok("Removed the 3 demo clients" in flash(r.text) and n("select count(*) from clients") == 1 and fq("select name from clients")[0]["name"] == "Real Co", "remove deletes the demo clients only")
left = {k: n(sql) for k, sql in {
    "training": f"select count(*) from sat_results where client_id in ({inn})",
    "devices": f"select count(*) from devices where client_id in ({inn})", "meetings": f"select count(*) from meetings where client_id in ({inn})",
    "licenses": f"select count(*) from licenses where client_id in ({inn})", "docs": f"select count(*) from documents where client_id in ({inn})",
    "portal": f"select count(*) from portal_users where client_id in ({inn})", "contacts": f"select count(*) from contacts where client_id in ({inn})",
    "budget": f"select count(*) from budget_lines where client_id in ({inn})", "roadmap": f"select count(*) from roadmap_items where client_id in ({inn})",
    "controls": f"select count(*) from client_control_status where client_id in ({inn})", "frameworks": f"select count(*) from client_frameworks where client_id in ({inn})",
    "suggestions": f"select count(*) from portal_submissions where client_id in ({inn})", "versions": "select count(*) from document_versions v left join documents d on d.id = v.document_id where d.id is null",
    "links": f"select count(*) from client_links where client_id in ({inn})", "queued mail": f"select count(*) from mail_queue where client_id in ({inn}) and status = 'queued'", "backup rows": "select (select count(*) from backup_jobs where uid like 'demo-%%') + (select count(*) from backup_job_runs where job_uid like 'demo-%%') + (select count(*) from backup_workloads where uid like 'demo-%%') + (select count(*) from backup_companies where uid like 'demo-%%')",
    "overrides": "select count(*) from device_overrides o left join devices d on d.id = o.device_id where d.id is null"}.items()}
ok(all(v == 0 for v in left.values()), "and everything attached to them: " + str(left))
ok("Demo data." not in st.get(FB + "/").text and not fq("select 1 from settings where name='demo_clients' and value is not null and value <> ''"), "banner gone, nothing remembered")
ok(n("select count(*) from audit_log where action in ('demo.load','demo.remove')") >= 2, "loading and removing are audited")
r = st.post(FB + "/demo/load", data={"_csrf": fcsrf(st, "/settings")}); ok("no clients" in flash(r.text) and n("select count(*) from clients") == 1, "not while there are real clients")
fq("delete from clients where name='Real Co'"); fq("insert into settings (name,value,is_secret) values ('ninja_client_id','x',0)")
php('Align\\Settings::setSecret("ninja_client_secret", "x");', env=FENV)
php_r = php('echo Align\\Demo\\Demo::blocked();', env=FENV).stdout; fq("delete from settings where name in ('ninja_client_id','ninja_client_secret')")
ok("before it's connected" in php_r or "connected" in php_r, "not with an RMM, PSA or backup service connected: " + php_r[:80])
r = st.post(FB + "/demo/remove", data={"_csrf": fcsrf(st, "/settings")}); ok("no demo data" in flash(r.text), "remove with nothing loaded is harmless")
done()
