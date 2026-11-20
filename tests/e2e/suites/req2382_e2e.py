"""2.38.2 Required or supporting documents: a framework page lists first the written documents the framework itself
calls for (the FTC Safeguards Rule: the WISP, a written risk assessment and an incident response plan, with the
5,000-consumer exemption noted) and counts only those; everything else is folded into "Supporting documents
(optional)", closed. A framework that checks settings (Microsoft 365 baseline) has no required documents. A program
document's page uses its framework's list; one without a framework (the Information Security Policy) has only
supporting documents. Every framework page opens without errors. Everything is made up."""
import json
from lib import *

C = 3  # Harbor Point Law Group LLP
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")


def tpl(slug): return q("select * from document_templates where slug = %s", slug)[0]
def fw(slug): return q("select id from compliance_frameworks where slug = %s", slug)[0]["id"]
def rows(t): return re.findall(r'data-related="([a-z0-9-]+)" data-state="([a-z]+)" data-required="([01])"', t)
def req(t): return [s for s, _, r in rows(t) if r == "1"]
def opt(t): return [s for s, _, r in rows(t) if r == "0"]
def count(t):
    m = re.search(r'data-related-count>([^<]+)<', t)
    return m.group(1).strip() if m else None
def make(slug):
    r = tech.post(B + "/documents", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "client_id": str(C), "template_id": str(tpl(slug)["id"])})
    return int(r.url.rstrip("/").split("/")[-1])


def cleanup():
    q("delete from documents where client_id = %s", C)
    q("delete from client_frameworks where client_id = %s", C)


cleanup()
try:
    # every required document is an installed template, for a framework that exists
    res = php("$bad = []; foreach (Align\\Docs\\Related::REQUIRED as $f => $docs) { if (!Align\\DB::value('SELECT id FROM compliance_frameworks WHERE slug = ?', [$f])) $bad[] = $f;"
              " foreach ($docs as $s => $n) if (!Align\\DB::value('SELECT id FROM document_templates WHERE slug = ?', [$s]) || trim($n) === '') $bad[] = $s; } echo json_encode($bad);")
    try: bad = json.loads(res.stdout)
    except Exception: bad = ["unreadable"]
    ok(bad == [], f"each required document is an installed template with a note: {res.stdout}{res.stderr[:200]}")

    # ---- the WISP framework: three required, the rest supporting and closed
    FW = fw("wisp-ftc")
    q("insert into client_frameworks (client_id, framework_id, next_review) values (%s, %s, '2027-11-01')", C, FW)
    t = tech.get(B + f"/clients/{C}/compliance/{FW}").text
    ok(not errs(t) and req(t) == ["wisp-ftc", "risk-assessment-report", "incident-response"], f"the WISP needs the WISP, a risk assessment and an incident response plan: {req(t)}")
    ok(len(opt(t)) >= 8 and not set(opt(t)) & set(req(t)), f"the other policies are supporting ({len(opt(t))})")
    ok(count(t) == "0 of 3 required in place", f"only required ones are counted: {count(t)}")
    ok("fewer than 5,000 consumers" in t and "314.4(b)" in t, "with where the rule asks for each, and the small-business exemption")
    ok(re.search(r"<details class=\"border-top\" data-related-optional>\s*<summary[^>]*>Supporting documents \(optional\) · 0 of \d+ in place</summary>", t), "supporting documents are folded away, closed")
    ok(len(rows(t)) == len({r[0] for r in rows(t)}), "no document is listed twice")
    tv = viewer.get(B + f"/clients/{C}/compliance/{FW}").text
    ok(req(tv) == req(t) and "Create from template" not in tv, "viewers see the same split without the buttons")
    RA = make("risk-assessment-report")
    ok(count(tech.get(B + f"/clients/{C}/compliance/{FW}").text) == "0 of 3 required in place", "a required document that is still a Draft isn't counted")
    q("update documents set status = 'active', review_due = '2020-01-01' where id = %s", RA)
    ok(count(tech.get(B + f"/clients/{C}/compliance/{FW}").text) == "0 of 3 required in place", "nor one whose review is overdue")
    q("delete from documents where id = %s", RA)
    IR = make("incident-response")
    q("update documents set status = 'active', review_due = date_add(curdate(), interval 1 year) where id = %s", IR)
    ok(count(tech.get(B + f"/clients/{C}/compliance/{FW}").text) == "1 of 3 required in place", "an Active incident response plan counts")

    # ---- the WISP document's page: its framework's other required documents, then what it points to
    W = make("wisp-ftc")
    t = tech.get(B + f"/documents/{W}").text
    ok(not errs(t) and req(t) == ["risk-assessment-report", "incident-response"] and count(t) == "1 of 2 required in place", f"the WISP's page: the risk assessment and incident response plan are required: {req(t)} {count(t)}")
    ok("encryption-policy" in opt(t) and "wisp-ftc" not in opt(t) + req(t), "the topic policies are supporting, and it doesn't list itself")

    # ---- a program document without a framework: only supporting documents
    I = make("information-security-policy")
    t = tech.get(B + f"/documents/{I}").text
    ok(not errs(t) and req(t) == [] and opt(t) and count(t) is None and "Optional documents that cover topics this one relies on" in t, "the Information Security Policy's page: everything it points to is optional")

    # ---- HIPAA lists its required documents; the Microsoft 365 baseline has none
    H = fw("hipaa-security")
    q("insert into client_frameworks (client_id, framework_id, next_review) values (%s, %s, '2027-11-01')", C, H)
    t = tech.get(B + f"/clients/{C}/compliance/{H}").text
    ok(req(t)[:2] == ["hipaa-risk-analysis", "risk-management-policy"] and "hipaa-baa-checklist" in req(t) and "164.308(a)(7)" in t, f"HIPAA's required documents with their sections: {req(t)}")
    M365 = fw("m365-baseline")
    q("insert into client_frameworks (client_id, framework_id, next_review) values (%s, %s, '2027-11-01')", C, M365)
    t = tech.get(B + f"/clients/{C}/compliance/{M365}").text
    has_rows = bool(json.loads(php("echo json_encode(Align\\Docs\\Related::forFramework('m365-baseline'));").stdout or "[]"))
    ok(not errs(t) and req(t) == [] and count(t) is None and (("checks settings and practices" in t) if has_rows else 'id="related-docs"' not in t), f"the Microsoft 365 baseline requires no documents (supporting ones: {has_rows})")
    ok("HIPAA 164.316 also asks" in tech.get(B + f"/clients/{C}/compliance/{H}").text, "HIPAA notes that each standard needs written policies too")
    S = fw("soc2-tsc")
    q("insert into client_frameworks (client_id, framework_id, next_review) values (%s, %s, '2027-11-01')", C, S)
    t = tech.get(B + f"/clients/{C}/compliance/{S}").text
    ok(re.match(r"\d+ of \d+ expected in place", count(t) or "") and "Auditors look for it" in t and "usually expected" in t, f"SOC 2 says expected, not required: {count(t)}")

    # ---- an affirmation has no other required document: the optional wording, without claiming it covers them
    AF = make("cmmc-l1-affirmation")
    t = tech.get(B + f"/documents/{AF}").text
    ok(req(t) == [] and opt(t) and "Optional documents that cover topics this one relies on" in t and "cover these topics itself" not in t, "the CMMC Level 1 affirmation's page: optional documents only")

    # ---- every framework's page opens cleanly
    needs = set(json.loads(php("echo json_encode(array_keys(Align\\Docs\\Related::REQUIRED));").stdout or "[]"))
    bad = []
    for f in q("select id, slug from compliance_frameworks"):
        q("insert ignore into client_frameworks (client_id, framework_id, next_review) values (%s, %s, '2027-11-01')", C, f["id"])
        t = tech.get(B + f"/clients/{C}/compliance/{f['id']}").text
        if errs(t) or (f["slug"] in needs and not req(t)):
            bad.append(f["slug"])
    ok(not bad, f"every framework page opens, with its required documents: {bad}")
finally:
    cleanup()
done()
