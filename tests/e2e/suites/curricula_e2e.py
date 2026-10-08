"""2.7.2 Huntress SAT through the Curricula API, against the mock Curricula API (tests/mock-server.php): the
integration (client ID and secret as secrets, Test, a refused secret); the token (Client Credentials, read scopes,
asked for once per run) and only GET requests after it, with the JSON:API Accept header and pages followed to
meta.page.lastPage; accounts matched to clients through their Huntress organization (ioOrgId), else by name, and
shown on Client mapping; training (learners counted once across assignments, done only when every one of theirs is
complete; drafts and old assignments left out; learner ids never stored), phishing campaigns (parents, drafts and
unsent left out), summary reports (non-https links dropped); the API's results taking the place of uploads in the
checks, the overview card (no delete for them, upload folded away), the QBR and the health score; reading once a day
unless forced; the Refresh button (techs only, audited); a failed read keeping the earlier results and showing its
error; unlinking and removing the credentials bringing the uploads back; an empty account list changing nothing;
the migration running again."""
import re, json, html as H
from datetime import date, datetime, timedelta, timezone
from lib import *

C1, C2 = 1, 2


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def phpo(code): r = php(code); return (r.stdout + r.stderr).strip()
def cset(**kw): requests.post(M + "/mock/curricula-set", data=json.dumps(kw))
def calls(): return requests.get(M + "/mock/curricula").json().get("calls", [])
def creset_calls(): st = requests.get(M + "/mock/curricula").json(); st["calls"] = []; requests.get(M + "/mock/curricula-reset"); cset(**st)
def iso(days=0): return (datetime.now(timezone.utc) - timedelta(days=days)).strftime("%Y-%m-%dT%H:%M:%S+00:00")
def day(days=0): return (date.today() - timedelta(days=days)).isoformat()
def run(force=False): return phpo(f"echo Align\\Sat\\Curricula::run({'true' if force else 'false'});")
def satc(cid): return json.loads(phpo(f"echo json_encode(Align\\Sat\\Sat::checks({cid}));"))
def source(cid): return phpo(f"echo Align\\Sat\\Sat::source({cid});")
def res(kind, id, **at): return {"type": kind, "id": id, "attributes": at}
cname = lambda cid: q("select name from clients where id=%s", cid)[0]["name"]


def cleanup():
    for t in ["sat_accounts", "sat_reports", "sat_results"]:
        q(f"delete from {t}")
    q("delete from client_links where provider in ('curricula','huntress')")
    q("delete from settings where name like 'curricula\\_%%'")
    requests.get(M + "/mock/curricula-reset")


cleanup()
import atexit
atexit.register(cleanup)
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
tok = lambda s, p: csrf(s, p)

# ---- the migration
ok(q("select count(*) n from information_schema.tables where table_schema=database() and table_name in ('sat_accounts','sat_reports')")[0]["n"] == 2
   and q("select column_default d from information_schema.columns where table_schema=database() and table_name='sat_results' and column_name='source'")[0]["d"].strip("'") == "upload",
   "migration 064 adds the tables and sat_results.source (uploads by default)")
r = php('(require "' + ROOT + '/db/migrations/064_curricula.php")();')
ok(r.returncode == 0 and not (r.stdout + r.stderr).strip(), "the migration can run again: " + (r.stdout + r.stderr)[:200])

# ---- the integration page
ok("Huntress SAT (Curricula)" in st.get(B + "/integrations").text, "the Integrations page has the Curricula card")
t = st.get(B + "/integrations/curricula").text
ok('name="curricula_client_id"' in t and 'name="curricula_client_secret"' in t and "account:read" in t, "the page has the client ID and secret and lists the read scopes")
setting("curricula_base", M + "/curricula")
st.post(B + "/integrations/curricula", data={"_csrf": tok(st, "/integrations/curricula"), "curricula_client_id": "cid", "curricula_client_secret": "wrong"})
r = st.post(B + "/integrations/curricula/test", data={"_csrf": tok(st, "/integrations/curricula")})
ok("refused the client ID and secret (401)" in flash(r.text), "a wrong secret is reported: " + flash(r.text)[:120])
st.post(B + "/integrations/curricula", data={"_csrf": tok(st, "/integrations/curricula"), "curricula_client_secret": "csec"})
rows = {r["name"]: r for r in q("select name, value, is_secret from settings where name in ('curricula_client_id','curricula_client_secret')")}
ok(all(r["is_secret"] == 1 for r in rows.values()) and rows["curricula_client_secret"]["value"] != "csec" and rows["curricula_client_id"]["value"] != "cid", "the ID and secret are stored encrypted")

# ---- the data: client 1's account (through its Huntress organization), client 2's (by name), one unmatched
phpo(f"Align\\Providers\\ClientLinks::set({C1}, 'huntress', '101', 'manual');")
enrolled = lambda ids, inactive=(): [res("learners", f"learnerSecret{i}", firstName=f"Fake{i}", lastName="Person", email=f"secret{i}@example.com", status="inactive" if i in inactive else "active") for i in ids]
learners = lambda ids, done: [res("learnerActivities", f"act-{a}-{i}", learnerId=f"learnerSecret{i}", progress=100 if i in done else 40, completedAt=iso(20) if i in done else None) for a, i in ids]
cset(accounts=[res("accounts", "acc1", name="Different Name In Curricula", status="active", ioOrgId="101", licenses=25),
               res("accounts", "acc2", name=cname(C2), status="active", ioOrgId=None, licenses=5),
               res("accounts", "acc3", name="Nobody Matches This", status="active", licenses=1),
               res("accounts", "acc1b", name="Second account, same organization", status="active", ioOrgId="101", type="paid"),
               res("accounts", "sbx", name="Sandbox", status="active", ioOrgId="101", type="sandbox"),
               res("accounts", "bad id!", name="Bad id")],
     assignments={"acc1": [res("assignments", "as1", name="Annual training", status="completed", startsAt=iso(60), endsAt=iso(30)),
                           res("assignments", "as2", name="Draft", status="draft", startsAt=iso(10)),
                           res("assignments", "as3", name="Old", status="completed", startsAt=iso(500), endsAt=iso(450)),
                           res("assignments", "as4", name="Phishing refresher", status="in-progress", startsAt=iso(45), endsAt=iso(20)),
                           res("assignments", "as5", name="This month's episode", status="in-progress", startsAt=iso(2), endsAt=iso(-28))]},
     # as1: 0-9 and 12 enrolled (12 never started: no activity), 11 inactive; as4 (ended): 0-4, 10, 13-20; as5 just launched
     learners={"as1": enrolled(list(range(10)) + [11, 12], inactive={11}), "as4": enrolled([0, 1, 2, 3, 4, 10] + list(range(13, 21))),
               "as5": enrolled(range(21))},
     activity={"as1": learners([("as1", i) for i in list(range(10)) + [11]], set(range(9)) | {11}),
               "as3": learners([("as3", 50)], set()),
               "as4": learners([("as4", i) for i in [0, 1, 2, 3, 4, 10] + list(range(13, 21))], {0, 1, 2, 3, 4, 10} | set(range(13, 21))),
               "as5": learners([("as5", i) for i in range(21)], set())},
     campaigns={"acc1": [res("phishingCampaigns", "pc1", title="Invoice lure", status="completed", isParent=False, firstSentAt=iso(20), campaignStartsAt=iso(21),
                             attemptStats={"totalRecipients": 20, "sent": 20, "uniqueClicks": 1, "totalClicks": 3, "reported": 8, "compromised": 0}),
                         res("phishingCampaigns", "pc0", title="Over time (parent)", status="in-progress", isParent=True, campaignStartsAt=iso(25), attemptStats={"sent": 99, "uniqueClicks": 50}),
                         res("phishingCampaigns", "pc2", title="Not yet", status="scheduled", isParent=False, campaignStartsAt=iso(1), attemptStats={"sent": 0}),
                         res("phishingCampaigns", "pc4", title="Being built", status="building", isParent=False, campaignStartsAt=iso(3), attemptStats={"sent": 4, "uniqueClicks": 4}),
                         res("phishingCampaigns", "pc3", title="Nothing sent", status="completed", isParent=False, campaignStartsAt=iso(5), attemptStats={"sent": 0})]},
     reports={"acc1": [res("accountSummaryReports", "r1", startDate=day(60), endDate=day(31), pdf="https://reports.example/r1.pdf", generatedAt=iso(30)),
                       res("accountSummaryReports", "r2", startDate=day(30), endDate=day(1), pdf="javascript:alert(1)", generatedAt=iso(0))]},
     page=2)
r = st.post(B + "/integrations/curricula/test", data={"_csrf": tok(st, "/integrations/curricula")})
ok("Connected to Curricula: 6 accounts visible" in flash(r.text), "Test gets a token and counts the accounts, across pages: " + flash(r.text)[:100])

# an earlier upload for client 1 (failing training), which the API's results take the place of
q("insert into sat_results (client_id, kind, source, report, learners, completed, covers_from, covers_to, uploaded_at) values (%s,'training','upload','Assignment: Learner Progress',10,1,%s,%s,NOW())", C1, day(40), day(40))
ok(satc(C1)["sat_training"]["status"] == "fail" and source(C1) == "upload", "before the sync the upload counts")
creset_calls()
out = run()
ok("4 accounts" in out and "2 matched" in out and "2 read" in out, "the sync reads the accounts (an invalid id and a sandbox skipped), matches two and reads both: " + out)
cl = calls()
ok(sum(1 for c in cl if c["path"] == "oauth/token") == 1, "one token for the whole run")
tokc = [c for c in cl if c["path"] == "oauth/token"][0]
ok(tokc["m"] == "POST" and tokc["form"].get("grant_type") == "client_credentials" and tokc["form"].get("scope") == "account:read assignments:read assignments:learner-activity learners:read phishing-campaigns:read",
   "the token is asked for with Client Credentials and only read scopes")
api = [c for c in cl if c["path"] != "oauth/token"]
ok(all(c["m"] == "GET" and c["auth"] and c["accept"] == "application/vnd.api+json" for c in api), "only GET requests with the token and the JSON:API Accept header")
ok(any(c["path"] == "accounts" and c["q"].get("page", {}).get("number") == "2" for c in api), "pages are followed")
ok(any(c["path"] == "accounts/acc1/phishing-campaigns" and c["q"].get("filter", {}).get("startsAfter") for c in api), "campaigns are asked for from a start date")
ok(not any(c["path"] in ("assignments/as5/learners", "assignments/as5/learner-activity", "assignments/as3/learner-activity") for c in api), "the old and the just-launched assignments aren't read")
links = {r["external_id"]: r["client_id"] for r in q("select client_id, external_id from client_links where provider='curricula'")}
ok(links.get("acc1") == C1 and links.get("acc2") == C2 and "acc3" not in links and "acc1b" not in links, f"acc1 linked through the Huntress organization (once: not acc1b too), acc2 by name, acc3 left: {links}")
ok(not q("select 1 from sat_accounts where account_id='sbx'"), "sandbox accounts aren't kept")

# ---- what's stored
rows = q("select * from sat_results where client_id=%s and source='api' order by kind desc", C1)
tr = [r for r in rows if r["kind"] == "training"][0]
ok(tr["learners"] == 20 and tr["completed"] == 18 and tr["assignments"] == 2,
   f"training: the 20 active learners of the two ended assignments once each (one who never started counts, an inactive one doesn't; the draft, old and just-launched ones left out), 18 finished all of theirs: {tr['learners']}/{tr['completed']}/{tr['assignments']}")
ph = [r for r in rows if r["kind"] == "phishing"]
ok(len(ph) == 1 and ph[0]["sent"] == 20 and ph[0]["clicked"] == 1 and ph[0]["reported"] == 8 and ph[0]["report"] == "Curricula: Invoice lure" and ph[0]["covers_to"].isoformat() == day(20),
   "phishing: one row for the sent campaign (parent, scheduled and unsent left out), dated when first sent")
dump = json.dumps([q("select * from sat_results"), q("select * from sat_accounts"), q("select * from sat_reports")], default=str)
ok("learnerSecret" not in dump and "secret0@" not in dump and "Fake" not in dump, "learner ids, names and emails aren't stored")
rep = {r["report_id"]: r["has_pdf"] for r in q("select report_id, has_pdf from sat_reports where account_id='acc1'")}
ok(rep == {"r1": 1, "r2": 1} and "reports.example" not in dump, f"summary reports kept by date, not their temporary links: {rep}")
ok(q("select io_org_id, licenses from sat_accounts where account_id='acc1'")[0] == {"io_org_id": "101", "licenses": 25}, "the account's Huntress organization and licenses are kept")

# ---- the checks: the API's results replace the uploads
c = satc(C1)
ok(source(C1) == "api" and c["sat_training"]["status"] == "pass" and "18 of 20 learners" in c["sat_training"]["detail"], "training from Curricula passes (the failing upload is ignored): " + c["sat_training"]["detail"])
ok(c["sat_phishing"]["status"] == "pass" and "Click rate 5%" in c["sat_phishing"]["detail"] and "40% reported" in c["sat_phishing"]["detail"], "phishing from Curricula: " + c["sat_phishing"]["detail"])
ok(source(C2) == "upload" and satc(C2)["sat_training"]["status"] == "unknown", "an account with nothing to report leaves the client on uploads")
t = st.get(B + f"/clients/{C1}").text
card = text(t.split('id="sat"')[1]) if 'id="sat"' in t else ""
ok("From Curricula account Different Name In Curricula" in card and "Curricula: Invoice lure" in card and "18/20 done" in card, "the overview card says where the results come from and lists them")
ok(f'href="/clients/{C1}/sat/report/r1"' in t and "reports.example" not in t, "the summary reports link through Align")
r = tech.get(B + f"/clients/{C1}/sat/report/r1", allow_redirects=False)
ok(r.status_code == 302 and r.headers.get("Location") == "https://reports.example/r1.pdf", "opening one asks Curricula for its current link: " + str(r.headers.get("Location")))
r = tech.get(B + f"/clients/{C1}/sat/report/r2")
ok("isn't available" in flash(r.text), "a link that isn't https isn't followed")
r = tech.get(B + f"/clients/{C2}/sat/report/r1")
ok("isn't available" in flash(r.text), "another client's report can't be opened through this client's address")
r = viewer.get(B + f"/clients/{C1}/sat/report/r1", allow_redirects=False)
ok(r.headers.get("Location") != "https://reports.example/r1.pdf" and f'/sat/report/r1"' not in viewer.get(B + f"/clients/{C1}").text, "viewers can't open reports")
sat_html = t.split('id="sat"')[1]
ok("/sat/" + str(tr["id"]) + "/delete" not in sat_html and "<details" in sat_html and "/sat/refresh" in sat_html, "no delete for API results, the upload form folded away, a Refresh button")
r = tech.post(B + f"/clients/{C1}/sat/{tr['id']}/delete", data={"_csrf": tok(tech, f"/clients/{C1}")})
ok(q("select 1 from sat_results where id=%s", tr["id"]), "API results can't be deleted through the upload's delete")
t = text(st.get(B + f"/clients/{C1}/report/qbr").text)
ok("18 of 20 people" in t, "the QBR uses the Curricula figures")
ok("Curricula" in st.get(B + "/mapping").text and "Nobody Matches This" in st.get(B + "/mapping").text, "Client mapping has a Curricula column offering the unlinked account")

# ---- once a day unless forced; Refresh
requests.get(M + "/mock/curricula-reset"); cset(**{"accounts": [res("accounts", "acc1", name="Different Name In Curricula", status="active", ioOrgId="101", licenses=25),
                                                                  res("accounts", "acc2", name=cname(C2), status="active", licenses=5), res("accounts", "acc3", name="Nobody Matches This", status="active", licenses=1)]})
out = run()
ok("0 read" in out and not any("assignments" in c["path"] for c in calls()), "read again only after a day: " + out)
r = viewer.post(B + f"/clients/{C1}/sat/refresh", data={"_csrf": tok(viewer, f"/clients/{C1}")})
ok(not any("assignments" in c["path"] for c in calls()), "viewers can't refresh")
r = tech.post(B + f"/clients/{C1}/sat/refresh", data={"_csrf": tok(tech, f"/clients/{C1}")})
ok("Read the training and phishing results from Curricula" in flash(r.text) and q("select 1 from audit_log where action='sat.refresh'"), "Refresh reads the client now (audited): " + flash(r.text)[:100])
ok(not q("select 1 from sat_results where client_id=%s and source='api'", C1) and source(C1) == "upload",
   "...and an account now reporting nothing leaves the client on its uploads")

# ---- a failed read keeps the earlier results and shows the error
cset(assignments={"acc1": [res("assignments", "as1", name="Annual training", status="in-progress", startsAt=iso(60))]}, learners={"as1": enrolled(range(4))},
     activity={"as1": learners([("as1", i) for i in range(5)], {0, 1, 2, 3, 4})})
run(True)
ok(q("select learners from sat_results where client_id=%s and source='api'", C1)[0]["learners"] == 4, "a forced run reads every linked account (a running assignment counts while none has ended)")
cset(fail={"assignments/as1/learners": 403})
run(True)
ok(q("select learners from sat_results where client_id=%s and source='api'", C1)[0]["learners"] == 5, "without learners:read, the learners with activity are counted")
cset(fail={"accounts/acc1/phishing-campaigns": 403})
out = run(True)
ok("1 failed" in out and "isn't allowed" in (q("select error from sat_accounts where account_id='acc1'")[0]["error"] or ""), "a refused read is counted and its error saved: " + out)
ok(q("select learners from sat_results where client_id=%s and source='api'", C1)[0]["learners"] == 5, "...keeping the earlier results")
ok("Last read failed" in text(st.get(B + f"/clients/{C1}").text), "...and the card shows the error")
cset(fail={})
run(True)
ok(q("select error from sat_accounts where account_id='acc1'")[0]["error"] is None, "the next good read clears the error")

# ---- an account that isn't active any more: its results go
cset(accounts=[res("accounts", "acc1", name="Different Name In Curricula", status="inactive", ioOrgId="101"), res("accounts", "acc2", name=cname(C2), status="active"),
               res("accounts", "acc3", name="Nobody Matches This", status="active")])
run()
ok(source(C1) == "upload" and not q("select 1 from sat_results where client_id=%s and source='api'", C1), "an inactive account's results go and the uploads count again")
cset(accounts=[res("accounts", "acc1", name="Different Name In Curricula", status="active", ioOrgId="101"), res("accounts", "acc2", name=cname(C2), status="active"),
               res("accounts", "acc3", name="Nobody Matches This", status="active")])
run(True)

# ---- an empty account list changes nothing
cset(accounts=[])
ok("returned no accounts" in run(), "an empty account list is refused")
ok(q("select count(*) n from sat_accounts")[0]["n"] == 3 and q("select 1 from client_links where provider='curricula' and external_id='acc1'"), "...and nothing was changed")

# ---- unlinking, then removing the credentials, brings the uploads back
cset(accounts=[res("accounts", "acc1", name="Different Name In Curricula", status="active", ioOrgId="101", licenses=25), res("accounts", "acc2", name=cname(C2), status="active", licenses=5)])
phpo(f"Align\\Providers\\ClientLinks::set({C1}, 'curricula', null, 'manual');")  # "kept unlinked" on Client mapping
run()
ok(not q("select 1 from sat_results where client_id=%s and source='api'", C1) and source(C1) == "upload" and satc(C1)["sat_training"]["status"] == "fail",
   "unlinked: its API results go and the upload counts again")
phpo(f"Align\\Providers\\ClientLinks::set({C1}, 'curricula', 'acc1', 'manual');")
run(True)
ok(source(C1) == "api", "linked again: the API counts")
q("delete from settings where name like 'curricula\\_client%%'")
ok(source(C1) == "upload", "without the credentials the uploads count")
done()
