"""2.13.0 Datto RMM (beta, read-only), alongside NinjaOne and Tactical RMM: its sites become organizations (Datto's
built-in Deleted Devices site left out) and link to Align clients by name; its devices become devices (host name, OS
name and build, last seen in this server's timezone, logged-in user, online, server / laptop / desktop / printer /
virtual by Datto's category and class), each under its site; make, model and serial come from Datto's per-device
audit, read once and kept, then only again when Datto audited the device again, never for printers, and stopped for
the run when Datto says to slow down; placeholders and control characters are dropped; a deleted device or one gone
from Datto is marked removed; an HTML page, a non-list, a short list or an error never removes devices; paging never
follows Datto's nextPageUrl; the keys are checked and refused keys explained; a changed platform needs the secret
again; the Integrations card, setup page and Test button; admins only."""
import re, json, subprocess, atexit, requests, html as H
from datetime import datetime
from lib import *


def align(*a): return subprocess.run(["php", ALIGN, *a], env=ENV, capture_output=True, text=True)
def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
def dev(uid): return (q("select * from devices where rmm_provider='dattormm' and rmm_device_id=%s", uid) or [None])[0]
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def audits(): return [l for l in requests.get(M + "/mock/drmm-log").json() if "/audit/" in l]
def last_run(): return json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])


st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
C1 = q("select id from clients where name='Cedar Ridge Family Dental, Inc.'")[0]["id"]
others_before = q("select count(*) n from devices where rmm_provider<>'dattormm' and removed_at is null")[0]["n"]


def cleanup():
    mock("/mock/drmm-set", {})
    q("delete from devices where rmm_provider='dattormm'")
    q("delete from rmm_orgs where provider='dattormm'")
    q("delete from client_links where provider='dattormm'")
    q("delete from rmm_hardware where provider='dattormm'")
    q("delete from settings where name in ('drmm_platform','drmm_api_key','drmm_api_secret')")


atexit.register(cleanup)
cleanup()

# ---- not set up: the card offers it
t = st.get(B + "/integrations").text
ok("Datto RMM" in t and "Beta. Computers and servers from Datto RMM" in t, "the Integrations page lists Datto RMM, marked beta")
p = st.get(B + "/integrations/dattormm").text
ok(all(f'name="{n}"' in p for n in ("drmm_platform", "drmm_api_key", "drmm_api_secret")) and "merlot" in p and "read-only security level" in p and not errs(p),
   "its page asks for the platform, API key and secret key, with read-only setup steps")
ok(tech.get(B + "/integrations/dattormm", allow_redirects=False).status_code in (302, 403), "techs can't open it")

# ---- set up through the page (the platform as a test address can only come from the database, as with NinjaOne)
setting("drmm_platform", M + "/drmm")
F = form(st, "/integrations/dattormm", 'action="/integrations/dattormm"')
r = st.post(B + "/integrations/dattormm", data={**F, "drmm_api_key": "drmm key!", "drmm_api_secret": "drmm-secret"})
ok("can only hold letters" in flash(r.text) and not q("select 1 from settings where name='drmm_api_key'"), "an API key with spaces or symbols is refused")
F = form(st, "/integrations/dattormm", 'action="/integrations/dattormm"')
r = st.post(B + "/integrations/dattormm", data={**F, "drmm_api_key": "drmm-key", "drmm_api_secret": "drmm-secret"})
ok(q("select value from settings where name='drmm_api_key'")[0]["value"] == "drmm-key" and "drmm-secret" not in st.get(B + "/integrations/dattormm").text,
   "the key and secret save (the secret isn't shown again)")
r = st.post(B + "/integrations/dattormm/test", data={"_csrf": csrf(st, "/integrations/dattormm")})
ok("Connected. 2 sites readable." in text(r.text), "Test signs in and reads the sites (Deleted Devices left out): " + flash(r.text)[:160])

# ---- the first sync
mock("/mock/drmm-set", {})
align("sync", "--quiet")
run = last_run()
res = " ".join(f"{k}: {v}" for k, v in run.items() if "Datto" in k)
ok("Datto RMM organizations" in run and "Datto RMM devices" in run and "ERROR" not in res, "the sync reads Datto RMM: " + res[:300])
ok("8 devices" in res and "hardware read for 6, 1 couldn't be read" in res, "the sync notes how many devices' hardware was read (one audit failed)")
orgs = {r["org_id"]: r for r in q("select org_id, name, description from rmm_orgs where provider='dattormm'")}
ok(set(orgs) == {"drmm-site-0001", "drmm-site-0002"} and orgs["drmm-site-0002"]["description"] == "Managed since 2024" and orgs["drmm-site-0001"]["description"] == "6 devices in Datto RMM",
   "Datto's sites become organizations (Deleted Devices left out)")
link = q("select client_id from client_links where provider='dattormm' and external_id='drmm-site-0001'")
ok(link and link[0]["client_id"] == C1, "the site with the client's name links automatically")
s = dev("drmm-dev-0001")
ok(s and s["device_type"] == "Server" and s["manufacturer"] == "Dell Inc." and s["model"] == "PowerEdge R450" and s["serial"] == "DRMMSRV1"
   and s["os_name"] == "Windows Server 2022 Standard" and s["os_build"] == "20348" and s["last_user"] == "CEDAR\\admin" and s["offline"] == 0 and s["rmm_org_id"] == "drmm-site-0001",
   "a server: hardware from its audit, OS name and build, user, online: %s" % ({k: s[k] for k in ("device_type", "manufacturer", "model", "serial", "os_name", "os_build")} if s else None))
lt = dev("drmm-dev-0002")
ok(lt and lt["device_type"] == "Laptop" and lt["manufacturer"] == "LENOVO" and lt["offline"] == 1 and lt["os_build"] == "22631", "a laptop, offline")
ok(lt and lt["last_contact"] and abs((datetime.now() - lt["last_contact"]).total_seconds() - 2 * 86400) < 600, "last seen (epoch milliseconds) is in this server's timezone: %s" % (lt and lt["last_contact"]))
mac = dev("drmm-dev-0003")
ok(mac and mac["device_type"] == "Laptop" and mac["os_name"] == "macOS Sonoma 14.5" and mac["os_build"] is None, "a Mac laptop; an OS without a Windows build keeps its name")
prn = dev("drmm-dev-0004")
ok(prn and prn["device_type"] == "Printer" and prn["manufacturer"] is None and not any("drmm-dev-0004" in a for a in audits()), "a printer is a printer and isn't audited")
vm = dev("drmm-dev-0005")
ok(vm and vm["is_virtual"] == 1 and vm["device_type"] == "Virtual server", "a VMware machine is a virtual server")
ok(dev("drmm-dev-0006") is None, "a device Datto marks deleted isn't brought in")
old = dev("drmm-dev-0007")
ok(old and old["rmm_org_id"] is None and old["client_id"] is None, "a device in Deleted Devices belongs to no client")
pc = dev("drmm-dev-0008")
ok(pc and pc["display_name"] == "CR-PC-DRMM" and pc["manufacturer"] is None and pc["device_type"] == "Desktop", "a device whose audit failed still comes in (control characters removed)")
doc = dev("drmm-dev-0009")
ok(doc and doc["serial"] is None and doc["manufacturer"] == "Micro-Star International", "an O.E.M. placeholder isn't a serial; control characters removed")
ok(not q("select 1 from devices where rmm_provider='dattormm' and display_name='BAD-UID'"), "a device with a bad uid is skipped")
ok(not any("elsewhere.example" in l for l in requests.get(M + "/mock/drmm-log").json()), "paging never follows Datto's nextPageUrl")
ok(q("select count(*) n from devices where rmm_provider<>'dattormm' and removed_at is null")[0]["n"] == others_before, "the other RMMs' devices are untouched")
d = st.get(B + f"/devices/{s['id']}").text
ok("Datto RMM" in d and not errs(d), "the device page names Datto RMM")
ok("Datto RMM" in st.get(B + "/mapping").text, "Client mapping has a Datto RMM column")

# ---- later syncs audit only what's new or audited again
mock("/mock/drmm-set", {})
align("sync", "--quiet")
ok(audits() == [], "the next sync audits nothing: known hardware is kept and a failed audit waits for Datto's next audit: %s" % audits())
ok(dev("drmm-dev-0001")["serial"] == "DRMMSRV1", "known hardware is kept without asking again")
mock("/mock/drmm-set", {"reaudit": ["drmm-dev-0001"]})
align("sync", "--quiet")
ok(audits() == ["GET /drmm/api/v2/audit/device/drmm-dev-0001"], "a device Datto audited again is read again: %s" % audits())
q("delete from rmm_hardware where provider='dattormm'")
mock("/mock/drmm-set", {"mode": "throttle-audit"})
align("sync", "--quiet")
res = " ".join(str(v) for k, v in last_run().items() if "Datto" in k)
ok(len(audits()) == 2 and "slow down" in res and dev("drmm-dev-0001")["removed_at"] is None, "when Datto says to slow down, audits stop for the run: " + res[:160])

# ---- gone from Datto: removed; bad answers never remove anything
mock("/mock/drmm-set", {"drop": ["drmm-dev-0009"]})
align("sync", "--quiet")
ok(dev("drmm-dev-0009")["removed_at"] is not None and dev("drmm-dev-0001")["removed_at"] is None, "a device gone from Datto is marked removed")
ok(not q("select 1 from rmm_hardware where provider='dattormm' and device_id='drmm-dev-0009'") and q("select 1 from rmm_hardware where provider='dattormm' and device_id='drmm-dev-0001'"),
   "its kept hardware is dropped; the others' stay")
live = q("select count(*) n from devices where rmm_provider='dattormm' and removed_at is null")[0]["n"]
for mode, why in [("html", "something other than a list"), ("object", "something other than a list"), ("short", "ended early"), ("fullnototal", "full page without a total"),
                  ("repeat", "returned the same"), ("down", "couldn't be read")]:
    mock("/mock/drmm-set", {"mode": mode, "drop": ["drmm-dev-0009"]})
    align("sync", "--quiet")
    err = " ".join(str(v) for k, v in last_run().items() if "Datto" in k)
    ok("ERROR" in err and why in err and q("select count(*) n from devices where rmm_provider='dattormm' and removed_at is null")[0]["n"] == live,
       f"a {mode} answer is an error and removes nothing: " + err[:140])
mock("/mock/drmm-set", {"mode": "lowcount", "drop": ["drmm-dev-0009"]})
align("sync", "--quiet")
ok(q("select count(*) n from devices where rmm_provider='dattormm' and removed_at is null")[0]["n"] == live, "a totalCount that's too low doesn't cut the list short: " + " ".join(str(v) for k, v in last_run().items() if "Datto" in k)[:300])
mock("/mock/drmm-set", {"mode": "expire"})
align("sync", "--quiet")
log = requests.get(M + "/mock/drmm-log").json()
ok(log.count("POST /drmm/auth/oauth/token") == 2 and "ERROR" not in " ".join(str(v) for k, v in last_run().items() if "Datto" in k), "a token refused mid-run is renewed once")
q("delete from rmm_hardware where provider='dattormm'")
mock("/mock/drmm-set", {"mode": "audit-fail"})
align("sync", "--quiet")
res = " ".join(str(v) for k, v in last_run().items() if "Datto" in k)
ok(len(audits()) == 5 and "keep failing" in res, "audits stop after 5 failures in a row: %d tried; %s" % (len(audits()), res[-120:]))
mock("/mock/drmm-set", {})

# ---- keys and platform
setting("drmm_api_secret", "wrong-secret", True)
r = st.post(B + "/integrations/dattormm/test", data={"_csrf": csrf(st, "/integrations/dattormm")})
ok("refused the API key or secret key" in text(r.text), "a wrong secret key is explained")
setting("drmm_api_secret", "drmm-secret", True)
F = form(st, "/integrations/dattormm", 'action="/integrations/dattormm"')
r = st.post(B + "/integrations/dattormm", data={**F, "drmm_platform": "merlot"})
ok("Enter the secret key again" in flash(r.text) and q("select value from settings where name='drmm_platform'")[0]["value"] == M + "/drmm",
   "a different platform needs the secret key again")
setting("drmm_platform", M + "/nothing-here")
r = st.post(B + "/integrations/dattormm/test", data={"_csrf": csrf(st, "/integrations/dattormm")})
ok("Pick the platform" in text(r.text), "a wrong platform is explained")
done()
