"""2.33.0 Compliance report: a printable report for one of a client's frameworks (cover with the review dates, score,
counts and a bar per section, open items with owner and due date and overdue ones highlighted, the full checklist with
notes, evidence and the linked document, a sign-off). Switches: open items only, include notes (on), include guidance
(off). Any staff role; only frameworks assigned to that client; audited (report.compliance). The client portal prints
the same report for the portal user's own client with only what the portal's framework page shows (no notes, evidence,
guidance, review dates or unshared documents). Everything is made up (Zz233, *.example addresses)."""
import datetime
import html as H
from lib import *
from playwright.sync_api import sync_playwright

C = 1       # Cedar Ridge Family Dental, Inc.
OTHER = 2   # Northfield Hardware & Supply
PW = "Quartz-Lantern-Field-33"
SEC = "KRUGKIDROVUWG2ZAMJZG653OEBTG66BC"
st = login("admin@example.com", "LongPassword123!")
viewer = login("viewer@example.com", "ViewerPassword123!")
VIEWER_ID = q("select id from users where email = 'viewer@example.com'")[0]["id"]
CNAME = q("select name from clients where id = %s", C)[0]["name"]
TODAY = datetime.date.today()
PAST = (TODAY - datetime.timedelta(days=30)).isoformat()
SOON = (TODAY + datetime.timedelta(days=60)).isoformat()


def text(t):
    """The page as plain text (tags dropped, entities decoded, spaces squeezed)."""
    return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))


def fmt(d): return php(f'echo fmt_date("{d}");').stdout.strip()
def audits(**kw): return q("select count(*) n from audit_log where action = 'report.compliance' and detail like %s"
                           + (" and user_id = %s" % int(kw["user"]) if "user" in kw else "")
                           + (" and portal_user_id = %s" % int(kw["portal"]) if "portal" in kw else ""), "%Zz233 Security Baseline%")[0]["n"]


def cleanup():
    q("delete from compliance_frameworks where slug like 'zz233-%%'")
    q("delete from documents where title like '%%Zz233%%'")
    q("delete from portal_users where email like '%%@compreport.example'")


def portal_user(email, can_devices=1, client=C):
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{client},"email"=>"{email}","name"=>"Pat Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        f'"can_roadmap"=>1,"can_budget"=>1,"can_devices"=>{can_devices},"can_documents"=>1,"can_approve"=>0,"can_contacts"=>0,'
        f'"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(SEC)})
    q("delete from login_attempts")
    return s, q("select id from portal_users where email = %s", email)[0]["id"]


def doc(title, status, shared, client=C):
    q("insert into documents (client_id, title, category, status, body_html, portal_shared) values (%s, %s, 'policy', %s, '<p>Zz233</p>', %s)", client, title, status, shared)
    return q("select id from documents where title = %s", title)[0]["id"]


def control(fw, ref, section, title, sort, guidance=None):
    q("insert into compliance_controls (framework_id, ref, section, title, guidance, sort) values (%s, %s, %s, %s, %s, %s)", fw, ref, section, title, guidance, sort)
    return q("select id from compliance_controls where framework_id = %s and ref = %s", fw, ref)[0]["id"]


def answer(cid, ctl, status, notes=None, evidence=None, owner=None, due=None, document=None):
    q("insert into client_control_status (client_id, control_id, status, notes, evidence, owner, due_date, document_id) values (%s, %s, %s, %s, %s, %s, %s, %s)",
      cid, ctl, status, notes, evidence, owner, due, document)


cleanup()
try:
    # ======== a made-up framework assigned to client C, another assigned only to OTHER
    q("insert into compliance_frameworks (slug, name, description) values ('zz233-main', 'Zz233 Security Baseline', 'Zz233 made-up baseline for the report test.')")
    FW = q("select id from compliance_frameworks where slug = 'zz233-main'")[0]["id"]
    q("insert into compliance_frameworks (slug, name) values ('zz233-other', 'Zz233 Other Framework')")
    FW2 = q("select id from compliance_frameworks where slug = 'zz233-other'")[0]["id"]
    q("insert into client_frameworks (client_id, framework_id, last_reviewed, next_review) values (%s, %s, '2026-05-04', '2027-05-04')", C, FW)
    q("insert into client_frameworks (client_id, framework_id) values (%s, %s)", OTHER, FW2)
    shared = doc("Zz233 MFA policy", "active", 1)
    internal = doc("Zz233 Internal runbook", "active", 0)
    draft = doc("<script>alert('doc')</script> Zz233 Draft plan", "draft", 0)
    a1 = control(FW, "AC-1", "Access control", "Zz233 MFA for every admin", 1)
    a2 = control(FW, "AC-2", "Access control", "Zz233 Password manager", 2)
    a3 = control(FW, "AC-3", "Access control", "Zz233 Remove old accounts", 3)
    a4 = control(FW, "AC-4", "Access control", "Zz233 Admin accounts reviewed", 4)
    b1 = control(FW, "BK-1", "Backups", "Zz233 Offsite tape", 5)
    b2 = control(FW, "BK-2", "Backups", "Zz233 Restore test", 6, "Zz233 guidance: test a restore every quarter.")
    b3 = control(FW, "BK-3", "Backups", "Zz233 Backup monitoring", 7)
    answer(C, a1, "met", "Zz233 internal note: checked the tenant", "Zz233 evidence: MFA report", document=shared)
    answer(C, a2, "partial", "<script>alert('notes')</script> Zz233 partial note", owner="Dana Example", due=PAST)
    answer(C, a3, "not_met", owner="<script>alert('owner')</script> Zz233 Owner", due=SOON, document=draft)
    answer(C, a4, "met")
    answer(C, b1, "na")
    answer(C, b3, "met", evidence="<script>alert('evidence')</script> Zz233 dashboard", document=internal)
    o1 = control(FW2, "OT-1", "Other", "Zz233 Other control", 1)
    answer(OTHER, o1, "met", "Zz233 other client secret")
    answer(OTHER, a1, "not_met", "Zz233 OTHER answer")   # an answer on FW for a client it isn't assigned to
    URL = f"/clients/{C}/report/compliance/{FW}"

    # ======== the staff report
    before = audits()
    r = st.get(B + URL)
    t, tt = r.text, text(r.text)
    ok(r.status_code == 200 and not errs(t), "the report opens for an assigned framework (%s)" % r.status_code)
    ok("Compliance report" in tt and "Zz233 Security Baseline" in tt and CNAME in tt and "Example MSP Inc." in tt, "cover: framework, client and prepared by the company")
    ok("Last reviewed " + fmt("2026-05-04") in tt and "Next review " + fmt("2027-05-04") in tt, "cover: last reviewed and next review")
    ok('counter(pages)' in t and '@page :first' in t, "page numbers (not on the cover)")
    kpi = lambda v, l: f'<div class="kpi-val">{v}</div><div class="kpi-lbl">{l}</div>' in t
    ok(kpi("58%", "Score") and kpi(3, "Met") and kpi(1, "Partial") and kpi(1, "Not met") and kpi(1, "N/A") and kpi(1, "Not assessed"), "score 58% and the counts")
    ok(t.count('class="hbar"') >= 3, "a bar for the framework and one per section")
    sec = t.split("By section")[1].split("<h2>Open items</h2>")[0]
    ok(re.search(r"Access control.*?63%", sec, re.S) and re.search(r"Backups.*?50%", sec, re.S), "a score per section (63%, 50%)")
    op = t.split("<h2>Open items</h2>")[1].split("<h2>Full checklist</h2>")[0]
    order = [op.find(x) for x in ("Zz233 Remove old accounts", "Zz233 Password manager", "Zz233 Restore test")]
    ok(all(i > 0 for i in order) and order == sorted(order) and "Zz233 MFA for every admin" not in op and "Zz233 Offsite tape" not in op,
       "open items: not met, partial, not assessed; met and N/A left out")
    rows = re.findall(r'<tr class="overdue-row">(.*?)</tr>', op, re.S)
    ok(len(rows) == 1 and "Zz233 Password manager" in rows[0] and 'class="nowrap overdue"' in rows[0] and fmt(PAST) in rows[0] and "Overdue" in rows[0],
       "the past due date is highlighted as overdue")
    ok("1 overdue" in text(op) and "Dana Example" in op and fmt(SOON) in op, "owners and due dates; the overdue count")
    full = text(t.split("<h2>Full checklist</h2>")[-1])
    ok("Zz233 internal note: checked the tenant" in full and "Zz233 evidence: MFA report" in full and "Zz233 MFA policy" in full and "Zz233 Internal runbook" in full,
       "full checklist: notes, evidence and linked document titles")
    ok("Zz233 Draft plan (draft)" in full, "a draft document is marked")
    ok("Zz233 guidance" not in t, "guidance is off by default")
    ok("Sign-off" in tt and t.count("<td></td><td></td><td></td>") == 2, "sign-off lines for the client and the provider")
    ok("<script>alert(" not in t and "&lt;script&gt;alert(&#039;owner&#039;)" in t and "&lt;script&gt;alert(&#039;evidence&#039;)" in t
       and "&lt;script&gt;alert(&#039;notes&#039;)" in t and "&lt;script&gt;alert(&#039;doc&#039;)" in t, "notes, evidence, owner and document title are escaped")
    ok('id="opt-open"' in t and 'id="opt-notes"' in t and 'id="opt-guidance"' in t and "Include notes" in t, "toolbar switches")
    ok(audits() == before + 1, "audited")
    ok("Zz233 OTHER answer" not in t, "another client's answer on the same control doesn't show")

    # ======== switches
    t = st.get(B + URL + "?open=1").text
    ok("<h2>Full checklist</h2>" not in t and "Zz233 Restore test" in t and "Zz233 internal note" not in t and "Zz233 Backup monitoring" not in t, "open items only: no full checklist")
    t = st.get(B + URL + "?notes=0").text
    ok("Zz233 internal note" not in t and "Zz233 partial note" not in t and "Zz233 evidence: MFA report" in t, "notes off: evidence stays")
    t = st.get(B + URL + "?guidance=1").text
    ok("Zz233 guidance: test a restore every quarter." in t and "Zz233 internal note" in t, "guidance on")

    # ======== refused: not assigned, another client's, missing
    for path, why in [(f"/clients/{C}/report/compliance/{FW2}", "a framework of another client"),
                      (f"/clients/{OTHER}/report/compliance/{FW}", "a framework not assigned to that client"),
                      (f"/clients/{C}/report/compliance/999999", "a framework that doesn't exist"),
                      (f"/clients/999999/report/compliance/{FW}", "a client that doesn't exist")]:
        r = st.get(B + path)
        ok(r.status_code == 404 and "Zz233 other client secret" not in r.text and "Zz233 OTHER answer" not in r.text and "kpi-val" not in r.text, "404: " + why)
    n = audits()
    st.get(B + f"/clients/{OTHER}/report/compliance/{FW}")
    ok(audits() == n, "a refused report isn't logged as printed")
    r = requests.get(B + URL, allow_redirects=False)
    ok(r.status_code in (302, 303) and "/login" in r.headers.get("Location", ""), "signed out: to the sign-in page")

    # ======== any staff role
    n = audits(user=VIEWER_ID)
    r = viewer.get(B + URL)
    ok(r.status_code == 200 and "Zz233 Password manager" in r.text and audits(user=VIEWER_ID) == n + 1, "a viewer can print it (audited with the viewer)")

    # ======== links: checklist button, the client's Reports page, the Reports hub
    t = st.get(B + f"/clients/{C}/compliance/{FW}").text
    ok(f'href="/clients/{C}/report/compliance/{FW}"' in t and "Print report" in t, "the checklist has Print report")
    t = st.get(B + f"/clients/{C}/reports").text
    ok(f'href="/clients/{C}/report/compliance/{FW}"' in t and "Compliance report: Zz233 Security Baseline" in t and f"/report/compliance/{FW2}" not in t, "the client's Reports page lists its frameworks only")
    t = st.get(B + f"/clients/{OTHER}/reports").text
    ok(f"/report/compliance/{FW2}" in t and f"/report/compliance/{FW}\"" not in t, "and the other client's page its own")
    t = st.get(B + "/reports").text
    ok('data-report="compliance-report"' in t and 'name="open"' in t and 'name="guidance"' in t, "the Reports hub has the Compliance report card")
    with sync_playwright() as p:
        b = p.chromium.launch()
        ctx = b.new_context(viewport={"width": 1440, "height": 900})
        pg = ctx.new_page()
        errors = []
        pg.on("pageerror", lambda e: errors.append(str(e)))
        pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
        __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
        pg.goto(B + f"/reports?client={C}")
        card = pg.locator('form[data-report="compliance-report"]')
        card.locator("select.report-framework").select_option(str(FW))
        card.locator('input[name="guidance"][type="checkbox"]').check()
        with ctx.expect_page() as newp:
            card.locator("button").click()
        rp = newp.value
        rp.wait_for_load_state()
        u = rp.url
        ok(f"/clients/{C}/report/compliance/{FW}?" in u and "guidance=1" in u and "notes=1" in u and "open=0" in u, "the hub card opens the report with its switches: " + u)
        ok("Zz233 guidance: test a restore" in rp.content(), "with guidance on")
        # The toolbar switch reloads with open items only
        with rp.expect_navigation():
            rp.locator("#opt-open").check()
        ok("open=1" in rp.url and rp.locator("h2", has_text="Full checklist").count() == 0, "the toolbar's Open items only switch works")
        ok(not errors, "no script errors: %s" % errors)
        b.close()

    # ======== the client portal
    pu, PU = portal_user("pat@compreport.example")
    t = pu.get(B + f"/portal/compliance/{FW}").text
    ok(f'href="/portal/compliance/{FW}/report"' in t and "Print report" in t, "the portal framework page has Print report")
    n = audits(portal=PU)
    r = pu.get(B + f"/portal/compliance/{FW}/report")
    t, tt = r.text, text(r.text)
    ok(r.status_code == 200 and not errs(t) and "Zz233 Security Baseline" in tt and CNAME in tt, "a portal user with compliance access prints it (%s)" % r.status_code)
    ok(kpi("58%", "Score") and "Zz233 Password manager" in t and "Dana Example" in t and fmt(PAST) in t and 'class="nowrap overdue"' in t,
       "same score, open items, owner and overdue due date")
    ok("Zz233 MFA policy" in t, "the shared document's title shows")
    hidden = ["Zz233 internal note", "Zz233 partial note", "Zz233 evidence: MFA report", "Zz233 dashboard", "Zz233 Internal runbook", "Zz233 Draft plan",
              "Zz233 guidance", "Last reviewed", "Next review", "Zz233 OTHER answer"]
    ok(not [h for h in hidden if h in t], "no notes, evidence, unshared or draft documents, guidance or review dates: %s" % [h for h in hidden if h in t])
    ok('id="opt-open"' in t and 'id="opt-notes"' not in t and 'id="opt-guidance"' not in t, "only the Open items only switch")
    ok("<script>alert(" not in t and "&lt;script&gt;alert(&#039;owner&#039;)" in t, "escaped in the portal too")
    t = pu.get(B + f"/portal/compliance/{FW}/report?notes=1&guidance=1&evidence=1").text
    ok(not [h for h in hidden if h in t], "?notes=1&guidance=1 changes nothing")
    t = pu.get(B + f"/portal/compliance/{FW}/report?open=1").text
    ok("<h2>Full checklist</h2>" not in t and "Zz233 Restore test" in t, "open items only in the portal")
    ok(audits(portal=PU) == n + 3, "audited with the portal user")
    r = pu.get(B + f"/portal/compliance/{FW2}/report")
    ok(r.status_code == 404 and "Zz233 other client secret" not in r.text and "Zz233 Other Framework" not in r.text, "another client's framework: 404")
    r = pu.get(B + f"/portal/compliance/{FW}/report?client_id={OTHER}&client={OTHER}&id={OTHER}")
    ok(r.status_code == 200 and CNAME in text(r.text) and "Zz233 OTHER answer" not in r.text, "a client id in the query string doesn't switch clients")
    r = pu.get(B + URL, allow_redirects=False)
    ok(r.status_code in (302, 303) and "kpi-val" not in r.text, "a portal user can't open the staff report")
    q("update portal_users set can_devices = 0 where id = %s", PU)
    r = pu.get(B + f"/portal/compliance/{FW}/report")
    ok(r.status_code == 403 and "Zz233 Password manager" not in r.text, "a portal user without compliance access is refused")
    # A user of the other client gets their own framework, not C's
    po, PO = portal_user("other@compreport.example", client=OTHER)
    ok(po.get(B + f"/portal/compliance/{FW}/report").status_code == 404, "the other client's user can't print C's framework")
    r = po.get(B + f"/portal/compliance/{FW2}/report")
    ok(r.status_code == 200 and "Zz233 Other Framework" in r.text and "Zz233 other client secret" not in r.text, "and prints its own (without its notes)")
finally:
    cleanup()
done()
