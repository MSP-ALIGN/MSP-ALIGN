"""1.36 No-PSA mode, on the fresh install (no PSA): clients from the RMM's organizations, CSV import of clients and
contacts, and screens that don't talk about a PSA that isn't there. The CSV rules for a PSA client run on the main install."""
import pymysql
from lib import *
import sitecustomize

FB = B_FRESH
FENV = dict(ENV, ALIGN_CONFIG=FRESH_CONFIG)
fdb = pymysql.connect(unix_socket=SOCKET, user="root", database=DB_FRESH, autocommit=True, cursorclass=pymysql.cursors.DictCursor)


def fq(sql, *a):
    with fdb.cursor() as c:
        c.execute(sql, a or None)
        return c.fetchall()


def fphp(code): return php(code, env=FENV)
def fsync(): return subprocess.run(["php", ALIGN, "sync"], env=FENV, capture_output=True, text=True).stdout
def fcsrf(s, p="/clients"): return re.search(r'name="_csrf" value="([^"]+)"', s.get(FB + p).text).group(1)


def upload(s, kind, name, data, base=FB, c=None):
    return s.post(base + "/clients/import", data={"_csrf": c or re.search(r'name="_csrf" value="([^"]+)"', s.get(base + "/clients/import").text).group(1), "kind": kind},
                  files={"file": (name, data, "text/csv")})


def token(r):
    m = re.search(r'name="token" value="([a-f0-9]{32})"', r.text)
    return m.group(1) if m else None


def text(r): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", r.text)))


# ---- an install with only an RMM
fphp('Align\\Settings::set("ninja_instance", "' + M + '"); Align\\Settings::set("ninja_client_id", "ninja-id"); Align\\Settings::setSecret("ninja_client_secret", "ninja-secret");'
     ' Align\\Settings::set("dell_api_base", "' + M + '"); Align\\Settings::set("lenovo_api_base", "' + M + '");')
fq("delete from clients")
fq("update rmm_orgs set client_created_at = null") if fq("select 1 from information_schema.columns where table_schema=database() and table_name='rmm_orgs' and column_name='client_created_at'") else None
fq("delete from settings where name='rmm_create_clients'")
out = fsync()
ok("NinjaOne organizations: 5 organizations" in out and "PSA" not in out and "Skipping" not in out, "sync without a PSA: organizations read, no 'PSA not configured' noise: " + out[:300])
ok(fq("select count(*) n from clients")[0]["n"] == 0, "no clients are made unless asked")
st = login("new@example.com", "FreshAdminPass123!", FB)
t = st.get(FB + "/mapping").text
ok("Add 5 clients from organizations" in t and "No PSA is connected" in t and not errs(t), "Client mapping offers to add clients from the organizations")

# ---- clients from organizations
fq("insert into clients (name, source, is_archived, planning_excluded) values ('Cedar Ridge Family Dental, LLC','manual',0,0)")  # a client added by hand before
orgs = {r["org_id"]: r["name"] for r in fq("select org_id, name from rmm_orgs where provider='ninjaone'")}
r = st.post(FB + "/mapping/create-clients", data={"_csrf": fcsrf(st, "/mapping"), "provider": "ninjaone"})
cl = fq("select c.id, c.name, c.source, l.external_id, l.match_method from clients c left join client_links l on l.client_id=c.id and l.provider='ninjaone' order by c.name")
ok(len(cl) == 5 and all(c["external_id"] for c in cl) and all(c["source"] == "manual" for c in cl), "each organization now has a linked client: " + str([(c["name"], c["external_id"]) for c in cl]))
cedar = [c for c in cl if c["name"].startswith("Cedar Ridge")]
ok(len(cedar) == 1 and orgs.get(cedar[0]["external_id"]) == "Cedar Ridge Family Dental" and cedar[0]["match_method"] == "auto", "a client with the organization's name (LLC aside) is linked, not duplicated")
ok("Added 4 clients" in flash(r.text), "says how many were added: " + flash(r.text))
ok(fq("select count(*) n from audit_log where action='client.create_from_rmm'")[0]["n"] >= 1, "audited")
fsync()
cid = cl[0]["id"]
t = st.get(FB + f"/clients/{cid}").text
nd = fq("select count(*) n from devices d join client_links l on l.provider=d.rmm_provider and l.external_id=d.rmm_org_id where l.client_id=%s and d.removed_at is null", cid)[0]["n"]
ok(nd > 0 and not errs(t) and "Lifecycle" in t, f"the client's lifecycle view fills from RMM devices alone ({nd} devices)")
ok(st.get(FB + f"/clients/{cid}/devices").status_code == 200 and st.get(FB + f"/clients/{cid}/budget").status_code == 200, "devices and budget pages work")
gone = cl[1]
fq("delete from clients where id=%s", gone["id"])
r = st.post(FB + "/mapping/create-clients", data={"_csrf": fcsrf(st, "/mapping"), "provider": "ninjaone"})
ok(fq("select count(*) n from clients where name=%s", gone["name"])[0]["n"] == 0, "a client deleted on purpose isn't added again")
# on every sync, only for organizations that never had one
r = st.post(FB + "/mapping/create-clients", data={"_csrf": fcsrf(st, "/mapping"), "provider": "ninjaone", "action": "auto", "auto": "1"})
ok(fq("select value from settings where name='rmm_create_clients'")[0]["value"] == "1", "admin turns on 'add clients for new organizations'")
last = cl[2]
fq("delete from clients where id=%s", last["id"]); fq("update rmm_orgs set client_created_at=null where org_id=%s", last["external_id"])  # as if the organization were new
out = fsync()
ok(f"Clients from new NinjaOne organizations: 1 added" in out and fq("select count(*) n from clients where name=%s", last["name"])[0]["n"] == 1
   and fq("select count(*) n from clients where name=%s", gone["name"])[0]["n"] == 0, "sync adds a client for a new organization only: " + out[out.find("Clients from"):][:120])
n0 = fq("select count(*) n from clients")[0]["n"]
requests.post(FB + "/mapping/create-clients", data={"provider": "ninjaone"}, allow_redirects=False)
ok(fq("select count(*) n from clients")[0]["n"] == n0, "a post without a session or token does nothing")

# ---- screens don't talk about a PSA that isn't there
dev = fq("select d.id from devices d join client_links l on l.provider=d.rmm_provider and l.external_id=d.rmm_org_id where l.client_id=%s and d.removed_at is null limit 1", cid)[0]["id"]
pages = ["/", "/clients", f"/clients/{cid}", f"/clients/{cid}/devices", f"/clients/{cid}/contacts", f"/clients/{cid}/licenses", f"/clients/{cid}/budget",
         f"/devices/{dev}", "/sync", "/licenses", "/renewals", "/settings/onboarding", "/clients/import"]
said = {}
for p in pages:
    r = st.get(FB + p)
    tt = text(r)
    hits = re.findall(r".{0,40}\bPSA\b.{0,40}", tt)
    if r.status_code != 200 or errs(r.text) or hits:
        said[p] = r.status_code if r.status_code != 200 else (errs(r.text) or hits[:2])
ok(not said, "no page mentions a PSA when there isn't one: " + str(said)[:600])
t = st.get(FB + "/").text
ok("Connect PSA" not in text(st.get(FB + "/")) or "Optional" in text(st.get(FB + "/")), "setup checklist treats the PSA as optional")
t = text(st.get(FB + "/help"))
ok("Run MSP-ALIGN without a PSA" in t and "No PSA needed" in t, "Help explains running without a PSA")

# ---- CSV import: clients
before = fq("select count(*) n from clients")[0]["n"]
csvdata = ("Company,Industry,Phone,City,State,Zip,Email,Notes,Favourite colour\n"
           "Zz Import One LLC,dental,555-0100,Springfield,CA,90000,pat@one.example,hello,blue\n"
           "zz import one llc,,,,,,,,\n"
           ",Legal,,,,,,,\n"
           "Zz Import Two,Martian,,,,,not-an-email,,\n"
           f'"{cl[0]["name"]}",Legal,555-0199,,,,,,\n')
r = upload(st, "clients", "clients.csv", csvdata)
tt = text(r)
ok(r.status_code == 200 and not errs(r.text) and token(r) and "2 to add" in tt and "1 to update" in tt and "1 skipped" in tt and "1 can't be imported" in tt,
   "clients file checked: 2 add, 1 update, 1 duplicate skipped, 1 without a name")
ok(re.search(r"clients\.csv\s*: 5 rows", tt) is not None, "the check names the uploaded file")
ok("Not used: Favourite colour" in tt and "Martian" in tt and "isn't an email address" in tt, "shows unused columns and what it left out")
ok(fq("select count(*) n from clients")[0]["n"] == before, "checking saves nothing")
r = st.post(FB + "/clients/import/run", data={"_csrf": fcsrf(st), "token": token(upload(st, "clients", "clients.csv", csvdata))})
ok("Imported 2 new clients and updated 1" in flash(r.text), "import runs: " + flash(r.text))
one = fq("select * from clients where name='Zz Import One LLC'")[0]
ok(one["industry"] == "Dental" and one["main_phone"] == "555-0100" and one["address"] == "Springfield, CA 90000" and one["contact_email"] == "pat@one.example" and one["notes"] == "hello",
   "values land in the right fields (industry matched to the list, city/state/zip as the address)")
two = fq("select * from clients where name='Zz Import Two'")[0]
ok(two["industry"] is None and two["contact_email"] is None, "values that don't fit are left out")
up = fq("select * from clients where id=%s", cl[0]["id"])[0]
ok(up["industry"] == "Legal" and up["main_phone"] == "555-0199" and up["address"] is None, "an existing client only gets the filled columns")
ok(fq("select count(*) n from audit_log where action='import.clients'")[0]["n"] >= 1, "audited")
ok(st.post(FB + "/clients/import/run", data={"_csrf": fcsrf(st), "token": "0" * 32}).url.endswith("/clients/import"), "an unknown or used import can't run")
# semicolons, Windows-1252, a BOM
r = upload(st, "clients", "win.csv", "﻿Name;Notes\n".encode("utf-8") + "Zz Café Société;Straße\n".encode("cp1252"))
ok(r.status_code == 200 and "Zz Café Société" in H.unescape(r.text) and "1 to add" in text(r), "semicolon file in Windows-1252 with a BOM reads correctly")
ok(upload(st, "clients", "bad.csv", "Colour\nblue\n").url.endswith("/clients/import?kind=clients") and True, "a file with no name column is refused")
tp = st.get(FB + "/clients/import/template/clients")
ok(tp.headers.get("Content-Type", "").startswith("text/csv") and tp.text.startswith("Name,Industry"), "clients template downloads")

# ---- CSV import: contacts
csvc = ("Client,First name,Last name,Title,Email,Mobile,Primary,Billing,Decision maker\n"
        "Zz Import One LLC,Pat,Lee,Office Manager,pat@one.example,555-0101,yes,x,1\n"
        "Zz Import One LLC,Sam,Poe,,,,no,,\n"
        "Nobody Inc,Ann,Other,,ann@nobody.example,,,,\n"
        "Zz Import One LLC,,,,,,,,\n")
r = upload(st, "contacts", "contacts.csv", csvc)
tt = text(r)
ok("2 to add" in tt and "2 can't be imported" in tt and 'No client named "Nobody Inc"' in tt, "contacts file checked: 2 to add; unknown client and missing name refused")
r = st.post(FB + "/clients/import/run", data={"_csrf": fcsrf(st), "token": token(r)})
pat = fq("select * from contacts where email='pat@one.example'")
ok(len(pat) == 1 and pat[0]["name"] == "Pat Lee" and pat[0]["is_primary"] == 1 and pat[0]["is_billing"] == 1 and pat[0]["decision_maker"] == 1 and pat[0]["mobile"] == "555-0101" and pat[0]["source"] == "manual",
   "contact added with first + last name, flags and mobile")
r = upload(st, "contacts", "again.csv", "Client,Name,Email,Title\nZz Import One LLC,Pat Lee,pat@one.example,Practice Manager\n")
ok("1 to update" in text(r), "the same email at the same client updates")
st.post(FB + "/clients/import/run", data={"_csrf": fcsrf(st), "token": token(r)})
ok(fq("select title from contacts where email='pat@one.example'")[0]["title"] == "Practice Manager" and fq("select count(*) n from contacts where email='pat@one.example'")[0]["n"] == 1, "updated, not duplicated")
ok(st.get(FB + "/clients/import/template/contacts").text.startswith("Client,Name"), "contacts template downloads")
fq("update contacts set is_billing=1, is_technical=1 where email='pat@one.example'")
r = upload(st, "contacts", "flags.csv", "Client,Name,Email,Billing,Technical\nZz Import One LLC,Pat Lee,pat@one.example,,no\n")
st.post(FB + "/clients/import/run", data={"_csrf": fcsrf(st), "token": token(r)})
fl = fq("select is_billing, is_technical from contacts where email='pat@one.example'")[0]
ok(fl["is_billing"] == 1 and fl["is_technical"] == 0, "a blank yes/no cell leaves the flag alone; 'no' clears it")
fq("update clients set address='1 Long Street\nSpringfield, CA 90000' where name='Zz Import One LLC'")
r = upload(st, "clients", "city.csv", "Name,City,State\nZz Import One LLC,Shelbyville,CA\n")
ok("address left as it is" in text(r), "city and state alone don't replace a full address")
r2 = upload(st, "contacts", "dup.csv", "Client,Name,Email\nZz Import One LLC,Dee Dupe,dee@one.example\n")
t1, t2 = token(r2), token(upload(st, "contacts", "dup.csv", "Client,Name,Email\nZz Import One LLC,Dee Dupe,dee@one.example\n"))
st.post(FB + "/clients/import/run", data={"_csrf": fcsrf(st), "token": t1}); st.post(FB + "/clients/import/run", data={"_csrf": fcsrf(st), "token": t2})
ok(fq("select count(*) n from contacts where email='dee@one.example'")[0]["n"] == 1, "the same file imported twice (two tabs) adds the contact once")

# ---- 2.2.2 Ready to start without a PSA: projects go on To do in their quarter and are marked started (no ticket)
def tx(h): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", h)))
fcur = fphp('echo Align\\Roadmap\\Plan::quarters()[Align\\Roadmap\\Plan::currentIndex()]["start"];').stdout.strip()
fq("delete from roadmap_items where title like 'NP %%'")
for title, status, quarter in [("NP due", "approved", fcur), ("NP later", "approved", "2099-01-01"), ("NP proposed", "proposed", fcur)]:
    fq("insert into roadmap_items (client_id, title, category, priority, status, target_quarter, cost) values (%s, %s, 'security', 'high', %s, %s, 500)", cid, title, status, quarter)
npid = {r["title"]: r["id"] for r in fq("select id, title from roadmap_items where title like 'NP %%'")}
t = st.get(FB + "/todo").text
ok(f'data-todo="project-{npid["NP due"]}"' in t and f'project-{npid["NP later"]}"' not in t and f'project-{npid["NP proposed"]}"' not in t,
   "without a PSA, an approved project goes on To do when its quarter is here (later and proposed ones don't)")
ok(tx(t) and "marks a project started" in tx(t) and "ticket" not in tx(t).split("Ready to start", 1)[1].split("NP due", 1)[0].lower(), "To do doesn't talk about tickets")
fw = st.get(FB + f"/projects/{npid['NP due']}/start").text
ok("Mark as started" in fw and "ticket" not in tx(fw).lower(), "the confirm window marks it started, with no word about tickets")
fr = st.get(FB + f"/projects/{npid['NP later']}/form").text
ok('data-ticket-state="later"' in fr and "Not started yet" in fr and "Ticket" not in fr, "the project window: Not started yet, with Ready to start")
pg = st.get(FB + f"/projects?client={cid}&status=all").text
ok("<th>Started</th>" in pg and ">Started<" in pg and not errs(pg), "Projects has a Started column and filter")
r = st.post(FB + f"/projects/{npid['NP due']}/start", data={"_csrf": fcsrf(st, "/todo"), "back": "/todo"})
row = fq("select psa_ticket_id, started_at, started_by from roadmap_items where id = %s", npid["NP due"])[0]
ok(row["started_at"] and row["started_by"] and row["psa_ticket_id"] is None and f'project-{npid["NP due"]}"' not in r.text and "Started" in flash(r.text),
   "Ready to start marks it started and it leaves To do: " + flash(r.text))
pg = st.get(FB + f"/projects?client={cid}&ticket=has&status=all").text
ok("NP due" in pg and "NP later" not in pg and "Make the ticket" not in pg, "the Started filter lists it")
fq("insert into roadmap_items (client_id, title, category, priority, status, target_quarter, cost) values (%s, 'NP two', 'security', 'high', 'scheduled', %s, 500), (%s, 'NP three', 'security', 'high', 'approved', %s, 500)", cid, fcur, cid, fcur)
two = {r["title"]: r["id"] for r in fq("select id, title from roadmap_items where title in ('NP two', 'NP three')")}
t = st.get(FB + "/todo").text
ok('id="modal-start-all"' in t and "Start 2 projects" in tx(t) and "Start them" in t, "Ready to start: all starts them all, without tickets")
r = st.post(FB + "/projects/start-all", data={"_csrf": fcsrf(st, "/todo"), "ids[]": list(two.values()), "back": "/todo"})
ok(all(fq("select started_at from roadmap_items where id = %s", i)[0]["started_at"] for i in two.values()) and "Started 2 projects without a ticket" in flash(r.text), flash(r.text))
fq("delete from roadmap_items where title like 'NP %%'")

# ---- on an install with a PSA: PSA details are left alone
ma = login("admin@example.com", "LongPassword123!")
psa_client = q("select id, name, main_phone, industry from clients where source='psa' and is_archived=0 order by id limit 1")[0]
r = upload(ma, "clients", "psa.csv", f'Name,Phone,Industry\n"{psa_client["name"]}",000-0000,Legal\n', base=B)
ok("details from ITFlow kept" in text(r) and "1 to update" in text(r), "a PSA client only takes industry and notes")
ma.post(B + "/clients/import/run", data={"_csrf": csrf(ma, "/clients"), "token": token(r)})
after = q("select main_phone, industry from clients where id=%s", psa_client["id"])[0]
ok(after["main_phone"] == psa_client["main_phone"] and after["industry"] == "Legal", "its phone from ITFlow is unchanged")
q("update clients set industry=%s where id=%s", psa_client["industry"], psa_client["id"])
r = upload(ma, "contacts", "psa-contacts.csv", f'Client,Name\n"{psa_client["name"]}",New Person\n', base=B)
ok("come from ITFlow" in text(r) and "0 to add" in text(r), "contacts for a PSA client are left to the PSA")
vw = login("viewer@example.com", "ViewerPassword123!")
ok(vw.get(B + "/clients/import").status_code == 403, "viewers can't import")
mt = token(upload(ma, "clients", "mine.csv", "Name\nZz Not Yours\n", base=B))
te = login("tech@example.com", TECH_PASSWORD)
te.post(B + "/clients/import/run", data={"_csrf": csrf(te, "/clients"), "token": mt})
ok(q("select count(*) n from clients where name='Zz Not Yours'")[0]["n"] == 0, "someone else's checked file can't be imported by another user")

fq("delete from settings where name='rmm_create_clients'")
done()
