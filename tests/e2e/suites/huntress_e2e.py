"""2.7.0 Huntress and security awareness training, against the mock Huntress API (tests/mock-server.php): the
integration (key and secret as secrets, Test, only GET requests, pages followed by next_page_token); organizations
matched to clients by name and shown on Client mapping; agents against the client's RMM devices (serial, then host
name; missing and not checking in); Managed Antivirus; open and closed incidents, escalations, summary reports,
external ports and ITDR identity counts (no names kept); the five checks, the dashboard, the client overview card,
the health score, compliance and alignment suggestions and the migration's links; a list the account can't read
(the rest still syncs); an empty organization list changing nothing. SAT: the three Huntress SAT exports read into
totals (no names stored), an unrecognised file refused naming its columns, the two checks and their thresholds,
deleting an upload, roles."""
import re, json, html as H
from datetime import date, datetime, timedelta, timezone
from lib import *

C1, C2 = 1, 2


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def phpo(code): r = php(code); return (r.stdout + r.stderr).strip()
def hset(**kw): requests.post(M + "/mock/huntress-set", data=json.dumps(kw))
def calls(): return requests.get(M + "/mock/huntress").json().get("calls", [])
def iso(days=0): return (datetime.now(timezone.utc) - timedelta(days=days)).strftime("%Y-%m-%dT%H:%M:%SZ")
def day(days=0): return (date.today() - timedelta(days=days)).isoformat()
def sync(): return phpo("echo Align\\Huntress\\Sync::run();")
def checks(cid): return json.loads(phpo(f"echo json_encode((Align\\Huntress\\Clients::forClient({cid}) ?? [])['checks'] ?? null);") or "null")
def satc(cid): return json.loads(phpo(f"echo json_encode(Align\\Sat\\Sat::checks({cid}));"))
cname = lambda cid: q("select name from clients where id=%s", cid)[0]["name"]


def cleanup():
    for t in ["huntress_orgs", "huntress_agents", "huntress_incidents", "huntress_escalations", "huntress_reports", "huntress_ports", "sat_results"]:
        q(f"delete from {t}")
    q("delete from client_links where provider='huntress'")
    q("delete from settings where name like 'huntress\\_%%' or name like 'sat\\_%%'")
    q("delete from client_frameworks where client_id=%s and framework_id in (select id from compliance_frameworks where slug='cis-v81-ig2')", C1)
    requests.get(M + "/mock/huntress-reset")


cleanup()
import atexit
atexit.register(cleanup)
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
tok = lambda s, p: csrf(s, p)

# ---- the migration: tables, and built-in content linked to the new checks
ok(q("select count(*) n from information_schema.tables where table_schema=database() and table_name in ('huntress_orgs','huntress_agents','huntress_incidents','huntress_escalations','huntress_reports','huntress_ports','sat_results')")[0]["n"] == 7,
   "migration 063 adds the tables")
links = {r["ref"]: r["auto_check"] for r in q("select c.ref, c.auto_check from compliance_controls c join compliance_frameworks f on f.id=c.framework_id where f.slug='cis-v81-ig2' and c.ref in ('10.1','10.2','10.5','14.1','14.2','16.9')")}
ok(links == {"10.1": "huntress_agents", "10.2": "huntress_av", "10.5": None, "14.1": "sat_training", "14.2": "sat_phishing", "16.9": None}, f"chosen controls are linked (not ASR or secure-coding training): {links}")
std = {r["title"]: r["auto_check"] for r in q("select title, auto_check from alignment_standards where title in ('EDR on every workstation and server','Security awareness training at least yearly','Phishing tests at least quarterly')")}
ok(all(v in ("huntress_agents", "sat_training", "sat_phishing") for v in std.values()), f"the starter standards are linked: {std}")
r = php('(require "' + ROOT + '/db/migrations/063_huntress_sat.php")();')
ok(r.returncode == 0 and not (r.stdout + r.stderr).strip(), "the migration can run again: " + (r.stdout + r.stderr)[:200])

# ---- the integration page
ok(st.get(B + "/integrations").text.count("Huntress") >= 1, "the Integrations page has the Huntress card")
t = st.get(B + "/integrations/huntress").text
ok('name="huntress_api_key"' in t and 'name="sat_training_pass"' in t, "the page has the key, secret and SAT thresholds")
setting("huntress_api_base", M + "/huntress-api")
r = st.post(B + "/integrations/huntress", data={"_csrf": tok(st, "/integrations/huntress"), "huntress_api_key": "hk", "huntress_api_secret": "hs", "huntress_offline_days": "7",
                                                "sat_training_pass": "90", "sat_click_max": "10", "sat_campaign_months": "6"})
row = q("select value, is_secret from settings where name='huntress_api_secret'")
ok(row and row[0]["is_secret"] == 1 and row[0]["value"] != "hs", "the secret is stored encrypted")
r = st.post(B + "/integrations/huntress/test", data={"_csrf": tok(st, "/integrations/huntress")})
ok("Connected to Huntress (Example MSP)" in flash(r.text), "Test reads the account: " + flash(r.text)[:100])

# ---- the data: client 1's organization and its devices
devs = json.loads(phpo(f"echo json_encode(array_map(fn($d) => [$d['id'], $d['serial'], $d['system_name']], Align\\Huntress\\Clients::coverage({C1}, ['org_id' => '0'])['needed']));"))
ok(len(devs) > 8, f"client 1 has RMM workstations and servers to compare ({len(devs)})")
missing_ids = [devs[0][0], devs[1][0]]
agents = []
for i, (did, serial, name) in enumerate(devs[2:]):
    a = {"id": 5000 + i, "organization_id": 101, "hostname": name, "serial_number": serial, "platform": "windows", "os": "Windows 11",
         "last_callback_at": iso(0), "defender_status": "Healthy", "defender_substatus": "Up to date", "defender_policy_status": "Compliant", "firewall_status": "Enabled"}
    if i == 0:
        a["serial_number"] = "To be filled by O.E.M."  # matched by host name instead
        a["hostname"] = name.lower() + ".corp.example"
    if i == 1:
        a["last_callback_at"] = iso(20)  # not checking in
    if i == 2:
        a["defender_status"], a["defender_substatus"] = "Unhealthy", "Out of date"
    if i == 3:
        a["defender_status"], a["defender_substatus"] = "Protected", "Up to date"  # 2.7.1: what real agents report
    if i == 4:
        a["defender_status"], a["defender_substatus"] = "Protected", "Signatures out of date"
    agents.append(a)
agents.append({"id": 6000, "organization_id": 102, "hostname": "NF-PC1", "serial_number": "NF1", "platform": "windows", "last_callback_at": iso(0)})
hset(organizations=[{"id": 101, "name": cname(C1), "key": "cedar", "agents_count": len(agents) - 1, "sat_learner_count": 25},
                    {"id": 102, "name": "Unmatched Org Name", "key": "nf", "agents_count": 1}, {"id": 103, "name": "Not A Client", "key": "x"}],
     agents=agents,
     incident_reports=[{"id": 1, "organization_id": 101, "status": "sent", "severity": "critical", "subject": "Ransomware canary tripped on " + devs[3][2], "sent_at": iso(1), "updated_at": iso(1), "body": "SECRET BODY TEXT", "indicator_types": ["ransomware_canaries"]},
                       {"id": 2, "organization_id": 101, "status": "sent", "severity": "low", "subject": "Unwanted app", "sent_at": iso(2), "updated_at": iso(2)},
                       {"id": 3, "organization_id": 101, "status": "closed", "severity": "high", "subject": "Old foothold", "sent_at": iso(40), "closed_at": iso(35), "updated_at": iso(35)},
                       {"id": 4, "organization_id": 101, "status": "draft", "severity": "high", "subject": "Draft", "updated_at": iso(0)},
                       {"id": 5, "organization_id": 101, "status": "closed", "severity": "low", "subject": "Ancient", "closed_at": iso(500), "updated_at": iso(500)}],
     escalations=[{"id": 9, "organizations": [{"id": 101, "name": "x"}], "status": "overdue", "severity": "high", "subject": "Approve isolation", "type": "Remediation", "created_at": iso(5), "updated_at": iso(5)}],
     reports=[{"id": 70, "organization_id": 101, "type": "monthly_summary", "period": day(60) + "..." + day(31), "url": "https://huntress.example/reports/70.pdf", "incidents_reported": 2, "signals_investigated": 40},
              {"id": 71, "organization_id": 101, "type": "monthly_summary", "period": day(30) + "..." + day(1), "url": "javascript:alert(1)", "incidents_reported": 1}],
     identities=[{"id": 1, "organization": {"id": 101}, "username": "secret.person@example.com", "enabled": True, "external": False, "mfa_enabled": True, "risk_level": "none"},
                 {"id": 2, "organization": {"id": 101}, "username": "b@example.com", "enabled": True, "external": False, "mfa_enabled": False, "risk_level": "low"},
                 {"id": 3, "organization": {"id": 101}, "username": "guest@x.com", "enabled": True, "external": True, "mfa_enabled": False, "risk_level": "high"}],
     external_ports=[{"id": 1, "ip_address": "203.0.113.5", "port": 443, "protocol": "TCP", "service": "https", "risky_service": False, "organization_ids": [101]}],
     page=3)
out = sync()
ok("3 organizations" in out and f"{len(agents)} agents" in out and "matched" in out, "the sync reads organizations and agents, across pages: " + out)
ok(all(c["m"] == "GET" for c in calls()), "only GET requests are sent")
ok(any(c["q"].get("page_token") for c in calls() if c["path"] == "agents"), "next_page_token is followed")
ok(q("select external_id from client_links where provider='huntress' and client_id=%s", C1)[0]["external_id"] == "101", "the organization with the client's name is linked to it")
ok(not q("select 1 from client_links where provider='huntress' and external_id='103'"), "an organization without a matching client stays unlinked")
ok(not q("select 1 from huntress_incidents where incident_id in (4)") and q("select count(*) n from huntress_incidents")[0]["n"] == 4, "drafts aren't kept")
ok("SECRET BODY" not in json.dumps(q("select * from huntress_incidents"), default=str) and "secret.person" not in json.dumps(q("select * from huntress_orgs"), default=str), "incident bodies and identity names aren't stored")
ok(q("select url from huntress_reports where report_id=71")[0]["url"] is None, "a report link that isn't https is dropped")
t = st.get(B + "/mapping").text
ok("Huntress" in t and "Unmatched Org Name" in t, "Client mapping has a Huntress column offering the unlinked organizations")

# ---- the checks
c = checks(C1)
ok(c["huntress_agents"]["status"] == "fail" and f"{len(devs) - 3} of {len(devs)}" in c["huntress_agents"]["detail"] and "missing on" in c["huntress_agents"]["detail"] and "not checked in for 7 days" in c["huntress_agents"]["detail"],
   "agents: two devices without one, one not checking in (one matched by host name despite a placeholder serial): " + c["huntress_agents"]["detail"])
ok(c["huntress_incidents"]["status"] == "fail" and "1 open critical or high incident" in c["huntress_incidents"]["detail"], "an open critical incident fails")
ok(c["huntress_av"]["status"] == "fail" and "Unhealthy" in c["huntress_av"]["detail"], "an agent with Defender unhealthy fails: " + c["huntress_av"]["detail"])
ok("(Protected (Up to date))" not in c["huntress_av"]["detail"] and "Signatures out of date" in c["huntress_av"]["detail"] and f"{len(agents) - 4} of {len(agents) - 2}" in c["huntress_av"]["detail"],
   "2.7.1: Protected and up to date counts as healthy; Protected with signatures out of date doesn't: " + c["huntress_av"]["detail"])
ok(c["huntress_identities"]["status"] == "pass" and "2 identities monitored, none at high risk, 1 without MFA" in c["huntress_identities"]["detail"], "ITDR: guests left out, counts only: " + c["huntress_identities"]["detail"])
ok(c["huntress_ports"]["status"] == "pass" and "1 open port, none risky" in c["huntress_ports"]["detail"], "external ports: none risky")
setting("huntress_offline_days", "30")
ok(checks(C1)["huntress_agents"]["detail"].count("not checked in") == 0, "the not-checking-in days are a setting")
setting("huntress_offline_days", "7")

# ---- where it shows
t = st.get(B + f"/clients/{C1}").text
card = text(t.split('id="huntress"')[1].split('id="sat"')[0]) if 'id="huntress"' in t else ""
ok("Huntress agent on every workstation and server" in card and "Ransomware canary" in card and "Approve isolation" in card and "overdue" in card, "the overview has the Huntress card with open incidents and escalations")
ok('href="https://huntress.example/reports/70.pdf"' in t and "javascript:" not in t, "summary report links (https only)")
ok("1 high" in card and "Ancient" not in card, "closed incidents in the last 12 months counted")
ok('id="huntress"' not in st.get(B + f"/clients/{C2}").text, "a client without a Huntress organization has no card")
t = text(st.get(B + "/").text)
ok("Open critical Huntress incident" in t and "Overdue Huntress escalation" in t, "the dashboard lists the open critical incident and the overdue escalation")
row = q("select security from client_health where client_id=%s and day=curdate()", C1)
ok(row and row[0]["security"] is not None, f"the health score's Security area counts the Huntress checks ({row and row[0]['security']})")
fw = q("select id from compliance_frameworks where slug='cis-v81-ig2'")[0]["id"]
q("insert ignore into client_frameworks (client_id, framework_id) values (%s, %s)", C1, fw)
t = st.get(B + f"/clients/{C1}/compliance/{fw}").text
ok("have a Huntress agent checking in" in H.unescape(t) and 'data-value="not_met"' in t, "a linked compliance control shows the check and suggests Not met")
ind = json.loads(phpo(f'echo json_encode(Align\\Alignment\\Alignment::indicators([], null, null, false, Align\\Health\\SecurityChecks::indicators({C1})));'))
ok(ind["huntress_agents"]["suggest"] == "misaligned" and ind["huntress_identities"]["suggest"] == "aligned", "alignment hints from the Huntress checks")
ok("huntress_ports" in st.get(B + f"/frameworks/{fw}").text, "the framework editor offers the new checks")
t = tech.get(B + f"/clients/{C1}/connectors").text
ok("Huntress" in t and "Security" in t, "the client's Connectors page lists its Huntress link")

# ---- a list the account can't read; an empty organization list
hset(fail={"identities": 403})
out = sync()
ok("identities not read" in out and "agents" in out, "an unreadable list is noted, the rest still syncs: " + out)
hset(fail={}, organizations=[])
out = sync()
ok("returned no organizations" in out and q("select count(*) n from huntress_orgs")[0]["n"] == 3, "an empty organization list changes nothing: " + out[:120])
st0 = requests.get(M + "/mock/huntress").json()
hset(organizations=st0.get("organizations_backup") or [{"id": 101, "name": cname(C1), "key": "cedar"}, {"id": 102, "name": "Unmatched Org Name"}, {"id": 103, "name": "Not A Client"}], agents=[])
out = sync()
ok("returned no agents" in out and q("select count(*) n from huntress_agents")[0]["n"] == len(agents), "an empty agent list changes nothing either: " + out[:120])
# duplicate agents (a reinstall): the one that called in last counts; escalations naming organizations by id
dup = dict(agents[1]); dup["id"] = 7777; dup["last_callback_at"] = iso(0)
hset(agents=agents + [dup], escalations=[{"id": 10, "organizations": ["101"], "status": "open", "subject": "Ids only", "updated_at": iso(1)}])
sync()
ok("not checked in for 7 days" not in checks(C1)["huntress_agents"]["detail"], "a newer agent for the same machine wins over the stale one")
ok(q("select 1 from huntress_escalations where escalation_id=10 and org_id='101'"), "escalations listing organizations by id are kept")
# Defender not managed by Huntress (no policy status): out of scope
hset(agents=[{**a, "defender_policy_status": None} for a in agents])
sync()
ok(checks(C1)["huntress_av"]["status"] == "unknown" and "Managed Antivirus not in use" in checks(C1)["huntress_av"]["detail"], "Defender not managed by Huntress: unknown, not failed: " + json.dumps(checks(C1)["huntress_av"]))
hset(agents=agents)
sync()
# Huntress not read for over a day: every check unknown
q("update huntress_orgs set synced_at = now() - interval 2 day")
c = checks(C1)
ok(all(v["status"] == "unknown" for v in c.values()) and "hasn't been read since" in c["huntress_agents"]["detail"], "data older than a day: the checks are unknown, saying why")
sync()

# ---- SAT: uploads
LP = "First Name,Last Name,Enrollment Date,Company,Phishing 101,Passwords,MFA Basics\n" + "\n".join(
    [f"Fake{i},Person{i},2026-01-05,\"{cname(C1)}\",2026-02-0{1 + i % 8},2026-03-02,2026-04-03" for i in range(9)] + ["Late,Learner,2026-01-05,x,2026-02-01,-,N/A"])
PA = "First Name,Last Name,Email,Opened At,Clicked At,Reported At,Compromised At\n" + "\n".join(
    [f"Fake{i},Person{i},f{i}@example.com,{day(10)},{day(10) if i < 2 else '-'},{day(9) if i >= 5 else '-'},{day(10) if i == 0 else '-'}" for i in range(20)])
up = lambda s, name, body, **kw: s.post(B + f"/clients/{C1}/sat", data={"_csrf": tok(s, f"/clients/{C1}"), **kw}, files={"file": (name, body, "text/csv")})
ok(satc(C1)["sat_training"]["status"] == "unknown", "no uploads: unknown")
r = up(tech, "learner_progress.csv", LP)
ok("Read 10 learners: 9 completed" in flash(r.text), "Learner Progress: completed when every assigned episode has a date (N/A isn't assigned): " + flash(r.text))
row = q("select * from sat_results where client_id=%s and kind='training'", C1)[0]
ok(row["learners"] == 10 and row["completed"] == 9 and "Fake" not in json.dumps(row, default=str), "only totals are stored, no names")
ok(satc(C1)["sat_training"]["status"] == "pass" and "9 of 10 learners" in satc(C1)["sat_training"]["detail"], "90% done passes (90% needed)")
setting("sat_training_pass", "95")
ok(satc(C1)["sat_training"]["status"] == "fail", "the training threshold is a setting")
setting("sat_training_pass", "90")
r = up(tech, "attempts.csv", PA)
ok("Read 20 phishing emails: 2 clicked, 15 reported" in flash(r.text), "Phishing: Attempts: " + flash(r.text))
ok(satc(C1)["sat_phishing"]["status"] == "fail" and "Click rate 10%" in satc(C1)["sat_phishing"]["detail"], "10% clicked fails (under 10% needed)")
setting("sat_click_max", "15")
ok(satc(C1)["sat_phishing"]["status"] == "pass" and "75% reported" in satc(C1)["sat_phishing"]["detail"], "under the click threshold with a recent campaign passes: " + satc(C1)["sat_phishing"]["detail"])
q("update sat_results set covers_from=%s, covers_to=%s where client_id=%s and kind='phishing'", day(250), day(250), C1)
ok(satc(C1)["sat_phishing"]["status"] == "fail" and "more than 6 months ago" in satc(C1)["sat_phishing"]["detail"], "no campaign in the last 6 months fails")
AO = "First Name,Last Name,Learner Status,Click %,Jan Campaign,Apr Campaign\nA,B,Active,50%,2026-01-10,-\nC,D,Active,0%,-,-\nE,F,Active,0%,N/A,-"
r = up(tech, "annual.csv", AO, campaign_date=day(5))
ok("Read 5 phishing emails: 1 clicked" in flash(r.text), "Phishing: Annual Overview (a learner's click % over the campaigns they got): " + flash(r.text))
ok(q("select covers_to from sat_results where client_id=%s and report like 'Phishing: Annual%%'", C1)[0]["covers_to"].isoformat() == day(5), "the campaign date given is used when it's the newest")
r = up(tech, "learner_progress.csv", LP)
ok(q("select count(*) n from sat_results where client_id=%s and kind='training'", C1)[0]["n"] == 1, "the same report for the same dates replaces the earlier upload")
r = up(tech, "people.csv", "First Name,Last Name,Phone,Employee ID\nA,B,5551234,1200\nC,D,5559876,2026")
ok("didn't recognise this file" in flash(r.text), "a list of people with numbers isn't taken for training results")
r = up(tech, "nodates.csv", "First Name,Last Name,Clicked,Reported\nA,B,yes,no\nC,D,no,yes")
ok("enter the campaign date" in flash(r.text), "a phishing report without dates asks for the campaign date")
r = up(tech, "nodates.csv", "First Name,Last Name,Clicked,Reported\nA,B,yes,no\nC,D,no,yes", campaign_date=day(3))
ok("Read 2 phishing emails: 1 clicked, 1 reported" in flash(r.text), "...and reads with one: " + flash(r.text))
q("delete from sat_results where client_id=%s and file_name='nodates.csv'", C1)
r = up(tech, "other.csv", "Device,Owner\nPC1,someone")
ok("didn't recognise this file" in flash(r.text) and "Device, Owner" in flash(r.text), "an unrecognised file is refused, naming its columns")
t = st.get(B + f"/clients/{C1}").text
ok('id="sat"' in t and "Assignment: Learner Progress" in t and "9/10 done" in t, "the overview has the training card with the history")
row = q("select security from client_health where client_id=%s and day=curdate()", C1)
ok(row and row[0]["security"] is not None, "SAT counts in the health score too")
t = text(st.get(B + f"/clients/{C1}/report/qbr").text)
ok("Security" in t and "Devices protected by Huntress" in t and "Training completed" in t and "Phishing click rate" in t and "Huntress summary reports" in t,
   "the QBR pack has a Security section (Huntress, training, phishing, the checks, summary reports)")
ok("Devices protected by Huntress" not in text(st.get(B + f"/clients/{C1}/report/qbr?s_security=0&s_health=1&s_changes=1&s_sla=1&s_assets=1&s_licensing=1&s_backup=1&s_compliance=1&s_alignment=1&s_roadmap=1&s_budget=1").text),
   "...which can be switched off")
ok("Devices protected by Huntress" not in text(st.get(B + f"/clients/{C2}/report/qbr").text), "a client without any security data has no Security section")
# roles
r = up(viewer, "x.csv", LP)
ok(q("select count(*) n from sat_results where client_id=%s", C1)[0]["n"] == 3, "viewers can't upload")
ok("/sat\"" not in viewer.get(B + f"/clients/{C1}").text and 'id="sat"' in viewer.get(B + f"/clients/{C1}").text, "viewers see the card without the upload form")
sid = q("select id from sat_results where client_id=%s and kind='training'", C1)[0]["id"]
tech.post(B + f"/clients/{C2}/sat/{sid}/delete", data={"_csrf": tok(tech, f"/clients/{C2}")})
ok(q("select 1 from sat_results where id=%s", sid), "an upload can't be deleted through another client's address")
tech.post(B + f"/clients/{C1}/sat/{sid}/delete", data={"_csrf": tok(tech, f"/clients/{C1}")})
ok(not q("select 1 from sat_results where id=%s", sid) and q("select 1 from audit_log where action='sat.delete'"), "deleting an upload (audited)")
ok(not errs(st.get(B + f"/clients/{C1}").text) and not errs(st.get(B + "/integrations/huntress").text), "no PHP errors on the pages")
done()
