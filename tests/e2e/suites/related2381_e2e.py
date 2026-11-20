"""2.38.1 Related documents checklist: a program document (the WISP here) lists the documents it relies on, and a
client's framework page lists the documents that framework needs, each with where the client stands (Active, review
overdue, Draft, missing): a document counts when it was made from the template or carries its name, never another
client's or an archived one. Techs and admins can create a missing one from its template or draft it with AI (with the
newest compliance review's facts when there is one); viewers only see the list. Everything is made up."""
import json
from lib import *

C = 3  # Harbor Point Law Group LLP
OTHER = 1
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")


def mock(**kw): requests.post(M + "/mock/ai", json=kw)
def calls(): return requests.get(M + "/mock/ai-state").json()["calls"]
def tpl(slug): return q("select * from document_templates where slug = %s", slug)[0]
def rows(t):
    """The checklist's rows on a page: [(slug, state)]."""
    return re.findall(r'data-related="([a-z0-9-]+)" data-state="([a-z]+)"', t)
def state(t, slug): return dict(rows(t)).get(slug)


def cleanup():
    q("delete from documents where client_id = %s", C)
    q("delete from documents where client_id = %s and title like 'Zz2381r %%'", OTHER)
    q("delete from client_frameworks where client_id = %s", C)
    q("delete from ai_reviews where client_id = %s", C)
    q("delete from ai_usage")
    q("delete from settings where name like 'ai\\_%%'")
    requests.get(M + "/mock/ai-reset")


cleanup()
try:
    WISP = tpl("wisp-ftc")
    FW = q("select id from compliance_frameworks where slug = 'wisp-ftc'")[0]["id"]
    q("insert into client_frameworks (client_id, framework_id, next_review) values (%s, %s, '2027-11-01')", C, FW)
    r = tech.post(B + "/documents", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "client_id": str(C), "template_id": str(WISP["id"])})
    W = int(r.url.rstrip("/").split("/")[-1])

    # ---- the WISP's page lists what it relies on
    t = tech.get(B + f"/documents/{W}").text
    rs = rows(t)
    ok(not errs(t) and "Documents this one relies on" in t and len(rs) >= 10 and all(s == "missing" for _, s in rs) and rs[0][0] == "risk-assessment-report",
       f"the WISP lists the documents it relies on, all missing at first ({len(rs)})")
    ok("Create from template" in t and "Draft with AI" not in t, "techs can create them; no AI button while AI is off")
    tv = viewer.get(B + f"/documents/{W}").text
    ok(len(rows(tv)) == len(rs) and "Create from template" not in tv, "viewers see the list without the buttons")
    other = tech.post(B + "/documents", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "client_id": str(C), "template_id": str(tpl("mobile-byod-policy")["id"])})
    ok("Documents this one relies on" not in other.text, "a policy that isn't a program document has no checklist")

    # ---- create one from the checklist; its state follows the document
    acc = tpl("access-control-policy")
    r = tech.post(B + "/documents", data={"_csrf": csrf(tech, f"/documents/{W}"), "client_id": str(C), "template_id": str(acc["id"])})
    A = int(r.url.rstrip("/").split("/")[-1])
    ok(q("select template_id, client_id from documents where id = %s", A)[0] == {"template_id": acc["id"], "client_id": C}, "Create from template makes the client's document from it")
    ok(state(tech.get(B + f"/documents/{W}").text, "access-control-policy") == "draft", "a new document shows as a Draft")
    q("update documents set status = 'active', review_due = '2020-01-01' where id = %s", A)
    t = tech.get(B + f"/documents/{W}").text
    ok(state(t, "access-control-policy") == "overdue" and "Review was due" in t, "Active but past its review date: review overdue")
    q("update documents set review_due = date_add(curdate(), interval 1 year) where id = %s", A)
    ok(state(tech.get(B + f"/documents/{W}").text, "access-control-policy") == "active", "Active and in date: in place")
    # a document made by hand counts by its name; another client's and an archived one don't
    q("insert into documents (client_id, title, category, kind, status, body_html, created_by) values (%s, 'Encryption Policy 2026', 'policy', 'page', 'active', '<p>x</p>', 1)", C)
    q("insert into documents (client_id, title, category, kind, status, body_html, template_id, created_by) values (%s, 'Zz2381r Vendor', 'policy', 'page', 'active', '<p>x</p>', %s, 1)", OTHER, tpl("vendor-risk-policy")["id"])
    q("insert into documents (client_id, title, category, kind, status, body_html, template_id, created_by) values (%s, 'Old incident plan', 'plan', 'page', 'archived', '<p>x</p>', %s, 1)", C, tpl("incident-response")["id"])
    t = tech.get(B + f"/documents/{W}").text
    ok(state(t, "encryption-policy") == "active" and state(t, "vendor-risk-policy") == "missing" and state(t, "incident-response") == "missing",
       "a hand-made document counts by its name; another client's or an archived one doesn't")

    # ---- the framework page lists the documents the framework needs
    t = tech.get(B + f"/clients/{C}/compliance/{FW}").text
    rs = rows(t)
    ok(not errs(t) and "Documents for " in t and rs and rs[0][0] == "wisp-ftc" and dict(rs).get("wisp-ftc") == "draft" and dict(rs).get("access-control-policy") == "active",
       f"the framework page: its own document first, then the policies covering its controls ({len(rs)})")
    ok(re.search(r'data-related="access-control-policy".*?\d+ controls?', t, re.S), "with how many of its controls each covers")

    # ---- Draft with AI from the checklist
    setting("ai_api_base", M + "/ai"); setting("ai_provider", "claude"); setting("ai_model", "claude-sonnet-5-5")
    setting("ai_api_key", "key-claude", secret=True); setting("ai_api_key_for", "claude"); setting("ai_enabled", "1"); setting("ai_feature_drafts", "1")
    t = tech.get(B + f"/documents/{W}").text
    ok("Draft with AI" in t and f'action="/clients/{C}/ai-drafts/template/{tpl("password-mfa-policy")["id"]}"' in t, "with AI on, missing documents can be drafted with AI")
    mfa = tpl("password-mfa-policy")
    mock(queue=[mfa["body_html"].replace("{{client_name}}", "Harbor Point Law Group LLP") + "<p>MFA on every account, per the facts.</p>"])
    r = tech.post(B + f"/clients/{C}/ai-drafts/template/{mfa['id']}", data={"_csrf": csrf(tech, f"/documents/{W}"), "review_id": "0"})
    d = q("select * from documents where client_id = %s and template_id = %s", C, mfa["id"])
    ok(len(d) == 1 and d[0]["status"] == "draft" and d[0]["title"].startswith(mfa["name"] + " (AI draft ") and r.url.endswith(f"/documents/{d[0]['id']}"), "Draft with AI makes a Draft from the template and opens it")
    ok("<<<FACTS" not in calls()[-1]["body"]["messages"][0]["content"][-1]["text"], "without a review, no facts are sent")
    q("insert into ai_reviews (client_id, status, frameworks, sources, files, facts, `groups`, matches, step, created_by, created_name) values (%s, 'applied', '[]', '[]', '[]', %s, '[]', '{}', 0, 1, 'Terry Tech')",
      C, json.dumps([{"id": 1, "text": "Backups run nightly", "quote": "nightly", "source": 1, "date": None}]))
    RID = q("select max(id) id from ai_reviews where client_id = %s", C)[0]["id"]
    t = tech.get(B + f"/documents/{W}").text
    ok(f'name="review_id" value="{RID}"' in t, "the newest compliance review's facts are offered")
    bk = tpl("backup-recovery-policy")
    mock(queue=[bk["body_html"].replace("{{client_name}}", "Harbor Point Law Group LLP") + "<p>Backups run nightly.</p>"])
    tech.post(B + f"/clients/{C}/ai-drafts/template/{bk['id']}", data={"_csrf": csrf(tech, f"/documents/{W}"), "review_id": str(RID)})
    ok("<<<FACTS" in calls()[-1]["body"]["messages"][0]["content"][-1]["text"] and q("select 1 from documents where client_id = %s and template_id = %s", C, bk["id"]), "with the review, its facts are sent")
    n = len(calls())
    ok(tech.post(B + f"/clients/{C}/ai-drafts/template/999999", data={"_csrf": csrf(tech, "/"), "review_id": "0"}).status_code == 404 and len(calls()) == n, "an unknown template: 404, nothing sent")
    ok(viewer.post(B + f"/clients/{C}/ai-drafts/template/{mfa['id']}", data={"_csrf": csrf(viewer, "/"), "review_id": "0"}).status_code == 403, "viewers can't draft (403)")
finally:
    cleanup()
done()
