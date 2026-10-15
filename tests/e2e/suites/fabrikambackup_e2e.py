"""Fabrikam Backup (community connector, beta, read-only): the Integrations card and setup page (admins only), the key
saved as a secret, the Test button, the sync (customers linked to clients by name, jobs with their result, machines
matched to the client's devices by host name; uids prefixed with the connector key, bad ids skipped, text cleaned,
sizes and statuses checked), a machine gone from the product removed, and wrong answers (an HTML page, a reply
without items, an outage, a page number that doesn't move on, a refused key) never removing anything.

`align make:connector` copies this suite for a new connector: change the expected values to your mock's data."""
import re, json, subprocess, atexit, requests, html as H
from lib import *

# template-only: begin
dev_connectors("Align\\Templates\\Backup\\Connector\\FabrikamBackup")
# template-only: end

KEY = "fabrikambackup"
NAME = "Fabrikam Backup"


def run_align(*a): return subprocess.run(["php", ALIGN, *a], env=ENV, capture_output=True, text=True)
def mock(body=None): return requests.post(M + f"/mock/{KEY}-set", json=body or {}).json()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def last_run(): return json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
def mine(run): return {k: v for k, v in run.items() if NAME in k}
def job(i): return (q("select * from backup_jobs where provider=%s and uid=%s", KEY, f"{KEY}:{i}") or [None])[0]
def machine(i): return (q("select * from backup_workloads where provider=%s and uid=%s", KEY, f"{KEY}:computer:{i}") or [None])[0]
def machines(): return q("select count(*) n from backup_workloads where provider=%s", KEY)[0]["n"]


st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
C1 = q("select id from clients where name='Cedar Ridge Family Dental, Inc.'")[0]["id"]
others = q("select count(*) n from backup_workloads where provider<>%s", KEY)[0]["n"]


def cleanup():
    mock({})
    for t in ["backup_job_runs where job_uid like %s", "backup_workload_jobs where workload_uid like %s", "backup_device_links where workload_uid like %s"]:
        q("delete from " + t, KEY + ":%")
    q("delete from backup_jobs where provider=%s", KEY)
    q("delete from backup_workloads where provider=%s", KEY)
    q("delete from backup_companies where provider=%s", KEY)
    q("delete from client_links where provider=%s", KEY)
    q("delete from settings where name like %s", KEY + "\\_%")


atexit.register(cleanup)
cleanup()

# ---- the card and page
t = st.get(B + "/integrations").text
ok(NAME in t and "Beta" in t, "the Integrations page lists the connector, marked beta")
p = st.get(B + f"/integrations/{KEY}").text
ok(f'name="{KEY}_url"' in p and f'name="{KEY}_api_key"' in p and "read-only" in p and not errs(p), "its page asks for the API address and key, with setup steps")
ok(tech.get(B + f"/integrations/{KEY}", allow_redirects=False).status_code in (302, 403), "techs can't open it")

# ---- set up through the page
F = form(st, f"/integrations/{KEY}", f'action="/integrations/{KEY}"')
st.post(B + f"/integrations/{KEY}", data={**F, f"{KEY}_url": M + f"/{KEY}", f"{KEY}_api_key": f"{KEY}-key"})
ok(q("select is_secret from settings where name=%s", KEY + "_api_key")[0]["is_secret"] == 1 and f"{KEY}-key" not in st.get(B + f"/integrations/{KEY}").text,
   "the key is stored as a secret and isn't shown again")
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("Connected. 2 customers readable." in text(r.text), "Test reads the customers (bad ids left out): " + flash(r.text)[:200])

# ---- the sync
run_align("sync", "--quiet")
got = mine(last_run())
ok(got and not any(str(v).startswith("ERROR") for v in got.values()) and "2 companies" in " ".join(map(str, got.values())), "the sync reads it: %s" % got)
link = q("select client_id from client_links where provider=%s and external_id=%s", KEY, f"{KEY}:c-1")
ok(link and link[0]["client_id"] == C1, "the customer with a client's name links to it (uids carry the connector key)")
j1, j2, j3 = job("j-1"), job("j-2"), job("j-3")
ok(j1 and j1["status"] == "success" and j1["source"] == "agent" and j1["duration_sec"] == 3600 and j1["chain_bytes"] == 52428800 and j1["target"] == "Cloud vault",
   "a job with its result, duration, size and destination")
ok(j2 and j2["status"] == "failed" and j2["failure_message"] == "Disk full on target" and j2["duration_sec"] is None and j2["chain_bytes"] is None,
   "a failed job's message is cleaned; a negative duration and a size that isn't a number are dropped")
ok(j3 and j3["status"] == "none" and j3["is_enabled"] == 0, "an unknown result is none; a disabled job is disabled")
m1 = machine("m-1")
ok(m1 and m1["client_id"] == C1 and m1["hostname"] == "pc-0001" and m1["restore_points"] == 14 and m1["device_id"]
   and q("select display_name from devices where id=%s", m1["device_id"])[0]["display_name"] == "PC-0001", "a machine belongs to its customer's client and is matched to that client's device")
ok(q("select 1 from backup_workload_jobs where workload_uid=%s and job_uid=%s", f"{KEY}:computer:m-1", f"{KEY}:j-1"), "a machine lists its job")
ok(machine("m-2") and machine("m-2")["restore_points"] == 7 and machine("m-3") is None, "a count sent as text is read; a machine without a name is skipped")
ok(not q("select 1 from backup_companies where provider=%s and name in ('Bad id','Array id')", KEY), "customers with bad ids are skipped")
ok(q("select count(*) n from backup_workloads where provider<>%s", KEY)[0]["n"] == others, "other backup products' machines are untouched")
b = st.get(B + f"/clients/{C1}/backups").text
ok("PC-0001 nightly" in b and not errs(b), "the client's backups page shows the job")
ok(NAME in st.get(B + "/mapping").text, "Client mapping has a column for it")

# ---- gone from the product: removed; bad answers never remove anything
mock({"drop": ["m-2"]})
run_align("sync", "--quiet")
ok(machine("m-2") is None and machine("m-1") is not None, "a machine gone from the product is removed")
for mode, why in [("html", "other than a list"), ("noitems", "other than a list"), ("down", "couldn't be read"), ("loop", "doesn't move forward")]:
    mock({"mode": mode})
    run_align("sync", "--quiet")
    err = " ".join(str(v) for v in mine(last_run()).values())
    ok("ERROR" in err and why in err and machines() == 1 and job("j-1") is not None, f"a {mode} answer is an error and removes nothing: " + err[:160])
mock({})
setting(KEY + "_api_key", "wrong-key", True)
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("refused the API key" in text(r.text), "a wrong key is explained")
done()
