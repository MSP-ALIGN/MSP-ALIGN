"""Fabrikam RMM (community connector, beta, read-only): the Integrations card and setup page (admins only), the key
saved as a secret, the Test button, the sync (organizations linked by name, devices across pages with their type,
hardware, OS, serial, last seen and user; bad ids skipped and text cleaned), a device gone from the product marked
removed, and wrong answers (an HTML page, a reply without items, an outage, a page number that doesn't move on, a
refused key) never removing anything.

`align make:connector` copies this suite for a new connector: change the expected values to your mock's data."""
import re, json, subprocess, atexit, requests, html as H
from datetime import datetime
from lib import *

# template-only: begin
dev_connectors("Align\\Templates\\Rmm\\Connector\\FabrikamRmm")
# template-only: end

KEY = "fabrikamrmm"
NAME = "Fabrikam RMM"


def mock(body=None): return requests.post(M + f"/mock/{KEY}-set", json=body or {}).json()
def dev(i): return (q("select * from devices where rmm_provider=%s and rmm_device_id=%s", KEY, i) or [None])[0]
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def last_run(): return json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
def mine(run): return {k: v for k, v in run.items() if NAME in k}
def live(): return q("select count(*) n from devices where rmm_provider=%s and removed_at is null", KEY)[0]["n"]


st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
C1 = q("select id from clients where name='Cedar Ridge Family Dental, Inc.'")[0]["id"]
others = q("select count(*) n from devices where rmm_provider<>%s and removed_at is null", KEY)[0]["n"]


def cleanup():
    mock({})
    q("delete from devices where rmm_provider=%s", KEY)
    q("delete from rmm_orgs where provider=%s", KEY)
    q("delete from client_links where provider=%s", KEY)
    q("delete from settings where name like %s", KEY + "\\_%")


atexit.register(cleanup)
cleanup()

# ---- not set up: the card offers it, nothing syncs
t = st.get(B + "/integrations").text
ok(NAME in t and "Beta" in t, "the Integrations page lists the connector, marked beta")
p = st.get(B + f"/integrations/{KEY}").text
ok(f'name="{KEY}_url"' in p and f'name="{KEY}_api_key"' in p and "read-only" in p and not errs(p), "its page asks for the API address and key, with setup steps")
ok(tech.get(B + f"/integrations/{KEY}", allow_redirects=False).status_code in (302, 403), "techs can't open it")

# ---- set up through the page (the key is a secret: encrypted, never shown again)
F = form(st, f"/integrations/{KEY}", f'action="/integrations/{KEY}"')
st.post(B + f"/integrations/{KEY}", data={**F, f"{KEY}_url": M + f"/{KEY}", f"{KEY}_api_key": f"{KEY}-key"})
ok(q("select value from settings where name=%s", KEY + "_url")[0]["value"] == M + f"/{KEY}" and f"{KEY}-key" not in st.get(B + f"/integrations/{KEY}").text,
   "the address and key save (the key isn't shown again)")
ok(q("select is_secret from settings where name=%s", KEY + "_api_key")[0]["is_secret"] == 1, "the key is stored as a secret")
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("Connected. 2 organizations readable." in text(r.text), "Test reads the organizations (a bad id is skipped): " + flash(r.text)[:200])

# ---- the sync
sync_now()
got = mine(last_run())
ok(f"{NAME} organizations" in got and f"{NAME} devices" in got and not any(str(v).startswith("ERROR") for v in got.values()), "the sync reads it: %s" % got)
orgs = {r["org_id"]: r for r in q("select org_id, name, description from rmm_orgs where provider=%s", KEY)}
ok(set(orgs) == {"org-1", "org-2"} and orgs["org-1"]["description"] == "Main office", "organizations are stored (a bad id is skipped)")
link = q("select client_id from client_links where provider=%s and external_id='org-1'", KEY)
ok(link and link[0]["client_id"] == C1, "the client with the same name links automatically")
s = dev("dev-srv-01")
ok(s and s["device_type"] == "Server" and s["manufacturer"] == "Dell Inc." and s["model"] == "PowerEdge T350" and s["serial"] == "FABSRV01"
   and s["os_name"] == "Windows Server 2022 Standard" and s["os_build"] == "20348" and s["last_user"] == "CEDAR\\admin" and s["offline"] == 0 and s["client_id"] is None,
   "a server: type, hardware, serial, OS, user, online: %s" % ({k: s[k] for k in ("device_type", "manufacturer", "model", "os_name", "os_build")} if s else None))
lt = dev("dev-lt-02")
ok(lt and lt["device_type"] == "Laptop" and lt["offline"] == 1 and lt["last_contact"] and abs((datetime.now() - lt["last_contact"]).total_seconds() - 3 * 86400) < 600,
   "a laptop, offline, last seen in this server's timezone")
vm = dev("dev-vm-03")
ok(vm and vm["is_virtual"] == 1 and vm["device_type"] == "Virtual server" and vm["serial"] is None and vm["rmm_org_id"] == "org-2",
   "from the second page: a QEMU machine is a virtual server and a placeholder isn't a serial")
stray = dev("dev-stray-04")
ok(stray and stray["display_name"] == "STRAY-FAB" and stray["last_contact"] is None and stray["rmm_org_id"] == "org-9",
   "control characters are removed and a time that isn't one is left empty")
ok(not q("select 1 from devices where rmm_provider=%s and display_name in ('BAD-ID','ARRAY-ID')", KEY), "devices with bad ids are skipped")
ok(q("select count(*) n from devices where rmm_provider<>%s and removed_at is null", KEY)[0]["n"] == others, "other RMMs' devices are untouched")
dp = st.get(B + f"/clients/{C1}/devices").text
ok("CR-SRV-FAB" in dp and "CR-LT-FAB" in dp and not errs(dp), "the client's devices list shows them")
d = st.get(B + f"/devices/{s['id']}").text
ok(NAME in d and f"/{KEY}/devices/dev-srv-01" in d and not errs(d), "the device page names the RMM and links to the device in its console")
ok(NAME in st.get(B + "/mapping").text, "Client mapping has a column for it")

# ---- gone from the product: removed; bad answers never remove anything
mock({"drop": ["dev-stray-04"]})
sync_now()
ok(dev("dev-stray-04")["removed_at"] is not None and dev("dev-srv-01")["removed_at"] is None, "a device gone from the product is marked removed")
for mode, why in [("html", "other than a list"), ("noitems", "other than a list"), ("down", "couldn't be read"), ("loop", "doesn't move forward")]:
    mock({"mode": mode})
    sync_now()
    err = " ".join(str(v) for v in mine(last_run()).values())
    ok("ERROR" in err and why in err and live() == 3, f"a {mode} answer is an error and removes nothing: " + err[:160])
mock({})
setting(KEY + "_api_key", "wrong-key", True)
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("refused the API key" in text(r.text), "a wrong key is explained")
done()
