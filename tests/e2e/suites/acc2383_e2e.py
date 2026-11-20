"""2.38.3 Compliance accuracy review. The built-in frameworks, document mappings and templates were checked against the
current sources and corrected through db/corrections/2.38.3.php (migration 102, Compliance\\Corrections):
- a fresh install and an upgrade from an old release both end with the corrected content (HIPAA 164.314/164.316 and
  the clearinghouse specification added, PCI DSS Appendix A1, FTC references, CIS v8.1 IG1 with guidance, Microsoft
  365 Security Suite IDs, CMMC wording, corrected templates) and nothing left to review;
- a field an MSP edited keeps their text and is listed on the framework or template page, where an admin can use the
  update or keep theirs; answers are limited to that page's framework or template, and only admins can answer;
- running the corrections again changes nothing;
- the CUI handling policy is no longer offered for CMMC Level 1, and the HIPAA, PCI and CIS required-document lists
  follow the corrected mappings.
Everything is made up."""
import json
from lib import *

C = 3  # Harbor Point Law Group LLP
admin = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)


def fwid(slug, db=None): return q("select id from compliance_frameworks where slug = %s", slug)[0]["id"]
def ctl(slug, ref): return q("select c.* from compliance_controls c join compliance_frameworks f on f.id = c.framework_id where f.slug = %s and c.ref = %s", slug, ref)
def tpl(slug): return q("select * from document_templates where slug = %s", slug)[0]
def apply_again():
    r = php("echo json_encode(Align\\Compliance\\Corrections::apply('2.38.3', require APP_ROOT . '/db/corrections/2.38.3.php'));")
    try: return json.loads(r.stdout)
    except Exception: return {"error": r.stdout + r.stderr[:300]}
import html as _html
def H_unescape(t): return _html.unescape(t)
def rows(t): return re.findall(r'data-related="([a-z0-9-]+)" data-state="([a-z]+)" data-required="([01])"', t)
def req(t): return [s for s, _, r in rows(t) if r == "1"]


def checks(label, Q):
    """The corrected content, read with the query function Q (the main install or an upgraded copy)."""
    n = lambda slug: Q("select count(*) n from compliance_controls c join compliance_frameworks f on f.id = c.framework_id where f.slug = %s", slug)[0]["n"]
    one = lambda slug, ref: (Q("select c.* from compliance_controls c join compliance_frameworks f on f.id = c.framework_id where f.slug = %s and c.ref = %s", slug, ref) or [{}])[0]
    ok(n("hipaa-security") == 48 and one("hipaa-security", "164.316(b)(2)(i)").get("title", "").startswith("Time limit (R)") and one("hipaa-security", "164.314(a)")
       and one("hipaa-security", "164.308(a)(4)(ii)(A)"), f"{label}: HIPAA has 164.314(a), 164.316 and the clearinghouse specification ({n('hipaa-security')} controls)")
    ok(one("hipaa-security", "164.312(a)(2)(iv)").get("title") == "Encryption and decryption: a mechanism to encrypt and decrypt ePHI (A)"
       and one("hipaa-security", "164.308(b)(1)-(3)") and one("hipaa-security", "164.312(e)(2)(ii)").get("tags") == "net_encrypt_transit", f"{label}: HIPAA wording and tags corrected")
    ok(n("pci-dss-4") == 260 and one("pci-dss-4", "A1.1.4") and "BIN and last four" in one("pci-dss-4", "3.4.1").get("title", "")
       and one("pci-dss-4", "6.3.3").get("auto_check") is None, f"{label}: PCI DSS has Appendix A1 and the v4.0.1 wording ({n('pci-dss-4')})")
    ok(one("wisp-ftc", "314.3(a)") and one("wisp-ftc", "314.4(b)(1)") and one("wisp-ftc", "314.4(e)(2)-(4)") and not one("wisp-ftc", "WISP")
       and "does not apply" in one("wisp-ftc", "314.4(j)").get("guidance", ""), f"{label}: FTC Safeguards references corrected")
    ig1 = Q("select name, (select count(*) from compliance_controls c where c.framework_id = f.id and (c.guidance is null or c.section not like '%%.%%')) bad from compliance_frameworks f where slug = 'cis-v8-ig1'")[0]
    ok(ig1["name"] == "CIS Controls v8.1 — IG1" and ig1["bad"] == 0 and "perimeter firewall alone does not count" in one("cis-v8-ig1", "4.4").get("guidance", ""),
       f"{label}: CIS IG1 is v8.1, every safeguard has guidance and the Control's name ({ig1})")
    defender = Q("select count(*) n from compliance_controls c join compliance_frameworks f on f.id = c.framework_id where f.slug = 'm365-baseline' and c.guidance like '%%MS.DEFENDER%%'")[0]["n"]
    ok(defender == 0 and one("m365-baseline", "M365-ID-19") and one("m365-baseline", "M365-DEV-03").get("auto_check") is None, f"{label}: Microsoft 365 cites the Security Suite baseline ({defender} old IDs)")
    ok(one("cmmc-l2", "RA.L2-3.11.1").get("title", "").endswith("transmission of CUI.") and "Phase 2 began Nov 10, 2026" in
       Q("select description d from compliance_frameworks where slug = 'cmmc-l1'")[0]["d"], f"{label}: CMMC wording and dates corrected")
    ok(one("ccpa-cpra", "1798.120(c)") and one("msp-baseline", "MSB-28") and one("cyber-insurance", "CI-20"), f"{label}: the CCPA, MSP baseline and insurance additions are there")
    hra = Q("select body_html b from document_templates where slug = 'hipaa-risk-analysis'")[0]["b"]
    wisp = Q("select body_html b from document_templates where slug = 'wisp-ftc'")[0]["b"]
    ok(hra.count("Signature:") == 1 and "does not require this analysis to be signed" in hra and "evaluates, assesses or tests the security of externally developed applications" in wisp
       and "TeamViewer" not in Q("select body_html b from document_templates where slug = 'remote-access-policy'")[0]["b"], f"{label}: templates corrected (one approval block, 314.4(c)(4), no tool brands)")
    ok(Q("select count(*) n from content_corrections")[0]["n"] == 0, f"{label}: nothing left for an admin to review")


SAVED = q("select * from client_frameworks where client_id = %s", C)  # put back afterwards for later suites

try:
    # ---- a fresh install and the main seed end with the corrected content
    # huntress_e2e runs migration 063 again on this database after 102 cleared M365-DEV-03's check, so 063 links it to
    # huntress_agents; a real upgrade runs 063 before 102. Undo that test-only link first.
    q("update compliance_controls c join compliance_frameworks f on f.id = c.framework_id set c.auto_check = null where f.slug = 'm365-baseline' and c.ref = 'M365-DEV-03' and c.auto_check = 'huntress_agents'")
    checks("main install", q)
    res = apply_again()
    ok(res.get("applied") == 0 and res.get("pending") == 0 and res.get("missing") == 0 and res.get("already", 0) > 200, f"running the corrections again changes nothing: {res}")

    # ---- an upgrade from 1.29 runs every migration to the corrected content
    cfg = load_snapshot("fresh_129", "align_test_acc2383")
    up = subprocess.run(["php", ALIGN, "migrate"], env={**ENV, "ALIGN_CONFIG": cfg}, capture_output=True, text=True)
    ok(up.returncode == 0 and "102_compliance_corrections" in up.stdout, f"the upgrade runs migration 102: {up.stdout[-200:]}{up.stderr[-300:]}")
    def qu(sql, *a):
        c = pymysql.connect(unix_socket=SOCKET, user="root", database="align_test_acc2383", cursorclass=pymysql.cursors.DictCursor, autocommit=True, charset="utf8mb4")
        try:
            with c.cursor() as k:
                k.execute(sql, a)
                return k.fetchall()
        finally:
            c.close()
    checks("upgrade from 1.29", qu)
    mysql("drop database if exists align_test_acc2383")

    # ---- an MSP's edits are kept and offered for review
    H = fwid("hipaa-security")
    enc = ctl("hipaa-security", "164.312(a)(2)(iv)")[0]
    acc = ctl("hipaa-security", "164.310(d)(2)(iii)")[0]
    q("update compliance_controls set title = 'Our own encryption wording' where id = %s", enc["id"])
    q("update compliance_controls set auto_check = 'hw_lifecycle' where id = %s", acc["id"])  # back to what Align shipped
    q("update document_templates set body_html = concat(body_html, '<p>Our extra paragraph.</p>') where slug = 'encryption-policy'")
    res = apply_again()
    ok(res.get("applied") == 1 and res.get("pending") == 2, f"an unedited field is corrected, the edited control and template wait for an admin: {res}")
    ok(ctl("hipaa-security", "164.310(d)(2)(iii)")[0]["auto_check"] is None and ctl("hipaa-security", "164.312(a)(2)(iv)")[0]["title"] == "Our own encryption wording",
       "the MSP's own wording is kept")
    t = admin.get(B + "/frameworks").text
    ok(re.search(rf'href="/frameworks/{H}" class="badge text-bg-warning ms-1" data-pending>1 update to review', t), "the frameworks list says HIPAA has an update to review")
    t = admin.get(B + f"/frameworks/{H}").text
    cid = re.findall(r'data-correction="(\d+)"', t)
    ok(not errs(t) and len(cid) == 1 and "Our own encryption wording" in t and "a mechanism to encrypt and decrypt ePHI" in t and "does not say" in t,
       "the framework page shows their text next to the update, with the reason")
    P = fwid("pci-dss-4")
    r = admin.post(B + f"/frameworks/{P}/corrections/{cid[0]}", data={"_csrf": csrf(admin, f"/frameworks/{P}"), "action": "apply"})
    ok("already handled" in flash(r.text) and ctl("hipaa-security", "164.312(a)(2)(iv)")[0]["title"] == "Our own encryption wording", "a correction posted to another framework's page does nothing")
    r = tech.post(B + f"/frameworks/{H}/corrections/{cid[0]}", data={"_csrf": csrf(tech, "/"), "action": "apply"}, allow_redirects=False)
    ok(r.status_code == 403 and ctl("hipaa-security", "164.312(a)(2)(iv)")[0]["title"] == "Our own encryption wording", f"a technician can't answer it ({r.status_code})")
    q("update compliance_controls set title = 'Our newer wording' where id = %s", enc["id"])
    ok("Our newer wording" in admin.get(B + f"/frameworks/{H}").text, "the card shows the field as it is now, not as it was when the update came")
    r = admin.post(B + f"/frameworks/{H}/corrections/{cid[0]}", data={"_csrf": csrf(admin, f"/frameworks/{H}"), "action": "bogus"})
    ok("already handled" in flash(r.text), "an unknown answer does nothing")
    r = admin.post(B + f"/frameworks/{H}/corrections/{cid[0]}", data={"_csrf": csrf(admin, f"/frameworks/{H}"), "action": "apply"})
    ok("Update applied" in flash(r.text) and ctl("hipaa-security", "164.312(a)(2)(iv)")[0]["title"] == "Encryption and decryption: a mechanism to encrypt and decrypt ePHI (A)"
       and "data-correction=" not in r.text, "Use the update puts the corrected text in and the card goes away")
    ok(q("select status, resolved_by from content_corrections where id = %s", cid[0])[0]["status"] == "applied", "the answer is recorded")
    ok(q("select count(*) n from audit_log where action = 'content.correction.apply'")[0]["n"] >= 1, "and audited")

    # the inserted controls sit where they belong
    refs = [r["ref"] for r in q("select c.ref from compliance_controls c join compliance_frameworks f on f.id = c.framework_id where f.slug = 'hipaa-security' order by c.sort, c.id")]
    ok(refs.index("164.308(a)(3)(ii)(C)") + 1 == refs.index("164.308(a)(4)(ii)(A)") == refs.index("164.308(a)(4)(ii)(B)") - 1
       and refs[-6:] == ["164.314(a)", "164.316(a)", "164.316(b)(1)", "164.316(b)(2)(i)", "164.316(b)(2)(ii)", "164.316(b)(2)(iii)"], f"new HIPAA controls are in order: {refs[8:12]} … {refs[-6:]}")
    # a framework's own field, a control's tags, and a control whose ref changed with an edited title
    q("update compliance_frameworks set description = 'Our own description' where slug = 'wisp-ftc'")
    nist = ctl("nist-csf-2", "ID.RA-02")[0]
    q("update compliance_controls set tags = 'vuln_scan' where id = %s", nist["id"])
    baa = ctl("hipaa-security", "164.308(b)(1)-(3)")[0]
    q("update compliance_controls set ref = '164.308(b)(1)', title = 'Our BAA wording' where id = %s", baa["id"])
    res = apply_again()
    ok(res.get("applied") == 1 and res.get("pending") == 4, f"the ref is corrected; three more wait for an admin (the template kept earlier still differs, and stays answered): {res}")
    ok(ctl("hipaa-security", "164.308(b)(1)-(3)") and ctl("hipaa-security", "164.308(b)(1)-(3)")[0]["title"] == "Our BAA wording", "the ref is corrected and the edited title kept")
    t = admin.get(B + f"/frameworks/{H}").text
    ok("164.308(b)(1)-(3) · Control" in H_unescape(t), "the card names the control by its current ref")
    WF = fwid("wisp-ftc")
    t = admin.get(B + f"/frameworks/{WF}").text
    d = re.findall(r'data-correction="(\d+)"', t)
    r = admin.post(B + f"/frameworks/{WF}/corrections/{d[0]}", data={"_csrf": csrf(admin, f"/frameworks/{WF}"), "action": "apply"})
    ok(len(d) == 1 and "Our own description" in t and "applies regardless of size" in q("select description from compliance_frameworks where slug = 'wisp-ftc'")[0]["description"],
       "a framework's own description can be updated from its page")
    NF = fwid("nist-csf-2")
    d = re.findall(r'data-correction="(\d+)"', admin.get(B + f"/frameworks/{NF}").text)
    admin.post(B + f"/frameworks/{NF}/corrections/{d[0]}", data={"_csrf": csrf(admin, f"/frameworks/{NF}"), "action": "apply"})
    ok(ctl("nist-csf-2", "ID.RA-02")[0]["tags"] == "gov_risk_assessment,vuln_remediation", "a control's tags can be updated")
    q("update compliance_controls set title = 'Written contract or other arrangement with each business associate that meets 164.314(a) (R)' where id = %s", baa["id"])
    t = admin.get(B + f"/frameworks/{H}").text
    ok("Our BAA wording" not in t and q("select status from content_corrections where target_id = %s and field = 'title'", baa["id"])[0]["status"] == "applied",
       "a correction the admin typed in themselves is settled and leaves the page")

    E = tpl("encryption-policy")
    ok(re.search(rf'href="/documents/templates/{E["id"]}" class="badge text-bg-warning ms-1" data-pending>Update to review', admin.get(B + "/documents/templates").text),
       "the templates list marks the edited template")
    t = admin.get(B + f"/documents/templates/{E['id']}").text
    tid = re.findall(r'data-correction="(\d+)"', t)
    ok(not errs(t) and len(tid) == 1 and "Show the updated text" in t and "FIPS 140-3" in t and "Your edits to it are lost" in t, "the template page offers the updated text, warning it replaces theirs")
    r = admin.post(B + f"/frameworks/{H}/corrections/{tid[0]}", data={"_csrf": csrf(admin, f"/frameworks/{H}"), "action": "apply"})
    ok("Our extra paragraph" in tpl("encryption-policy")["body_html"], "a template's correction can't be answered from a framework page")
    r = admin.post(B + f"/documents/templates/{E['id']}/corrections/{tid[0]}", data={"_csrf": csrf(admin, f"/documents/templates/{E['id']}"), "action": "keep"})
    ok("Kept your version" in flash(r.text) and "Our extra paragraph" in tpl("encryption-policy")["body_html"] and "data-correction=" not in r.text, "Keep mine leaves their text and hides the card")
    apply_again()
    ok(q("select status from content_corrections where id = %s", tid[0])[0]["status"] == "kept" and "data-correction=" not in admin.get(B + f"/documents/templates/{E['id']}").text,
       "running the corrections again doesn't bring back an answered one")
    q("update document_templates set body_html = replace(body_html, '<p>Our extra paragraph.</p>', '') where slug = 'encryption-policy'")

    # ---- document mappings
    q("delete from client_frameworks where client_id = %s", C)
    L1, HI, PCI, IG1 = fwid("cmmc-l1"), fwid("hipaa-security"), fwid("pci-dss-4"), fwid("cis-v8-ig1")
    for f in (L1, HI, PCI, IG1):
        q("insert into client_frameworks (client_id, framework_id, next_review) values (%s, %s, '2027-11-01')", C, f)
    t = tech.get(B + f"/clients/{C}/compliance/{L1}").text
    ok(not errs(t) and "cui-handling-policy" not in [s for s, _, _ in rows(t)], "the CUI handling policy isn't offered for CMMC Level 1 (FCI only)")
    t = tech.get(B + f"/clients/{C}/compliance/{HI}").text
    ok({"logging-monitoring-policy", "retention-disposal", "hipaa-baa-checklist"} <= set(req(t)) and "164.312(b) audit controls" in t, f"HIPAA requires audit controls and disposal policies too: {req(t)}")
    t = tech.get(B + f"/clients/{C}/compliance/{PCI}").text
    ok("retention-disposal" in req(t) and "3.2.1" in t, "PCI DSS requires the retention and disposal policy (3.2.1)")
    t = tech.get(B + f"/clients/{C}/compliance/{IG1}").text
    ok("vendor-risk-policy" not in req(t) and "Safeguard 4.1 also asks" in t, "CIS IG1 no longer calls a vendor policy required, and notes 4.1")
finally:
    q("delete from client_frameworks where client_id = %s", C)
    for r in SAVED:
        q("insert into client_frameworks (" + ", ".join(r) + ") values (" + ", ".join(["%s"] * len(r)) + ")", *r.values())
    q("delete from content_corrections")
    mysql("drop database if exists align_test_acc2383")
done()
