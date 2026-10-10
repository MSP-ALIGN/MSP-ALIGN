"""2.9.0 Vendors, step 2: budget lines link to the client vendor their vendor name matches (in Align and through the
REST API; the vendor field suggests the client's vendors; kept through a rename; unlinked when the vendor is deleted;
the migration links lines saved before it); a vendor's costs and next renewal include its budget lines; vendor names
on budget lines that aren't on the list are offered; Renewals shows who each date is with, counts the dates by vendor
across clients and filters to one vendor or client (anything else in the URL is ignored); client vendors import from
a CSV file (add, update, template, category by label, PSA vendors only take Align's fields, duplicate and unknown-client
rows, nothing cleared by a blank cell, licenses and budget lines link to new vendors), techs only, audited."""
import re, json, subprocess, atexit, requests, html as H
from datetime import date, timedelta
from lib import *

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def cid_of(psa): return q("select id from clients where psa_id=%s", psa)[0]["id"]
def line(name): return (q("select * from budget_lines where name=%s", name) or [None])[0]

C1, C2, C3 = cid_of("1"), cid_of("2"), cid_of("3")
CN = {c: q("select name from clients where id=%s", c)[0]["name"] for c in (C1, C2, C3)}
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
tok = lambda s, p: csrf(s, p)
in60 = (date.today() + timedelta(days=60)).isoformat()


def cleanup():
    q("delete from budget_lines where name like 'V2 %%'")
    q("delete from licenses where name like 'V2 %%'")
    q("delete from client_vendors where source='manual' and client_id in (%s,%s,%s)", C1, C2, C3)
    q("delete from vendor_templates where name like 'V2 %%'")
    q("update client_vendors set services=NULL, category=NULL, align_notes=NULL where psa_id in ('10','12')")
    q("delete from api_keys where name like 'v2 %%'")


atexit.register(cleanup)
cleanup()
T = q("select * from vendor_templates where psa_template_id='5'")[0]  # Comcast Business, from the ITFlow sync

# ---- budget lines link by vendor name
tech.post(B + f"/clients/{C3}/vendors", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "template_id": str(T["id"]), "account_number": "C3-1"})
v3 = q("select id from client_vendors where client_id=%s and template_id=%s", C3, T["id"])[0]["id"]
tech.post(B + f"/clients/{C3}/budget", data={"_csrf": tok(tech, f"/clients/{C3}/budget"), "name": "V2 Fiber", "category": "connectivity", "vendor": "comcast BUSINESS",
    "amount": "250", "frequency": "monthly", "start_date": (date.today() - timedelta(days=300)).isoformat(), "contract_end": in60, "notice_days": "30"})
ok(line("V2 Fiber") and line("V2 Fiber")["vendor_id"] == v3, "a budget line whose vendor name matches a client vendor is linked to it")
bl = line("V2 Fiber")
bp = tech.get(B + f"/clients/{C3}/budget").text
ok('list="modal-budget-%d-vendors"' % bl["id"] in bp and '<option value="Comcast Business">' in bp and "Linked to the client" in bp, "the budget line window suggests the client's vendors and says it's linked")
vp = tech.get(B + f"/clients/{C3}/vendors").text
ok("1 budget line" in text(vp) and re.search(r"\$250(\.00)?/mo", text(vp)), "the vendor shows the budget line and its monthly cost: " + text(vp)[text(vp).find("budget line"):][:60])
ok(re.search(r"/renewals\?days=365&amp;client=%d&amp;vendor=comcast\+business" % C3, vp), "its next renewal links to the vendor's dates on Renewals")
tech.post(B + f"/budget-lines/{bl['id']}", data={"_csrf": tok(tech, f"/clients/{C3}/budget"), "name": "V2 Fiber", "category": "connectivity", "vendor": "Someone Else",
    "amount": "250", "frequency": "monthly", "contract_end": in60})
ok(line("V2 Fiber")["vendor_id"] is None, "changing the line's vendor name to one not on the list unlinks it")
tech.post(B + f"/budget-lines/{bl['id']}", data={"_csrf": tok(tech, f"/clients/{C3}/budget"), "name": "V2 Fiber", "category": "connectivity", "vendor": "Comcast Business",
    "amount": "250", "frequency": "monthly", "start_date": (date.today() - timedelta(days=300)).isoformat(), "contract_end": in60, "notice_days": "30"})
ok(line("V2 Fiber")["vendor_id"] == v3, "...and back")

# ---- rename keeps the link; unlinked budget vendors are offered; delete unlinks
tech.post(B + f"/vendors/templates/{T['id']}", data={"_csrf": tok(tech, "/vendors"), "action": "save", "name": "V2 Comcast Renamed Again", "category": "internet",
    "support_phone": T["support_phone"] or ""})
ok(line("V2 Fiber")["vendor"] == "V2 Comcast Renamed Again" and line("V2 Fiber")["vendor_id"] == v3, "renaming the template renames the linked budget line's vendor and keeps the link")
tech.post(B + f"/vendors/templates/{T['id']}", data={"_csrf": tok(tech, "/vendors"), "action": "save", "name": "Comcast Business", "category": "internet", "support_phone": T["support_phone"] or ""})
tech.post(B + f"/clients/{C3}/budget", data={"_csrf": tok(tech, f"/clients/{C3}/budget"), "name": "V2 Phones", "category": "telecom", "vendor": "V2 Voice <i>Co</i>", "amount": "90", "frequency": "monthly"})
vp = tech.get(B + f"/clients/{C3}/vendors").text
ok('id="unlinked-vendors"' in vp and "V2 Voice &lt;i&gt;Co&lt;/i&gt;" in vp and "Licenses or budget lines name vendors" in text(vp), "a budget line's vendor that isn't on the list is offered (escaped)")
tech.post(B + f"/clients/{C3}/vendors", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "name": "V2 Voice <i>Co</i>"})
voice = q("select id from client_vendors where client_id=%s and name='V2 Voice <i>Co</i>'", C3)[0]["id"]
ok(line("V2 Phones")["vendor_id"] == voice, "adding it links the budget line")
tech.post(B + f"/vendors/{voice}", data={"_csrf": tok(tech, f"/clients/{C3}/vendors"), "action": "delete"})
ok(line("V2 Phones")["vendor_id"] is None and line("V2 Phones")["vendor"] == "V2 Voice <i>Co</i>", "deleting the vendor leaves the budget line's vendor name, unlinked")

# ---- the REST API links too; the migration links lines saved before it (and runs again safely)
key = subprocess.run(["php", "-r", PHP + '[$i,$t]=Align\\Api\\Keys::create("v2 key", Align\\Api\\Keys::allScopes(), null, null, 5000, null, 1); echo $t;'], env=ENV, capture_output=True, text=True).stdout.strip()
r = requests.post(B + "/api/v1/budget-lines", headers={"Authorization": "Bearer " + key, "Content-Type": "application/json"},
                  data=json.dumps({"client_id": C3, "name": "V2 Api line", "amount": 10, "vendor": "Comcast business"}), timeout=60)
ok(r.status_code == 201 and line("V2 Api line")["vendor_id"] == v3, "a budget line added through the REST API links to the client vendor: %s" % r.status_code)
q("update budget_lines set vendor_id=NULL where name in ('V2 Api line','V2 Fiber')")
out = phpv('(require "' + ROOT + '/db/migrations/068_vendor_budget.php")(); (require "' + ROOT + '/db/migrations/068_vendor_budget.php")(); echo "done";')
ok(out.endswith("done") and line("V2 Api line")["vendor_id"] == v3 and line("V2 Fiber")["vendor_id"] == v3, "the migration links existing lines by vendor name, and can run again: " + out[-80:])

# ---- Renewals: who each date is with, by vendor, filtered
rp = tech.get(B + "/renewals?days=90").text
t = text(rp)
ok(not errs(rp) and re.search(r"V2 Fiber.{0,200}Comcast Business", t) and 'id="renewals-by-vendor"' in rp, "Renewals shows who a date is with, and a By vendor card")
bv = rp[rp.find('id="renewals-by-vendor"'):]
ok(re.search(r'href="/renewals\?days=90&amp;vendor=comcast\+business">Comcast Business</a>', bv), "the By vendor card links each vendor to its dates")
rf = tech.get(B + "/renewals?days=90&vendor=COMCAST+business").text
ok("Coming up with Comcast Business" in text(rf) and "V2 Fiber" in text(rf) and 'id="renewal-filters"' in rf, "?vendor= shows one vendor's dates (case doesn't matter)")
dates_all = len(re.findall(r'<li class="list-group-item py-2', rp))
dates_v = len(re.findall(r'<li class="list-group-item py-2', rf))
ok(0 < dates_v <= dates_all, f"...fewer or the same dates ({dates_v} of {dates_all})")
rc = tech.get(B + f"/renewals?days=90&client={C3}").text
cu = text(rc[rc.find("Coming up"):rc.find('id="renewals-by-vendor"')])
ok(CN[C3] in text(rc) and "V2 Fiber" in cu and all(CN[c] not in cu for c in (C1, C2)), "?client= shows one client's dates: " + cu[:200])
rx = tech.get(B + "/renewals?days=90&vendor=%3Cscript%3Ealert(1)%3C/script%3E&client=abc").text
ok(rx.count('<li class="list-group-item py-2') == dates_all and "<script>alert(1)" not in rx and 'id="renewal-filters"' not in rx, "an unknown vendor or a bad client id in the URL is ignored")
ok("V2 Fiber" in text(viewer.get(B + "/renewals?days=90").text), "viewers see Renewals by vendor too")

# ---- CSV import of client vendors
def preview(s, csvtext):
    r = s.post(B + "/clients/import", data={"_csrf": tok(s, "/clients/import?kind=vendors"), "kind": "vendors"}, files={"file": ("v2.csv", csvtext.encode(), "text/csv")})
    m = re.search(r'name="token" value="([a-f0-9]{32})"', r.text)
    return r, (m.group(1) if m else None)

q("insert into vendor_templates (name, category, support_phone, website) values ('V2 Registrar Tpl', 'registrar', '555-0177', 'https://reg.example')")
tech.post(B + f"/clients/{C2}/licenses", data={"_csrf": tok(tech, f"/clients/{C2}/licenses"), "name": "V2 Copier lease", "vendor": "V2 Copiers Inc", "license_type": "site", "seats": "1"})
csvtext = ("Client,Vendor,Template,Category,Account number,Support phone,Services,Notes\n"
           f"{CN[C2]},V2 Copiers Inc,,Copiers / printing,CP-9,555-0100,2 copiers,=HYPERLINK(\"x\")\n"
           f"{CN[C2]},,V2 Registrar Tpl,,RG-1,555-0177,3 domains,\n"
           f"{CN[C2]},v2 copiers inc,,,,,,\n"
           f"Nobody Inc,V2 Lost,,,,,,\n"
           f"{CN[C2]},V2 Odd Cat,,Spaceships,,,,\n"
           f"{CN[C2]},Comcast Business,,Internet provider,HACK,999,1 Gbps,Ask for Pat\n"
           f"{CN[C2]},V2 No Tpl,Missing Tpl,,,,,\n")
r, token = preview(tech, csvtext)
t = text(r.text)
ok(token and "4 to add" in t and "1 to update" in t, "the check counts adds and updates: " + t[t.find("rows."):t.find("rows.") + 80])
ok("Same vendor as row 2" in t and 'No client named "Nobody Inc"' in t and "isn't one Align has, guessed instead" in t and 'no template named "Missing Tpl"' in t,
   "duplicate rows, unknown clients, categories and templates are noted")
ok("Template: V2 Registrar Tpl" in t and "Category: Copiers / printing" in t and "only category, services and notes change" in t, "the check shows the template and category by name; a PSA vendor only takes Align's fields")
vr, _ = preview(viewer, csvtext)
ok(not _, "viewers can't import")
m0 = q("select coalesce(max(id),0) m from audit_log")[0]["m"]
tech.post(B + "/clients/import/run", data={"_csrf": tok(tech, "/clients/import"), "token": token})
cop = q("select * from client_vendors where client_id=%s and name='V2 Copiers Inc'", C2)
reg = q("select * from client_vendors where client_id=%s and template_id=(select id from vendor_templates where name='V2 Registrar Tpl')", C2)
ok(cop and cop[0]["category"] == "print" and cop[0]["account_number"] == "CP-9" and cop[0]["notes"] == '=HYPERLINK("x")', "a vendor is added (category by its label; a formula-like cell stored as typed)")
ok(reg and reg[0]["name"] is None and reg[0]["support_phone"] is None and reg[0]["account_number"] == "RG-1" and reg[0]["services"] == "3 domains",
   "a template row: the vendor follows the template (values the same as the template's left blank)")
ok(q("select category from client_vendors where client_id=%s and name='V2 Odd Cat'", C2)[0]["category"] == "other", "an unknown category is guessed")
c12 = q("select * from client_vendors where psa_id='12'")[0]
ok(c12["account_number"] == "CB-2002" and c12["support_phone"] == "800-555-0101" and c12["services"] == "1 Gbps" and c12["align_notes"] == "Ask for Pat",
   "a vendor from ITFlow keeps ITFlow's details; services and notes (as Align notes) are taken")
ok(q("select vendor_id from licenses where name='V2 Copier lease'")[0]["vendor_id"] == cop[0]["id"], "a license naming an imported vendor links to it")
a = q("select detail from audit_log where id > %s and action='import.vendors'", m0)
ok(a and "+%s: V2 Copiers Inc" % CN[C2] in a[0]["detail"], "the import is audited with what it added")
r, token = preview(tech, f"Client,Vendor,Account number,Services\n{CN[C2]},V2 Copiers Inc,,4 copiers\n")
tech.post(B + "/clients/import/run", data={"_csrf": tok(tech, "/clients/import"), "token": token})
cop2 = q("select * from client_vendors where id=%s", cop[0]["id"])[0]
ok(cop2["services"] == "4 copiers" and cop2["account_number"] == "CP-9", "importing again updates what the file fills in; a blank cell clears nothing")
r, token = preview(tech, f"Client,Vendor,Account number,Services\n{CN[C2]},v2 COPIERS inc,cp-9,4 Copiers\n{CN[C3]},comcast business,,\n")
t = text(r.text)
ok("0 to update" in t and "already here, nothing new" in t, "a row with what the vendor already shows (case aside) is nothing new, not an update")
ok(q("select name from client_vendors where id=%s", v3)[0]["name"] is None, "...and a vendor named by its template keeps following it")
rn = tech.get(B + f"/renewals?days=30&client={C3}&vendor=v2+nobody").text
ok("That vendor has no dates in this period" in text(rn), "asking Renewals for a vendor with no dates in the period says so")
tp = tech.get(B + "/clients/import/template/vendors")
ok(tp.status_code == 200 and tp.text.startswith("Client,Vendor,Template,Category") and "Example" in tp.text, "the vendors template CSV downloads")
ok('href="/clients/import?kind=vendors"' in tech.get(B + "/vendors").text, "the Vendors page links to the import")
done()
