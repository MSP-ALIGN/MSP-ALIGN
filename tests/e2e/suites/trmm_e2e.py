"""2.11.0 Tactical RMM (beta, read-only), alongside NinjaOne: its clients become organizations (with their sites) and
link to Align clients by name; its agents become devices (make and model split, OS name and build, serial, last seen in
this server's timezone, logged-in user, offline, server / laptop / desktop / virtual by type and model), each under the
client its client name names; agents with bad ids are skipped and text is cleaned; an agent gone from Tactical is marked
removed; an HTML page, a non-list or an error never removes devices; the key is checked and refused keys explained; the
Integrations card, setup page and Test button; admins only."""
import re, json, subprocess, atexit, requests, html as H
from datetime import datetime
from lib import *

def align(*a): return subprocess.run(["php", ALIGN, *a], env=ENV, capture_output=True, text=True)
def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
def dev(agent): return (q("select * from devices where rmm_provider='tacticalrmm' and rmm_device_id=%s", agent) or [None])[0]
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
C1 = q("select id from clients where name='Cedar Ridge Family Dental, Inc.'")[0]["id"]
ninja_before = q("select count(*) n from devices where rmm_provider='ninjaone' and removed_at is null")[0]["n"]


def cleanup():
    mock("/mock/trmm-set", {})
    q("delete from devices where rmm_provider='tacticalrmm'")
    q("delete from rmm_orgs where provider='tacticalrmm'")
    q("delete from client_links where provider='tacticalrmm'")
    q("delete from settings where name in ('trmm_url','trmm_api_key')")


atexit.register(cleanup)
cleanup()

# ---- not set up: the card offers it, nothing syncs
t = st.get(B + "/integrations").text
ok("Tactical RMM" in t and "Beta" in t, "the Integrations page lists Tactical RMM, marked beta")
p = st.get(B + "/integrations/tacticalrmm").text
ok('name="trmm_url"' in p and 'name="trmm_api_key"' in p and "read-only" in p and not errs(p), "its page asks for the API address and key, with read-only setup steps")
ok(tech.get(B + "/integrations/tacticalrmm", allow_redirects=False).status_code in (302, 403), "techs can't open it")

# ---- set up through the page (the key is a secret: encrypted, never shown again)
F = form(st, "/integrations/tacticalrmm", 'action="/integrations/tacticalrmm"')
r = st.post(B + "/integrations/tacticalrmm", data={**F, "trmm_url": M + "/trmm", "trmm_api_key": "trmm-key"})
ok(q("select value from settings where name='trmm_url'")[0]["value"] == M + "/trmm" and "trmm-key" not in st.get(B + "/integrations/tacticalrmm").text, "the address and key save (the key isn't shown again)")
r = st.post(B + "/integrations/tacticalrmm/test", data={"_csrf": csrf(st, "/integrations/tacticalrmm")})
ok("Connected. 3 clients readable." in text(r.text), "Test reads the clients: " + " | ".join(f for f in [flash(r.text)] if f)[:200])

# ---- the sync
out = align("sync", "--quiet")
run = json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
ok("Tactical RMM organizations" in run and "Tactical RMM devices" in run and not any(str(v).startswith("ERROR") for k, v in run.items() if "Tactical" in k),
   "the sync reads Tactical RMM: " + "; ".join(f"{k}: {v}" for k, v in run.items() if "Tactical" in k))
orgs = {r["org_id"]: r for r in q("select org_id, name, description from rmm_orgs where provider='tacticalrmm'")}
ok(set(orgs) == {"1", "2", "4"} and orgs["1"]["description"] == "Sites: Main office, Annex", "Tactical's clients become organizations with their sites (a bad id is skipped)")
link = q("select client_id from client_links where provider='tacticalrmm' and external_id='1'")
ok(link and link[0]["client_id"] == C1, "the client with the same name links automatically")
s = dev("TRMMagentServer01aaaaaaaaaaaaaaaaaaaaaaaaaaaa")
ok(s and s["device_type"] == "Server" and s["manufacturer"] == "Dell Inc." and s["model"] == "PowerEdge T350" and s["serial"] == "TRMMSRV01"
   and s["os_name"] == "Windows Server 2022 Standard" and s["os_build"] == "20348" and s["rmm_org_id"] == "1" and s["last_user"] == "CEDAR\\admin" and s["offline"] == 0,
   "a server: make and model split, OS name and build, serial, user, online: %s" % ({k: s[k] for k in ("device_type", "manufacturer", "model", "os_name", "os_build")} if s else None))
lt = dev("TRMMagentLaptop02bbbbbbbbbbbbbbbbbbbbbbbbbbbb")
ok(lt and lt["device_type"] == "Laptop" and lt["manufacturer"] == "LENOVO" and lt["os_build"] == "22631" and lt["offline"] == 1, "a ThinkPad is a laptop; overdue counts as offline; the build drops its revision")
seen = lt["last_contact"]
ok(seen and abs((datetime.now() - seen).total_seconds() - 3 * 86400) < 600, "last seen is in this server's timezone: %s" % seen)
mac = dev("TRMMagentMac03ccccccccccccccccccccccccccccccc")
ok(mac and mac["device_type"] == "Laptop" and mac["manufacturer"] == "Apple" and mac["rmm_org_id"] == "1", "a MacBook is a laptop; a client name differing in case still finds its client")
un = dev("TRMMagentUnknown04dddddddddddddddddddddddddd")
ok(un and un["manufacturer"] is None and un["model"] is None and un["serial"] is None and un["last_user"] is None and un["display_name"] == "CR-PC-TRMM" and un["device_type"] == "Desktop",
   "an agent that couldn't read its hardware: no make, model or serial (Tactical's placeholders dropped), control characters removed")
vm = dev("TRMMagentVm05eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee")
ok(vm and vm["is_virtual"] == 1 and vm["device_type"] == "Virtual server" and vm["serial"] is None and vm["rmm_org_id"] == "2", "a QEMU machine is a virtual server; 'Not Specified' isn't a serial")
stray = dev("TRMMagentStray06ffffffffffffffffffffffffffff")
ok(stray and stray["rmm_org_id"] is None and stray["client_id"] is None, "an agent whose client isn't in the list belongs to no client")
amb = dev("TRMMagentAmbig07gggggggggggggggggggggggggggg")
ok(amb and amb["rmm_org_id"] is None and vm["rmm_org_id"] == "2", "a client name matching two clients but for case goes to neither (an exact name still finds its client)")
ok(not q("select 1 from devices where rmm_provider='tacticalrmm' and display_name in ('BAD-ID','ARRAY-ID')"), "agents with bad ids are skipped")
ok(q("select count(*) n from devices where rmm_provider='ninjaone' and removed_at is null")[0]["n"] == ninja_before, "NinjaOne's devices are untouched")
dp = st.get(B + f"/clients/{C1}/devices").text
ok("CR-SRV-TRMM" in dp and "CR-LT-TRMM" in dp and not errs(dp), "the client's devices list shows them")
d = st.get(B + f"/devices/{s['id']}").text
ok("Tactical RMM" in d and not errs(d) and "deviceDashboard" not in d, "the device page names Tactical RMM (no console link)")
ok("Tactical RMM" in st.get(B + "/mapping").text, "Client mapping has a Tactical RMM column")

# ---- gone from Tactical: removed; bad answers never remove anything
mock("/mock/trmm-set", {"drop": ["TRMMagentStray06ffffffffffffffffffffffffffff"]})
align("sync", "--quiet")
ok(dev("TRMMagentStray06ffffffffffffffffffffffffffff")["removed_at"] is not None and dev("TRMMagentServer01aaaaaaaaaaaaaaaaaaaaaaaaaaaa")["removed_at"] is None, "an agent gone from Tactical is marked removed")
for mode, why in [("html", "the API's"), ("object", "the API's"), ("down", "couldn't be read")]:
    mock("/mock/trmm-set", {"mode": mode})
    align("sync", "--quiet")
    run = json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
    err = " ".join(str(v) for k, v in run.items() if "Tactical" in k)
    ok("ERROR" in err and why in err and q("select count(*) n from devices where rmm_provider='tacticalrmm' and removed_at is null")[0]["n"] == 6,
       f"a {mode} answer is an error and removes nothing: " + err[:140])
mock("/mock/trmm-set", {})
setting("trmm_api_key", "wrong-key", True)
r = st.post(B + "/integrations/tacticalrmm/test", data={"_csrf": csrf(st, "/integrations/tacticalrmm")})
ok("refused the API key" in r.text or "refused the API key" in flash(r.text), "a wrong key is explained")
setting("trmm_url", M + "/nothing-here")
setting("trmm_api_key", "trmm-key", True)
r = st.post(B + "/integrations/tacticalrmm/test", data={"_csrf": csrf(st, "/integrations/tacticalrmm")})
ok("not the dashboard" in r.text or "not the dashboard" in flash(r.text), "a wrong address says to use the API's")
done()
