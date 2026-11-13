"""2.37.0 DNSFilter (read only): the Integrations card and page (admins only), the key saved as a secret, the Test
button, the sync (organizations stored and linked to clients by name, canceled ones and bad ids or names left out;
roaming clients, networks and the daily request / blocked / threat totals read), the two checks (a roaming client on
every RMM workstation and laptop, matched by host name; office networks with a policy and traffic), the client's
DNSFilter card, the QBR's Security numbers, the Client mapping column, the offline setting, a second page of roaming
clients, an organization gone from DNSFilter, and wrong answers (an HTML page, a reply without the list, an empty
list, an outage, a page that repeats, a refused report, a refused key) never removing anything. Requests are GETs
with the key only in the Authorization header."""
import re, json, atexit, requests, html as H
from lib import *


KEY = "dnsfilter"
NAME = "DNSFilter"


def mock(body=None): return requests.post(M + f"/mock/{KEY}-set", json=body or {}).json()
def mocklog(): return requests.get(M + f"/mock/{KEY}-log").json()["requests"]
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def last_run(): return json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
def mine(run): return {k: v for k, v in run.items() if k == NAME}
def result(rec, check): return (q("select * from connector_results where provider=%s and record_id=%s and check_key=%s", KEY, rec, f"{KEY}_{check}") or [None])[0]
def records(): return q("select count(*) n from connector_records where provider=%s", KEY)[0]["n"]
def data(rec): return json.loads(q("select data from connector_records where provider=%s and record_id=%s", KEY, rec)[0]["data"])


def phpj(code):
    """Runs PHP that echoes JSON; the decoded result."""
    r = php(code)
    return json.loads(r.stdout) if r.stdout.strip().startswith(("{", "[")) else r.stdout + r.stderr


st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
C1 = q("select id from clients where name='Cedar Ridge Family Dental, Inc.'")[0]["id"]
C2 = q("select id from clients where name='Northfield Hardware & Supply'")[0]["id"]


def cleanup():
    mock({})
    q("delete from connector_results where provider=%s", KEY)
    q("delete from connector_records where provider=%s", KEY)
    q("delete from client_links where provider=%s", KEY)
    q("delete from dnsfilter_agents")
    q("delete from settings where name like %s", KEY + "\\_%")


atexit.register(cleanup)
cleanup()

# ---- before it's set up: the card, and the checks already named (standards can pick them)
t = st.get(B + "/integrations").text
ok(NAME in t and not errs(t), "the Integrations page lists DNSFilter")
p = st.get(B + f"/integrations/{KEY}").text
ok(f'name="{KEY}_api_key"' in p and f'name="{KEY}_offline_days"' in p and "Read Only" in p and "API Keys" in p and not errs(p), "its page asks for the key and the offline days, with setup steps")
ok(tech.get(B + f"/integrations/{KEY}", allow_redirects=False).status_code in (302, 403), "techs can't open it")
labels = phpj("echo json_encode(Align\\Health\\SecurityChecks::labels());")
ok(isinstance(labels, dict) and {f"{KEY}_agents", f"{KEY}_networks"} <= set(labels) and labels[f"{KEY}_agents"].startswith(NAME), "its two checks are among Align's automatic checks")
ok(not any(k.startswith(KEY + "_") for k in phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));")), "not set up: no indicators for clients")

# ---- the roaming clients of Cedar Ridge, built from its RMM workstations and laptops in the seed
need = phpj(f"""echo json_encode(array_values(array_map(fn($d) => $d['system_name'], array_filter((new Align\\Lifecycle\\Lifecycle())->devices({C1}),
    fn($d) => !Align\\Lifecycle\\Lifecycle::notInUse($d) && empty($d['retired_at']) && ($d['source'] ?? '') === 'rmm' && empty($d['stale'])
        && in_array($d['device_class'] ?? '', ['desktop', 'laptop'], true)))));""")
ok(isinstance(need, list) and len(need) >= 8, "the seed has workstations and laptops for Cedar Ridge: %d" % (len(need) if isinstance(need, list) else 0))
import datetime as dt
ago = lambda days: (dt.datetime.now(dt.timezone.utc) - dt.timedelta(days=days)).strftime("%Y-%m-%dT%H:%M:%S.000Z")
agents = []
for i, h in enumerate(need):
    if i in (0, 1):
        continue  # no roaming client
    a = {"id": f"c-{i}", "hostname": h, "last_sync": ago(0)}
    if i == 2: a["last_sync"] = ago(20)  # offline (7 days)
    if i == 3: a["agent_state"] = "bypassed"  # not filtering
    if i == 4: a["hostname"] = h.lower() + ".corp.example"  # host name with a domain still matches
    if i == 5: a["status"] = "uninstalled"  # gone: the device counts as without one
    if i == 6: a["status"] = "uninstall_queued"  # being removed: the same
    if i == 7: a["agent_state"] = "pending_uninstall"  # the same, by its state
    agents.append(a)
agents += [{"id": "c-old", "hostname": "OLD-PC-99", "last_sync": ago(100)},  # matched to no device
           {"id": "c-proxy", "hostname": "RELAY-1", "agent_type": "proxy"},  # a LAN proxy: not a roaming client
           {"id": "x" * 70, "hostname": "BAD-ID"}]  # id too long: left out
mock({"agents": {"101": agents}})
N = len(need)
OK = N - 5 - 1 - 1

# ---- set up through the page
F = form(st, f"/integrations/{KEY}", f'action="/integrations/{KEY}"')
st.post(B + f"/integrations/{KEY}", data={**F, f"{KEY}_api_key": f"{KEY}-key", f"{KEY}_offline_days": "7"})
setting(f"{KEY}_api_base", M + f"/{KEY}")  # test override (allow_insecure_integrations only)
ok(q("select is_secret from settings where name=%s", KEY + "_api_key")[0]["is_secret"] == 1 and f"{KEY}-key" not in st.get(B + f"/integrations/{KEY}").text,
   "the key is stored as a secret and isn't shown again")
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("Connected to DNSFilter as Align Read Only. 3 organizations readable." in text(r.text), "Test reads who the key belongs to and the organizations (canceled and bad ones left out): " + flash(r.text)[:200])
ind = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));")
ok(ind.get(f"{KEY}_agents", {}).get("unknown") is True and "Not linked" in ind[f"{KEY}_agents"]["text"], "set up but not synced: the client's checks are unknown, saying why")

# ---- the sync
mock({"agents": {"101": agents}})
sync_now()
got = mine(last_run())
stored_agents = len(agents) - 2 + 2 + 1  # proxy and bad id left out; Northfield's 2 and Lakeside's 1
ok(got and str(got[NAME]).startswith(f"3 organizations, 2 linked to clients, {stored_agents} roaming clients, 6 check results"), "the sync reads it: %s" % got)
names = {r["record_id"]: r["name"] for r in q("select record_id, name from connector_records where provider=%s", KEY)}
ok(set(names) == {"101", "102", "103"} and names["103"] == "Lakeside Test Org", "organizations are stored (canceled, a too-long id and an empty name left out; control characters removed)")
links = {r["external_id"]: r["client_id"] for r in q("select external_id, client_id from client_links where provider=%s and external_id is not null", KEY)}
ok(links.get("101") == C1 and links.get("102") == C2 and "103" not in links, "clients with the same name link automatically")
ok(not q("select 1 from dnsfilter_agents where agent_id in ('c-proxy') or length(agent_id) > 64"), "proxies and bad ids aren't stored as roaming clients")
d1 = data("101")
ok(d1["networks"] == 2 and d1["networks_policy"] == 2, "networks: the deleted one left out, both others have a policy: %s" % {k: d1.get(k) for k in ("networks", "networks_policy")})
ok((d1["requests_30d"], d1["blocked_30d"], d1["threats_30d"], d1["threats_90d"], d1["network_requests_7d"]) == (30000, 1500, 90, 270, 4200),
   "daily totals add up (30 / 90 days, network traffic in 7 days), values for other organizations, non-days and negatives left out: %s" % d1)
a1 = result("101", "agents")
ok(a1["status"] == "fail" and a1["detail"].startswith(f"{OK} of {N} workstations and laptops filtered; 5 without one (") and "1 not synced in 7 days" in a1["detail"] and "1 not filtering" in a1["detail"],
   "roaming clients: missing, uninstalled, offline and bypassed ones fail the check: " + a1["detail"])
n1 = result("101", "networks")
ok(n1["status"] == "pass" and n1["detail"] == "2 networks with a filtering policy; 4,200 requests filtered in the last 7 days.", "networks with a policy and traffic pass: " + n1["detail"])
ok(result("102", "networks")["status"] == "unknown" and "roaming clients only" in result("102", "networks")["detail"], "no networks: unknown, not a fail")
ok(result("103", "networks")["status"] == "fail" and result("103", "networks")["detail"] == "1 of 2 networks have no filtering policy.", "a network without a policy fails (one with only a scheduled policy counts as filtered)")
ok(result("103", "agents")["status"] == "unknown" and "Not linked" in result("103", "agents")["detail"], "an organization not linked to a client: roaming client check unknown")

# ---- in Align's checks, the client card, the QBR, mapping
ind = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));")
ok(ind[f"{KEY}_agents"]["ok"] is False and ind[f"{KEY}_agents"]["suggest"] == "not_met" and ind[f"{KEY}_networks"]["ok"] is True and ind[f"{KEY}_networks"]["suggest"] == "met",
   "the client's indicators carry the results")
stored = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::stored([{C1}]));")
ok(stored.get(str(C1), {}).get("checks", {}).get(f"{KEY}_agents", {}).get("status") == "fail", "stored results (health score, reports) include them")
# checkResults (health scores, reports) agrees with the client's live indicators, even right after a link moves
q("delete from client_links where provider=%s and client_id=%s", KEY, C2)
q("update client_links set client_id=%s where provider=%s and external_id='101'", C2, KEY)
moved = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::stored([{C2}]));").get(str(C2), {}).get("checks", {}).get(f"{KEY}_agents", {})
live = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C2}));").get(f"{KEY}_agents", {})
ok(moved.get("detail") and moved.get("detail") == live.get("text") and moved["detail"] != a1["detail"], "after moving an organization to another client, stored results use the new client's devices: " + str(moved.get("detail"))[:120])
q("update client_links set client_id=%s where provider=%s and external_id='101'", C1, KEY)
q("insert into client_links (client_id, provider, external_id, match_method) values (%s, %s, '102', 'auto')", C2, KEY)
ok(phpj("echo json_encode(Align\\Compliance\\Evidence::systems());").get(KEY + "_") == NAME, "the evidence check lists it as a system")
cp = st.get(B + f"/clients/{C1}").text
ok('id="dnsfilter"' in cp and "Threats blocked" in cp and ">270<" in cp and need[0] in cp and "OLD-PC-99" in cp and not errs(cp),
   "the client's overview has a DNSFilter card: totals, devices without a roaming client, unmatched roaming clients")
vp = login("viewer@example.com", "ViewerPassword123!")
if True:
    v = vp.get(B + f"/clients/{C1}").text
    ok('id="dnsfilter"' in v and f"/clients/{C1}/connectors#security" not in v.split('id="dnsfilter"')[1][:2000], "viewers see the card without the Manage link")
qbr = text(st.get(B + f"/clients/{C1}/report/qbr").text)
ok("Threats blocked by DNS filtering" in qbr and "270" in qbr and f"{OK}/{N}" in qbr and "Computers with DNS filtering" in qbr, "the QBR's Security section shows threats blocked and coverage")
ok("DNSFilter roaming client on every workstation and laptop" in qbr, "...and the checks")
m = st.get(B + "/mapping").text
ok(NAME in m and "Lakeside Test Org" in m and not errs(m), "Client mapping has a DNSFilter column")
c = st.get(B + f"/clients/{C1}/connectors").text
ok(NAME in c and not errs(c), "the client's connectors page shows the link")

# ---- only GETs, key only in the header
log = mocklog()
ok(log and all(x.startswith("GET ") and x.endswith("auth=set") for x in log) and not any(f"{KEY}-key" in x for x in log), "only GET requests, the key never in a URL (%d requests)" % len(log))

# ---- the offline setting, a change in DNSFilter, a second page, an organization gone
setting(f"{KEY}_offline_days", "30")
ind = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));")
ok("not synced" not in ind[f"{KEY}_agents"]["text"] and f"{OK + 1} of {N}" in ind[f"{KEY}_agents"]["text"], "with 30 offline days, a roaming client last synced 20 days ago counts: " + ind[f"{KEY}_agents"]["text"])
setting(f"{KEY}_offline_days", "7")
mock({"agents": {"101": agents}, "nopolicy": True, "many": True, "drop": ["103"]})
sync_now()
ok(result("101", "networks")["status"] == "fail" and result("101", "networks")["detail"] == "1 of 2 networks have no filtering policy.", "a network losing its policy fails on the next sync")
ok(data("102")["agents"] == 122, "a second page of roaming clients is read: %s" % data("102").get("agents"))
ok(records() == 2 and result("103", "networks") is None and not q("select 1 from dnsfilter_agents where org_id='103'"), "an organization gone from DNSFilter is removed with its results and roaming clients")
mock({"agents": {"101": agents}, "quiet": True})
sync_now()
ok(result("101", "networks")["status"] == "fail" and "no traffic in the last 7 days" in result("101", "networks")["detail"], "networks with a policy but no traffic fail")

mock({"agents": {"101": agents}, "mode": "cap50", "many": True})
sync_now()
ok("ERROR" not in str(mine(last_run())) and data("102")["agents"] == 122, "pages capped at 50 are still read to the end: %s" % data("102").get("agents"))
mock({"agents": {"101": agents}, "mode": "forbidden_one"})
sync_now()
ok("ERROR" not in str(mine(last_run())) and data("103")["threats_30d"] is None and data("101")["threats_30d"] == 90,
   "a report refused for one organization leaves only its totals unknown: %s" % mine(last_run()))
mock({"agents": {"101": agents}, "mode": "forbidden_report"})
sync_now()
ok("ERROR" not in str(mine(last_run())) and data("101")["requests_30d"] is None and result("101", "networks")["status"] == "unknown" and "traffic" in result("101", "networks")["detail"],
   "every report refused: totals unknown, the networks check unknown rather than failed")
c = st.get(B + f"/clients/{C1}").text
ok('id="dnsfilter"' in c and not errs(c), "the card still shows with unknown totals")

# ---- wrong answers never remove anything
mock({"agents": {"101": agents}})
sync_now()
before = q("select count(*) n from dnsfilter_agents")[0]["n"]
recs = records()
ok(recs == 3, "organization 103 is back once DNSFilter lists it again")
for mode, why in [("html", "JSON"), ("noitems", "list of organizations"), ("empty", "returned no organizations"), ("down", "couldn't be read"),
                  ("loop", "same page of organizations twice"), ("fallback", "same roaming client for two organizations"),
                  ("netfallback", "networks of another organization")]:
    mock({"mode": mode, "agents": {"101": agents}})
    sync_now()
    err = " ".join(str(v) for v in mine(last_run()).values())
    ok("ERROR" in err and why in err and records() == recs and result("101", "agents") is not None
       and q("select count(*) n from dnsfilter_agents")[0]["n"] == before and q("select 1 from client_links where provider=%s and external_id='101'", KEY),
       f"a {mode} answer is an error and removes nothing: " + err[:160])
q("update connector_records set synced_at = now() - interval 30 hour where provider=%s", KEY)
q("update connector_results set synced_at = now() - interval 30 hour where provider=%s", KEY)
ind = phpj(f"echo json_encode(Align\\Health\\SecurityChecks::indicators({C1}));")
ok(ind[f"{KEY}_agents"]["unknown"] is True and "Not read since" in ind[f"{KEY}_agents"]["text"], "data not refreshed for more than a day counts as unknown")
qbr = text(st.get(B + f"/clients/{C1}/report/qbr").text)
ok("Threats blocked by DNS filtering" not in qbr and "Computers with DNS filtering" not in qbr, "the QBR doesn't print old DNSFilter numbers")
mock({})
setting(KEY + "_api_key", "wrong-key", True)
r = st.post(B + f"/integrations/{KEY}/test", data={"_csrf": csrf(st, f"/integrations/{KEY}")})
ok("refused the API key" in text(r.text) and "wrong-key" not in r.text, "a wrong key is explained (and not shown)")
done()
