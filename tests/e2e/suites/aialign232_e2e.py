"""2.32.0 Alignment write-ups with AI: on a finished alignment review (never a draft), "Write recommendations with AI"
sends a digest of the review (score, counts, the standards not met with their why / how / usual fix and linked roadmap
projects; never the notes on answers, people, contact details, device names or serials), the draft waits in the
session to edit, Save keeps it with that review (edit, remove; audited). The staff QBR pack prints the newest
review's write-up in its Alignment section (a switch leaves it out); the client portal never does. Techs and admins;
AI and the 'alignment' switch must be on. The provider mock is tests/mock-ai.php; everything is made up (Zz232,
*.example addresses)."""
import json
from lib import *

C = 1       # Cedar Ridge Family Dental, Inc.: contacts and devices with serials
OTHER = 2   # Northfield Hardware & Supply
PW = "Quartz-Lantern-Field-32"
SEC = "KRUGKIDROVUWG2ZAMJZG653OEBTG66BA"
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
TECH = q("select id, name from users where email = 'tech@example.com'")[0]
SCFG = WORK + "/staging-aialign.php"
base_cfg = open(CONFIG).read().rstrip().rstrip(";").rstrip()
open(SCFG, "w").write(base_cfg[:-1] + ', "staging" => true];\n')


def mock(**kw): requests.post(M + "/mock/ai", json=kw)
def calls(): return requests.get(M + "/mock/ai-state").json()["calls"]
def page(who, path): return who.get(B + path).text
def req(c): return c["body"]["messages"][0]["content"][-1]["text"] if isinstance(c["body"]["messages"][0]["content"], list) else c["body"]["messages"][0]["content"]
def post(who, path, **data): return who.post(B + path, data={"_csrf": csrf(who, "/"), **data})
def line(t): return re.sub(r"\s+", " ", t).strip()


def portal_user(email):
    p = {"can_roadmap": 1, "can_budget": 1, "can_devices": 1, "can_documents": 1, "can_approve": 1, "can_contacts": 1, "can_submit": 0}
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{C},"email"=>"{email}","name"=>"Pat Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        + ",".join(f'"{k}"=>{v}' for k, v in p.items()) + f',"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(SEC)})
    q("delete from login_attempts")
    return s


def review(cid, status, ago_days, answers, score=None):
    """A review of client cid made directly: answers is [(standard row, answer, note)]; finished ones keep titles and counts."""
    if status == "done":
        q("insert into alignment_reviews (client_id, status, started_at, started_by, finished_at, finished_by, score, aligned, misaligned, na, unanswered) values "
          "(%s, 'done', now() - interval %s day, %s, now() - interval %s day, %s, %s, %s, %s, %s, 2)", cid, ago_days, TECH["id"], ago_days, TECH["id"], score,
          sum(1 for a in answers if a[1] == "aligned"), sum(1 for a in answers if a[1] == "misaligned"), sum(1 for a in answers if a[1] == "na"))
    else:
        q("insert into alignment_reviews (client_id, status, started_by) values (%s, 'draft', %s)", cid, TECH["id"])
    rid = q("select max(id) as id from alignment_reviews")[0]["id"]
    for s, a, n in answers:
        q("insert into alignment_answers (review_id, standard_id, answer, note, title, priority, updated_by) values (%s, %s, %s, %s, %s, %s, %s)",
          rid, s["id"], a, n, s["title"] if status == "done" else None, s["priority"] if status == "done" else None, TECH["id"])
    MADE.append(rid)
    return rid


MADE = []


def cleanup():
    if MADE:
        q("delete from alignment_reviews where id in (" + ",".join(str(i) for i in MADE) + ")")
    q("delete from alignment_writeups")
    q("delete from roadmap_items where title like 'Zz232%%' or title = 'Replace laptop ZZPIKE-LT232'")
    q("delete from devices where display_name = 'ZZPIKE-LT232'")
    q("delete from portal_users where email like '%%@aialign.example'")
    q("delete from ai_usage")
    q("delete from notify_state where k like 'ai_%%'")
    q("delete from settings where name like 'ai\\_%%'")
    requests.get(M + "/mock/ai-reset")


cleanup()
STD = q("select s.id, s.title, s.priority, s.why, s.how, s.fix_title from alignment_standards s where s.is_active = 1 and s.why is not null and s.how is not null order by s.id")
CRIT = next(s for s in STD if s["priority"] == "critical")
MED = next(s for s in STD if s["priority"] == "medium")
LOW = next((s for s in STD if s["priority"] == "low"), STD[-1])
OK1, OK2, NA = [s for s in STD if s["id"] not in (CRIT["id"], MED["id"], LOW["id"])][:3]
NOTE = "Zz232 Jordan Pike said the admin password is hunter2232; mail jpike@cedar.example, call 555-0199, laptop ZZPIKE-LT232"
OLD = review(C, "done", 200, [(CRIT, "misaligned", "Zz232 old note"), (MED, "misaligned", None), (OK1, "aligned", None)], 40)
NEW = review(C, "done", 0, [(CRIT, "misaligned", NOTE), (MED, "misaligned", "Zz232 second note by Casey Rowe"), (LOW, "misaligned", None),
                            (OK1, "aligned", "fine"), (OK2, "aligned", None), (NA, "na", None)], 55)
OTHERREV = review(OTHER, "done", 0, [(CRIT, "misaligned", None)], 0)
# Roadmap projects linked to the gaps: a plain one, and a device project whose title holds the device's name
q("insert into roadmap_items (client_id, title, category, status, priority, cost, target_quarter, alignment_standard_id, created_by) values "
  "(%s, 'Zz232 MFA rollout', 'security', 'proposed', 'high', 1200, null, %s, 1)", C, CRIT["id"])
q("insert into devices (source, client_id, display_name, system_name, serial, device_type, device_class) values ('manual', %s, 'ZZPIKE-LT232', 'ZZPIKE-LT232', 'ZZSER232X', 'Laptop', 'workstation')", C)
DEVICE = q("select id from devices where display_name = 'ZZPIKE-LT232'")[0]["id"]
q("insert into roadmap_items (client_id, title, category, status, priority, cost, alignment_standard_id, created_by) values (%s, 'Replace laptop ZZPIKE-LT232', 'hardware', 'proposed', 'medium', 1400, %s, 1)", C, MED["id"])
DEVPROJ = q("select id from roadmap_items where title = 'Replace laptop ZZPIKE-LT232'")[0]["id"]
q("insert into roadmap_item_devices (roadmap_item_id, device_id) values (%s, %s)", DEVPROJ, DEVICE)
# What must never reach AI: the client's contacts, its devices' names and serials, and the notes on answers
PEOPLE = [r for c in q("select name, email, phone from contacts where client_id = %s", C) for r in (c["name"], c["email"], c["phone"]) if r]
PEOPLE += [q("select contact_name from clients where id = %s", C)[0]["contact_name"], TECH["name"]]
DEVS = [r for d in q("select serial, display_name from devices where client_id = %s", C) for r in (d["serial"], d["display_name"]) if r and len(r) > 3]
NOTES = ["Jordan Pike", "hunter2232", "jpike@cedar.example", "555-0199", "Casey Rowe", "Zz232 old note", "Zz232 second note"]
assert "ZZPIKE-LT232" in DEVS

WRITEUP = ("Your IT is **partly aligned** with our standards. <script>alert(1)</script> Several important items need work.\n\n"
           "## High priority\n- **Turn on MFA**: accounts can be taken over. We propose the MFA rollout. <img src=x onerror=alert(2)>\n\n"
           "## Medium priority\n- **Replace an old laptop**: it is out of support.\n\n## Low priority\n- **Tidy up**: small gain.\n\nNext steps: agree the plan at the QBR.")
WRITEUP_OLD = "Older review write-up for Zz232: the score was 40% and MFA was missing.\n\n## High priority\n- **MFA**: needed then too."
RV = f"/clients/{C}/alignment/reviews/{NEW}"
WU = RV + "/writeup"

setting("ai_api_base", M + "/ai"); setting("ai_provider", "claude"); setting("ai_model", "claude-sonnet-5-5")
setting("ai_api_key", "key-claude", secret=True); setting("ai_api_key_for", "claude")

try:
    # ======== hidden and refused until AI and the switch are on; never on a draft
    t = page(tech, RV)
    ok(not errs(t) and "Write-up for the owner" in t and "Write recommendations with AI" not in t and "Write it yourself" in t, "a finished review: write it yourself, no AI button while AI is off")
    ok("AI is turned off" in flash(post(tech, WU + "/ai").text) and not calls(), "refused while AI is off, nothing sent")
    setting("ai_enabled", "1")
    setting("ai_feature_alignment", "0")
    ok("Write recommendations with AI" not in page(tech, RV) and "Write recommendations with AI" not in page(tech, WU), "the switch hides the button")
    ok("Alignment write-up is turned off" in flash(post(tech, WU + "/ai").text) and not calls(), "and refuses")
    setting("ai_feature_alignment", "1")
    ok("Write recommendations with AI" in page(tech, RV) and "Write recommendations with AI" in page(tech, WU), "the button shows for techs on a finished review")
    ok("Write recommendations with AI" not in page(viewer, RV) and "Write-up for the owner" not in page(viewer, RV), "viewers see no button or empty card")
    t = page(st, "/integrations/ai")
    ok('name="ai_feature_alignment"' in t and "Alignment write-up: recommendations" in t, "the switch on the AI settings page")
    DRAFT = review(C, "draft", 0, [(CRIT, "misaligned", None)])
    t = page(tech, f"/clients/{C}/alignment/review")
    ok(not errs(t) and "Write recommendations with AI" not in t and "Write-up for the owner" not in t, "not on a draft review's page")
    ok(tech.get(B + f"/clients/{C}/alignment/reviews/{DRAFT}/writeup").status_code == 404 and post(tech, f"/clients/{C}/alignment/reviews/{DRAFT}/writeup/ai").status_code == 404
       and post(tech, f"/clients/{C}/alignment/reviews/{DRAFT}/writeup", action="save", body="x" * 100).status_code == 404 and not calls(), "a draft's write-up URLs answer 404")
    q("delete from alignment_reviews where id = %s", DRAFT)

    # ======== viewers: 403 on every new URL; another client's review refused
    vt = csrf(viewer, "/")
    ok(viewer.get(B + WU, allow_redirects=False).status_code == 403
       and all(viewer.post(B + p, data={"_csrf": vt, "body": "x" * 100, "action": "save"}, allow_redirects=False).status_code == 403 for p in (WU, WU + "/ai"))
       and viewer.post(B + WU, data={"_csrf": vt, "action": "delete"}, allow_redirects=False).status_code == 403 and not calls(), "viewers get 403 on every URL")
    other = f"/clients/{C}/alignment/reviews/{OTHERREV}/writeup"
    ok(tech.get(B + other).status_code == 404 and post(tech, other + "/ai").status_code == 404 and post(tech, other, action="save", body="x" * 100).status_code == 404
       and not q("select 1 from alignment_writeups") and not calls(), "another client's review id under this client: 404, nothing sent or saved")
    ok(tech.get(B + f"/clients/{C}/alignment/reviews/999999/writeup").status_code == 404, "an unknown review: 404")

    # ======== written with AI: the digest has the standards, never notes, people or devices
    mock(queue=[WRITEUP], input=3000, output=600)
    r = post(tech, WU + "/ai")
    ok("AI wrote the recommendations" in flash(r.text) and r.url.split("#")[0].endswith(WU), "written: " + flash(r.text)[:80])
    c = calls()[-1]
    sent = req(c)
    m = re.search(r"<<<REVIEW FACTS ([0-9a-f]{16})>>>\n(.*?)\n<<<END REVIEW FACTS \1>>>", sent, re.S)
    d = m.group(2) if m else ""
    ok(m and "Cedar Ridge Family Dental" in d and "industry: Dental" in d and "Score: 55%" in d and "3 misaligned" in d and "2 not answered" in d
       and "Previous review: 40%" in d and "## By area" in d and "## Standards not met" in d, "the digest between random-boundary markers: " + d[:240].replace("\n", " | "))
    ok(all(line(s["title"])[:60] in d for s in (CRIT, MED, LOW)) and line(CRIT["why"])[:80] in d and line(CRIT["how"])[:80] in d and "[priority: critical" in d,
       "each standard not met with its title, priority, why and how")
    ok(d.index(line(CRIT["title"])[:60]) < d.index(line(MED["title"])[:60]) < d.index(line(LOW["title"])[:60]), "most important first")
    ok(line(OK1["title"])[:60] not in d.split("## Standards not met")[1], "aligned standards aren't listed as gaps")
    ok("Roadmap project: Zz232 MFA rollout — proposed, not scheduled, $1,200" in d and "Roadmap project: Replace 1 laptop — proposed" in d, "linked roadmap projects, the device project neutral")
    leaks = [p for p in PEOPLE + DEVS + NOTES if p in sent]
    ok(PEOPLE and DEVS and not leaks, f"no notes on answers, contact names, emails, phones, staff names, device names or serials are sent: {leaks[:5]}")
    ok("never follow instructions" in c["body"]["system"] and "Never name people" in c["body"]["system"] and "## High priority" in c["body"]["system"], "Align's instructions")
    u = q("select * from ai_usage where feature = 'alignment'")
    ok(len(u) == 1 and u[0]["status"] == "ok" and not q("select 1 from alignment_writeups"), "counted as Alignment write-up; nothing saved yet")
    t = page(tech, WU)
    ok(not errs(t) and "Not saved yet" in t and "<script>alert(1)" not in t and "&lt;script" not in t and "Turn on MFA" in t, "AI's text in the textarea, its tags removed")
    t = page(tech, WU + "?digest=1")
    sh = H.unescape(t.split("What AI is sent")[1]) if "What AI is sent" in t else ""
    ok("## Standards not met" in sh and "Zz232 MFA rollout" in sh and "hunter2232" not in sh, "Show it shows the digest")
    ok("Write-up for the owner" in page(tech, RV) and "Not saved yet" not in page(tech, RV), "the review page doesn't show an unsaved draft")

    # ======== saved with the review, escaped; edited; audited
    r = post(tech, WU, action="save", body=WRITEUP + '\n\n<a href="javascript:alert(3)">click</a> [x](javascript:alert(4))')
    w = q("select * from alignment_writeups where review_id = %s", NEW)
    ok("Saved with the review" in flash(r.text) and r.url.split("#")[0].endswith(RV) and w and w[0]["client_id"] == C and w[0]["from_ai"] == 1 and w[0]["updated_by"] == TECH["id"]
       and "<script" not in w[0]["body"] and "<a " not in w[0]["body"], "Save keeps it with the review (tags removed)")
    t = page(tech, RV)
    card = t.split('id="writeup"')[1][:5000] if 'id="writeup"' in t else ""
    ok(not errs(t) and '<h4 class="ai-summary-head">High priority</h4>' in card and "<li><b>Turn on MFA</b>" in card and "Edit" in card and "Remove" in card
       and "(written with AI)" in card, "the review page shows it as safe HTML, with Edit and Remove")
    ok("<script>alert" not in t and "onerror" not in t and "javascript:alert(3)" not in t and 'href="javascript' not in t and "<a " not in card.split('class="ai-summary"')[-1].split("Saved by")[0]
       and "[x](javascript:alert(4))" in card, "no script, event attribute or javascript: link survives (Markdown links stay text)")
    ok("Turn on MFA" in page(viewer, RV) and "Remove" not in page(viewer, RV).split('id="writeup"')[1][:3000], "viewers read it, without Edit or Remove")
    ok(q("select 1 from audit_log where action = 'alignment.writeup_save' and detail like %s", "%Cedar Ridge%(written with AI)%")
       and q("select 1 from audit_log where action = 'ai.alignment_writeup' and detail like %s", "%write-up written%"), "audited")
    ok("Not saved yet" not in page(tech, WU), "the draft is gone from the session once saved")
    post(tech, WU, action="save", body=WRITEUP.replace("Several important items", "Zz232 edited: several items"))
    w = q("select * from alignment_writeups where review_id = %s", NEW)[0]
    ok("Zz232 edited" in w["body"] and w["from_ai"] == 1 and "Zz232 edited" in page(tech, RV), "edited by hand, still marked written with AI")
    ok("Write the recommendations first" in flash(post(tech, WU, action="save", body="  <b> </b> ").text)
       and "Zz232 edited" in q("select body from alignment_writeups where review_id = %s", NEW)[0]["body"], "an empty write-up isn't saved")

    # ======== printed in the staff QBR pack; the switch leaves it out; never in the portal
    t = page(tech, f"/clients/{C}/report/qbr")
    sec = t.split("Alignment with our standards")[-1][:9000] if "Alignment with our standards" in t else ""
    ok(not errs(t) and "<h3>Our recommendations</h3>" in sec and '<h4 class="ai-summary-head">High priority</h4>' in sec and "Zz232 edited" in sec,
       "printed in the Alignment section of the staff QBR pack")
    ok("<script>alert" not in t and "onerror" not in t and "javascript:alert" not in t, "escaped in the pack")
    ok('id="opt-ai_alignment"' in t and "Alignment write-up" in t, "an Alignment write-up switch in the toolbar")
    ok("Zz232 edited" not in page(tech, f"/clients/{C}/report/qbr?ai_alignment=0") and "Our recommendations" not in page(tech, f"/clients/{C}/report/qbr?ai_alignment=0"), "the switch leaves it out")
    ok("Zz232 edited" not in page(tech, f"/clients/{C}/report/qbr?s_alignment=0"), "and so does switching the Alignment section off")
    ok("Zz232 edited" in page(viewer, f"/clients/{C}/report/qbr"), "viewers see it in the staff pack")
    ok('id="opt-ai_alignment"' not in page(tech, f"/clients/{OTHER}/report/qbr"), "another client's pack has no switch")
    pu = portal_user("all@aialign.example")
    r = pu.get(B + "/portal/report/qbr")
    ok(r.status_code == 200 and "Zz232 edited" not in r.text and "Our recommendations" not in r.text and "Turn on MFA" not in r.text and "opt-ai_alignment" not in r.text,
       "never in the client portal's pack")
    ok(all("Turn on MFA" not in pu.get(B + p + "?ai_alignment=1&s_alignment=1").text for p in ("/portal/report/qbr", "/portal", "/portal/roadmap", "/portal/changes")),
       "not even with ?ai_alignment=1&s_alignment=1, nor on other portal pages")
    ok(pu.get(B + WU, allow_redirects=False).status_code in (302, 303, 401, 403), "a portal user can't open the staff write-up page")

    # ======== an older finished review can have one; the pack still shows the newest review's
    ow = f"/clients/{C}/alignment/reviews/{OLD}/writeup"
    mock(queue=[WRITEUP_OLD])
    post(tech, ow + "/ai")
    ok("Older review write-up" in page(tech, ow), "an older review gets a draft")
    post(tech, ow, action="save", body=H.unescape(page(tech, ow).split('name="body"')[1].split(">", 1)[1].split("</textarea>")[0]))
    ok(q("select from_ai from alignment_writeups where review_id = %s", OLD) == [{"from_ai": 1}] and "Older review write-up" in page(tech, f"/clients/{C}/alignment/reviews/{OLD}"),
       "saved with the older review, shown on its page")
    t = page(tech, f"/clients/{C}/report/qbr")
    ok("Zz232 edited" in t and "Older review write-up" not in t, "the pack prints the newest review's write-up only")

    # ======== failures: truncated, unusable, the provider, the limit, a test server
    mock(queue=[{"text": WRITEUP, "truncated": True}])
    ok("cut off" in flash(post(tech, WU + "/ai").text), "a cut-off write-up is refused")
    mock(queue=["OK"])
    ok("wasn't a usable write-up" in flash(post(tech, WU + "/ai").text), "a too-short answer is refused")
    mock(queue=["<<<>>> ### !!! 123 456 789 ... --- *** ``` ``` ### !!! 123 456 789 ... --- *** ### !!! 123 456 789 ... --- *** ### !!! 123 456 789 ..."])
    ok("wasn't a usable write-up" in flash(post(tech, WU + "/ai").text), "garbage without words is refused")
    mock(fail=500)
    ok("(500)" in flash(post(tech, WU + "/ai").text), "a provider error is shown")
    mock(fail=0)
    ok("Not saved yet" not in page(tech, WU) and "Zz232 edited" in q("select body from alignment_writeups where review_id = %s", NEW)[0]["body"], "failures leave the saved write-up alone")
    ok(q("select count(*) as n from audit_log where action = 'ai.alignment_writeup' and detail like %s", "%failed%")[0]["n"] >= 4, "failures audited")
    used = int(q("select sum(input_tokens + output_tokens) as n from ai_usage")[0]["n"])
    setting("ai_monthly_tokens", str(used + 500))
    n = len(calls())
    ok("more than the" in flash(post(tech, WU + "/ai").text) and len(calls()) == n, "the monthly limit stops it, nothing sent")
    setting("ai_monthly_tokens", "1000000")
    ok(php("echo Align\\Ai\\Ai::usable('alignment') ? 'on' : 'off';", {**ENV, "ALIGN_CONFIG": SCFG}).stdout == "off", "off on a test server")

    # ======== remove; a removed review takes its write-up with it
    r = post(tech, WU, action="delete")
    ok("removed" in flash(r.text) and not q("select 1 from alignment_writeups where review_id = %s", NEW)
       and q("select 1 from audit_log where action = 'alignment.writeup_remove' and detail like %s", f"%review #{NEW}%"), "Remove takes it off the review, audited")
    t = page(tech, f"/clients/{C}/report/qbr")
    ok("Zz232 edited" not in t and "Older review write-up" not in t and 'id="opt-ai_alignment"' not in t, "the pack has none now (no fallback to an older review), and no switch")
    q("delete from alignment_reviews where id = %s", OLD)
    ok(not q("select 1 from alignment_writeups where review_id = %s", OLD), "deleting a review deletes its write-up")
finally:
    cleanup()
    try:
        os.remove(SCFG)
    except OSError:
        pass
done()
