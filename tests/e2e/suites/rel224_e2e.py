"""2.24.0 (1) Two-factor setup shows a QR code: Align\\Qr draws it (checked against fixtures that decode with a QR
reader), the staff and client portal setup screens show it for the exact setup link of the key on the page, and the
portal invitation email tells people to have an authenticator app ready (the key is never emailed). (2) Contact review
report: details, roles, portal access, licenses and review columns, switchable; archived contacts, internal notes and
other clients' people left out; names escaped; portal users with no contact listed; a CSV with formulas neutralized;
staff only, plus the client portal for users with contacts access (licenses only with licensing access); audited.
(3) The Client-Built Software Policy template: generic wording, editor-safe, makes a document, and migration 083 adds it
to an existing install once without touching an edited copy."""
import re, json, csv, io, html as H
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
PW = "Quartz-Lantern-Field-31"
SEC = "ONSWG4TFORZWK3DPONSWG4TFORZWK3DP"
MIG = ROOT + "/db/migrations/083_client_built_software_template.php"


def cleanup():
    """Removes everything this suite adds (made-up rel224.example people, R224 licenses), so it can run again."""
    q("delete from portal_users where email like '%%@rel224.example'")
    q("delete from license_assignments where license_id in (select id from licenses where name like 'R224 %%')")
    q("delete from licenses where name like 'R224 %%'")
    q("delete from contacts where email like '%%@rel224.example'")
    q("delete from mail_queue where recipients like %s", "%rel224.example%")


cleanup()


def svg_matrix(page):
    """The QR code drawn in a page: the dark modules of its single <path> (quiet zone of 4 removed)."""
    m = re.search(r'<svg[^>]*class="qr-code"[^>]*viewBox="0 0 (\d+) \1"[^>]*>.*?<path fill="#000" d="([^"]*)"', page, re.S)
    if not m:
        return None
    n = int(m.group(1)) - 8
    grid = [["0"] * n for _ in range(n)]
    for x, y in re.findall(r"M(\d+) (\d+)h1v1h-1z", m.group(2)):
        grid[int(y) - 4][int(x) - 4] = "1"
    return ["".join(r) for r in grid]


def php_matrix(text):
    """Align\\Qr::matrix() for text, as rows of 0/1."""
    out = php(f'echo json_encode(array_map(fn($r) => implode("", array_map(fn($b) => $b ? "1" : "0", $r)), Align\\Qr::matrix(base64_decode("{__import__("base64").b64encode(text.encode()).decode()}"))));')
    return json.loads(out.stdout or "null")


def mkportal(email, client=1, secret=SEC, **perms):
    p = {"can_roadmap": 1, "can_budget": 1, "can_devices": 1, "can_documents": 1, "can_approve": 0, "can_contacts": 0, **perms}
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{client},"email"=>"{email}","name"=>"Robin Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        + ",".join(f'"{k}"=>{v}' for k, v in p.items()) + f',"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{secret}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')


def plogin(email, secret=SEC):
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(secret)})
    return s


# ======== (1) QR codes
fx = json.load(open(ROOT + "/tests/e2e/fixtures/qr-2.24.0.json"))
for f in fx:
    v = (len(f["rows"]) - 17) // 4
    ok(php_matrix(f["text"]) == f["rows"], f"QR version {v} ({len(f['text'])} bytes) matches the reference that a QR reader decodes")
r = php('try { Align\\Qr::matrix(str_repeat("x", 2400)); echo "no"; } catch (InvalidArgumentException $e) { echo "refused"; }')
ok(r.stdout.strip() == "refused", "text too long for any QR code is refused, not drawn wrong")
sv = php('echo Align\\Qr::svg("hi", "<b>label</b>");').stdout
ok(sv.startswith("<svg") and 'aria-label="&lt;b&gt;label&lt;/b&gt;"' in sv and "<script" not in sv and 'fill="#fff"' in sv, "the SVG is plain rectangles on white with an escaped label")

# staff: starting setup shows the QR of the key on the page
r = st.post(B + "/account/2fa", data={"_csrf": csrf(st, "/account"), "action": "begin"})
p = st.get(B + "/account").text
key = re.search(r'<code class="select-all">([A-Z2-7 ]+)</code>', p)
mx = svg_matrix(p)
ok(key and mx and "scan this QR code" in p and not errs(p), "staff 2FA setup shows a QR code beside the key")
if key and mx:
    uri = php(f'echo Align\\Totp::uri("{key.group(1).replace(" ", "")}", "admin@example.com");').stdout
    ok(mx == php_matrix(uri), "the staff QR code is exactly the setup link for that key and account")
st.post(B + "/account/2fa", data={"_csrf": csrf(st, "/account"), "action": "cancel"})
ok("qr-code" not in st.get(B + "/account").text, "after Cancel there is no QR code on the page")

# portal: invitation -> password -> setup screen with the QR
tok = "c" * 64
php('Align\\DB::insert("portal_users", ["client_id"=>1,"email"=>"newbie@rel224.example","name"=>"Nova Newcomer","is_active"=>1,"can_documents"=>1,'
    f'"invite_token_hash"=>hash("sha256","{tok}"),"invite_expires_at"=>date("Y-m-d H:i:s", time()+3600)]);')
s = requests.Session()
r = s.post(B + f"/portal/invite/{tok}", data={"_csrf": csrf(s, f"/portal/invite/{tok}"), "password": "Harbor-Willow-Stone-58", "confirm": "Harbor-Willow-Stone-58"})
p = r.text  # 2.24.1: setup starts by itself, so the page right after the password already has the QR code
key = re.search(r'<code class="d-block my-1 user-select-all">([A-Z2-7 ]+)</code>', p)
mx = svg_matrix(p)
ok(key and mx and "scan this QR code" in p and "Can't scan it?" in p and not errs(p), "a newly invited portal user sees a QR code to scan, with the key as a fallback")
if key and mx:
    uri = php(f'echo Align\\Totp::uri("{key.group(1).replace(" ", "")}", "newbie@rel224.example");').stdout
    ok(mx == php_matrix(uri), "the portal QR code is exactly the setup link for that key and account")
    ok(not q("select 1 from audit_log where detail like %s", "%" + key.group(1).replace(" ", "") + "%"), "the key is not written to the audit log")

# the invitation email: be ready to scan; no key in it (read inside a transaction, before the immediate send after commit wipes the body)
def mail_body(kind, url):
    """The HTML body Notify::portalLink queues for a made-up portal user ('' when nothing was queued)."""
    return php('Align\\DB::transaction(function () { Align\\Mail\\Notify::portalLink(["email"=>"mail@rel224.example","name"=>"Mia Mail","client_id"=>1,"client_name"=>"Cedar Ridge Family Dental, Inc."], "' + url + '", "' + kind + '");'
               ' echo (string) Align\\DB::value("SELECT body_html FROM mail_queue WHERE recipients LIKE ? ORDER BY id DESC LIMIT 1", ["%rel224.example%"]); });').stdout


setting("notif_client_portal_invite", "1"); setting("notif_client_portal_reset", "1")
body = H.unescape(mail_body("invite", "https://align.example/portal/invite/x"))
ok("ready to scan a QR code" in body and "otpauth" not in body and "secret=" not in body, "the invitation says to have an authenticator app ready to scan; no key is emailed: " + re.sub(r"<[^>]+>", " ", body)[-260:])
body = H.unescape(mail_body("self-reset", "https://align.example/portal/reset/x"))
ok("Reset your password" in body and "scan a QR code" not in body, "a password reset email doesn't mention a QR code")
q("delete from settings where name in ('notif_client_portal_invite','notif_client_portal_reset')")

# ======== (2) Contact review report
q("insert into contacts (client_id, source, name, title, department, email, phone, extension, mobile, location, is_primary, is_billing, decision_maker, created_at, updated_at) values "
  "(1,'manual','<b>Zed</b> Reviewer','Office <i>Manager</i>','Front desk','zed@rel224.example','555-0110','12','555-0111','Main office',0,1,1,now(),now())")
q("update contacts set align_notes='R224-INTERNAL-NOTE', psa_notes='R224-PSA-NOTE' where email='zed@rel224.example'")
q("insert into contacts (client_id, source, name, email, archived_at, created_at, updated_at) values (1,'manual','Gone Former','gone@rel224.example',now(),now(),now())")
q("insert into contacts (client_id, source, name, email, created_at, updated_at) values (1,'manual','=HYPERLINK(\"http://x.example\")','formula@rel224.example',now(),now())")
q("insert into contacts (client_id, source, name, email, created_at, updated_at) values (2,'manual','Other Clientperson','other@rel224.example',now(),now())")
zed = q("select id from contacts where email='zed@rel224.example'")[0]["id"]
lid = q("insert into licenses (client_id, source, name, license_type, created_at, updated_at) values (1,'manual','R224 Office Suite','user',now(),now()) returning id")[0]["id"]
q("insert into license_assignments (client_id, license_id, contact_id, source, created_at) values (1,%s,%s,'manual',now())", lid, zed)
mkportal("zed@rel224.example")  # has a contact: Active
php('Align\\DB::insert("portal_users", ["client_id"=>1,"email"=>"pending@rel224.example","name"=>"Pat Pending","is_active"=>1,"can_documents"=>1,"invite_token_hash"=>hash("sha256","p"),"invite_expires_at"=>date("Y-m-d H:i:s", time()+3600)]);')
php('Align\\DB::insert("portal_users", ["client_id"=>1,"email"=>"late@rel224.example","name"=>"Lee Lapsed","is_active"=>1,"can_documents"=>1,"invite_token_hash"=>hash("sha256","l"),"invite_expires_at"=>date("Y-m-d H:i:s", time()-3600)]);')

r = st.get(B + "/clients/1/report/contacts")
t = r.text
ok(r.status_code == 200 and "Contact Review" in t and not errs(t), "the contact review opens for a client")
ok("&lt;b&gt;Zed&lt;/b&gt; Reviewer" in t and "<b>Zed</b>" not in t and "Office &lt;i&gt;Manager&lt;/i&gt;" in t, "names and titles are escaped")
row = t[t.find("&lt;b&gt;Zed&lt;/b&gt; Reviewer"):]
row = row[:row.find("</tr>")]
ok(all(x in row for x in ["zed@rel224.example", "Phone 555-0110 x12", "Mobile 555-0111", "Main office", "Front desk", "Billing", "Decision maker", "R224 Office Suite", ">Active<"]),
   "each person shows details, roles, licenses and portal access: " + re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", row))[:200])
ok("Gone Former" not in t and "Other Clientperson" not in t and "R224-INTERNAL-NOTE" not in t and "R224-PSA-NOTE" not in t, "archived contacts, other clients' people and internal notes are left out")
ok("Portal users not in your contact list" in t and "Pat Pending" in t and ">Invited<" in t and "Lee Lapsed" in t and ">Invite expired<" in t, "portal users with no contact are listed, marked Invited or Invite expired")
ok(all(x in t for x in ["Still here?", "check-box", "Anyone missing?", "Reviewed by", "Signature", "Please check each person"]), "review columns, rows for anyone missing and a sign-off")
ok(all(f'id="opt-{k}"' in t for k in ["licenses", "portal", "review"]), "the toolbar has the three switches")
t2 = st.get(B + "/clients/1/report/contacts?review=0&licenses=0&portal=0").text
ok("Still here?" not in t2 and "Anyone missing?" not in t2 and "R224 Office Suite" not in t2 and "<th>Licenses</th>" not in t2 and "Portal users not in" not in t2
   and "zed@rel224.example" in t2, "switching them off leaves just the contact list")
ok(q("select 1 from audit_log where action='report.contacts'"), "opening it is audited")
v = requests.get(B + "/clients/1/report/contacts", allow_redirects=False)
ok(v.status_code in (302, 303) and "/login" in v.headers.get("Location", ""), "signed out: sent to sign-in")
ok(tech.get(B + "/clients/1/report/contacts").status_code == 200, "any staff role can open it")

# CSV
r = st.get(B + "/clients/1/report/contacts-csv")
ok(r.headers.get("Content-Type", "").startswith("text/csv") and re.search(r'filename="Cedar-Ridge-Family-Dental-Inc-contacts-\d{4}-\d{2}-\d{2}\.csv"', r.headers.get("Content-Disposition", "")),
   "the CSV downloads with a safe file name")
rows = list(csv.reader(io.StringIO(r.text)))
hdr = rows[0]
ok(hdr[:7] == ["Name", "Title", "Department", "Email", "Phone", "Mobile", "Location"] and hdr[-3:] == ["Licenses", "Still here? (Yes/No)", "Changes"] and "Client portal" in hdr, "CSV columns: details, roles, portal, licenses, review")
z = next((x for x in rows if x[3] == "zed@rel224.example"), None)
ok(z and z[0] == "<b>Zed</b> Reviewer" and z[hdr.index("Billing")] == "Yes" and z[hdr.index("Primary")] == "" and z[hdr.index("Client portal")] == "Active" and z[hdr.index("Licenses")] == "R224 Office Suite",
   "a person's row in the CSV")
fr = next((x for x in rows if x[3] == "formula@rel224.example"), None)
ok(fr and fr[0].startswith("'="), "a name starting with = is neutralized in the CSV")
ok(not any("gone@rel224.example" in x or "other@rel224.example" in x for x in rows), "the CSV leaves out archived and other clients' contacts")
ok(q("select 1 from audit_log where action='report.contacts_csv'"), "the CSV download is audited")

# Where staff find it
ok("/report/contacts" in st.get(B + "/reports").text and "Contact list (CSV)" in st.get(B + "/reports").text, "the Reports page has Contact review and Contact list (CSV)")
ok('href="/clients/1/report/contacts"' in st.get(B + "/clients/1/contacts").text, "the client's Contacts page links to the review report")
cr = st.get(B + "/clients/1/reports").text
ok("/clients/1/report/contacts" in cr and "/clients/1/report/contacts-csv" in cr, "the client's Reports page lists both")

# Client portal
mkportal("full@rel224.example")
mkportal("nolic@rel224.example", can_budget=0)
mkportal("nodocs@rel224.example", can_documents=0)
mkportal("other@rel224.example", client=2)
pf = plogin("full@rel224.example")
t = pf.get(B + "/portal/report/contacts").text
ok("Contact Review" in t and "&lt;b&gt;Zed&lt;/b&gt; Reviewer" in t and "R224 Office Suite" in t and "Other Clientperson" not in t and not errs(t), "a portal user with contacts access prints their own organization's review")
ok('href="/portal/report/contacts"' in pf.get(B + "/portal/contacts").text, "the portal's Contacts page has Print for review")
pn = plogin("nolic@rel224.example")
t = pn.get(B + "/portal/report/contacts").text
ok("&lt;b&gt;Zed&lt;/b&gt; Reviewer" in t and "R224 Office Suite" not in t and 'id="opt-licenses"' not in t, "without licensing access there are no licenses, even with ?licenses=1")
ok("R224 Office Suite" not in pn.get(B + "/portal/report/contacts?licenses=1").text, "and asking for them in the address doesn't help")
r = plogin("nodocs@rel224.example").get(B + "/portal/report/contacts")
ok(r.status_code == 403 and "Zed" not in r.text, f"without contacts access the report is refused ({r.status_code})")
t = plogin("other@rel224.example").get(B + "/portal/report/contacts").text
ok("Other Clientperson" in t and "Zed" not in t, "another client's portal user sees only their own people")
ok(pf.get(B + "/portal/report/contacts-csv").status_code == 404, "there is no CSV in the portal")

# ======== (3) Client-Built Software Policy template
SLUG = "client-built-software-policy"
body = open(ROOT + f"/db/templates/{SLUG}.html").read()
t = q("select * from document_templates where slug=%s", SLUG)
ok(t and t[0]["is_builtin"] == 1 and t[0]["body_html"] == body and t[0]["category"] == "policy" and "AI-assisted" in t[0]["description"], "the template is installed as a built-in policy")
known = {"client_name", "client_address", "client_contact", "client_contact_email", "client_phone", "client_website", "vcio_name", "company_name",
         "company_phone", "company_emergency_phone", "company_email", "company_website", "today", "year"}
allowed = {"p", "br", "h1", "h2", "h3", "h4", "strong", "em", "u", "ul", "ol", "li", "blockquote", "hr", "a"}
tags = set(re.findall(r"</?([a-z0-9]+)", body))
ph = set(re.findall(r"\{\{\s*([a-z_]+)\s*\}\}", body))
ok(tags <= allowed and ph <= known, f"editor-safe tags {sorted(tags - allowed)} and known placeholders {sorted(ph - known)}")
ok(not re.search(r"(?i)huntress|claude|chatgpt|copilot", body), "no MSP, vendor or AI product names")
ok(not re.search(r"<li>(?:(?!</li>).)*<(?:ul|ol)>", body, re.S) and body.count("<li>") == body.count("</li>"), "no lists inside list items, balanced")
ok(all(x in body for x in ["<h2>4. Intake</h2>", "<h2>5. Review checklist</h2>", "Data flow:", "<h2>6. Packaging, signing and deployment</h2>", "[a code-signing certificate issued to {{client_name}}",
                           "<h2>7. Updates and change control</h2>", "Developer leaves:", "Supports:", "Review and approval"]), "intake, review, signing choice, change control, retirement, mappings and sign-off")
tid = t[0]["id"]
r = st.post(B + "/documents", data={"_csrf": csrf(st, "/clients/1/documents"), "client_id": "1", "template_id": str(tid)})
did = int(r.url.rstrip("/").split("/")[-1])
d = q("select body_html from documents where id=%s", did)[0]["body_html"]
ok("{{" not in d and "Cedar Ridge Family Dental" in d and not errs(r.text), "a document is made from it with the names filled in")
q("delete from documents where id=%s", did)

keep = t[0]
linked = [x["id"] for x in q("select id from documents where template_id=%s", tid)]
try:
    q("update documents set template_id=null where template_id=%s", tid)
    q("delete from document_templates where slug=%s", SLUG)
    out = php(f'(require "{MIG}")(); (require "{MIG}")(); echo "ok";')
    ok(out.stdout.strip() == "ok" and len(q("select id from document_templates where slug=%s", SLUG)) == 1, "migration 083 adds it to an existing install once " + out.stderr[-200:])
    q("update document_templates set body_html='<h1>Our own version</h1>' where slug=%s", SLUG)
    php(f'(require "{MIG}")();')
    ok(q("select body_html from document_templates where slug=%s", SLUG)[0]["body_html"] == "<h1>Our own version</h1>", "an edited copy is left alone")
finally:
    q("delete from document_templates where slug=%s", SLUG)
    q("insert into document_templates (id, slug, name, category, description, body_html, is_builtin) values (%s,%s,%s,%s,%s,%s,1)",
      keep["id"], keep["slug"], keep["name"], keep["category"], keep["description"], keep["body_html"])
    for x in linked:
        q("update documents set template_id=%s where id=%s", tid, x)
    cleanup()
done()
