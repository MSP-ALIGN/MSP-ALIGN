"""Fabrikam Cloud (community security connector, beta, read-only): the Integrations card and setup page (admins only),
the key saved as a secret, the Test button, the sync (tenants stored and linked to clients by name, bad ids and names
skipped, three checks worked out from each tenant's numbers, a number it didn't send making that check unknown), the
checks in Align's automatic checks (labels, a client's indicators, stored results for the health score, the evidence
check), the Client mapping column and the client's evidence page, a tenant whose numbers change, and wrong answers (an
HTML page, a reply without items, an empty list, an outage, a page number that doesn't move on, a refused key) never
removing anything, while a tenant gone from the product is removed with its results.

`align make:connector` copies this suite for a new connector: change the expected values to your mock's data."""
import re, json, subprocess, atexit, requests, html as H
from lib import *

# template-only: begin
dev_connectors("Align\\Templates\\Saas\\Connector\\Fabrikam")
# template-only: end

KEY = "fabrikam"
NAME = "Fabrikam Cloud"


def mock(body=None): return requests.post(M + f"/mock/{KEY}-set", json=body or {}).json()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def last_run(): return json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
def mine(run): return {k: v for k, v in run.items() if k == NAME}
def result(rec, check): return (q("select * from connector_results where provider=%s and record_id=%s and check_key=%s", KEY, rec, f"{KEY}_{check}") or [None])[0]
def records(): return q("select count(*) n from connector_records where provider=%s", KEY)[0]["n"]


def phpj(code):
    """Runs PHP that echoes JSON; the decoded result."""
    r = php(code)
    return json.loads(r.stdout) if r.stdout.strip().startswith(("{", "[")) else r.stdout + r.stderr


st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
C1 = q("select id from clients where name='Cedar Ridge Family Dental, Inc.'")[0]["id"]


def cleanup():
    mock({})
    q("delete from connector_results where provider=%s", KEY)
    q("delete from connector_records where provider=%s", KEY)
    q("delete from client_links where provider=%s", KEY)
    q("delete from settings where name like %s", KEY + "\\_%")


atexit.register(cleanup)
cleanup()

# ---- before it's set up: the card, and the checks already named (standards can pick them)
t = st.get(B + "/integrations").text
ok(NAME in t and "Beta" in t, "the Integrations page lists the connector, marked beta")
p = st.get(B + f"/integrations/{KEY}").text
ok(f'name="{KEY}_url"' in p and f'name="{KEY}_api_key"' in p and "read-only" in p and not errs(p), "its page asks for the API address and key, with setup steps")
ok(tech.get(B + f"/integrations/{KEY}", allow_redirects=False).status_code in (302, 403), "techs can't open it")
labels = phpj("echo json_encode(Align\\Health\\SecurityChecks::labels());")
ok(isinstance(labels, dict) and {f"{KEY}_mfa", f"{KEY}_devices", f"{KEY}_scan"} <= set(labels) and labels[f"{KEY}_mfa"].startswith(NAME),
   "its three checks are among Align's automatic checks")
ok(not any(k.startswith(KEY + "_") for k in phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));")), "not set up: no indicators for clients")

# ---- set up through the page
F = form(st, f"/integrations/{KEY}", f'action="/integrations/{KEY}"')
st.post(B + f"/integrations/{KEY}", data={**F, f"{KEY}_url": M + f"/{KEY}", f"{KEY}_api_key": f"{KEY}-key"})
ok(q("select is_secret from settings where name=%s", KEY + "_api_key")[0]["is_secret"] == 1 and f"{KEY}-key" not in st.get(B + f"/integrations/{KEY}").text,
   "the key is stored as a secret and isn't shown again")
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("Connected. 3 tenants readable." in text(r.text), "Test reads the tenants (bad ids and names left out): " + flash(r.text)[:200])
ind = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));")
ok(ind.get(f"{KEY}_mfa", {}).get("unknown") is True and "Not linked" in ind[f"{KEY}_mfa"]["text"], "set up but not synced: the client's checks are unknown, saying why")

# ---- the sync
sync_now()
got = mine(last_run())
ok(got and str(got[NAME]).startswith("3 tenants, 1 linked to clients, 9 check results"), "the sync reads it: %s" % got)
names = {r["record_id"]: r["name"] for r in q("select record_id, name from connector_records where provider=%s", KEY)}
ok(set(names) == {"t-1", "t-2", "t-5"} and names["t-2"] == "Lakeside Test Org", "tenants are stored (a too-long id and an empty name skipped; control characters removed)")
link = q("select client_id from client_links where provider=%s and external_id='t-1'", KEY)
ok(link and link[0]["client_id"] == C1, "the client with the same name links automatically")
ok(result("t-1", "mfa")["status"] == "pass" and result("t-1", "mfa")["detail"] == "10 of 10 users sign in with multi-factor authentication.", "MFA: 10 of 10 passes")
ok(result("t-1", "devices")["status"] == "fail" and "4 of 5" in result("t-1", "devices")["detail"], "devices: 4 of 5 protected fails")
ok(result("t-1", "scan")["status"] == "pass" and result("t-2", "scan")["status"] == "fail", "a scan yesterday passes; one 30 days ago fails")
ok(result("t-2", "mfa")["status"] == "unknown" and result("t-2", "devices")["status"] == "pass", "a number it didn't send is unknown, never a pass; numbers sent as text are read")
ok(all(result("t-5", c)["status"] == "unknown" for c in ("mfa", "devices", "scan")), "negative, non-number, zero and unreal values are all unknown")

# ---- in Align's checks
ind = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));")
ok(ind[f"{KEY}_mfa"]["ok"] is True and ind[f"{KEY}_devices"]["ok"] is False and ind[f"{KEY}_devices"]["suggest"] == "not_met" and ind[f"{KEY}_mfa"]["suggest"] == "met",
   "the client's indicators carry the results")
stored = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::stored([{C1}]));")
ok(stored.get(str(C1), {}).get("checks", {}).get(f"{KEY}_devices", {}).get("status") == "fail", "stored results (health score, reports) include them")
ok(phpj("echo json_encode(Align\\Compliance\\Evidence::systems());").get(KEY + "_") == NAME, "the evidence check lists it as a system")
ev = st.get(B + f"/clients/{C1}/evidence").text
ok(NAME in ev and not errs(ev), "the client's evidence page shows its checks")
m = st.get(B + "/mapping").text
ok(NAME in m and not errs(m), "Client mapping has a column for it")
cp = st.get(B + f"/clients/{C1}/connectors").text
ok(NAME in cp and not errs(cp), "the client's connectors page shows its link")

# ---- a change in the product shows on the next sync; bad answers never remove anything
mock({"mfa": 7, "drop": ["t-5"]})
sync_now()
ok(result("t-1", "mfa")["status"] == "fail" and "7 of 10" in result("t-1", "mfa")["detail"], "a change in the product shows on the next sync")
ok(records() == 2 and result("t-5", "mfa") is None, "a tenant gone from the product is removed with its results")
for mode, why in [("html", "other than a list"), ("noitems", "other than a list"), ("empty", "returned no records"), ("down", "couldn't be read"), ("loop", "doesn't move forward")]:
    mock({"mode": mode})
    sync_now()
    err = " ".join(str(v) for v in mine(last_run()).values())
    ok("ERROR" in err and why in err and records() == 2 and result("t-1", "mfa") is not None and q("select 1 from client_links where provider=%s and external_id='t-1'", KEY),
       f"a {mode} answer is an error and removes nothing: " + err[:160])
q("update connector_results set synced_at = now() - interval 30 hour where provider=%s", KEY)
ind = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));")
ok(ind[f"{KEY}_mfa"]["unknown"] is True and "Not read since" in ind[f"{KEY}_mfa"]["text"], "results not refreshed for more than a day count as unknown")
mock({})
setting(KEY + "_api_key", "wrong-key", True)
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("refused the API key" in text(r.text), "a wrong key is explained")
done()
