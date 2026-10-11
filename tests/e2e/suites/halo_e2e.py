"""2.12.0 HaloPSA (beta, read-only) as the PSA. On the main install, where ITFlow is the PSA: the Integrations card and
setup page, saving, Test, refused secrets and addresses explained, a changed authorisation server needing the secret
again, and a second PSA set up shown as "Not in use" and never read (the client list stays ITFlow's). On an install of
its own whose PSA is HaloPSA: clients (inactive archived), client details from the main site and main contact,
contacts (Halo's "General User" skipped, a user's client found through its site, bad emails dropped), assets (make,
model, serial and OS from the asset type's fields, placeholders dropped, bad ids skipped) imported as devices, the
managed-services estimate from recurring invoices, record_count followed past Halo's silent page cap, no 2-minute
poll, nothing but reads sent, and bad answers (an HTML page, a non-list, a repeated page, an error, no permission)
reported without archiving anything."""
import re, json, subprocess, atexit, requests, pymysql, html as H
from lib import *

HDB = "align_halo"


def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))


st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
itflow_clients = q("select count(*) n from clients where source='psa' and is_archived=0")[0]["n"]
psa_before = (q("select value from settings where name='psa_provider'") or [{"value": ""}])[0]["value"]


def cleanup():
    mock("/mock/halo-set", {})
    q("update settings set value=%s where name='psa_provider'", psa_before)
    q("delete from settings where name in ('halo_url','halo_client_id','halo_client_secret','halo_auth_url','halo_tenant')")
    mysql(f"drop database if exists {HDB}")


atexit.register(cleanup)
cleanup()

# ==== the main install (ITFlow is its PSA) ====
t = st.get(B + "/integrations").text
ok("HaloPSA" in t and "Beta. Clients, sites, contacts" in t, "the Integrations page lists HaloPSA, marked beta")
p = st.get(B + "/integrations/halopsa").text
ok(all(f'name="{n}"' in p for n in ("halo_url", "halo_client_id", "halo_client_secret", "halo_auth_url", "halo_tenant")) and "read:customers" in p and not errs(p),
   "its page asks for the address, client ID and secret (authorisation server and tenant optional), with read-only permissions")
ok(tech.get(B + "/integrations/halopsa", allow_redirects=False).status_code in (302, 403), "techs can't open it")
F = form(st, "/integrations/halopsa", 'action="/integrations/halopsa"')
r = st.post(B + "/integrations/halopsa", data={**F, "halo_url": M + "/halo", "halo_client_id": "halo id!", "halo_client_secret": "halo-secret"})
ok("only hold letters" in flash(r.text) and not q("select 1 from settings where name='halo_url' and value<>''"), "a client ID with spaces or symbols is refused (nothing saved)")
F = form(st, "/integrations/halopsa", 'action="/integrations/halopsa"')
st.post(B + "/integrations/halopsa", data={**F, "halo_client_id": "halo-id", "halo_client_secret": "halo-secret"})
F = form(st, "/integrations/halopsa", 'action="/integrations/halopsa"')
r = st.post(B + "/integrations/halopsa", data={**F, "halo_url": M + "/halo"})
ok("Enter the client secret again with the Halo web address" in flash(r.text) and not q("select 1 from settings where name='halo_url' and value<>''"),
   "a secret saved before any address isn't sent to the first one set without typing it again")
q("update settings set value='' where name='psa_provider'")  # an install from before psa_provider was always written
F = form(st, "/integrations/halopsa", 'action="/integrations/halopsa"')
r = st.post(B + "/integrations/halopsa", data={**F, "halo_url": M + "/halo", "halo_client_id": "halo-id", "halo_client_secret": "halo-secret"})
ok(q("select value from settings where name='halo_url'")[0]["value"] == M + "/halo" and "halo-secret" not in st.get(B + "/integrations/halopsa").text,
   "the address, ID and secret save (the secret isn't shown again)")
ok(q("select value from settings where name='psa_provider'")[0]["value"] == "itflow", "setting up a second PSA writes down the one in use (ITFlow), never switching the client list")
t = text(st.get(B + "/integrations").text)
ok(re.search(r"HaloPSA .{0,80}Not in use", t) is not None, "its card says Not in use: ITFlow is this install's PSA")
p = text(st.get(B + "/integrations/halopsa").text)
ok("ITFlow is this install's PSA, so nothing is read from HaloPSA" in p, "its page explains why")
r = st.post(B + "/integrations/halopsa/test", data={"_csrf": csrf(st, "/integrations/halopsa")})
ok("Connected. 3 clients readable." in text(r.text), "Test signs in and counts the clients: " + flash(r.text)[:160])
align("sync", "--quiet")
run = json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
ok(not any("Halo" in k for k in run) and q("select count(*) n from clients where source='psa' and is_archived=0")[0]["n"] == itflow_clients,
   "the sync reads ITFlow only; its clients are untouched")
F = form(st, "/integrations/halopsa", 'action="/integrations/halopsa"')
r = st.post(B + "/integrations/halopsa", data={**F, "halo_auth_url": M + "/elsewhere"})
ok("Enter the client secret again" in flash(r.text) and not q("select 1 from settings where name='halo_auth_url' and value<>''"),
   "pointing the authorisation server somewhere new needs the secret again")
setting("halo_auth_url", M + "/halo/auth")
F = form(st, "/integrations/halopsa", 'action="/integrations/halopsa"')
r = st.post(B + "/integrations/halopsa", data={**F, "halo_auth_url": ""})
ok("Enter the client secret again" in flash(r.text) and q("select value from settings where name='halo_auth_url'")[0]["value"] == M + "/halo/auth",
   "clearing the authorisation server needs the secret again too")
setting("halo_auth_url", "")
setting("halo_client_secret", "wrong-secret", True)
r = st.post(B + "/integrations/halopsa/test", data={"_csrf": csrf(st, "/integrations/halopsa")})
ok("refused the client ID or secret" in text(r.text), "a wrong secret is explained")
setting("halo_client_secret", "halo-secret", True)
setting("halo_url", M + "/nothing-here")
r = st.post(B + "/integrations/halopsa/test", data={"_csrf": csrf(st, "/integrations/halopsa")})
ok("no token address" in text(r.text), "a wrong address is explained")
setting("halo_url", M + "/halo")

# ==== an install whose PSA is HaloPSA ====
mysql(f"drop database if exists {HDB}; create database {HDB}; grant all on {HDB}.* to 'align_test'@'localhost'")
cfg = f"{WORK}/{HDB}.php"
open(cfg, "w").write(open(CONFIG).read().replace(f'"name" => "{DB_MAIN}"', f'"name" => "{HDB}"'))
HENV = {**ENV, "ALIGN_CONFIG": cfg}
def hal(*a): return subprocess.run(["php", ALIGN, *a], env=HENV, capture_output=True, text=True)
mig = hal("migrate")
hdb = pymysql.connect(unix_socket=SOCKET, user="root", database=HDB, cursorclass=pymysql.cursors.DictCursor, autocommit=True)
def h(sql, *a):
    with hdb.cursor() as c:
        c.execute(sql, a or None)
        return c.fetchall()
for k, v in [("halo_url", M + "/halo"), ("halo_client_id", "halo-id"), ("psa_provider", "halopsa")]:
    h("insert into settings (name,value,is_secret) values (%s,%s,0) on duplicate key update value=values(value)", k, v)
php('Align\\Settings::setSecret("halo_client_secret", "halo-secret");', HENV)
mock("/mock/halo-set", {})  # also empties the request log
hal("sync", "--quiet")
run = json.loads(h("select summary from sync_runs order by id desc limit 1")[0]["summary"])
ok(mig.returncode == 0 and "HaloPSA clients" in run and not any(str(v).startswith("ERROR") for v in run.values()),
   "a sync reads HaloPSA: " + "; ".join(f"{k}: {str(v)[:90]}" for k, v in run.items() if "Halo" in k or "Managed" in k))
cl = {r["psa_id"]: r for r in h("select * from clients where source='psa'")}
ok(set(cl) == {"1", "2", "3"} and cl["3"]["is_archived"] == 1 and cl["1"]["is_archived"] == 0 and cl["2"]["name"] == "Harbor Point Law",
   "clients come in past the 2-row page cap (record_count followed); inactive is archived; control characters removed")
c1 = cl["1"]
ok(c1["address"] == "100 Cedar Ridge Rd\nSuite 4\nSpringfield\nOR\n97000" and c1["main_phone"] == "555-0100" and c1["website"] == "cedarridgedental.example"
   and c1["contact_name"] == "Dana Example" and c1["contact_email"] == "dana@cedarridgedental.example" and c1["contact_title"] == "Office Manager",
   "client details: address and phone from the main site, website, main contact: %r" % ({k: c1[k] for k in ("address", "main_phone", "website", "contact_name")},))
ok(not cl["2"]["website"], "a website that isn't an address is dropped")
co = {r["psa_id"]: r for r in h("select * from contacts where psa_id is not null")}
ok("102" not in co and "105" not in co, "Halo's General User and a user with no known client are skipped")
ok(co.get("103") and co["103"]["client_id"] == c1["id"] and co["103"]["email"] is None and co["103"]["is_important"] == 1,
   "a user without a client_id belongs to its site's client; a bad email is dropped")
ok(co.get("104") and co["104"]["name"] == "Pat Former" and "106" not in co, "first name + surname when there's no name; an inactive user isn't added")
pa = {r["psa_asset_id"]: r for r in h("select * from psa_assets")}
a = pa.get("201")
ok(a and a["make"] == "Dell Inc." and a["model"] == "OptiPlex 7090" and a["serial"] == "HALOSN201" and a["os"] == "Windows 11 Pro"
   and str(a["warranty_expire"]) == "2027-03-31" and a["type"] == "Workstation" and a["location_name"] == "Main office",
   "an asset: make, model, serial and OS from its fields, warranty end, site")
ok(pa.get("202") and pa["202"]["serial"] is None and pa.get("203") and pa["203"]["is_archived"] == 1 and pa["203"]["warranty_expire"] is None,
   "an O.E.M. placeholder isn't a serial; inactive is archived; year 0001 isn't a date")
ok(pa.get("204") and pa["204"]["psa_client_id"] == "2" and pa["204"]["name"] == "HP-LAPTOP-HALO" and "x5" not in pa and len(pa) == 4,
   "an asset's client from its site; the key field names it; a bad id is skipped")
dv = {r["serial"]: r for r in h("select * from devices where removed_at is null")}
ok(dv.get("HALOSN201") and dv["HALOSN201"]["device_type"] == "Desktop" and dv["HALOSN201"]["client_id"] == c1["id"], "assets come in as devices (a Workstation is a desktop)")
b = h("select * from psa_billing where client_id=%s", c1["id"])
ok(b and float(b[0]["monthly"]) == 1250.5 and b[0]["method"] == "recurring invoices", "the managed-services estimate comes from the recurring invoices: %s" % (b[0]["monthly"] if b else None))
before = h("select last_run from psa_poll_state where id=1")
pr = hal("psa:poll", "--quiet")
ok(pr.returncode == 0 and h("select last_run from psa_poll_state where id=1") == before, "no 2-minute poll for a read-only PSA")
log = requests.get(M + "/mock/halo-log").json()
ok(log and all(x.startswith("GET /halo/api/") or x == "POST /halo/auth/token" for x in log), "nothing but reads (and the sign-in) is sent: " + ", ".join(sorted(set(x.split(" ")[0] for x in log))))

# ---- Halo's page variations: a record_count as text, none at all, a tenant
for mode in ("strcount", "nocount"):
    mock("/mock/halo-set", {"mode": mode})
    hal("sync", "--quiet")
    run = json.loads(h("select summary from sync_runs order by id desc limit 1")[0]["summary"])
    ok(not str(run.get("HaloPSA clients", "")).startswith("ERROR") and h("select count(*) n from clients where source='psa'")[0]["n"] == 3 and len(h("select * from psa_assets")) == 4,
       f"{mode}: every page is still read: " + str(run.get("HaloPSA clients", ""))[:80])
mock("/mock/halo-set", {})
h("insert into settings (name,value,is_secret) values ('halo_tenant','acme',0) on duplicate key update value='acme'")
hal("sync", "--quiet")
ok(not str(json.loads(h("select summary from sync_runs order by id desc limit 1")[0]["summary"]).get("HaloPSA clients", "")).startswith("ERROR"), "the tenant goes with the sign-in")
h("update settings set value='other' where name='halo_tenant'")
hal("sync", "--quiet")
ok("refused the client ID or secret" in str(json.loads(h("select summary from sync_runs order by id desc limit 1")[0]["summary"]).get("HaloPSA clients", "")), "a wrong tenant is refused")
h("delete from settings where name='halo_tenant'")
mock("/mock/halo-set", {"mode": "nototal"})
hal("sync", "--quiet")
run = json.loads(h("select summary from sync_runs order by id desc limit 1")[0]["summary"])
b2 = h("select * from psa_billing where client_id=%s", c1["id"])
ok("without totals" in " ".join(str(v) for k, v in run.items() if "Managed" in k) and b2 and float(b2[0]["monthly"]) == 1250.5,
   "invoices without totals don't zero the estimate")

# ---- bad answers never archive anything
n_active = h("select count(*) n from clients where source='psa' and is_archived=0")[0]["n"]
for mode, why in [("html", "something other than a list"), ("object", "something other than a list"), ("repeat", "same clients again"), ("short", "ended early"), ("down", "couldn't be read")]:
    mock("/mock/halo-set", {"mode": mode})
    hal("sync", "--quiet")
    run = json.loads(h("select summary from sync_runs order by id desc limit 1")[0]["summary"])
    err = str(run.get("HaloPSA clients", ""))
    ok(err.startswith("ERROR") and why in err and h("select count(*) n from clients where source='psa' and is_archived=0")[0]["n"] == n_active,
       f"a {mode} answer is an error and archives nothing: " + err[:120])
mock("/mock/halo-set", {"mode": "forbid-assets"})
hal("sync", "--quiet")
run = json.loads(h("select summary from sync_runs order by id desc limit 1")[0]["summary"])
err = " ".join(str(v) for k, v in run.items() if "assets" in k)
ok("won't let the API application read /Asset" in err and len(h("select * from psa_assets")) == 4, "no asset permission is explained and the stored assets stay: " + err[:120])
mock("/mock/halo-set", {"drop": [2]})
hal("sync", "--quiet")
ok(h("select is_archived from clients where psa_id='2'")[0]["is_archived"] == 1, "a client gone from Halo is archived")
hdb.close()
done()
