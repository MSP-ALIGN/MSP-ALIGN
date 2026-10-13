"""2.18.0 Evidence check (the Phase 4 gate): a tech answers a client's automatic security checks without seeing Align's
results; each answer is stored with Align's result at that moment; the client's results page and Compliance →
Evidence check compare them (agree, disagree, couldn't tell, no result) with a state per check (trusted with two
clients that agree and none that disagree, disagrees, needs more, not compared, no answers) and a gate per system (every
check trusted; the newest round counts); the anonymized export has no client names, notes or people; it takes records
and lists in a check's own wording out of Align's text, then addresses, IDs and domains, then names (whole words), then
what a length limit cut short; drops the text when Align had no result; letters clients in a keyed order; techs only;
migration 076 can run again."""
import json, csv, io, datetime
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
ids = [r["id"] for r in q("select id from clients where is_archived=0 and planning_excluded=0 order by id limit 2")]
C1, C2 = ids
N1 = q("select name from clients where id=%s", C1)[0]["name"]
N2 = q("select name from clients where id=%s", C2)[0]["name"]
now = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def m365(cid, checks):
    q("delete from client_m365 where client_id=%s", cid)
    q("insert into client_m365 (client_id, status, tenant_id, tenant_name, tenant_domain, security_json, security_at) values (%s, 'connected', %s, %s, %s, %s, NOW())",
      cid, f"eeeeeeee-aaaa-4bbb-8ccc-00000000000{cid % 10}", f"Tenantco{cid} Holdings", f"tenantco{cid}.onmicrosoft.com", json.dumps({"at": now, "checks": checks}))


def cleanup():
    q("delete from evidence_checks")
    q("delete from client_m365 where client_id in (%s, %s)", C1, C2)


cleanup()
import atexit
atexit.register(cleanup)
m365(C1, {"m365_secure_score": {"status": "pass", "detail": "Secure Score 81% (40 of 49 points)"},
          "m365_mfa_users": {"status": "fail", "detail": "8 of 10 licensed users registered for MFA"},
          "m365_stale": {"status": "pass", "detail": "0 of 10 licensed accounts unused for 90 days"}})

# ---- the questionnaire: statements and answers, never Align's results
r = tech.get(B + f"/clients/{C1}/evidence")
t = r.text
ok(r.status_code == 200 and "Microsoft 365" in t and "Every Microsoft 365 user registered for MFA" in t and 'name="a[m365_mfa_users]"' in t and not errs(t), "techs get the questionnaire")
ok("8 of 10 licensed users" not in t and "Secure Score 81%" not in t and "fa-robot" not in t, "it never shows Align's results")
ok("Huntress" not in t.split('<form')[1] if '<form' in t else False, "systems Align can't read for this client are left out")
ok(viewer.get(B + f"/clients/{C1}/evidence").status_code == 403 and viewer.get(B + "/compliance/evidence").status_code == 403, "viewers can't open it")

# ---- answering: stored with Align's result at that moment
data = {"_csrf": csrf(tech, f"/clients/{C1}/evidence"), "a[m365_secure_score]": "pass", "a[m365_mfa_users]": "pass", "a[m365_stale]": "unsure",
        "a[m365_admin_count]": "fail", "a[huntress_agents]": "nonsense", "a[not_a_check]": "pass", "note[m365_mfa_users]": "Entra: all 10 users have Authenticator"}
r = tech.post(B + f"/clients/{C1}/evidence", data=data)
rows = {x["check_key"]: x for x in q("select * from evidence_checks where client_id=%s", C1)}
ok(set(rows) == {"m365_secure_score", "m365_mfa_users", "m365_stale", "m365_admin_count"} and "Saved 4 answers" in flash(r.text), "4 answers saved; unknown checks and odd values left out " + str(sorted(rows)))
ok(rows["m365_mfa_users"]["auto_status"] == "fail" and "8 of 10" in rows["m365_mfa_users"]["auto_detail"] and rows["m365_admin_count"]["auto_status"] == "unknown",
   "each keeps Align's result then")
ok(r.url.endswith("results=1") and "Disagree" in r.text and "Agree" in r.text and "Align had no result" in r.text and "You couldn&#039;t tell" in r.text, "then the results page compares them")
ok("Entra: all 10 users have Authenticator" in r.text, "...with how the tech checked")
ok(q("select detail from audit_log where action='evidence.save' order by id desc limit 1")[0]["detail"].endswith("4 answers"), "audited")
r = tech.post(B + f"/clients/{C1}/evidence", data={"_csrf": csrf(tech, f"/clients/{C1}/evidence")})
ok("Nothing was saved" in flash(r.text), "an empty form saves nothing")

# ---- the report: one client is not enough; a disagreement shows
t = tech.get(B + "/compliance/evidence").text
gate = t.split('id="gate-microsoft-365"')[1][:4000]
ok('id="gate-microsoft-365"' in t and "Disagreements" in gate[:600] and "1 client compared (2 needed per check)" in gate and not errs(t), "Microsoft 365: one client compared, a disagreement")
li = lambda k: gate.split(f'data-check="{k}"')[1][:700]
ok("Disagrees" in li("m365_mfa_users") and "Needs 1 more client" in li("m365_secure_score") and "Not compared" in li("m365_stale") and "Not compared" in li("m365_admin_count"),
   "each check has its own state: disagrees, needs one more client, not compared (can't tell / no result)")
ok('id="disagreements"' in t and N1 in t.split('id="disagreements"')[1] and "8 of 10 licensed users" in t, "the disagreement is listed with what Align found")

# ---- a second client that agrees, and the first answered again: the newest round counts and the gate passes
m365(C2, {"m365_secure_score": {"status": "fail", "detail": "Secure Score 40% (20 of 49 points)"}, "m365_mfa_users": {"status": "pass", "detail": "5 of 5 licensed users registered for MFA"}})
tech.post(B + f"/clients/{C2}/evidence", data={"_csrf": csrf(tech, f"/clients/{C2}/evidence"), "a[m365_secure_score]": "fail", "a[m365_mfa_users]": "pass"})
tech.post(B + f"/clients/{C1}/evidence", data={"_csrf": csrf(tech, f"/clients/{C1}/evidence"), "a[m365_mfa_users]": "fail"})
t = tech.get(B + "/compliance/evidence").text
gate = t.split('id="gate-microsoft-365"')[1][:4000]
ok("Gate passed" not in gate[:700] and "2 of " in gate[:700] and "checks trusted" in gate[:700] and "2 clients compared" in gate and "Trusted" in li("m365_secure_score") and "Trusted" in li("m365_mfa_users")
   and "Not compared" in li("m365_stale") and "No answers" in gate, "two checks trusted on two clients; the rest not yet, so the system hasn't passed " + gate[:300].replace("\n", " "))
ok("None: every answer that could be compared agrees" in t, "no disagreements left (the newer answer replaced the old one)")
ok(q("select count(*) n from evidence_checks where client_id=%s and check_key='m365_mfa_users'", C1)[0]["n"] == 2, "...and the earlier round is kept")
eid = q("select max(id) m from evidence_checks")[0]["m"]
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'm365_stale', 'pass', 'pass', '0 stale', 1, NOW())", C2)
gate = tech.get(B + "/compliance/evidence").text.split('id="gate-microsoft-365"')[1][:4000]
ok("Needs 1 more client" in li("m365_stale"), "a check compared on a single client needs one more")
keys = json.loads(php('echo json_encode(array_keys(Align\\Health\\SecurityChecks::labels()));').stdout)
for c in (C1, C2):
    for k in keys:
        if k.startswith("m365_"):
            q("insert into evidence_checks (client_id, check_key, manual, auto_status, user_id, created_at) values (%s, %s, 'pass', 'pass', 1, NOW())", c, k)
gate = tech.get(B + "/compliance/evidence").text.split('id="gate-microsoft-365"')[1][:700]
ok("Gate passed" in gate, "every Microsoft 365 check trusted on two clients: the system passes")
q("delete from evidence_checks where id > %s", eid)

# ---- the anonymized export
dev = q("select display_name from devices where client_id=%s and display_name is not null and char_length(display_name) >= 3 limit 1", C1)
name = dev[0]["display_name"] if dev else "PC-EVIDENCE-01"
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, note, user_id, created_at) values (%s, 'huntress_agents', 'fail', 'pass', %s, 'secret note', 1, NOW())",
  C1, f"9 of 10 have an agent; missing on {name}; mail at it@acme-dental.example, see https://portal.example/x, host 10.20.30.40 and acme-dental.example, {N1}")
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'huntress_incidents', 'pass', 'fail', '1 open critical or high incident: Ransomware on FRONTDESK-7 for jdoe', 1, NOW())", C1)
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'm365_admin_count', 'pass', 'fail', %s, 1, NOW())",
  C1, f"Graph error for Tenantco{C1} Holdings (tenant 3f2b1c4d-aaaa-4bbb-8ccc-1234567890ab): mail via contoso-mail.protection.outlook.com, SPF include spf.protection.outlook.com")
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'huntress_av', 'pass', 'fail', '2 of 4 Windows agents have Defender healthy and compliant: ZQ-UNSEEN-HOST (unhealthy), BOOKKEEP2 (incompatible)', 1, NOW())", C1)
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'email_dkim', 'pass', 'pass', 'DKIM key published (selector zqcustom2024)', 1, NOW())", C1)
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'email_dkim', 'pass', 'fail', 'No DKIM key found for zq.example at google, zqsel99 or 24 common selectors', 1, NOW())", C2)
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'email_spf', 'pass', 'pass', 'SPF: v=spf1 include:zqwidgets.example include:zqwidg…', 1, NOW())", C1)
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'email_dmarc', 'pass', 'fail', 'The DMARC record has no valid policy (p=): v=DMARC1; rua=mailto:zqjane@zqdental.c…', 1, NOW())", C2)
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'sat_x', 'pass', 'pass', 'Trained: zqperson@zqdental.c… and ZQCUTNAM…', 1, NOW())", C2)
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'huntress_identities', 'pass', 'unknown', 'Huntress has not been read since QQ-SECRET-DETAIL', 1, NOW())", C1)
q("insert into evidence_checks (client_id, check_key, manual, auto_status, auto_detail, user_id, created_at) values (%s, 'huntress_agents', 'fail', 'fail', '3 of 5 workstations and servers have a Huntress agent checking in; missing on ZQ-NOT-A-DEVICE, OTHERBOX; not checked in for 30 days: ZQ-OLD-ONE', 1, NOW())", C2)
r = tech.get(B + "/compliance/evidence/export")
body = r.text
rows = list(csv.reader(io.StringIO(body)))
ok(r.status_code == 200 and "text/csv" in r.headers.get("content-type", "") and rows[0][0] == "Client" and len(rows) > 5, "the export is a CSV")
ok(N1 not in body and N2 not in body and "secret note" not in body and "Alex" not in body and "Terry" not in body and "admin@example.com" not in body, "no client names, notes or people")
ok({r_[0] for r_ in rows[1:]} == {"Client A", "Client B"}, "clients are Client A and Client B")
ok(name not in body and "acme-dental" not in body and "10.20.30.40" not in body and "portal.example" not in body and "[name]" in body and "[domain]" in body
   and "[IP address]" in body and "[email]" in body and "[web address]" in body, "device names, domains, addresses taken out of Align's text")
ok("FRONTDESK-7" not in body and "jdoe" not in body and "1 open critical or high incident: [incident subject]" in body, "an incident's subject is left out")
ok("Tenantco" not in body and "3f2b1c4d" not in body and "[ID]" in body and "include spf.protection.outlook.com" in body and "contoso-mail" not in body and "via [domain]" in body,
   "tenant names and IDs are taken out; provider domains stay " + str([x for x in rows if x[2] == "m365_admin_count"]))
ok("ZQ-UNSEEN-HOST" not in body and "BOOKKEEP2" not in body and "[name] (unhealthy), [name] (incompatible)" in body, "antivirus agents' names are taken out by their place in the text")
ok("zqcustom2024" not in body and "(selector [selector])" in body and "zqsel99" not in body and "at [selectors] or 24 common selectors" in body, "DKIM selectors are taken out")
ok("zqwidg" not in body and "zqjane" not in body and "zqdental" not in body and "SPF: [record]" in body and "(p=): [record]" in body, "SPF and DMARC records are taken out")
ok("zqperson" not in body and "ZQCUTNAM" not in body and "[email]" in body and "[cut]…" in body, "...and so is what's left of an address or a name cut short")
ok("ZQ-NOT-A-DEVICE" not in body and "OTHERBOX" not in body and "ZQ-OLD-ONE" not in body and "; missing on [names]; not checked in for 30 days: [names]" in body,
   "agent lists are taken out even for names Align doesn't know")
ok("QQ-SECRET-DETAIL" not in body and [x[5] for x in rows if x[2] == "huntress_identities"] == [""], "no text when Align had no result")
ok("@[name]" not in body and ".[name]" not in body, "addresses go before names, so no part of one is left behind")
red = json.loads(php(f'echo json_encode([Align\\Compliance\\Evidence::redact("Zq" . {json.dumps(N1)} . " and " . {json.dumps(N1)} . "!", {C1}, "m365_x")]);').stdout)[0]
ok(red == "Zq" + N1 + " and [name]!", "names are taken out as whole words only " + red)
order = sorted([C1, C2], key=lambda c: json.loads(php(f'echo json_encode(hash_hmac("sha256", "evidence:{c}", Align\\Crypto::key()));').stdout))
ok({r_[0] for r_ in rows if r_[2] == "huntress_av"} == {"Client " + "AB"[order.index(C1)]}, "clients are lettered in a keyed order, not by name")
ok(q("select detail from audit_log where action='evidence.export' order by id desc limit 1")[0]["detail"].endswith("(anonymized)"), "the export is audited")

# ---- an archived client drops out of the report
q("update clients set is_archived=1 where id=%s", C2)
t = tech.get(B + "/compliance/evidence").text
q("update clients set is_archived=0 where id=%s", C2)
ok(N2 not in t and "1 client compared (2 needed per check)" in t, "an archived client's answers aren't counted")
q("update clients set planning_excluded=1 where id=%s", C2)
t = tech.get(B + "/compliance/evidence").text
q("update clients set planning_excluded=0 where id=%s", C2)
ok(N2 not in t, "...nor one left out of planning")

# ---- where it's linked from; the migration again
ok(f'href="/clients/{C1}/evidence"' in tech.get(B + f"/clients/{C1}/compliance").text and 'href="/compliance/evidence"' in tech.get(B + "/compliance").text, "linked from Compliance")
ok('href="/compliance/evidence"' not in viewer.get(B + "/compliance").text, "...not for viewers")
r = php('(require "' + ROOT + '/db/migrations/076_evidence_checks.php")(); echo "ok";')
ok(r.stdout.strip() == "ok", "migration 076 can run again " + r.stderr[-200:])
done()
