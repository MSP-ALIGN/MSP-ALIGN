"""2.41.0 Form-based documents:
- one set of answers per client for the named fields templates use ({{field:key}}); a connection's value (Microsoft
  365, the PSA) fills in until someone types an answer, with its source shown; saving changes only answers changed
  on the page (was[]);
- a form per template makes the draft; the answers sit in the document as marked values that a changed answer
  updates in Draft pages, while text edited by hand stays; an Active page shows "Update available" and the update goes
  into one Draft copy (the Active page never changes);
- the editor keeps the marks when it saves; an older document gets "Rebuild with form" (a new draft, the old kept);
- AI: "Suggest answers with AI" stores suggestions only (Use confirms one; unknown keys, answered fields and bad
  choices are dropped); an AI draft gets the answers as confirmed facts and keeps their places;
- the client's Documents overview: required documents across its frameworks, the next step, owners, and
  "Reviewed, no changes", which closes the policy review task once every required document is reviewed;
- techs and admins change answers (viewers read); answers are escaped wherever they land; migration 105 is safe to
  run again and leaves an edited template alone. Everything is made up."""
import json
from lib import *

C = 3  # Harbor Point Law Group LLP: no frameworks or documents in the seed
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
TECH_ID = q("select id from users where email = 'tech@example.com'")[0]["id"]
ANS = f"/clients/{C}/document-answers"


def mock(**kw): requests.post(M + "/mock/ai", json=kw)
def calls(): return requests.get(M + "/mock/ai-state").json()["calls"]
def request_text(c): return c["body"]["messages"][0]["content"][-1]["text"]
def tpl(slug): return q("select * from document_templates where slug = %s", slug)[0]
def doc(i): return q("select * from documents where id = %s", i)[0]
def newest(): return q("select * from documents where client_id = %s order by id desc limit 1", C)[0]
def page(who, path): return who.get(B + path).text
def stored(): return {r["fact"]: r for r in q("select * from client_document_facts where client_id = %s", C)}
def save(data, who=tech, path=ANS):
    d = {"_csrf": csrf(who, path)}
    d.update(data)
    return who.post(B + ANS, data=d)
def span(key, text): return f'data-field="{key}" data-value="{text}">{text}</span>'


def cleanup():
    q("delete from documents where client_id = %s", C)
    q("delete from client_document_facts where client_id = %s", C)
    q("delete from client_m365 where client_id = %s", C)
    q("delete from client_frameworks where client_id = %s", C)
    q("delete from compliance_tasks where client_id = %s", C)
    q("delete from ai_usage")
    q("delete from notify_state where k like 'ai_%%'")
    q("delete from settings where name like 'ai\\_%%'")
    q("delete from login_attempts")
    requests.get(M + "/mock/ai-reset")


cleanup()
IR = tpl("incident-response")
try:
    # ---- the templates carry named fields (fresh install: 025 seeds them from the files)
    ok("{{field:legal_counsel}}" in IR["body_html"] and "{{field:cyber_insurance}}" in IR["body_html"], "the Incident Response template uses named fields")
    ok("Signature" not in "".join(re.findall(r"<p>[^<]*\{\{field:[^}]+\}\}[^<]*Signature", IR["body_html"])), "no field sits in an approval block")

    # ---- the form: every field, grouped; a connection's value with its source
    q("insert into client_m365 (client_id, status, mode, tenant_name, tenant_domain) values (%s, 'connected', 'msp', 'Harbor Point', 'harborpoint.example')", C)
    t = page(tech, ANS)
    ok("Answers for documents" in t and "People and roles" in t and "Systems and tools" in t, "the answers form shows the fields by group")
    ok(re.search(r'data-field-row="email_platform".*?From a connection.*?From Microsoft 365 connection: <b>Microsoft 365</b>', t, re.S), "the email platform comes from the client's Microsoft 365 connection, with its source")
    v = page(viewer, ANS)
    ok("Answers for documents" in v and 'name="go"' not in v and "readonly" in v, "viewers can read the answers but not save them")
    r = viewer.post(B + ANS, data={"_csrf": csrf(viewer, ANS), "answers[security_lead]": "Nope", "was[security_lead]": ""})
    ok("security_lead" not in stored(), "a viewer's save is refused")

    # ---- saving: only answers changed on the page; an unchanged connection value is never saved as an answer
    r = save({"answers[security_lead]": "Dana Reyes, Office Manager", "was[security_lead]": "",
              "answers[email_platform]": "", "was[email_platform]": "", "answers[legal_counsel]": "Lee & Park LLP, 555-0100", "was[legal_counsel]": "", "go": "save"})
    s = stored()
    ok(s["security_lead"]["value"] == "Dana Reyes, Office Manager" and s["legal_counsel"]["value"] == "Lee & Park LLP, 555-0100", "typed answers are saved")
    ok("email_platform" not in s, "the connection's value stays a connection value (not frozen as an answer)")
    q("update client_document_facts set value = 'Sam Lee, Partner' where client_id = %s and fact = 'security_lead'", C)  # someone else saved meanwhile
    save({"answers[security_lead]": "Dana Reyes, Office Manager", "was[security_lead]": "Dana Reyes, Office Manager", "go": "save"})
    ok(stored()["security_lead"]["value"] == "Sam Lee, Partner", "a page opened before someone else's save doesn't put back the old answer")
    save({"answers[security_lead]": "Dana Reyes, Office Manager", "was[security_lead]": "Sam Lee, Partner", "go": "save"})
    save({"answers[mfa_scope]": "everyone, always", "was[mfa_scope]": "", "go": "save"})
    ok("mfa_scope" not in stored(), "a choice that isn't one of the field's options is not saved")

    # ---- a template's form makes the draft with marked values
    t = page(tech, f"{ANS}?template={IR['id']}")
    ok("Form: Incident Response" in t and 'data-field-row="legal_counsel"' in t and 'data-field-row="password_manager"' not in t, "a template's form shows only the fields it uses")
    save({"template": str(IR["id"]), "go": "create"}, path=f"{ANS}?template={IR['id']}")
    D = newest()
    ok(D["template_id"] == IR["id"] and D["status"] == "draft", "Save and make the draft creates a Draft from the template")
    ok(span("legal_counsel", "Lee &amp; Park LLP, 555-0100") in D["body_html"], "the answer sits in the draft as a marked value")
    ok(span("cyber_insurance", "[Cyber insurance]") in D["body_html"], "an unanswered field is a visible blank the Blanks list counts")

    # ---- an answer change updates the draft in place; text edited by hand stays
    body = D["body_html"].replace(span("bank_fraud", "[Bank fraud line]"), 'data-field="bank_fraud" data-value="[Bank fraud line]">First Example Bank (typed in the editor)</span>')
    ok(body != D["body_html"], "(test) the bank line was edited by hand in the editor")
    r = tech.post(B + f"/documents/{D['id']}/save", data={"_csrf": csrf(tech, f"/documents/{D['id']}"), "body": body, "base_version": str(D["version"])})
    ok(r.status_code == 200 and 'data-field="bank_fraud"' in doc(D["id"])["body_html"], "the editor's save keeps the marks (Html::clean allows them)")
    save({"answers[cyber_insurance]": "Example Mutual, policy CY-1, 800-555-0199", "was[cyber_insurance]": "",
          "answers[bank_fraud]": "Second Example Bank", "was[bank_fraud]": "", "go": "save"})
    b = doc(D["id"])["body_html"]
    ok(span("cyber_insurance", "Example Mutual, policy CY-1, 800-555-0199") in b, "the new answer updates the draft in place")
    ok("First Example Bank (typed in the editor)" in b and "Second Example Bank" not in b, "text edited by hand is never replaced")
    ok(q("select 1 from document_versions where document_id = %s and note = 'Answers updated'", D["id"]), "the update is a new version in the history")

    # ---- an Active page: Update available, one Draft copy, the Active page never changes
    q("update documents set status = 'active' where id = %s", D["id"])
    save({"answers[legal_counsel]": "Ortiz Law, 555-0111", "was[legal_counsel]": "Lee & Park LLP, 555-0100", "go": "save"})
    act = doc(D["id"])
    ok(span("legal_counsel", "Lee &amp; Park LLP, 555-0100") in act["body_html"], "an Active page is not changed by an answer change")
    t = page(tech, f"/documents/{D['id']}")
    ok("data-update-available" in t and "Update available" in t and "Lee &amp; Park LLP, 555-0100 → Ortiz Law, 555-0111" in t, "the Active page shows Update available with what changed")
    r = tech.post(B + f"/documents/{D['id']}/fields", data={"_csrf": csrf(tech, f"/documents/{D['id']}")})
    cp = newest()
    ok(cp["id"] != D["id"] and cp["status"] == "draft" and cp["ai_source_id"] == D["id"] and "(draft " in cp["title"], "the update goes into a Draft copy linked to the Active page")
    ok(span("legal_counsel", "Ortiz Law, 555-0111") in cp["body_html"] and "First Example Bank (typed in the editor)" in cp["body_html"], "the copy has the new answer and keeps the hand edits")
    ok(doc(D["id"])["body_html"] == act["body_html"], "the Active page itself is unchanged")
    tech.post(B + f"/documents/{D['id']}/fields", data={"_csrf": csrf(tech, f"/documents/{D['id']}")})
    ok(q("select count(*) as n from documents where ai_source_id = %s", D["id"])[0]["n"] == 1, "a second Update reuses the same draft copy")
    ok('data-form-tab' in t and f"document-answers?doc={D['id']}" in t, "the editor has a Form tab with this document's answers")

    # ---- Rebuild with form: an older document made from a template before it had fields
    pw = tpl("password-mfa-policy")
    old = q("insert into documents (client_id, title, category, status, body_html, template_id, created_by, updated_by) values (%s, 'Password Policy 2025', 'policy', 'active', '<h1>Password policy</h1><p>Old text.</p>', %s, %s, %s)", C, pw["id"], TECH_ID, TECH_ID)
    OLD = newest()["id"]
    t = page(tech, f"/documents/{OLD}")
    ok("data-rebuild" in t and f"document-answers?rebuild={OLD}" in t, "an older document from a template offers Rebuild with form")
    t = page(tech, f"{ANS}?rebuild={OLD}")
    ok("Rebuild “Password Policy 2025” with the form" in t and 'data-field-row="password_manager"' in t, "the rebuild form shows the template's fields")
    save({"rebuild": str(OLD), "answers[password_manager]": "ExampleVault", "was[password_manager]": "", "go": "rebuild"}, path=f"{ANS}?rebuild={OLD}")
    nb = newest()
    ok(nb["id"] != OLD and "(rebuilt " in nb["title"] and nb["template_id"] == pw["id"] and span("password_manager", "ExampleVault") in nb["body_html"], "Save and rebuild makes a new draft from the template with the answers")
    ok(doc(OLD)["body_html"] == "<h1>Password policy</h1><p>Old text.</p>", "the older document is kept as it was")

    # ---- answers are escaped wherever they land
    save({"answers[lob_apps]": '<script>alert(1)</script> "Apps"', "was[lob_apps]": "", "go": "save"})
    t = page(tech, ANS)
    ok("<script>alert(1)</script>" not in t and "&lt;script&gt;" in t, "the form escapes an answer")
    mobile = tpl("mobile-byod-policy")
    save({"template": str(mobile["id"]), "go": "create"}, path=f"{ANS}?template={mobile['id']}")
    mb = newest()["body_html"]
    ok("<script>" not in mb and "&lt;script&gt;alert(1)&lt;/script&gt;" in mb, "a document gets the answer as text, never as markup")

    # ---- AI: suggestions wait to be confirmed
    setting("ai_api_base", M + "/ai"); setting("ai_provider", "claude"); setting("ai_model", "claude-sonnet-5-5")
    setting("ai_api_key", "key-claude", secret=True); setting("ai_api_key_for", "claude")
    setting("ai_enabled", "1"); setting("ai_feature_drafts", "1"); setting("ai_monthly_tokens", "1000000")
    mock(text=json.dumps({"data_types": "patient records (ePHI)", "security_lead": "Should be ignored", "mfa_scope": "sometimes", "unknown_key": "x", "time_source": "time.nist.gov"}))
    r = tech.post(B + ANS + "/suggest", data={"_csrf": csrf(tech, ANS)})
    s = stored()
    ok(s.get("data_types", {}).get("suggested") == "patient records (ePHI)" and s["data_types"]["value"] == "", "AI's suggestion is stored as a suggestion, not an answer")
    ok(s["security_lead"]["value"] == "Dana Reyes, Office Manager" and not s["security_lead"]["suggested"], "an answered field gets no suggestion")
    ok("mfa_scope" not in s and "unknown_key" not in s, "a bad choice and an unknown field are dropped")
    rq = request_text(calls()[-1])
    ok("data_types" in rq and "Security lead (confirmed): Dana Reyes" in rq, "AI gets the empty fields and the confirmed answers")
    t = page(tech, ANS)
    ok(re.search(r'data-field-row="data_types".*?AI suggested, confirm.*?patient records \(ePHI\)', t, re.S), "the form shows the suggestion to confirm")
    tech.post(B + ANS, data={"_csrf": csrf(tech, ANS), "accept[data_types]": "1"})
    ok(stored()["data_types"]["value"] == "patient records (ePHI)" and not stored()["data_types"]["suggested"], "Use makes the suggestion the answer")
    tech.post(B + ANS + "/dismiss", data={"_csrf": csrf(tech, ANS), "key": "time_source"})
    ok("time_source" not in stored(), "Dismiss drops a suggestion")

    # ---- AI draft from a template: answers as confirmed facts, their places kept
    bcdr = tpl("bcdr-plan")
    filler = "<p>" + "This plan keeps the practice running through outages and incidents. " * 220 + "</p>"
    mock(text="<h1>Business Continuity Plan</h1><p>Legal counsel: {{field:legal_counsel}}</p>" + filler)
    tech.post(B + f"/clients/{C}/ai-drafts/template/{bcdr['id']}", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "review_id": "0"})
    ai = newest()
    ok("(AI draft" in ai["title"] and span("legal_counsel", "Ortiz Law, 555-0111") in ai["body_html"], "the AI draft has the answer filled in again as a marked value")
    rq = request_text(calls()[-1])
    ok("Confirmed answers from the client's form" in rq and "{{field:legal_counsel}}: Ortiz Law" in rq and "{{field:cyber_insurance}}" in rq, "AI got the answers as confirmed facts and the markers in the template")

    # ---- the Documents overview
    wisp = q("select id from compliance_frameworks where slug = 'wisp-ftc'")[0]["id"]
    q("insert into client_frameworks (client_id, framework_id) values (%s, %s)", C, wisp)
    t = page(tech, f"/clients/{C}/documents")
    ok('id="doc-overview"' in t and 'data-required="wisp-ftc"' in t and 'data-required="risk-assessment-report"' in t, "the overview lists the framework's required documents")
    ok(re.search(r'data-required="wisp-ftc" data-action="create".*?document-answers\?template=', t, re.S), "a missing one offers Fill the form")
    ok(re.search(r'data-required="incident-response" data-action="update"', t), "the Active incident plan shows Update available")
    ok(f"/documents/{D['id']}/owner" in t and 'data-autosubmit' in t, "techs can set an owner")
    tech.post(B + f"/documents/{D['id']}/owner", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "owner_id": str(TECH_ID)})
    ok(doc(D["id"])["owner_id"] == TECH_ID, "the owner is saved")
    tech.post(B + f"/documents/{D['id']}/owner", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "owner_id": "999999"})
    ok(doc(D["id"])["owner_id"] == TECH_ID, "an unknown owner is refused")
    # Reviewed, no changes: not done while a required document is missing, nor from a document that isn't required
    def add_active(slug):
        tp = tpl(slug)
        q("insert into documents (client_id, title, category, status, body_html, template_id, review_due, created_by, updated_by) values (%s, %s, 'policy', 'active', '<p>Reviewed text.</p>', %s, curdate() + interval 11 month, %s, %s)", C, tp["name"], tp["id"], TECH_ID, TECH_ID)
        return q("select max(id) as id from documents where client_id = %s", C)[0]["id"]
    add_active("wisp-ftc")
    q("insert into compliance_tasks (client_id, kind, every_months, next_due, active) values (%s, 'policy_review', 12, curdate() + interval 10 day, 1)", C)
    tid = q("select id from compliance_tasks where client_id = %s and kind = 'policy_review'", C)[0]["id"]
    tech.post(B + f"/documents/{D['id']}/reviewed", data={"_csrf": csrf(tech, f"/clients/{C}/documents")})
    ok(not q("select 1 from compliance_task_log where task_id = %s", tid), "(review) a required document still missing: the policy review task is not done")
    extra = add_active("password-mfa-policy")
    add_active("risk-assessment-report")
    tech.post(B + f"/documents/{extra}/reviewed", data={"_csrf": csrf(tech, f"/clients/{C}/documents")})
    ok(not q("select 1 from compliance_task_log where task_id = %s", tid), "(review) reviewing a document that isn't required doesn't close it")
    q("update documents set review_due = curdate() + interval 5 day where id = %s", D["id"])
    r = tech.post(B + f"/documents/{D['id']}/reviewed", data={"_csrf": csrf(tech, f"/clients/{C}/documents")})
    d = doc(D["id"])
    ok(str(d["review_due"]) == q("select curdate() + interval 12 month as d")[0]["d"].isoformat(), "Reviewed, no changes sets the next review a policy-review interval out")
    ok(q("select 1 from document_versions where document_id = %s and note like 'Reviewed, no changes%%'", D["id"]), "and adds a history entry")
    task = q("select * from compliance_tasks where client_id = %s and kind = 'policy_review'", C)[0]
    log = q("select * from compliance_task_log where task_id = %s", task["id"])
    ok(log and log[0]["document_id"] == D["id"] and "Required documents reviewed" in (log[0]["note"] or "") and task["next_due"] > q("select curdate() + interval 300 day as d")[0]["d"],
       "every required document reviewed: the policy review task is done with the document as proof")
    ok("Reviewed, no changes" in flash(r.text) or "reviewed" in r.text.lower(), "the page says so")
    r = tech.post(B + f"/documents/{cp['id']}/reviewed", data={"_csrf": csrf(tech, f"/clients/{C}/documents")})
    ok(doc(cp["id"])["review_due"] == cp["review_due"], "a Draft can't be marked reviewed")
    r = viewer.post(B + f"/documents/{D['id']}/reviewed", data={"_csrf": csrf(viewer, f"/clients/{C}/documents")})
    ok(r.status_code in (403, 302, 200) and q("select count(*) as n from compliance_task_log where task_id = %s", task["id"])[0]["n"] == 1, "viewers can't mark documents reviewed")

    # ---- (review) the browser: Enter in the form saves (never accepts a suggestion); the editor keeps the marks
    q("insert into client_document_facts (client_id, fact, value, suggested, suggested_at) values (%s, 'time_source', '', 'pool.ntp.org', now())", C)
    from playwright.sync_api import sync_playwright
    with sync_playwright() as p:
        b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1400, "height": 900})
        pg.goto(B + "/login"); pg.fill("input[name=email]", "tech@example.com"); pg.fill("input[name=password]", TECH_PASSWORD); pg.click("button")
        __import__('sitecustomize').after_login(pg, "tech@example.com"); pg.wait_for_load_state()
        pg.goto(B + ANS); pg.fill("#ans-cage_code", "1AB23"); pg.press("#ans-cage_code", "Enter"); pg.wait_for_load_state()
        s2 = stored()
        ok(s2["cage_code"]["value"] == "1AB23" and s2["time_source"]["value"] == "" and s2["time_source"]["suggested"] == "pool.ntp.org", "Enter saves the form and leaves the AI suggestion waiting")
        pg.goto(B + f"/documents/{cp['id']}"); pg.wait_for_selector("#doc-editor .ql-editor"); pg.wait_for_timeout(800)
        v0 = doc(cp["id"])["version"]
        pg.click("#doc-editor .ql-editor h1"); pg.keyboard.press("End"); pg.keyboard.type(" (reviewed)")
        pg.wait_for_timeout(4000)
        b2 = doc(cp["id"])
        ok(b2["version"] > v0 and "(reviewed)" in b2["body_html"] and span("legal_counsel", "Ortiz Law, 555-0111") in b2["body_html"], "an edit saved through the editor keeps the marked values")
        b.close()

    # ---- migration 105 runs again safely and leaves an edited template alone
    q("update document_templates set body_html = '<h1>Our own AUP</h1>' where slug = 'acceptable-use'")
    out = php('$m = require APP_ROOT . "/db/migrations/105_document_forms.php"; $m(); $m(); echo "ran";')
    ok("ran" in out.stdout and not out.stderr.strip(), "migration 105 runs twice without errors")
    ok(tpl("acceptable-use")["body_html"] == "<h1>Our own AUP</h1>", "a template the MSP edited is left alone")
    q("update document_templates set body_html = %s where slug = 'acceptable-use'", open(os.path.join(ROOT, "db", "templates", "acceptable-use.html"), encoding="utf-8").read())
    ok(not errs(page(tech, f"/clients/{C}/documents")) and not errs(page(tech, ANS)), "no PHP warnings on the pages")
finally:
    cleanup()
done()
