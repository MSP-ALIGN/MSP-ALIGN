"""2.40.0 Guided compliance setup:
- adding a framework opens its setup (7 steps); viewers can read a step but change nothing;
- scope: "No" marks the controls it rules out Not applicable (by their main tag), never the ones that still apply
  (NEVER_SCOPED), and "Yes" puts back only what the scope marked; answers are shared between frameworks;
- evidence: "Use these results" answers only Not assessed controls from the automatic checks;
- questions: one per topic covers the framework's controls with that main tag; scope N/A stays; an answer in one
  framework shows as "Already answered in ..." in another's setup;
- questions for the client: a private link (link only here), the client's page and answers, the link closing,
  answers waiting for staff, accept applies the topic with the client's name, decline changes nothing; the
  portal lists an open link for a portal user with the same email; unknown links show the expired page;
- gaps: a No answer becomes one proposed roadmap project (category compliance), not twice;
- finish: recurring tasks added for the framework's topics, the setup marked finished, the next review saved;
- recurring tasks: marking done needs a note or the client's own document, moves the due date, is logged; changes
  are checked; due tasks show on the To do list and the roadmap.
Everything is made up."""
import re
from lib import *

C = 3  # Harbor Point Law Group LLP
PW = "Quartz-Lantern-Field-31"
SEC = "ONSWG4TFORZWK3DPONSWG4TFORZWK3DP"
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
MSB = q("select id from compliance_frameworks where slug = 'msp-baseline'")[0]["id"]
CI = q("select id from compliance_frameworks where slug = 'cyber-insurance'")[0]["id"]
EMAIL = "pat240@hplg.example"


def st(fw, ref):
    r = q("select s.status, s.notes from compliance_controls c left join client_control_status s on s.control_id = c.id and s.client_id = %s where c.framework_id = %s and c.ref = %s", C, fw, ref)
    return (r[0]["status"] or "not_assessed", r[0]["notes"]) if r else (None, None)
def main_tag(fw, tag): return [r["ref"] for r in q("select ref from compliance_controls where framework_id = %s and substring_index(tags, ',', 1) = %s", fw, tag)]
def page(fw, step, **kw): return tech.get(B + f"/clients/{C}/compliance/{fw}/setup?step={step}" + "".join(f"&{k}={v}" for k, v in kw.items()))
def post(fw, action, data, who=None):
    who = who or tech
    return who.post(B + f"/clients/{C}/compliance/{fw}/setup/{action}", data={"_csrf": csrf(who, f"/clients/{C}/compliance/{fw}/setup?step=1"), **data})


def cleanup():
    for t in ["client_question_requests", "compliance_tasks", "compliance_setups", "client_scope_answers"]:
        q(f"delete from {t} where client_id = %s", C)
    q("delete s from client_control_status s join compliance_controls c on c.id = s.control_id where s.client_id = %s and c.framework_id in (%s, %s)", C, MSB, CI)
    q("delete from client_frameworks where client_id = %s and framework_id in (%s, %s)", C, MSB, CI)
    q("delete from roadmap_items where client_id = %s and title like '%%(MSP Security Baseline)'", C)
    q("delete from contacts where email = %s", EMAIL)
    q("delete from portal_users where email = %s", EMAIL)
    q("delete from documents where client_id = %s and title like 'Zz240 %%'", C)
    q("delete from login_attempts")


cleanup()
SAVED = q("select * from client_frameworks where client_id = %s", C)
try:
    ok(all(q("select count(*) n from information_schema.tables where table_schema = database() and table_name = %s", t)[0]["n"] == 1
           for t in ["compliance_setups", "client_scope_answers", "compliance_tasks", "compliance_task_log", "client_question_requests", "client_questions"]), "migration 104 adds the setup tables")

    # ---- adding a framework opens its setup
    r = tech.post(B + f"/clients/{C}/compliance", data={"_csrf": csrf(tech, f"/clients/{C}/compliance"), "framework_id": str(MSB)})
    ok(r.url.endswith(f"/clients/{C}/compliance/{MSB}/setup") and "guided setup" in flash(r.text) and not errs(r.text), f"adding a framework opens the guided setup: {r.url}")
    ok(q("select step from compliance_setups where client_id = %s and framework_id = %s", C, MSB)[0]["step"] == 1, "the setup is started at step 1")
    for n in range(1, 8):
        t = page(MSB, n).text
        ok(not errs(t) and f'aria-current="step"' in t, f"step {n} opens")
    ok(q("select step from compliance_setups where client_id = %s and framework_id = %s", C, MSB)[0]["step"] == 1, "(review) opening later steps doesn't move the progress on")
    tv = viewer.get(B + f"/clients/{C}/compliance/{MSB}/setup?step=5").text
    ok(not errs(tv) and 'name="a[' not in tv and "Send ticked questions" not in tv, "a viewer reads a step without answer buttons")
    r = post(MSB, "scope", {"scope[wireless]": "no"}, who=viewer)
    ok(not q("select 1 from client_scope_answers where client_id = %s", C), "a viewer can't save anything")
    ok("Continue setup" in tech.get(B + f"/clients/{C}").text and "Continue setup" in tech.get(B + f"/clients/{C}/compliance").text
       and "Continue setup" in tech.get(B + f"/clients/{C}/compliance/{MSB}").text, "Continue setup shows on the overview, the compliance page and the checklist")

    # ---- scope
    t = page(MSB, 1).text
    ok('data-scope="wireless"' in t and 'data-scope="pci_provider"' not in t, "the PCI provider question is only asked for PCI DSS")
    wifi = main_tag(MSB, "net_wireless")
    pay = main_tag(MSB, "pay_verification")
    r = post(MSB, "scope", {"scope[wireless]": "no", "scope[payments]": "yes"})
    ok(wifi and all(st(MSB, x)[0] == "na" and "scope: wireless" in st(MSB, x)[1] for x in wifi) and all(st(MSB, x)[0] == "not_assessed" for x in pay),
       f"No marks the Wi-Fi items Not applicable, Yes leaves the payment items: {wifi} {pay}")
    ok(r.url.endswith("step=2") and "Not applicable" in flash(r.text), "saving the scope goes on to the evidence step")
    post(MSB, "scope", {"scope[wireless]": "yes"})
    ok(all(st(MSB, x)[0] == "not_assessed" for x in wifi), "changing it to Yes puts them back")
    # a control answered by hand isn't touched by the scope
    q("insert into client_control_status (client_id, control_id, status, notes) select %s, id, 'met', 'Checked on site' from compliance_controls where framework_id = %s and ref = %s on duplicate key update status = 'met', notes = 'Checked on site'", C, MSB, wifi[0])
    post(MSB, "scope", {"scope[wireless]": "no"})
    ok(st(MSB, wifi[0]) == ("met", "Checked on site"), "a control already answered keeps its answer")
    q("delete s from client_control_status s join compliance_controls c on c.id = s.control_id where s.client_id = %s and c.framework_id = %s and c.ref = %s", C, MSB, wifi[0])
    post(MSB, "scope", {"scope[wireless]": "yes"})
    r = php("echo json_encode(Align\\Compliance\\Setup::NEVER_SCOPED);")
    ok(all(x in r.stdout for x in ['"11.2.1"', '"A.5.34"', '"6.4.3"', '"PI1.2"', '"AC.L2-3.1.18"']), f"items that still apply are never ruled out by scope: {r.stdout[:160]}")
    r = php("echo json_encode([array_keys(Align\\Compliance\\Setup::scopeFor('ccpa-cpra')), array_keys(Align\\Compliance\\Setup::scopeFor('soc2-tsc'))]);")
    ok('consumer_data' not in r.stdout.split('],')[0] and 'consumer_data' in r.stdout.split('],')[-1], f"(review) the consumer data question is never asked for the CCPA: {r.stdout}")

    # ---- evidence: only Not assessed controls change
    t = page(MSB, 2).text
    ok("Use these results" in t or "no automatically checked items" in t, "the evidence step lists the automatic checks")
    auto = q("select ref from compliance_controls where framework_id = %s and auto_check is not null and auto_check <> '' order by sort limit 1", MSB)
    if auto:
        q("insert into client_control_status (client_id, control_id, status, notes) select %s, id, 'partial', 'Mine' from compliance_controls where framework_id = %s and ref = %s on duplicate key update status = 'partial', notes = 'Mine'", C, MSB, auto[0]["ref"])
    post(MSB, "evidence", {})
    ok(not auto or st(MSB, auto[0]["ref"]) == ("partial", "Mine"), "using the results leaves an answered control alone")

    # ---- questions
    t = page(MSB, 5).text
    ok(1 <= t.count('data-topic="') <= 10 and re.search(r"page 1 of \d+", t), "the questions are grouped by topic, ten to a page")
    pages = int(re.search(r"page \d+ of (\d+)", t).group(1))
    found = None
    for pg in range(1, pages + 1):
        if 'data-topic="pay_verification"' in page(MSB, 5, page=pg).text:
            found = pg
    ok(found is not None, "the payment verification topic is asked")
    r = post(MSB, "answer", {"page": str(found), "a[pay_verification]": "no", "n[pay_verification]": "Zz240 no callback rule", "go": "stay"})
    ok(all(st(MSB, x)[0] == "not_met" and "No: Zz240 no callback rule (guided setup)" in st(MSB, x)[1] for x in pay) and "answered" in flash(r.text), "one answer sets every control in the topic, with a dated note")
    ok(q("select step from compliance_setups where client_id = %s and framework_id = %s", C, MSB)[0]["step"] >= 5, "answering moves the progress on")
    post(MSB, "scope", {"scope[wireless]": "no"})
    wtopic = "net_wireless"
    post(MSB, "answer", {"page": "1", f"a[{wtopic}]": "yes", "go": "stay"})
    ok(all(st(MSB, x)[0] == "na" for x in wifi), "a Yes doesn't override what the scope ruled out")
    post(MSB, "answer", {"page": "1", "a[bogus_tag]": "yes", "a[pay_verification]": "maybe", "go": "stay"})
    ok(all(st(MSB, x)[0] == "not_met" for x in pay), "unknown topics and answers change nothing")

    # ---- the same topic in another framework
    tech.post(B + f"/clients/{C}/compliance", data={"_csrf": csrf(tech, f"/clients/{C}/compliance"), "framework_id": str(CI)})
    cpay = main_tag(CI, "pay_verification")
    hit = ""
    for pg in range(1, 6):
        t = page(CI, 5, page=pg).text
        m = re.search(r'data-topic="pay_verification".*?(?=data-topic=|id="client-questions")', t, re.S)
        if m:
            hit = m.group(0)
            break
    ok(cpay and "Already answered in" in hit and "MSP Security Baseline" in hit and 'data-use-answer="no"' in hit, "Cyber Insurance's setup shows the answer given in the MSP baseline")
    ok(st(CI, cpay[0])[0] == "not_assessed", "but doesn't apply it until it's saved")
    # (review) scope answers apply to every framework the client has
    post(MSB, "scope", {"scope[payments]": "no"})
    ok(st(CI, cpay[0])[0] == "na" and all(st(MSB, x)[0] == "not_met" for x in pay), "a scope answer given in one setup applies to the client's other frameworks too (answered items kept)")
    post(MSB, "scope", {"scope[payments]": "yes"})
    ok(st(CI, cpay[0])[0] == "not_assessed", "and changing it puts them back there too")

    # ---- questions for the client: link only
    q("insert into contacts (client_id, name, email) values (%s, 'Pat Rivera', %s)", C, EMAIL)
    contact = q("select id from contacts where email = %s", EMAIL)[0]["id"]
    T2 = [r["t"] for r in q("select distinct substring_index(tags, ',', 1) t from compliance_controls where framework_id = %s and tags is not null and tags <> '' order by t", MSB)
          if r["t"] not in ("pay_verification", "net_wireless")][0]
    r = post(MSB, "ask", {"page": "1", "contact_id": "", "name": "Anyone", "email": "anyone@else.example", "topics[]": [T2], "action": "link"})
    ok("contacts" in flash(r.text) and not q("select 1 from client_question_requests where email = 'anyone@else.example'"), "(review) questions only go to the client's own contacts")
    r = post(MSB, "ask", {"page": "1", "contact_id": str(contact), "topics[]": [T2, "bogus", "pay_verification"], "message": "Thanks <b>Pat</b>", "action": "link"})
    m = re.search(r"(https?://\S+/portal/answers/[A-Za-z0-9_-]+)", flash(r.text))
    ok(m, f"Create link only shows the link once: {flash(r.text)[:160]}")
    link = m.group(1) if m else ""
    req = q("select * from client_question_requests where client_id = %s order by id desc limit 1", C)[0]
    qs = q("select * from client_questions where request_id = %s order by id", req["id"])
    ok(req["email"] == EMAIL and req["token_hash"] and len(req["token_hash"]) == 64 and link.split("/")[-1] not in str(req), "the request keeps only a hash of the link")
    ok([x["topic"] for x in qs] == [T2, "pay_verification"], f"only the framework's real topics are sent: {[x['topic'] for x in qs]}")
    anon = requests.Session()
    path = "/portal/answers/" + link.split("/")[-1]
    t = anon.get(B + path).text
    ok(not errs(t) and "A few security questions" in t and "Thanks &lt;b&gt;Pat&lt;/b&gt;" in t and qs[0]["question"][:30] in H.unescape(t), "the client's page shows the questions, the message escaped")
    ok(anon.get(B + "/portal/answers/" + "x" * 43).status_code == 404, "an unknown link shows the expired page")
    # the portal lists it for a portal user with the same email
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{C},"email"=>"{EMAIL}","name"=>"Pat Rivera","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,"can_documents"=>1,'
        f'"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    ps = requests.Session()
    rr = ps.post(B + "/portal/login", data={"_csrf": csrf(ps, "/portal/login"), "email": EMAIL, "password": PW})
    if rr.url.endswith("/portal/login/2fa"):
        ps.post(B + "/portal/login/2fa", data={"_csrf": csrf(ps, "/portal/login/2fa"), "code": totp(SEC)})
    home = ps.get(B + "/portal").text
    ok("Security questions for you" in home and path not in home and f"/portal/answers-open/{req['id']}" in home, "the portal lists the questions for that contact, without the link itself")
    rr = ps.get(B + f"/portal/answers-open/{req['id']}", allow_redirects=False)
    ok(rr.status_code in (302, 303) and rr.headers.get("Location", "").endswith(path), "and opens the link on click")
    # answers: another request's question id is ignored
    q("insert into client_question_requests (client_id, framework_id, name, email, status) values (%s, %s, 'X', 'x@x.example', 'open')", C, MSB)
    oid = q("select id from client_question_requests where email = 'x@x.example'")[0]["id"]
    q("insert into client_questions (request_id, client_id, framework_id, topic, question) values (%s, %s, %s, %s, 'Other?')", oid, C, MSB, T2)
    otherq = q("select id from client_questions where request_id = %s", oid)[0]["id"]
    data = {"_csrf": csrf(anon, path), f"a[{qs[0]['id']}]": "yes", f"n[{qs[0]['id']}]": "<script>x</script> visitors sign in", f"a[{otherq}]": "no"}
    r = anon.post(B + path, data=data)
    ok("Thank you" in r.text and q("select answer from client_questions where id = %s", otherq)[0]["answer"] is None, "answers are saved; another request's question isn't")
    t = anon.get(B + path).text
    ok(q("select status from client_question_requests where id = %s", req["id"])[0]["status"] == "open" and qs[1]["question"][:30] in H.unescape(t)
       and qs[0]["question"][:30] not in H.unescape(t), "(review) answered in part, the link stays open with the rest")
    anon.post(B + path, data={"_csrf": csrf(anon, path), f"a[{qs[1]['id']}]": "unsure"})
    ok(q("select status, token_enc from client_question_requests where id = %s", req["id"])[0] == {"status": "answered", "token_enc": None}
       and anon.get(B + path).status_code == 404, "the link closes once everything is answered")
    ok(all(st(MSB, x)[0] == "not_assessed" for x in main_tag(MSB, T2)), "nothing changes before staff accept")
    t = page(MSB, 5).text
    ok("client answer" in t.lower() and "&lt;script&gt;x&lt;/script&gt; visitors sign in" in t, "the answers wait on the setup, escaped")
    ok("compliance answer" in tech.get(B + "/todo").text, "and on the To do list")
    r = post(MSB, "review", {"question_id": str(qs[0]["id"]), "accept": "1", "page": "1"})
    refs = main_tag(MSB, qs[0]["topic"])
    ok(refs and all(st(MSB, x)[0] == "met" and "Client answer (Pat Rivera" in (st(MSB, x)[1] or "") for x in refs), "accepting applies the topic with who answered")
    if len(qs) > 1:
        before = [st(MSB, x) for x in main_tag(MSB, qs[1]["topic"])]
        post(MSB, "review", {"question_id": str(qs[1]["id"]), "page": "1"})
        ok([st(MSB, x) for x in main_tag(MSB, qs[1]["topic"])] == before and q("select review from client_questions where id = %s", qs[1]["id"])[0]["review"] == "declined", "declining changes nothing")
    r = post(MSB, "review", {"question_id": str(qs[0]["id"]), "accept": "1", "page": "1"})
    ok("already reviewed" in flash(r.text), "an answer is reviewed once")
    q("delete from client_question_requests where id = %s", oid)

    # ---- gaps to the roadmap
    t = page(MSB, 6).text
    ok('data-gap="pay_verification"' in t, "the No answer is listed as a gap")
    quarter = re.search(r'name="g\[pay_verification\]".*?<option value="(\d{4}-\d{2}-\d{2})"', t, re.S).group(1)
    post(MSB, "gaps", {"g[pay_verification]": quarter, "g[bogus]": quarter, "go": "stay"})
    post(MSB, "gaps", {"g[pay_verification]": quarter, "go": "stay"})
    items = q("select * from roadmap_items where client_id = %s and title like '%%(MSP Security Baseline)'", C)
    ok(len(items) == 1 and items[0]["category"] == "compliance" and items[0]["status"] == "proposed" and str(items[0]["target_quarter"]) == quarter, f"one proposed project, not two: {[i['title'] for i in items]}")
    ok("Payment changes" in items[0]["description"] and "?" not in items[0]["description"], f"its description names the topic, not the question: {items[0]['description']}")
    ok("On the roadmap" in page(MSB, 6).text, "the gap then links to it")

    # ---- finish
    kinds = q("select count(*) n from compliance_tasks where client_id = %s", C)[0]["n"]
    r = post(MSB, "finish", {"next_review": "2027-10-01"})
    tasks = q("select * from compliance_tasks where client_id = %s", C)
    ok("setup finished" in flash(r.text) and tasks and kinds == 0, f"finishing adds the recurring tasks: {[t['kind'] for t in tasks]}")
    ok(q("select finished_at from compliance_setups where client_id = %s and framework_id = %s", C, MSB)[0]["finished_at"] is not None
       and str(q("select next_review from client_frameworks where client_id = %s and framework_id = %s", C, MSB)[0]["next_review"]) == "2027-10-01", "the setup is finished and the next review saved")
    post(CI, "finish", {})
    ok(q("select count(*) n from compliance_tasks where client_id = %s", C)[0]["n"] >= len(tasks)
       and len({t["kind"] for t in q("select kind from compliance_tasks where client_id = %s", C)}) == q("select count(*) n from compliance_tasks where client_id = %s", C)[0]["n"],
       "a second framework adds only the kinds still missing")

    # ---- recurring tasks
    t0 = q("select * from compliance_tasks where client_id = %s order by id limit 1", C)[0]
    url = f"/clients/{C}/compliance-tasks/{t0['id']}/done"
    r = tech.post(B + url, data={"_csrf": csrf(tech, f"/clients/{C}/compliance"), "note": "", "document_id": "", "done_on": "2026-11-01"})
    ok("note or pick a document" in flash(r.text) and not q("select 1 from compliance_task_log where task_id = %s", t0["id"]), "done needs a note or a document")
    q("insert into documents (client_id, title, category, kind, status, body_html) values (1, 'Zz240 other client doc', 'policy', 'page', 'active', '<p>x</p>')")
    odoc = q("select id from documents where title = 'Zz240 other client doc'")[0]["id"]
    r = tech.post(B + url, data={"_csrf": csrf(tech, f"/clients/{C}/compliance"), "note": "", "document_id": str(odoc), "done_on": "2026-11-01"})
    ok(not q("select 1 from compliance_task_log where task_id = %s", t0["id"]), "another client's document isn't proof")
    q("delete from documents where id = %s", odoc)
    r = tech.post(B + url, data={"_csrf": csrf(tech, f"/clients/{C}/compliance"), "note": "Zz240 <b>done</b> with the owner", "document_id": "", "done_on": "2026-11-01"})
    t1 = q("select * from compliance_tasks where id = %s", t0["id"])[0]
    expect = php(f"echo date('Y-m-d', strtotime('2026-11-01 +{t0['every_months']} months'));").stdout.strip()
    ok("recorded" in flash(r.text) and str(t1["next_due"]) == expect and q("select note from compliance_task_log where task_id = %s", t0["id"])[0]["note"] == "Zz240 <b>done</b> with the owner",
       f"recording moves the due date by its interval ({expect}) and logs it")
    r = tech.post(B + f"/clients/{C}/compliance-tasks/{t0['id']}", data={"_csrf": csrf(tech, f"/clients/{C}/compliance"), "every_months": "99", "next_due": "2026-02-30", "active": "1"})
    t2 = q("select every_months, next_due from compliance_tasks where id = %s", t0["id"])[0]
    ok(t2["every_months"] == 36 and str(t2["next_due"]) == expect, "the interval is capped and an impossible date ignored")
    r = php("echo json_encode([Align\\Compliance\\Tasks::interval('vuln_scan', ['pci-dss-4']), Align\\Compliance\\Tasks::interval('vuln_scan', ['hipaa-security']), Align\\Compliance\\Tasks::interval('restore_test', ['cis-v81-ig2', 'msp-baseline']), Align\\Compliance\\Tasks::addMonths('2026-01-31', 1)]);")
    ok(r.stdout.strip() == '[3,12,3,"2026-02-28"]', f"(review) intervals follow the client's frameworks and month ends don't drift: {r.stdout}{r.stderr[:200]}")
    r = viewer.post(B + url, data={"_csrf": csrf(viewer, f"/clients/{C}/compliance"), "note": "nope"}, allow_redirects=False)
    ok(q("select count(*) n from compliance_task_log where task_id = %s", t0["id"])[0]["n"] == 1, "a viewer can't mark a task done")
    q("update compliance_tasks set next_due = date_sub(curdate(), interval 3 day) where id = %s", t0["id"])
    ok("compliance task" in tech.get(B + "/todo").text, "an overdue task shows on the To do list")
    rm = tech.get(B + f"/clients/{C}/roadmap").text
    ok("compliance-tasks" in rm and not errs(rm), "and on the roadmap")
    ok('data-state="overdue"' in tech.get(B + f"/clients/{C}/compliance").text, "the compliance page marks it overdue")
finally:
    cleanup()
    for row in SAVED:
        q("insert ignore into client_frameworks (client_id, framework_id, next_review, last_reviewed) values (%s, %s, %s, %s)", row["client_id"], row["framework_id"], row["next_review"], row["last_reviewed"])

print("FAILURES:", len(fails))
