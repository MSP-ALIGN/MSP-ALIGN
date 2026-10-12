"""2.15.1 document templates: the expanded Acceptable Use Policy (AI tools, prohibited activities, enforcement), the
rewritten Incident Response Plan (MSP-led response, definitions, roles, severity levels), WISP, BC/DR and Information
Security Policy additions, and the new Data Classification & Handling and Risk Management policies. Migration 074
updates a built-in template only while it still holds the previous built-in body (an edited one is left alone), adds
missing new templates, and can run twice. Every template uses only the editor's tags and known placeholders, has no
MSP-, vendor- or source-specific wording, and makes a document with nothing left unfilled. {{company_emergency_phone}}
comes from Settings → General and falls back to the office phone."""
import re, html as H
from lib import *

TPL = ROOT + "/db/templates/"
MIG = ROOT + "/db/migrations/074_template_updates.php"
CHANGED = ["acceptable-use", "incident-response", "wisp-ftc", "bcdr-plan", "information-security-policy", "mobile-byod-policy",
           "security-awareness-policy", "password-mfa-policy", "cui-handling-policy", "asset-management-policy", "hipaa-breach-notification"]
OLD = ROOT + "/tests/e2e/fixtures/templates-2.15.0/"  # the 2.15.0 bodies of the templates 074 replaces (from db/templates)
NEW = ["data-classification-policy", "risk-management-policy"]
st = login("admin@example.com", "LongPassword123!")
phone0 = (q("select value from settings where name='company_phone'") or [{}])[0].get("value")


def body(slug): return open(TPL + slug + ".html").read()
def row(slug): return (q("select * from document_templates where slug=%s", slug) or [None])[0]
def migrate(): return php(f'(require "{MIG}")(); (require "{MIG}")(); echo "ok";')


def make(slug, client=1):
    """A document made from the template for a client: its filled-in body."""
    tid = row(slug)["id"]
    r = st.post(B + "/documents", data={"_csrf": csrf(st, f"/clients/{client}/documents"), "client_id": str(client), "template_id": str(tid)})
    did = int(r.url.rstrip("/").split("/")[-1])
    d = q("select body_html from documents where id=%s", did)[0]["body_html"]
    q("delete from documents where id=%s", did)
    return d, r


# ---- the seeded install ran 007 (old bodies) then 074: the built-in rows are now the new files
for slug in CHANGED + NEW:
    t = row(slug)
    ok(t and t["is_builtin"] == 1 and t["body_html"] == body(slug), f"{slug}: the built-in template is the 2.15.1 version")
ok("AI tools" in row("acceptable-use")["description"] and "severity" in row("incident-response")["description"], "the 1.x descriptions are updated")

# ---- what the templates say
aup, ir = body("acceptable-use"), body("incident-response")
for s, m in [("Artificial intelligence (AI) tools", "an AI section"), ("[approved tools, e.g. Microsoft 365 Copilot", "approved AI tools as a bracket"),
             ("isn't used to train", "business accounts only"), ("is responsible for checking it", "people check AI output"),
             ("without telling everyone in the meeting first", "meeting recorders"), ("deepfake", "deepfakes are reported"),
             ("Security Awareness Training Policy", "cross-reference to AI training"), ("shadow IT", "unapproved cloud apps"),
             ("single sign-on", "single sign-on"), ("<h2>12. Enforcement</h2>", "an Enforcement section"), ("Review and approval", "review and approval"),
             ("Acknowledgment", "a signed acknowledgment")]:
    ok(s in aup, "AUP: " + m)
for s, m in [("Event:</strong>", "defines event"), ("Breach:</strong>", "defines breach"), ("Incident Handler", "an MSP Incident Handler"),
             ("Service Manager", "a client Service Manager"), ("<h2>8. Severity levels</h2>", "severity levels"),
             ("Do not reboot", "staff don't try to fix it"), ("Never report from a device or email account that might be compromised", "report from a clean device"),
             ("Check for regulated data", "MSP intake checks for regulated data"), ("Persistence", "remediation checklist"),
             ("cyber insurance", "keeps the insurance step"), ("tabletop", "keeps tabletop testing"), ("managed detection and response service", "neutral MDR wording")]:
    ok(s in ir, "IRP: " + m)
ok(all(x in ir for x in ["<strong>Critical:</strong>", "<strong>High:</strong>", "<strong>Medium:</strong>", "<strong>Low:</strong>"]) and "Resource Proprietor" not in ir,
   "IRP: four severity levels, no leftover roles")
w = body("wisp-ftc")
ok(all(x in w for x in ["Filing cabinets", "Clean desk", "escorted", "speaks for {{client_name}}", "Employee acknowledgment", "non-disclosure", "[90 days]", "different channel"]),
   "WISP: where data lives, clean desk, visitors, spokesperson, acknowledgment, NDA, log reviews, passwords apart")
ok(not re.search(r"(?i)\btax|e-file|4557|stakeholder liaison", w), "WISP: nothing specific to tax preparers")
ok("<h2>8. Emergency mode operation</h2>" in body("bcdr-plan") and "<h2>14. Review and approval</h2>" in body("bcdr-plan") and "section 10 using" in body("bcdr-plan"),
   "BC/DR: an emergency mode section, sections renumbered")
isp = body("information-security-policy")
ok("guest Wi-Fi" in isp and "AutoRun" in isp and "before they join the business network" in isp and "Data Classification &amp; Handling Policy" in isp, "ISP: baseline network and device rules, links to the new policies")
dc, rm = body("data-classification-policy"), body("risk-management-policy")
ok(all(f"<strong>{x}:</strong>" in dc for x in ["Public", "Internal", "Confidential", "Restricted"]) and "[PHI]" in dc and "[Card data]" in dc and "[CUI]" in dc
   and "Data custodian" in dc, "Data Classification: the four ISP levels, roles and rules by data type")
ok("NIST SP 800-30" in rm and "quarterly business review" in rm and "[HIPAA]" in rm and "Cost-benefit" in rm, "Risk Management: NIST steps, QBR cadence, HIPAA only where it applies")

# ---- every built-in template: editor-safe tags, known placeholders, generic wording
known = {"client_name", "client_address", "client_contact", "client_contact_email", "client_phone", "client_website", "vcio_name", "company_name",
         "company_phone", "company_emergency_phone", "company_email", "company_website", "today", "year"}
allowed = {"p", "br", "h1", "h2", "h3", "h4", "strong", "em", "u", "ul", "ol", "li", "blockquote", "hr", "a"}
banned = r"(?i)huntress|mountaineer|siskiyou|yreka|scalepad|vcio toolbox|lifecycle manager|security scientist|ADOA|P8110|Pub(lication)? 4557|\bMarco\b"
for slug in CHANGED + NEW:
    b = body(slug)
    tags = set(re.findall(r"</?([a-z0-9]+)", b))
    ph = set(re.findall(r"\{\{\s*([a-z_]+)\s*\}\}", b))
    ok(tags <= allowed and ph <= known and not re.search(banned, b), f"{slug}: allowed tags {sorted(tags - allowed)}, placeholders {sorted(ph - known)}, generic wording")
    if slug in CHANGED[:5] + NEW:  # the templates rewritten here (three older ones still nest a list)
        ok(not re.search(r"<li>(?:(?!</li>).)*<(?:ul|ol)>", b, re.S), f"{slug}: no lists inside list items (the editor flattens them)")
    ok(len(re.sub(r"<[^>]+>", " ", b).split()) >= 600, f"{slug}: a usable length ({len(re.sub(r'<[^>]+>', ' ', b).split())} words)")

# ---- the 24/7 security phone: Settings → General, and the office phone when it's blank
setting("company_phone", "555-0100"); setting("company_emergency_phone", "")
d, r = make("incident-response")
ok("555-0100" in d and "{{" not in d and not errs(r.text), "with no 24/7 phone the incident plan shows the office phone")
t = st.get(B + "/settings").text
ok('name="company_emergency_phone"' in t and not errs(t), "Settings → General has the 24/7 security phone")
st.post(B + "/settings", data={"_csrf": csrf(st, "/settings"), "_tab": "general", "company_emergency_phone": "555-0199"})
ok((q("select value from settings where name='company_emergency_phone'") or [{}])[0].get("value") == "555-0199", "it saves")
d, r = make("incident-response")
ok("555-0199" in d and "{{" not in d, "the incident plan uses the 24/7 phone")
for slug in CHANGED + NEW:
    d, r = make(slug)
    ok("{{" not in d and len(d) > 4000 and not errs(r.text), f"{slug}: a document is made ({len(d)} bytes)")
setting("company_emergency_phone", "")

# ---- existing installs: every old built-in body is updated, edited ones aren't, only the new templates are added
src = open(ROOT + "/db/migrations/007_wisp_and_templates.php").read()
old = {slug: re.search(r"'slug' => '%s',.*?'body' => <<<'HTML'\n(.*?)\nHTML," % slug, src, re.S).group(1) for slug in ["acceptable-use", "incident-response", "wisp-ftc"]}
old.update({slug: open(OLD + slug + ".html").read() for slug in CHANGED if slug not in old})
ok(sorted(old) == sorted(CHANGED), "an old body for each changed template")
for slug, b in old.items():
    q("update document_templates set body_html=%s where slug=%s", b, slug)
q("update document_templates set description='Rules for employees using company devices, accounts and data.' where slug='acceptable-use'")
q("update document_templates set body_html='<h1>Our own BC/DR plan</h1>' where slug='bcdr-plan'")
q("update documents set template_id=null where template_id=%s", row("risk-management-policy")["id"])
q("delete from document_templates where slug='risk-management-policy'")
ccpa = row("ccpa-privacy-notice")
q("delete from document_templates where slug='ccpa-privacy-notice'")
out = migrate()
ok(out.stdout.strip() == "ok", "the migration runs twice without error " + out.stderr[-300:])
for slug in CHANGED:
    if slug != "bcdr-plan":
        ok(row(slug)["body_html"] == body(slug), f"{slug}: the old built-in body is updated")
ok("AI tools" in row("acceptable-use")["description"], "the old description is updated")
ok(row("bcdr-plan")["body_html"] == "<h1>Our own BC/DR plan</h1>", "a template the MSP edited is left alone")
ok(row("risk-management-policy") and row("risk-management-policy")["body_html"] == rm, "a missing new template is added")
ok(row("ccpa-privacy-notice") is None, "a built-in template the MSP deleted stays deleted")
q("update document_templates set body_html=%s where slug='bcdr-plan'", body("bcdr-plan"))
q("insert into document_templates (id, slug, name, category, description, body_html, is_builtin) values (%s,%s,%s,%s,%s,%s,1)",
  ccpa["id"], ccpa["slug"], ccpa["name"], ccpa["category"], ccpa["description"], ccpa["body_html"])
setting("company_phone", phone0 or "")
done()
