"""2.8.0 Vendors: ITFlow's client vendors sync in (the MSP's own vendors and unlinked clients' are skipped; text from
ITFlow is cleaned; client vendors made from one ITFlow template share one Align template; archived there = retired
here and back again; an empty answer retires nothing; a vendor added by hand is taken over, not duplicated); a PSA
license links to its vendor. By hand: vendors from a template follow it (a blank field shows the template's, a filled
one overrides), Align licenses link by vendor name (kept through a rename), license vendors not on the list are offered,
save as template, deleting a template keeps what vendors showed, PSA details can't be changed or deleted, retire and
restore, duplicates refused, names escaped, techs only, audited."""
import re, json, subprocess, atexit, requests, html as H
from lib import *

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
def sync(): return phpv("echo Align\\Vendors\\Vendors::syncFromPsa(Align\\Providers\\Providers::psa()), ' | ', Align\\Licensing\\Licenses::syncFromPsa(Align\\Providers\\Providers::psa());")
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def cv(psa): return (q("select * from client_vendors where psa_id=%s", psa) or [None])[0]
def cid_of(psa): return q("select id from clients where psa_id=%s", psa)[0]["id"]

C1, C2, C3 = cid_of("1"), cid_of("2"), cid_of("3")
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
made_templates, touched = [], []


def cleanup():
    for vid, fields in touched:
        mock("/mock/vendor-edit", {"vendor_id": vid, "fields": fields})
    for vid in (20, 21, 22):
        mock("/mock/vendor-delete", {"vendor_id": vid})
    q("delete from licenses where name like 'VE %%'")
    q("delete from client_vendors where psa_id in ('20','21','22') or (source='manual' and client_id in (%s,%s,%s))", C1, C2, C3)
    for t in made_templates:
        q("delete from vendor_templates where name=%s", t)
    q("update vendor_templates set name='Comcast Business', support_phone='800-555-0101' where psa_template_id='5'")
    q("delete from settings where name='psa_vendors_empty_since'")
    sync()


atexit.register(cleanup)

# ---- what the seed's sync brought in
out = sync()
ok("vendors" in out and "Exception" not in out and "SQLSTATE" not in out, "the vendor and license syncs run: " + out[:160])
ok(cv("10") and cv("11") and cv("13") and cv("12") and cv("10")["client_id"] == C1 and cv("12")["client_id"] == C2, "client vendors come in under their clients")
ok(not cv("14") and not cv("1") and not q("select 1 from client_vendors where name like 'Microsoft (via%%'"), "the MSP's own vendors and an unlinked client's vendor are skipped")
v10 = cv("10")
ok(v10["support_phone"] == "+1 800-555-0101 ext. 2" and v10["account_number"] == "CB-1001" and v10["contact_name"] == "Pat Rep" and v10["notes"] == "Circuit 12/ABCD/345\nStatic block /29"
   and v10["hours"] == "24/7" and v10["sla"] == "4 hours" and v10["source"] == "psa", "details come across (phone with country code and extension; notes keep their line break): %s" % v10["support_phone"])
ok(cv("13")["account_number"] == "AD  -9", "control characters in ITFlow's text are removed (line breaks become spaces): %r" % cv("13")["account_number"])
tpl = q("select * from vendor_templates where psa_template_id='5'")
ok(len(tpl) == 1 and tpl[0]["name"] == "Comcast Business" and tpl[0]["category"] == "internet" and cv("10")["template_id"] == cv("12")["template_id"] == tpl[0]["id"],
   "client vendors from the same ITFlow template share one Align template (category guessed)")
ok(cv("10")["category"] is None and cv("11")["category"] == "registrar" and cv("13")["category"] == "software", "a template's vendor takes its category from it; others get a guess")
lic105 = q("select vendor_id, psa_vendor_id from licenses where psa_id='105'")[0]
ok(lic105["vendor_id"] == cv("13")["id"] and lic105["psa_vendor_id"] == "13", "a license from ITFlow links to the client vendor ITFlow names")
ok(q("select vendor_id from licenses where psa_id='101'")[0]["vendor_id"] is None, "a license bought through the MSP's distributor isn't linked to a client vendor")
page = st.get(B + f"/clients/{C1}/vendors").text
t = text(page)
ok(not errs(page) and "Comcast Business" in t and "CB-1001" in t and "GoDaddy" in t and "Adobe" in t and "Internet provider" in t, "the client's Vendors page lists them by category")
ok(not re.search(r'href="[^"]*javascript', page) and 'href="https://www.godaddy.example.com"' in page, "websites are linked only as http(s) (a javascript: one stays text)")
ok(re.search(r"Adobe\s*</a>\s*<span[^>]*>ITFlow", page) and "1 license" in t, "a PSA vendor is marked as from ITFlow and counts its linked license")
lp = st.get(B + f"/clients/{C1}/licenses").text
ok(re.search(r'href="/clients/%d/vendors"[^>]*><i class="fas fa-store[^"]*"></i>Adobe' % C1, lp), "Licensing shows the linked vendor, linking to Vendors")

# ---- changes in ITFlow: edited, archived (retired), restored; one added and deleted
touched.append((11, {"vendor_account_number": "GD-77", "vendor_archived_at": None}))
mock("/mock/vendor-edit", {"vendor_id": 11, "fields": {"vendor_account_number": "GD-78"}})
sync()
ok(cv("11")["account_number"] == "GD-78", "a change in ITFlow updates the vendor")
mock("/mock/vendor-edit", {"vendor_id": 11, "fields": {"vendor_archived_at": "2026-01-01 00:00:00"}})
sync()
ok(cv("11")["retired_at"] and cv("11")["retired_reason"] == "psa", "archived in ITFlow: retired here, not deleted")
ok("retired in ITFlow" in text(st.get(B + f"/clients/{C1}/vendors?retired=1").text), "...and shown as retired in ITFlow")
mock("/mock/vendor-edit", {"vendor_id": 11, "fields": {"vendor_archived_at": None}})
sync()
ok(cv("11")["retired_at"] is None, "restored in ITFlow: restored here")
mock("/mock/vendor-add", {"vendor_id": 20, "fields": {"vendor_name": "Example Copier Co", "vendor_client_id": 1}})
sync()
ok(cv("20") and cv("20")["category"] == "print", "a new vendor in ITFlow appears (category guessed)")
mock("/mock/vendor-delete", {"vendor_id": 20})
sync()
ok(cv("20")["retired_at"] is not None, "deleted in ITFlow: retired")

# ---- an empty answer changes nothing
for vid in (10, 11, 12, 13):
    mock("/mock/vendor-edit", {"vendor_id": vid, "fields": {"vendor_archived_at": "2026-01-01 00:00:00"}})
    touched.append((vid, {"vendor_archived_at": None}))
out = sync()
ok("returned no client vendors" in out and cv("10")["retired_at"] is None and cv("12")["retired_at"] is None, "no client vendors from ITFlow: refused, nothing retired: " + out[:120])
for vid in (10, 11, 12, 13):
    mock("/mock/vendor-edit", {"vendor_id": vid, "fields": {"vendor_archived_at": None}})
sync()
ok(not q("select 1 from settings where name='psa_vendors_empty_since' and value is not null and value <> ''"), "the empty-answer clock clears once vendors come back")

# ---- a vendor added by hand, then in ITFlow with the same name: taken over
tok = lambda s, p: csrf(s, p)
tech.post(B + f"/clients/{C1}/vendors", data={"_csrf": tok(tech, f"/clients/{C1}/vendors"), "name": "Example ISP Co", "services": "Backup line", "account_number": "X1"})
mine = q("select * from client_vendors where client_id=%s and name='Example ISP Co'", C1)
ok(mine and mine[0]["source"] == "manual" and mine[0]["category"] == "internet", "a tech adds a vendor by name (category guessed)")
mock("/mock/vendor-add", {"vendor_id": 21, "fields": {"vendor_name": "example  isp co", "vendor_client_id": 1, "vendor_account_number": "X2"}})
sync()
again = q("select * from client_vendors where client_id=%s and (name='Example ISP Co' or psa_id='21')", C1)
ok(len(again) == 1 and again[0]["id"] == mine[0]["id"] and again[0]["psa_id"] == "21" and again[0]["account_number"] == "X2" and again[0]["services"] == "Backup line",
   "the same vendor from ITFlow takes over the hand-added one (ITFlow's details, Align's services kept)")

# ---- a PSA vendor: details stay ITFlow's, Align's fields save, can't be deleted
v = cv("10")
tech.post(B + f"/vendors/{v['id']}", data={"_csrf": tok(tech, f"/clients/{C1}/vendors"), "action": "save", "name": "Hacked", "account_number": "nope",
    "template_id": str(v["template_id"]), "category": "", "services": "1 Gbps fiber", "align_notes": "Renewal talk in March"})
v2 = cv("10")
ok(v2["name"] == "Comcast Business" and v2["account_number"] == "CB-1001" and v2["services"] == "1 Gbps fiber" and v2["align_notes"] == "Renewal talk in March",
   "a PSA vendor's details can't be changed in Align; services and notes save")
r = tech.post(B + f"/vendors/{v['id']}", data={"_csrf": tok(tech, f"/clients/{C1}/vendors"), "action": "delete"})
ok(cv("10") and "can be retired but not deleted" in flash(r.text), "a PSA vendor can't be deleted")

# ---- a template cleared in Align stays cleared through the sync (set only when a vendor first arrives)
tech.post(B + f"/vendors/{v['id']}", data={"_csrf": tok(tech, f"/clients/{C1}/vendors"), "action": "save", "template_id": "", "category": "internet", "services": "1 Gbps fiber"})
sync()
ok(cv("10")["template_id"] is None and cv("10")["category"] == "internet", "a PSA vendor's template set to none in Align stays none after a sync")
q("update client_vendors set template_id=%s, category=NULL where psa_id='10'", v["template_id"])

# ---- a name the database counts as the same (accents) never breaks the sync
made_templates.append("Telefónica Empresas")
tech.post(B + "/vendors/templates", data={"_csrf": tok(tech, "/vendors"), "name": "Telefónica Empresas", "category": "internet"})
mock("/mock/vendor-add", {"vendor_id": 22, "fields": {"vendor_name": "Telefonica Empresas", "vendor_client_id": 1, "vendor_template_id": 9}})
out = sync()
ok("SQLSTATE" not in out and "Exception" not in out and cv("22")["template_id"] == q("select id from vendor_templates where name='Telefónica Empresas'")[0]["id"],
   "a PSA template named like an existing one but for accents uses that template: " + out[:100])
r = tech.post(B + "/vendors/templates", data={"_csrf": tok(tech, "/vendors"), "name": "telefonica empresas"})
ok(r.status_code == 200 and "already a template" in flash(r.text), "...and adding one by hand is refused politely, not a database error")
mock("/mock/vendor-delete", {"vendor_id": 22})
sync()

# ---- by hand, from a template: follows the template, a filled field overrides
T = tpl[0]["id"]
r = tech.post(B + f"/clients/{C3}/vendors", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "template_id": str(T), "name": "", "account_number": "C3-ACCT", "support_phone": tpl[0]["support_phone"]})
mine3 = q("select * from client_vendors where client_id=%s and source='manual'", C3)
ok(len(mine3) == 1 and mine3[0]["name"] is None and mine3[0]["support_phone"] is None and mine3[0]["template_id"] == T,
   "added from a template: the name and a value the same as the template's are left to follow it: " + flash(r.text)[:80])
q("update vendor_templates set support_phone='800-555-0199' where id=%s", T)
t3 = text(st.get(B + f"/clients/{C3}/vendors").text)
ok("Comcast Business" in t3 and "800-555-0199" in t3 and "C3-ACCT" in t3, "the template's new support number shows for that client")
tech.post(B + f"/vendors/{mine3[0]['id']}", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "action": "save", "template_id": str(T), "name": "", "support_phone": "877-555-0100", "account_number": "C3-ACCT"})
ok("877-555-0100" in text(st.get(B + f"/clients/{C3}/vendors").text) and "800-555-0199" not in text(st.get(B + f"/clients/{C3}/vendors").text), "a filled-in field overrides the template's")
r = tech.post(B + f"/clients/{C3}/vendors", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "name": "comcast business"})
ok(len(q("select 1 from client_vendors where client_id=%s", C3)) == 1 and "already has" in flash(r.text), "the same vendor twice for a client is refused")
tech.post(B + f"/clients/{C3}/vendors", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "name": "VE Other Co"})
other = q("select id from client_vendors where client_id=%s and name='VE Other Co'", C3)[0]["id"]
r = tech.post(B + f"/vendors/{other}", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "action": "save", "name": "Comcast business", "template_id": ""})
ok(q("select name from client_vendors where id=%s", other)[0]["name"] == "VE Other Co" and "already has a vendor named" in flash(r.text), "renaming a vendor to another of the client's vendors is refused")
q("delete from client_vendors where id=%s", other)

# ---- Align licenses link by vendor name, and keep the link through a rename
tech.post(B + f"/clients/{C3}/licenses", data={"_csrf": tok(tech, f"/clients/{C3}/licenses"), "name": "VE Static IPs", "vendor": "COMCAST business", "license_type": "site", "seats": "1"})
le = q("select * from licenses where name='VE Static IPs'")[0]
ok(le["vendor_id"] == mine3[0]["id"], "an Align license whose vendor name matches a client vendor is linked to it")
mp = tech.get(B + f"/licenses/{le['id']}/form?back=/clients/{C3}/licenses").text
ok('list="modal-license-%d-vendors"' % le["id"] in mp and '<option value="Comcast Business">' in mp and "Linked to the client" in mp, "the license window suggests the client's vendors and says it's linked")
tech.post(B + f"/vendors/templates/{T}", data={"_csrf": tok(tech, "/vendors"), "action": "save", "name": "Comcast Business Fiber", "category": "internet", "support_phone": "800-555-0199"})
le2 = q("select * from licenses where id=%s", le["id"])[0]
ok(le2["vendor"] == "Comcast Business Fiber" and le2["vendor_id"] == mine3[0]["id"], "renaming the template renames the linked Align license's vendor and keeps the link")
ok(cv("10")["name"] == "Comcast Business", "...while a PSA vendor keeps ITFlow's own name")

# ---- license vendors not on the list are offered, and become vendors
tech.post(B + f"/clients/{C3}/licenses", data={"_csrf": tok(tech, f"/clients/{C3}/licenses"), "name": "VE Widget suite", "vendor": "Acme <b>Widgets</b>", "license_type": "user", "seats": "2"})
pg = tech.get(B + f"/clients/{C3}/vendors").text
ok('id="unlinked-vendors"' in pg and "Acme &lt;b&gt;Widgets&lt;/b&gt;" in pg and "<b>Widgets</b>" not in pg, "a license vendor not on the list is offered (escaped)")
ok("Microsoft (via Pax8)" not in text(st.get(B + f"/clients/{C1}/vendors").text), "a PSA license's distributor isn't offered (it never links by name)")
tech.post(B + f"/clients/{C3}/vendors", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "name": "Acme <b>Widgets</b>"})
acme = q("select * from client_vendors where client_id=%s and name='Acme <b>Widgets</b>'", C3)
ok(acme and q("select vendor_id from licenses where name='VE Widget suite'")[0]["vendor_id"] == acme[0]["id"], "adding it links the license")

# ---- save as template; delete a template (vendors keep what they showed)
made_templates.append("Acme <b>Widgets</b>")
q("update client_vendors set support_phone='555-0142', website='acme.example.com' where id=%s", acme[0]["id"])
tech.post(B + f"/vendors/{acme[0]['id']}", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "action": "template"})
at = q("select * from vendor_templates where name='Acme <b>Widgets</b>'")
a2 = q("select * from client_vendors where id=%s", acme[0]["id"])[0]
ok(at and at[0]["support_phone"] == "555-0142" and a2["template_id"] == at[0]["id"] and a2["support_phone"] is None and a2["website"] is None,
   "save as template: a template from its details, and the vendor now follows it")
vp = st.get(B + "/vendors").text
ok(not errs(vp) and "Acme &lt;b&gt;Widgets&lt;/b&gt;" in vp and "<b>Widgets</b>" not in vp and "Comcast Business Fiber" in vp, "the Vendors page lists the templates (escaped)")
r = tech.post(B + "/vendors/templates", data={"_csrf": tok(tech, "/vendors"), "name": "acme <B>widgets</B>"})
ok(len(q("select 1 from vendor_templates where name like 'acme%%'")) == 1 and "already a template" in flash(r.text), "template names are unique")
tech.post(B + f"/vendors/templates/{at[0]['id']}", data={"_csrf": tok(tech, "/vendors"), "action": "delete"})
a3 = q("select * from client_vendors where id=%s", acme[0]["id"])[0]
ok(not q("select 1 from vendor_templates where id=%s", at[0]["id"]) and a3["template_id"] is None and a3["name"] == "Acme <b>Widgets</b>" and a3["support_phone"] == "555-0142",
   "deleting a template: its vendors keep the name and details they showed")

# ---- retire, restore, delete (by hand); viewers read only
tech.post(B + f"/vendors/{acme[0]['id']}", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "action": "retire"})
ok(q("select retired_reason from client_vendors where id=%s", acme[0]["id"])[0]["retired_reason"] == "align" and q("select vendor_id from licenses where name='VE Widget suite'")[0]["vendor_id"] == acme[0]["id"],
   "retired: its licenses stay linked")
tech.post(B + f"/vendors/{acme[0]['id']}", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "action": "restore"})
ok(q("select retired_at from client_vendors where id=%s", acme[0]["id"])[0]["retired_at"] is None, "restored")
vpage = viewer.get(B + f"/clients/{C3}/vendors").text
ok("Comcast Business Fiber" in text(vpage) and 'data-bs-target="#modal-vendor"' not in vpage and "data-lazy-modal" not in vpage, "viewers see vendors without edit controls")
viewer.post(B + f"/clients/{C3}/vendors", data={"_csrf": tok(viewer, f"/clients/{C3}"), "name": "Viewer Vendor"})
ok(not q("select 1 from client_vendors where name='Viewer Vendor'"), "viewers can't add vendors")
ok(viewer.get(B + f"/vendors/{acme[0]['id']}/form").status_code in (302, 403) or "Edit" not in viewer.get(B + f"/vendors/{acme[0]['id']}/form").text, "...or open the edit form")
tech.post(B + f"/vendors/{acme[0]['id']}", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "action": "delete"})
ok(not q("select 1 from client_vendors where id=%s", acme[0]["id"]) and q("select vendor_id, vendor from licenses where name='VE Widget suite'")[0] == {"vendor_id": None, "vendor": "Acme <b>Widgets</b>"},
   "deleted: the license keeps the vendor name, unlinked")
ok(all(q("select 1 from audit_log where action=%s", a) for a in ["vendor.create", "vendor.update", "vendor.retire", "vendor.restore", "vendor.delete",
    "vendor_template.create", "vendor_template.update", "vendor_template.delete"]), "every change is audited")

# ---- the 2-minute poll runs the vendor sync and counts its changes
touched.append((13, {"vendor_account_number": "AD\r\n-9\x00"}))
mock("/mock/vendor-edit", {"vendor_id": 13, "fields": {"vendor_account_number": "AD-10"}})
out = phpv("echo Align\\Sync\\PsaAssetSync::run(Align\\Providers\\Providers::psa());")
a = q("select detail from audit_log where action='sync.psa_poll' order by id desc limit 1")
ok(cv("13")["account_number"] == "AD-10" and "vendors" in out and a and "vendor or license change" in a[0]["detail"], "the PSA poll syncs vendors and audits the change: " + out[:120])
done()
