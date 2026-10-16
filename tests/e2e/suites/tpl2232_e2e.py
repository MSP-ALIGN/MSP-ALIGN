"""2.23.2 document templates for organizations whose parent organization, funder or insurer sets minimum security rules:
the Information Security Policy's Network and wireless section (current WPA, guest or public Wi-Fi only when separated) and
optional board approval; quarterly awareness training; a choice of critical patch time, a rollback plan and a compliance
audit; the Incident Response Plan's required reports with deadlines and a communication plan; shorter access removal
times as a choice. Migration 082 updates a built-in template only while it still holds an earlier built-in body (an
edited one is left alone), updates the three descriptions still in the old wording, and can run twice. Every template
keeps to the editor's tags and known placeholders, keeps generic wording, and makes a document with
nothing left unfilled."""
import re
from lib import *

TPL = ROOT + "/db/templates/"
MIG = ROOT + "/db/migrations/082_template_policy_updates.php"
OLD = ROOT + "/tests/e2e/fixtures/templates-2.23.1/"  # the 2.23.1 bodies 082 replaces (from db/templates)
OLDER = ROOT + "/tests/e2e/fixtures/templates-2.15.0/"
CHANGED = ["information-security-policy", "security-awareness-policy", "vulnerability-patch-policy", "incident-response", "access-control-policy"]
DESC = {"information-security-policy": "The top-level policy: scope, roles, the security lead, acceptable use and how the other policies fit together.",
        "vulnerability-patch-policy": "Scanning, patch timeframes by severity, exceptions and end-of-life systems.",
        "security-awareness-policy": "Onboarding and annual training, phishing simulations, role-based training and records."}
st = login("admin@example.com", "LongPassword123!")


def body(slug): return open(TPL + slug + ".html").read()
def row(slug): return (q("select * from document_templates where slug=%s", slug) or [None])[0]
def text(b): return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", b))
def migrate(): return php(f'(require "{MIG}")(); (require "{MIG}")(); echo "ok";')


def make(slug, client=1):
    """A document made from the template for a client: its filled-in body and the response."""
    tid = row(slug)["id"]
    r = st.post(B + "/documents", data={"_csrf": csrf(st, f"/clients/{client}/documents"), "client_id": str(client), "template_id": str(tid)})
    did = int(r.url.rstrip("/").split("/")[-1])
    d = q("select body_html from documents where id=%s", did)[0]["body_html"]
    q("delete from documents where id=%s", did)
    return d, r


# ---- a new install has the 2.23.2 bodies and descriptions
for slug in CHANGED:
    t = row(slug)
    ok(t and t["is_builtin"] == 1 and t["body_html"] == body(slug), f"{slug}: the built-in template is the 2.23.2 version")
ok("network and Wi-Fi" in row("information-security-policy")["description"] and "rollback" in row("vulnerability-patch-policy")["description"]
   and "quarterly" in row("security-awareness-policy")["description"] and "timeframes to choose" in row("access-control-policy")["description"]
   and "communication plan" in row("incident-response")["description"], "the descriptions say what's new (the IRP's after 007 and 074 too)")

# ---- what the templates say
isp = body("information-security-policy")
ok("<h2>9. Network and wireless security</h2>" in isp and "<h2>15. Review and approval</h2>" in isp and "(section 9)" in isp, "ISP: a network and wireless section, later sections renumbered")
ok("section 7, Data classification" in body("data-classification-policy") and "<h2>7. Data classification</h2>" in isp
   and "<h2>5. Risk management</h2>" in isp and "section 5, Risk management" in body("risk-management-policy"), "ISP: the sections other policies point to keep their numbers")
ok(all(x in isp for x in ["current Wi-Fi Protected Access standard", "WPA3", "WEP", "WPS", "only if it can be done securely", "guest Wi-Fi is not offered",
                          "changed when someone who knew it leaves", "where guest Wi-Fi is offered", "Remote Access Policy (section 8)"])
   and "<h2>8. Home networks and public Wi-Fi</h2>" in body("remote-access-policy"), "ISP: current WPA, nothing older, guest or public Wi-Fi only when separated")
ok("Board of directors (if required)" in isp and "approved by the executive sponsor, or by the board of directors where that is required" in isp
   and "Board approval (if required):" in isp and "[Remove this role if" in isp, "ISP: optional board approval, with a line to record it")
ok(all(x in isp for x in ["guest Wi-Fi", "AutoRun", "before they join the business network"]), "ISP: the 2.15.1 baseline items are still there")
sa = text(body("security-awareness-policy"))
ok("Ongoing training, at least quarterly" in sa and "every calendar quarter" in sa and "interns and volunteers who have" in sa and "once every 12 months" in sa,
   "Training: at least every quarter for anyone who can reach sensitive data, plus the annual refresher")
vp = text(body("vulnerability-patch-policy"))
ok("Critical (CVSS 9.0–10.0): within [7 days / 2 business days]" in vp and "Rollback plan:" in vp and "Compliance audit:" in vp
   and "or the Critical timeframe below, whichever is shorter" in vp and "independent reviewer" in vp and "exception (section 10)" in vp and "<h2>10. Exceptions and compensating controls</h2>" in body("vulnerability-patch-policy"),
   "Patch: a choice of critical patch time, a rollback plan, a compliance audit")
ir = body("incident-response")
ok("Organizations that must be told (if any)" in ir and "Required incident reports:" in ir and "e.g. within 48 hours" in ir and "<h2>5. Contacts</h2>" in ir,
   "IRP: organizations that must be told, with how and by when")
ok("☐ <strong>Required reports:</strong>" in ir and ir.index("Required reports:</strong>") < ir.index("Accounts:</strong>"), "IRP: a checklist step for those reports, early")
ok(all(x in ir for x in ["<h3>Communication plan</h3>", "Inside {{client_name}}:", "Outside {{client_name}}:", "Who is told:", "holding statement"])
   and "<h2>11. Incidents that may involve sensitive data</h2>" in ir and "<h2>14. Testing</h2>" in ir and "tabletop" in ir and "includes the communication plan and any required incident reports" in ir,
   "IRP: an internal and external communication plan; section numbers and annual testing unchanged")
ac = text(body("access-control-policy"))
ok("[within 5 business days / on the effective date of the change]" in ac and "[within 24 hours of departure / at departure, no later than the end of the last working day]" in ac
   and "immediately" in ac, "Access Control: removal on the day of a departure or role change as a choice")

# ---- every changed template: editor-safe tags, known placeholders, generic wording
known = {"client_name", "client_address", "client_contact", "client_contact_email", "client_phone", "client_website", "vcio_name", "company_name",
         "company_phone", "company_emergency_phone", "company_email", "company_website", "today", "year"}
allowed = {"p", "br", "h1", "h2", "h3", "h4", "strong", "em", "u", "ul", "ol", "li", "blockquote", "hr", "a"}
banned = r"(?i)huntress|mountaineer|siskiyou|yreka|scalepad|vcio toolbox|lifecycle manager|\bMarco\b|knowbe4|ninjaone"
for slug in CHANGED:
    b = body(slug)
    tags = set(re.findall(r"</?([a-z0-9]+)", b))
    ph = set(re.findall(r"\{\{\s*([a-z_]+)\s*\}\}", b))
    ok(tags <= allowed and ph <= known and not re.search(banned, b), f"{slug}: allowed tags {sorted(tags - allowed)}, placeholders {sorted(ph - known)}, generic wording")
    ok(b.count("<ul>") == b.count("</ul>") and b.count("<li>") == b.count("</li>") and b.count("<p>") == b.count("</p>"), f"{slug}: balanced tags")
    ok(not re.search(r"<li>(?:(?!</li>).)*<(?:ul|ol)>", b, re.S), f"{slug}: no lists inside list items (the editor flattens them)")
    d, r = make(slug)
    ok("{{" not in d and len(d) > 4000 and not errs(r.text), f"{slug}: a document is made ({len(d)} bytes)")

# ---- existing installs. 1: every 2.23.1 body (and a 2.15.0 one) and old description is updated
DESC["access-control-policy"] = "Account provisioning and removal, least privilege, admin accounts, access reviews and session lock."
DESC["incident-response"] = ("What staff do first, how to report, severity levels, and how the MSP triages, contains and recovers, "
                             "with steps for incidents involving sensitive data and notifications.")
keep = {s: row(s) for s in CHANGED}
inc = keep["incident-response"]
linked = [d["id"] for d in q("select id from documents where template_id=%s", inc["id"])]
try:
    for slug in CHANGED:
        q("update document_templates set body_html=%s, description=%s where slug=%s", open(OLD + slug + ".html").read(), DESC[slug], slug)
    out = migrate()
    ok(out.stdout.strip() == "ok", "the migration runs twice without error " + out.stderr[-300:])
    for slug in CHANGED:
        ok(row(slug)["body_html"] == body(slug) and row(slug)["description"] == keep[slug]["description"], f"{slug}: the 2.23.1 body and description are updated")
    q("update document_templates set body_html=%s where slug='security-awareness-policy'", open(OLDER + "security-awareness-policy.html").read())
    migrate()
    ok(row("security-awareness-policy")["body_html"] == body("security-awareness-policy"), "a body still on 2.15.0 is updated too")

    # 2: an edited body or description is left alone, a deleted template stays deleted
    q("update document_templates set body_html='<h1>Our own access policy</h1>' where slug='access-control-policy'")
    q("update document_templates set body_html=%s, description='Our own patch policy' where slug='vulnerability-patch-policy'", open(OLD + "vulnerability-patch-policy.html").read())
    q("update documents set template_id=null where template_id=%s", inc["id"])
    q("delete from document_templates where slug='incident-response'")
    out = migrate()
    ok(out.stdout.strip() == "ok", "it runs again without error " + out.stderr[-300:])
    ok(row("access-control-policy")["body_html"] == "<h1>Our own access policy</h1>", "a template the MSP edited is left alone")
    ok(row("vulnerability-patch-policy")["body_html"] == body("vulnerability-patch-policy") and row("vulnerability-patch-policy")["description"] == "Our own patch policy",
       "an old body is updated even when its description was edited; the edited description stays")
    ok(row("incident-response") is None, "a built-in template the MSP deleted stays deleted")
finally:
    # ---- put the seed back
    if row("incident-response") is None:
        q("insert into document_templates (id, slug, name, category, description, body_html, is_builtin) values (%s,%s,%s,%s,%s,%s,1)",
          inc["id"], inc["slug"], inc["name"], inc["category"], inc["description"], inc["body_html"])
    for d in linked:
        q("update documents set template_id=%s where id=%s", inc["id"], d)
    for slug, t in keep.items():
        q("update document_templates set body_html=%s, description=%s, updated_at=%s where slug=%s", t["body_html"], t["description"], t["updated_at"], slug)
done()
