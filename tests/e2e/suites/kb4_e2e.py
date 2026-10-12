"""2.14.0 KnowBe4 (beta, read-only), per client: the Integrations switch; the form on a client's training card (admins
only, techs and the switched-off card don't get it); a token checked before it's saved (encrypted, never shown) and
refused ones explained; the read: account name, subscription, seats and risk score, training completion (enrollments
of the last 13 months that are due, policies left out, people counted once, paged), phishing tests (delivered, clicked,
reported, data entered; unsent and old ones left out, counts clamped); the results in the checks instead of uploads and
Curricula; a just-launched campaign counted only while nothing is due; a 429, an HTML page or a non-list is an error
that keeps the last results; Refresh; Remove takes the results with it; audited, with a security alert."""
import re, json, subprocess, atexit, requests, html as H
from lib import *


def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def align(*a): return subprocess.run(["php", ALIGN, *a], env=ENV, capture_output=True, text=True)
def card(s): return text(s.get(B + f"/clients/{C1}").text)


TOKEN = "kb4-token-cedar-0123456789abcdef"
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
C1 = q("select id from clients where name='Cedar Ridge Family Dental, Inc.'")[0]["id"]


def cleanup():
    mock("/mock/kb4-set", {})
    q("delete from client_knowbe4")
    q("delete from sat_results where provider='knowbe4'")
    q("delete from settings where name in ('knowbe4_enabled','kb4_base')")


atexit.register(cleanup)
cleanup()
setting("kb4_base", M + "/kb4")  # the test address (only honoured with allow_insecure_integrations)

# ---- switched off: no form; the Integrations card offers the switch
ok("Read this client's results from its KnowBe4 console" not in card(st), "no KnowBe4 form on the card while KnowBe4 is off")
t = text(st.get(B + "/integrations").text)
ok("KnowBe4" in t and "Beta. Security awareness training completion and phishing test results" in t, "the Integrations page lists KnowBe4, marked beta")
p = st.get(B + "/integrations/knowbe4").text
ok('name="knowbe4_enabled"' in p and "Reporting API" in p and not errs(p), "its page has the switch and per-client setup steps")
r = st.post(B + f"/clients/{C1}/sat/knowbe4", data={"_csrf": csrf(st, f"/clients/{C1}"), "region": "us", "token": TOKEN})
ok("Switch KnowBe4 on" in flash(r.text) and not q("select 1 from client_knowbe4"), "a token can't be saved while KnowBe4 is off")
F = form(st, "/integrations/knowbe4", 'action="/integrations/knowbe4"')
st.post(B + "/integrations/knowbe4", data={**F, "knowbe4_enabled": "1", "knowbe4_enabled_present": "1"})
ok(q("select value from settings where name='knowbe4_enabled'")[0]["value"] == "1", "KnowBe4 switches on")

# ---- the form: admins only
ok("Read this client's results from its KnowBe4 console" in card(st), "admins get the KnowBe4 form on the client's training card")
ok("Read this client's results from its KnowBe4 console" not in card(tech), "techs don't")
r = tech.post(B + f"/clients/{C1}/sat/knowbe4", data={"_csrf": csrf(tech, f"/clients/{C1}"), "region": "us", "token": TOKEN}, allow_redirects=False)
ok(r.status_code in (302, 403) and not q("select 1 from client_knowbe4"), "a tech can't save a token")

# ---- refused tokens and regions aren't saved
for data, why in [({"region": "xx", "token": TOKEN}, "Choose the KnowBe4 region"), ({"region": "us", "token": "short"}, "doesn't look like a KnowBe4"),
                  ({"region": "eu", "token": "kb4-token-wrong-0123456789abcdef"}, "refused the token")]:
    r = st.post(B + f"/clients/{C1}/sat/knowbe4", data={"_csrf": csrf(st, f"/clients/{C1}"), **data})
    ok(why in flash(r.text) and "Nothing was saved" in flash(r.text) and not q("select 1 from client_knowbe4"), f"refused ({why}): " + flash(r.text)[:120])

# ---- connect: checked, saved encrypted, read straight away
mock("/mock/kb4-set", {})
r = st.post(B + f"/clients/{C1}/sat/knowbe4", data={"_csrf": csrf(st, f"/clients/{C1}"), "region": "us", "token": TOKEN})
ok("Connected to KnowBe4 account Cedar Ridge Dental and read its results" in flash(r.text), "the token is checked, saved and read: " + flash(r.text)[:160])
row = q("select * from client_knowbe4 where client_id=%s", C1)[0]
ok(row["token_enc"].startswith("v1:") and TOKEN not in row["token_enc"] and TOKEN not in st.get(B + f"/clients/{C1}").text, "the token is stored encrypted and never shown")
ok(row["account_name"] == "Cedar Ridge Dental" and row["subscription"] == "Platinum" and row["seats"] == 25 and float(row["risk_score"]) == 31.46 and row["synced_at"] and row["error"] is None,
   "the account's name (control characters removed), subscription, seats and risk score are kept")
tr = q("select * from sat_results where client_id=%s and provider='knowbe4' and kind='training'", C1)
ok(len(tr) == 1 and tr[0]["learners"] == 4 and tr[0]["completed"] == 3 and tr[0]["assignments"] == 1 and tr[0]["source"] == "api",
   "training: 3 of 4 people done in the ended campaign (policy and old enrollments left out, paged): %s" % ({k: tr[0][k] for k in ("learners", "completed", "assignments")} if tr else None))
ph = {r["report"]: r for r in q("select * from sat_results where client_id=%s and provider='knowbe4' and kind='phishing'", C1)}
ok(set(ph) == {"KnowBe4: Q3 phishing test", "KnowBe4: Q2 phishing test"}, "phishing: the sent tests of the period (unsent and old ones left out): %s" % sorted(ph))
q3 = ph.get("KnowBe4: Q3 phishing test")
ok(q3 and q3["sent"] == 20 and q3["clicked"] == 2 and q3["reported"] == 6 and q3["compromised"] == 1, "a test's delivered, clicked, reported and data-entered counts")
ok(ph.get("KnowBe4: Q2 phishing test") and ph["KnowBe4: Q2 phishing test"]["clicked"] == 20, "a click count above the emails sent is clamped")
log = requests.get(M + "/mock/kb4-log").json()
ok(any("enrollments?page=3" in l for l in log) and all(l.startswith("GET ") for l in log), "lists are paged; only reads are sent")
c = card(st)
ok("From KnowBe4 account Cedar Ridge Dental" in c and "25 seats" in c and "risk score 31.5" in c and "3/4 done (75%)" in c, "the card shows the account and the results")
checks = json.loads(php(f'echo json_encode([Align\\Sat\\Sat::source({C1}), Align\\Sat\\Sat::checks({C1})["sat_training"]["status"]]);').stdout)
ok(checks[0] == "api", "the checks use KnowBe4's results instead of uploads: %s" % checks)
ok(q("select count(*) n from audit_log where action='sat.knowbe4' and detail like %s", "%token saved%")[0]["n"] >= 1, "saving the token is audited")

ok("Replace the KnowBe4 token" in card(st) and "Replace the KnowBe4 token" not in card(tech), "admins can replace the token; techs can't")
audit = " ".join(r["detail"] or "" for r in q("select detail from audit_log where action like 'sat.%%' or action like 'integration.%%'"))
ok(TOKEN not in audit and TOKEN not in (q("select error from client_knowbe4")[0]["error"] or ""), "the token is never in the audit log or an error")
# rows another provider left behind never show or count for a KnowBe4 client
q("insert into sat_results (client_id, kind, source, provider, report, sent, clicked, campaigns, covers_from, covers_to) values (%s,'phishing','api','curricula','Curricula: stale',100,90,1,CURDATE(),CURDATE())", C1)
ok("Curricula: stale" not in card(st) and "Click rate 50" not in card(st), "a Curricula row left behind isn't shown or counted while the client reads KnowBe4")
q("delete from sat_results where report='Curricula: stale'")

# ---- a campaign still running counts everyone in it, finished or not (not only those who finished)
mock("/mock/kb4-set", {"mode": "running_only"})
st.post(B + f"/clients/{C1}/sat/refresh", data={"_csrf": csrf(st, f"/clients/{C1}")})
tr = q("select * from sat_results where client_id=%s and provider='knowbe4' and kind='training'", C1)
ok(tr and tr[0]["learners"] == 2 and tr[0]["completed"] == 1, "a running campaign: 1 of 2 done, the campaign found by name when its id is missing: %s" % ({k: tr[0][k] for k in ("learners", "completed")} if tr else None))

# ---- a just-launched campaign doesn't count while something is due; Refresh reads again
mock("/mock/kb4-set", {"new_campaign": True})
r = st.post(B + f"/clients/{C1}/sat/refresh", data={"_csrf": csrf(st, f"/clients/{C1}")})
tr = q("select * from sat_results where client_id=%s and provider='knowbe4' and kind='training'", C1)
ok("Read the training and phishing results from KnowBe4" in flash(r.text) and tr[0]["learners"] == 4 and tr[0]["completed"] == 3, "Refresh reads again; a campaign launched yesterday isn't counted yet")

# ---- errors keep the last results
for mode, why in [("throttle", "request limit"), ("html", "something other than"), ("object", "something other than a list"), ("down", "couldn't be read")]:
    mock("/mock/kb4-set", {"mode": mode})
    q("update client_knowbe4 set synced_at = NULL where client_id=%s", C1)
    align("sync", "--quiet")
    run = json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
    err = q("select error from client_knowbe4 where client_id=%s", C1)[0]["error"] or ""
    ok("1 failed" in str(run.get("KnowBe4", "")) and why in err and len(q("select 1 from sat_results where client_id=%s and provider='knowbe4'", C1)) == 3,
       f"a {mode} answer is an error and the last results stay: " + err[:100])
mock("/mock/kb4-set", {})
q("update client_knowbe4 set synced_at = NULL where client_id=%s", C1)
align("sync", "--quiet")
ok(q("select error from client_knowbe4 where client_id=%s", C1)[0]["error"] is None and "1 read" in json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"]).get("KnowBe4", ""),
   "the next good sync reads it and clears the error")
align("sync", "--quiet")
ok("1 up to date" in json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"]).get("KnowBe4", ""), "read once a day")

# ---- remove (admins only)
r = tech.post(B + f"/clients/{C1}/sat/knowbe4/remove", data={"_csrf": csrf(tech, f"/clients/{C1}")}, allow_redirects=False)
ok(r.status_code in (302, 403) and q("select 1 from client_knowbe4"), "a tech can't remove it")
r = st.post(B + f"/clients/{C1}/sat/knowbe4/remove", data={"_csrf": csrf(st, f"/clients/{C1}")})
ok("KnowBe4 removed" in flash(r.text) and not q("select 1 from client_knowbe4") and not q("select 1 from sat_results where provider='knowbe4'"), "Remove takes the token and its results")
ok(json.loads(php(f'echo json_encode(Align\\Sat\\Sat::source({C1}));').stdout) == "upload", "the client's uploads count again")
done()
