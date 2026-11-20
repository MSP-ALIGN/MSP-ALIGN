"""2.39.0 Easier documents:
- a client's details for documents (security lead, privacy official, executive sponsor, ...) are entered once on its
  Documents page by a tech or admin (viewers read them) and fill the matching "[name, title]" blanks in documents made
  from a template; approval lines ("Approved by: [name, title]") are left for the signers;
- "Fill from client details" fills them in a document made earlier, on the version the editor has (409 otherwise),
  with a history entry;
- when a detail changes, the Documents page offers to update the Draft documents that still name the old value
  (from the session, never from the form); Active documents are never changed; "Leave them" changes nothing;
- the editor lists the blanks left (with a count, grouped by section) and the outline; filling one from the panel
  is an ordinary autosaved edit; making a document Active with blanks left asks first; the signature and
  acknowledgment dialogs warn while blanks remain.
Everything is made up."""
from playwright.sync_api import sync_playwright
from lib import *

C = 3  # Harbor Point Law Group LLP
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")


def tpl(slug): return q("select * from document_templates where slug = %s", slug)[0]
def body(d): return q("select body_html from documents where id = %s", d)[0]["body_html"]
def ver(d): return q("select version from documents where id = %s", d)[0]["version"]
def blanks(h):
    """Blanks in a body, counted like Docs\\Blanks (text only, not a plain number)."""
    t = H.unescape(re.sub(r"<[^>]+>", "\n", h))
    return len([b for b in re.findall(r"\[[^\[\]\r\n]{1,120}\]", t) if not re.fullmatch(r"\[\d+\]", b)])
def new_doc(slug):
    r = tech.post(B + "/documents", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "client_id": str(C), "template_id": str(tpl(slug)["id"])})
    return int(r.url.rstrip("/").split("/")[-1])
def facts(**kw):
    data = {"_csrf": csrf(tech, f"/clients/{C}/documents")}
    data.update({f"facts[{k}]": v for k, v in kw.items()})
    return tech.post(B + f"/clients/{C}/document-facts", data=data)


def cleanup():
    q("delete from documents where client_id = %s", C)
    q("delete from client_document_facts where client_id = %s", C)


cleanup()
try:
    ok(q("select count(*) n from information_schema.tables where table_schema = database() and table_name = 'client_document_facts'")[0]["n"] == 1, "migration 103 adds client_document_facts")

    # ---- a document made before there are any details keeps its blanks
    E = new_doc("encryption-policy")
    ok("Security lead ([name, title])" in body(E), "without client details the security lead blank stays")
    t = tech.get(B + f"/documents/{E}").text
    ok(not errs(t) and 'id="doc-guide"' in t and "Add client details" in t and 'id="doc-fill-facts"' not in t, "the editor has the Blanks / Outline card and links to the client details")

    # ---- the client details card
    t = tech.get(B + f"/clients/{C}/documents").text
    ok(not errs(t) and 'id="doc-facts"' in t and 'name="facts[security_lead]"' in t and "Save details" in t, "the Documents page has the client details card")
    tv = viewer.get(B + f"/clients/{C}/documents").text
    ok('name="facts[security_lead]"' in tv and "Save details" not in tv and "readonly" in tv, "viewers see the details read-only")
    r = viewer.post(B + f"/clients/{C}/document-facts", data={"_csrf": csrf(viewer, f"/clients/{C}/documents"), "facts[security_lead]": "Nope"}, allow_redirects=False)
    ok(r.status_code in (302, 403) and not q("select 1 from client_document_facts where client_id = %s", C), "a viewer can't save them")

    r = facts(security_lead="Dana Whitfield, Office Manager", executive_sponsor="Morgan Ellery, Managing Partner",
              privacy_official="  Riley\tQuinn,\nCompliance  ", affirming_official="x" * 300, bogus="ignored")
    got = {x["fact"]: x["value"] for x in q("select fact, value from client_document_facts where client_id = %s", C)}
    ok("Client details saved" in flash(r.text) and got.get("security_lead") == "Dana Whitfield, Office Manager" and got.get("privacy_official") == "Riley Quinn, Compliance"
       and len(got.get("affirming_official", "")) == 150 and "bogus" not in got, f"saved, cleaned to one line, cut to 150 characters, unknown keys ignored: {sorted(got)}")
    facts(affirming_official="")
    ok(not q("select 1 from client_document_facts where client_id = %s and fact = 'affirming_official'", C), "an empty value clears a detail")
    ok(q("select count(*) n from audit_log where action = 'document.facts'")[0]["n"] >= 1, "saving is in the audit log")

    # ---- a new document from a template uses them; approval lines stay blank
    I = new_doc("information-security-policy")
    h = body(I)
    ok("Security lead (Dana Whitfield, Office Manager)" in h and "Executive sponsor (Morgan Ellery, Managing Partner)" in h, "a new document fills the security lead and executive sponsor")
    ok("Approved by: [name, title]" in h, "the approval line is left for the signers")

    # ---- Fill from client details, on the editor's version
    v = ver(E)
    r = tech.post(B + f"/documents/{E}/facts", data={"_csrf": csrf(tech, f"/documents/{E}"), "base_version": str(v - 1)}, headers={"Accept": "application/json"})
    ok(r.status_code == 409 and "Security lead ([name, title])" in body(E), "an old version gets 409 and nothing changes")
    r = tech.post(B + f"/documents/{E}/facts", data={"_csrf": csrf(tech, f"/documents/{E}"), "base_version": str(v)}, headers={"Accept": "application/json"}).json()
    ok(r.get("filled") == 1 and r.get("version") == v + 1 and "Security lead (Dana Whitfield, Office Manager)" in body(E) and r.get("blanks") == blanks(body(E)),
       f"fills the blank, a new version, the blanks left: {r}")
    ok(q("select note from document_versions where document_id = %s order by id desc limit 1", E)[0]["note"] == "Filled from client details", "with a history entry")
    r = tech.post(B + f"/documents/{E}/facts", data={"_csrf": csrf(tech, f"/documents/{E}"), "base_version": str(v + 1)}, headers={"Accept": "application/json"}).json()
    ok(r.get("filled") == 0 and ver(E) == v + 1, "nothing left to fill: no new version")
    r = viewer.post(B + f"/documents/{E}/facts", data={"_csrf": csrf(viewer, f"/documents/{E}"), "base_version": str(v + 1)}, headers={"Accept": "application/json"}, allow_redirects=False)
    ok(r.status_code in (302, 403) and ver(E) == v + 1, "a viewer can't fill")
    ok('id="doc-fill-facts"' in tech.get(B + f"/documents/{E}").text and 'id="doc-fill-facts"' not in viewer.get(B + f"/documents/{E}").text, "the button is for techs and admins")

    # ---- a detail changes: drafts only, when asked
    q("update documents set status = 'active' where id = %s", I)
    iv = ver(I)
    r = facts(security_lead="Sam Okafor, Operations Lead")
    t = tech.get(B + f"/clients/{C}/documents").text
    ok("data-facts-pending" in t and "Encryption Policy" in t[t.find("data-facts-pending"):t.find("data-facts-pending") + 1500]
       and "Information Security Policy" not in t[t.find("data-facts-pending"):t.find("data-facts-pending") + 1500], "the page offers to update the draft, not the Active document")
    r = tech.post(B + f"/clients/{C}/document-facts/drafts", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "update": "1"})
    ok("Updated 1 draft document" in flash(r.text) and "Security lead (Sam Okafor, Operations Lead)" in body(E), "Update the drafts puts the new name in")
    ok("Dana Whitfield" in body(I) and ver(I) == iv, "the Active document is left as it was")
    ok(q("select note from document_versions where document_id = %s order by id desc limit 1", E)[0]["note"] == "Client details updated", "the draft gets a history entry")
    ok("data-facts-pending" not in tech.get(B + f"/clients/{C}/documents").text, "the offer is gone once answered")
    # Leave them
    facts(security_lead="Lee Marsh, Office Lead")
    ev = ver(E)
    r = tech.post(B + f"/clients/{C}/document-facts/drafts", data={"_csrf": csrf(tech, f"/clients/{C}/documents")})
    ok("left as they are" in flash(r.text) and "Sam Okafor" in body(E) and ver(E) == ev, "Leave them changes nothing")
    # nothing waiting: a crafted update changes nothing
    r = tech.post(B + f"/clients/{C}/document-facts/drafts", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "update": "1", "changes[security_lead][]": ["Sam Okafor, Operations Lead", "<b>x</b>"]})
    ok("Sam Okafor" in body(E) and ver(E) == ev, "the changes come from the session, not the form")
    # (review) two changes before answering: the drafts that still say the first name get the latest one
    facts(security_lead="Sam Okafor, Operations Lead")
    facts(security_lead="Ari Banks, IT Lead")
    facts(security_lead="Jo Park, COO")
    t = tech.get(B + f"/clients/{C}/documents").text
    ok("Sam Okafor, Operations Lead → Jo Park, COO" in H.unescape(t), "two changes in a row are offered as one")
    tech.post(B + f"/clients/{C}/document-facts/drafts", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "update": "1"})
    ok("Security lead (Jo Park, COO)" in body(E), "and update the drafts from the first name to the latest")
    # (review) changed again by someone else before the answer: nothing out of date goes in
    facts(security_lead="Kim Lowe, CFO")
    q("update client_document_facts set value = 'Pat Vo, CEO' where client_id = %s and fact = 'security_lead'", C)
    ev = ver(E)
    tech.post(B + f"/clients/{C}/document-facts/drafts", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "update": "1"})
    ok("Jo Park, COO" in body(E) and ver(E) == ev, "a detail changed since isn't put in with its old value")
    # (review) the whole name, not the start of a longer one
    r = php("[$h, $n] = Align\\Docs\\Facts::replace('<ul><li>Security lead (Christopher Smith, CEO): x</li><li>Security lead (Chris): y</li></ul>', ['security_lead' => ['Chris', 'Dana']]); echo $n, '|', $h;")
    ok(r.stdout.startswith("1|") and "Christopher Smith" in r.stdout and "Security lead (Dana)" in r.stdout, f"replacing a name leaves longer names alone: {r.stdout[:160]}{r.stderr[:200]}")
    r = php("echo json_encode([Align\\Docs\\Facts::clean(\"Ann\\u{202E}Lee\\u{200B}\"), Align\\Docs\\Facts::clean(\"\\xff\\xfe\")]);")
    ok(r.stdout.strip() == '["Ann Lee",""]', f"direction overrides, zero-width spaces and broken text are dropped: {r.stdout}{r.stderr[:200]}")

    # ---- values are text
    facts(executive_sponsor="<script>alert(1)</script> & Co")
    X = new_doc("risk-management-policy")
    h = body(X)
    ok("<script>" not in h and "&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co" in h, "a detail goes in as text")
    t = tech.get(B + f"/clients/{C}/documents").text
    ok("<script>alert(1)</script>" not in t, "and is escaped on the page")
    facts(executive_sponsor="Morgan Ellery, Managing Partner")

    # ---- the dialogs warn while blanks remain
    t = tech.get(B + f"/documents/{E}").text
    m = re.search(r"data-blank-warning[^>]*>.*?data-blank-n>(\d+)<", t, re.S)
    ok(m and int(m.group(1)) == blanks(body(E)) and t.count("data-blank-warning") == 2, f"the signature and acknowledgment dialogs say how many blanks are left ({m and m.group(1)})")

    # ---- the editor: blanks, outline, filling, Active
    with sync_playwright() as p:
        b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1440, "height": 900})
        pg.goto(B + "/login"); pg.fill("input[name=email]", "tech@example.com"); pg.fill("input[name=password]", TECH_PASSWORD); pg.click("button")
        __import__('sitecustomize').after_login(pg, "tech@example.com"); pg.wait_for_load_state()
        pg.goto(B + f"/documents/{E}"); pg.wait_for_selector("#doc-editor .ql-editor"); pg.wait_for_timeout(800)
        n0 = int(pg.inner_text("#doc-blank-count"))
        ok(n0 == blanks(body(E)) and n0 > 0, f"the Blanks count matches the server's ({n0})")
        ok(pg.locator("#doc-blanks .doc-guide-head").count() >= 2 and pg.locator("#doc-blanks .doc-blank").count() == n0, "blanks are listed under their sections")
        heads = pg.locator("#doc-outline .doc-outline-item").all_inner_texts()
        ok(len(heads) >= 5 and any("Purpose" in x for x in heads), f"the outline lists the headings ({len(heads)})")
        first = pg.locator("#doc-blanks .doc-blank").first
        blank = first.get_attribute("data-blank")
        first.locator("input").fill("Zz239 filled value")
        first.locator("button").click()
        pg.wait_for_timeout(2500)
        ok(int(pg.inner_text("#doc-blank-count")) == n0 - 1, "filling one counts down")
        ok("Zz239 filled value" in body(E) and blanks(body(E)) == n0 - 1, f"and it is saved like typing ({blank})")
        # (review) typing just before, so an autosave runs while the question is open: it must not save Active
        pg.click("#doc-editor .ql-editor h1"); pg.keyboard.press("End"); pg.keyboard.type(" zz")
        pg.select_option("#doc-status", "active")
        pg.wait_for_selector(".modal.show", timeout=5000)
        pg.wait_for_timeout(3000)
        ok("blanks left to fill" in pg.inner_text(".modal.show") or "blank left to fill" in pg.inner_text(".modal.show"), "making it Active with blanks left asks first")
        pg.click(".modal.show [data-align-confirm-cancel]")
        pg.wait_for_timeout(1500)
        ok(pg.input_value("#doc-status") == "draft" and q("select status from documents where id = %s", E)[0]["status"] == "draft", "Cancel keeps it a Draft")
        # Fill from client details in the editor
        Z = None
        q("delete from client_document_facts where client_id = %s and fact = 'security_lead'", C)
        Z = new_doc("password-mfa-policy")
        facts(security_lead="Lee Marsh, Office Lead")
        pg.goto(B + f"/documents/{Z}"); pg.wait_for_selector("#doc-editor .ql-editor"); pg.wait_for_timeout(800)
        n1 = int(pg.inner_text("#doc-blank-count"))
        pg.click("#doc-fill-facts"); pg.wait_for_timeout(2500)
        ok("Filled 1 blank" in pg.inner_text("#doc-state") and int(pg.inner_text("#doc-blank-count")) == n1 - 1, "Fill from client details fills and the list follows")
        ok("Security lead (Lee Marsh, Office Lead)" in pg.inner_text("#doc-editor") and "Lee Marsh" in body(Z), "the editor shows the saved result")
        b.close()
finally:
    cleanup()

print("FAILURES:", len(fails))
