"""2.2: contracts. Templates (builder, sample, import/export), making a contract for a client or a new lead, sending it,
the client's signing page (emailed code, fields they fill in, typed or drawn signature, decline), countersigning,
the signed PDF with its certificate, uploads of contracts signed elsewhere, checking a PDF, and the Onboarding menu."""
from lib import *
from playwright.sync_api import sync_playwright
import zlib, base64, hashlib, json, os, html as H2

def local(u): return re.sub(r"^https?://[^/]+", B, u)
def mails(): return requests.get(M + "/mock/graph").json()["mail"]
def last_mail(sub):
    m = [x for x in mails() if sub in x["message"]["subject"]]
    return m[-1]["message"] if m else None
def pdf_text(b):
    """Text from Align's PDFs (Flate streams, hex strings in Windows-1252)."""
    out = []
    # each stream by its /Length (compressed data can end in a newline byte, so "up to endstream" can cut it short)
    for m in re.finditer(rb"/Length (\d+)[^>]*>>\s*stream\r?\n", b):
        s = b[m.end():m.end() + int(m.group(1))]
        try: s = zlib.decompress(s)
        except Exception: pass
        out += [bytes.fromhex(h.decode()).decode("cp1252", "replace") for h in re.findall(rb"<([0-9a-f]+)> Tj", s)]
    return re.sub(r"\s+", " ", " ".join(out))
def link_from(msg): return local(re.search(r'href="(https?://[^"]+/portal/sign/[A-Za-z0-9_-]+)"', msg["body"]["content"]).group(1))
def portal_csrf(s, url): return re.search(r'name="_csrf" value="([^"]+)"', s.get(url).text).group(1)
def drawn_png():
    r = php('$i = imagecreatetruecolor(400, 120); imagesavealpha($i, true); imagefill($i, 0, 0, imagecolorallocatealpha($i, 0, 0, 0, 127));'
            ' $k = imagecolorallocate($i, 20, 30, 90); imagesetthickness($i, 4); imageline($i, 20, 90, 120, 20, $k); imageline($i, 120, 20, 200, 95, $k); imageline($i, 200, 95, 380, 30, $k);'
            ' ob_start(); imagepng($i); echo base64_encode(ob_get_clean());')
    return "data:image/png;base64," + r.stdout.strip()

requests.get(M + "/mock/graph-reset")
q("delete from contracts"); q("delete from contract_templates"); q("delete from mail_queue"); q("delete from clients where name = %s", "Harbor Point <Law> Group")
setting("company_address", "1 Mountain Way, Pinecrest, CO 80000")
a = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")

# ---- the Onboarding menu
t = a.get(B + "/").text
ok("ONBOARDING" in t and 'href="/contracts"' in t and 'href="/onboarding"' in t, "the menu has an Onboarding section with Contracts and New clients")
t = a.get(B + "/settings").text
ok('href="/settings/onboarding"' not in t.split('settings-tabs')[1].split('</ul>')[0], "the welcome email moved out of the Settings tabs")
t = a.get(B + "/settings/onboarding").text
ok(not errs(t) and "Welcome email &amp; guide" in t and 'href="/onboarding"' in t and "nav-tabs group-tabs" in t, "Welcome email & guide is a tab under New clients")
ok("ONBOARDING" not in viewer.get(B + "/").text and viewer.get(B + "/contracts").status_code == 403, "viewers don't see contracts")
ok(tech.get(B + "/contracts").status_code == 200 and tech.get(B + "/contracts/templates").status_code == 403, "techs make contracts; templates are for admins")

# ---- templates
t = a.get(B + "/contracts/templates").text
ok(not errs(t) and "Upload your contract" in t and "sample" not in t.lower().split("modal-new-template")[0], "templates page: upload your own; nothing pre-built")
r = a.post(B + "/contracts/templates", data={"_csrf": csrf(a, "/contracts/templates"), "start": "blank", "name": "Managed IT Services Agreement"})
tid = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
t = r.text
d = json.loads(H2.unescape(re.search(r'<script type="application/json" id="ct-def-json">(.*?)</script>', t, re.S).group(1)))
ok(not errs(t) and 'id="ct-def-json"' in t and d["blocks"][0]["html"] == "" and not d["services"]["rows"] and not d["fields"], "Write it in Align: an empty page, no wording, no services")
# A contract written in Align (the wording here is the test's own)
P = lambda *x: "".join("<p>%s</p>" % y for y in x)
d = {
    "style": {"title": "Managed IT Services Agreement", "footer": "{{company_name}} · Managed IT Services Agreement", "page_numbers": True},
    "signing": {"countersign": "after", "link_days": 30, "verify_code": True, "subject": "Your agreement with {{company_name}} is ready to sign",
                "message": "Hi {{signer_name}},\n\nYour agreement is ready. Please review it and sign online."},
    "sections": [{"key": "onsite", "label": "Onsite support", "on": True}, {"key": "backup", "label": "Backup and recovery", "on": True}],
    "fields": [{"key": "start_date", "label": "Start date", "type": "date", "by": "provider", "required": True},
               {"key": "term_months", "label": "Term (months)", "type": "number", "by": "provider", "required": True, "default": "12"},
               {"key": "notice_days", "label": "Notice period (days)", "type": "number", "by": "provider", "default": "30"},
               {"key": "payment_days", "label": "Payment due (days)", "type": "number", "by": "provider", "default": "30"},
               {"key": "hourly_rate", "label": "Hourly rate", "type": "money", "by": "provider", "default": "150"},
               {"key": "onsite_hours", "label": "Onsite hours", "type": "number", "by": "provider", "default": "4"},
               {"key": "backup_retention", "label": "Backup retention", "type": "text", "by": "provider", "default": "90 days"},
               {"key": "billing_name", "label": "Billing contact", "type": "text", "by": "client", "required": True},
               {"key": "billing_email", "label": "Billing email", "type": "email", "by": "client", "required": True},
               {"key": "po_number", "label": "PO number (if any)", "type": "text", "by": "client"}],
    "services": {"title": "Services and fees", "rows": [
        {"key": "workstation", "label": "Managed workstation", "description": "Monitoring and support", "unit": "per device", "price": 65, "period": "month", "auto": "workstations"},
        {"key": "server", "label": "Managed server", "description": "Servers and hosts", "unit": "per server", "price": 175, "period": "month", "auto": "servers"},
        {"key": "firewall", "label": "Managed firewall", "unit": "per firewall", "price": 45, "period": "month", "auto": "firewalls"},
        {"key": "m365", "label": "Microsoft 365 management", "unit": "per user", "price": 8, "period": "month", "auto": "m365_users"},
        {"key": "backup", "label": "Server backup", "unit": "per server", "price": 95, "period": "month", "auto": "servers", "optional": True},
        {"key": "onboarding", "label": "Onboarding", "price": 750, "period": "once", "qty": 1}]},
    "blocks": [
        {"type": "text", "html": P("This Agreement is made on {{contract_date}} between <strong>{{company_name}}</strong> and <strong>{{client_name}}</strong>, {{client_address}}.") + "<h2>Services</h2>" + P("Services as listed below.")},
        {"type": "services"},
        {"type": "text", "html": "<h2>Term</h2>" + P("Begins on {{start_date}} for {{term_months}} months; {{notice_days}} days' notice.") + "<h2>Fees</h2>" + P("Due within {{payment_days}} days. Other work at {{hourly_rate}} per hour.")
            + "<h2>Responsibilities</h2><ul><li>Give access.</li><li>Keep software licensed.</li></ul>"},
        {"type": "text", "section": "onsite", "html": "<h2>Onsite support</h2>" + P("Up to {{onsite_hours}} hours a month.")},
        {"type": "text", "section": "backup", "html": "<h2>Backup and recovery</h2>" + P("Backups kept for {{backup_retention}}.")},
        {"type": "fields", "title": "Billing details", "keys": ["billing_name", "billing_email", "po_number"]},
        {"type": "signatures"}]}
r = a.post(B + f"/contracts/templates/{tid}", data={"_csrf": csrf(a, f"/contracts/templates/{tid}"), "name": "Managed IT Services Agreement", "is_active": "1", "def": json.dumps(d)})
d = json.loads(q("select def from contract_templates where id=%s", tid)[0]["def"])
ok(len(d["blocks"]) == 7 and len(d["services"]["rows"]) == 6 and len(d["sections"]) == 2, "a written contract saved: text, services, sections")
pdf = a.get(B + f"/contracts/templates/{tid}/pdf")
ok(pdf.content[:5] == b"%PDF-" and "Managed IT Services Agreement" in pdf_text(pdf.content) and "DRAFT - not signed" in pdf_text(pdf.content), "sample PDF, marked as a draft")
# save: a changed def, with something nasty and something unknown
d["blocks"].insert(1, {"type": "text", "html": "<p>Hourly rate {{hourly_rate}} and {{nope_field}} <script>alert(1)</script><img src=x onerror=alert(1)></p>", "section": "made_up"})
d["fields"].append({"key": "client_name", "label": "Clashes with a built-in", "type": "weird", "by": "who"})
d["fields"].append({"label": "Response time", "type": "choice", "options": "1 hour\n4 hours", "by": "provider", "required": True, "default": "4 hours"})
d["services"]["rows"].append({"label": "<b>Bad</b>", "price": "-5", "period": "decade", "auto": "rockets"})
d["style"]["color"] = "javascript:alert(1)"; d["style"]["size"] = 99; d["signing"]["countersign"] = "after"
r = a.post(B + f"/contracts/templates/{tid}", data={"_csrf": csrf(a, f"/contracts/templates/{tid}"), "name": "Managed IT Services Agreement", "description": "Our standard", "is_active": "1", "def": json.dumps(d)})
ok("Template saved" in flash(r.text) and "{{nope_field}}" in flash(r.text), "saved, with a warning about the unknown placeholder: " + flash(r.text)[:140])
row = q("select def, version from contract_templates where id=%s", tid)[0]; s = json.loads(row["def"])
ok("<script" not in row["def"] and "onerror" not in row["def"] and "{{hourly_rate}}" in s["blocks"][1]["html"] and s["blocks"][1]["section"] == "", "text is cleaned (script and image gone), unknown section dropped")
ok([f["key"] for f in s["fields"]][-2:] == ["clashes_with_a_built_in", "response_time"] and s["fields"][-2]["type"] == "text" and s["fields"][-2]["by"] == "provider", "a field can't take a built-in name; bad type and side fall back")
ok(s["fields"][-1]["options"] == ["1 hour", "4 hours"], "choice options kept")
bad = s["services"]["rows"][-1]
ok(bad["price"] == 0 and bad["period"] == "month" and bad["auto"] == "" and bad["label"] == "<b>Bad</b>", "service row values are limited (the label is text, escaped when shown)")
ok(s["style"]["color"] == "" and s["style"]["size"] == 12 and row["version"] == 3, "style limited; the version went up")
prev = a.post(B + f"/contracts/templates/{tid}/preview", data={"_csrf": csrf(a, f"/contracts/templates/{tid}"), "def": json.dumps(s)}).text
ok("cf-unknown" in prev and "&lt;b&gt;Bad&lt;/b&gt;" in prev and "<script" not in prev and "Example Client, Inc." in prev, "live preview: sample values, unknown placeholder flagged, text escaped")
# tidy: drop the junk again
s["blocks"].pop(1); s["fields"] = [f for f in s["fields"] if f["key"] != "clashes_with_a_built_in"]; s["services"]["rows"].pop()
a.post(B + f"/contracts/templates/{tid}", data={"_csrf": csrf(a, f"/contracts/templates/{tid}"), "name": "Managed IT Services Agreement", "is_active": "1", "def": json.dumps(s)})
ex = a.get(B + f"/contracts/templates/{tid}/export")
ok(ex.json()["format"] == "msp-align-contract-template" and ex.json()["template"]["name"] == "Managed IT Services Agreement", "export")
r = a.post(B + "/contracts/templates/import", data={"_csrf": csrf(a, "/contracts/templates")}, files={"file": ("t.json", ex.content, "application/json")})
tid2 = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
ok(tid2 != tid and "Template imported" in flash(r.text) and json.loads(q("select def from contract_templates where id=%s", tid2)[0]["def"]) == json.loads(q("select def from contract_templates where id=%s", tid)[0]["def"]), "import makes an identical copy")
r = a.post(B + "/contracts/templates/import", data={"_csrf": csrf(a, "/contracts/templates")}, files={"file": ("t.json", b'{"format":"other"}', "application/json")})
ok("isn't an MSP Align contract template" in flash(r.text), "a wrong file is refused")
# the imported copy: only the client signs, no emailed code
d2 = json.loads(q("select def from contract_templates where id=%s", tid2)[0]["def"]); d2["signing"]["countersign"] = "none"; d2["signing"]["verify_code"] = False
a.post(B + f"/contracts/templates/{tid2}", data={"_csrf": csrf(a, f"/contracts/templates/{tid2}"), "name": "Simple agreement", "is_active": "1", "def": json.dumps(d2)})
a.post(B + "/contracts/templates/settings", data={"_csrf": csrf(a, "/contracts/templates"), "company_address": "1 Mountain Way\nPinecrest", "contract_remind_days": "3", "contract_max_reminders": "2"})
dt = json.loads(q("select def from contract_templates where id=%s", tid)[0]["def"])
ok(not any(f["key"] == "start_date" for f in dt["fields"]) and "{{start_date}}" in dt["blocks"][0]["html"] and "contract_date" not in json.dumps(dt),
   "a template from before the built-in Contract start date: its Start date blank and {{contract_date}} become the built-in")
ok(q("select value from settings where name='company_address'")[0]["value"] == "1 Mountain Way\nPinecrest" and "1 Mountain Way, Pinecrest" in json.loads(php("echo json_encode(Align\\Contracts\\Contracts::values(Align\\Contracts\\Render::sample(Align\\Contracts\\Template::blank())));").stdout)["company_address"], "contract settings saved; the address prints on one line")

# ---- a contract for an existing client: counted for you
cid = q("select id from clients where name like 'Cedar Ridge%%' limit 1")[0]["id"]
r = tech.post(B + "/contracts", data={"_csrf": csrf(tech, "/contracts"), "template_id": tid, "for": "client", "client_id": cid})
kid = int(re.search(r"/contracts/(\d+)$", r.url).group(1)); t = r.text
k = q("select * from contracts where id=%s", kid)[0]; v = json.loads(k["vals"])
counts = json.loads(php(f'echo json_encode(Align\\Contracts\\Contracts::autoCounts({cid}));').stdout)
ok(k["status"] == "draft" and k["client_id"] == cid and v["svc"]["workstation"]["qty"] == counts["workstations"] and v["svc"]["server"]["qty"] == counts["servers"], "quantities counted from the client's devices: %s workstations, %s servers" % (counts["workstations"], counts["servers"]))
ok(k["signer_email"] and "Align counts" in t and "What the client sees" in t and not errs(t), "signer filled in from the client's contacts; the prepare page shows counts and a preview")
ok(v["f"].get("term_months") == "12" and not v.get("start"), "defaults filled in, the rest left for you")
r = tech.post(B + f"/contracts/{kid}/send", data={"_csrf": csrf(tech, f"/contracts/{kid}"), "action": "send", "subject": "x", "message": "y"})
ok("Before sending" in flash(r.text) and 'Fill in "Contract start date"' in flash(r.text), "can't send with a required field empty: " + flash(r.text)[:120])
F = {"_csrf": csrf(tech, f"/contracts/{kid}"), "then": "save", "title": "Managed IT Services Agreement", "client_id": str(cid), "signer_name": "Dr. Jordan Ellis", "signer_title": "Owner",
     "signer_email": "jordan@cedarridgedental.example", "verify_code": "1", "sec_present": "1", "sec[onsite]": "1",
     "start": "2026-11-01", "f[term_months]": "24", "f[notice_days]": "30", "f[payment_days]": "15", "f[hourly_rate]": "165", "f[onsite_hours]": "6", "f[backup_retention]": "1 year", "f[response_time]": "1 hour",
     "svc[workstation][on]": "1", "svc[workstation][qty]": "20", "svc[workstation][price]": "70", "svc[server][on]": "1", "svc[server][qty]": "3", "svc[server][price]": "175",
     "svc[firewall][qty]": "1", "svc[firewall][price]": "45", "svc[backup][qty]": "3", "svc[backup][price]": "95", "svc[m365][on]": "1", "svc[m365][qty]": "18", "svc[m365][price]": "8", "svc[onboarding][on]": "1", "svc[onboarding][qty]": "1", "svc[onboarding][price]": "750",
     "extra[0][label]": "Network upgrade <script>", "extra[0][description]": "New switch", "extra[0][qty]": "1", "extra[0][price]": "1200", "extra[0][period]": "once"}
r = tech.post(B + f"/contracts/{kid}", data=F)
ok("Draft saved" in flash(r.text), "draft saved")
v = json.loads(q("select vals from contracts where id=%s", kid)[0]["vals"])
ok(v["sec"] == {"onsite": True, "backup": False} and v["svc"]["firewall"]["on"] is False and v["extra"][0]["label"] == "Network upgrade <script>" and v["f"]["hourly_rate"] == "165", "sections, services and an added line saved")
t = r.text
pv = t.split('data-ct-preview>')[1]
ok("$2,069" in pv and "$1,950" in pv, "preview totals: monthly 20×$70 + 3×$175 + 18×$8 = $2,069; one-time $750 + $1,200 = $1,950")
ok("Backup and recovery" not in pv and "Onsite support" in pv and "Network upgrade &lt;script&gt;" in pv and "<script>" not in pv, "the backup section is left out, onsite kept; the added line is escaped")
ok("cf-client" in pv and "Billing contact" in pv, "the client's fields show as theirs to fill in")
r = tech.post(B + f"/contracts/{kid}/send", data={"_csrf": csrf(tech, f"/contracts/{kid}"), "action": "send", "subject": "Your agreement with {{company_name}}", "message": "Hi {{signer_name}},\n\nHere it is.", "cc_me": "1"})
ok("Sent to jordan@cedarridgedental.example" in flash(r.text), "sent: " + flash(r.text))
k = q("select * from contracts where id=%s", kid)[0]
ok(k["status"] == "sent" and k["token_hash"] and len(k["token_hash"]) == 64 and k["sent_by"], "sent; only the link's hash is stored")
msg = last_mail("Your agreement with Example MSP")
ok(msg and msg["toRecipients"][0]["emailAddress"]["address"] == "jordan@cedarridgedental.example" and "Hi Dr. Jordan Ellis" in msg["body"]["content"] and "Review and sign" in msg["body"]["content"]
   and msg.get("ccRecipients"), "the email: placeholders filled, a Review and sign button, a copy to the sender")
link = link_from(msg)
ok(link.split("/")[-1] not in json.dumps(q("select * from contracts where id=%s", kid), default=str), "the raw link isn't in the database")
ok(not q("select 1 from mail_queue where kind='contract_sign' and purged=0 and status='sent'"), "the sent email's body (with the link) is wiped from the outbox")
ok(tech.post(B + f"/contracts/{kid}", data=F).url.endswith(f"/contracts/{kid}") and json.loads(q("select vals from contracts where id=%s", kid)[0]["vals"])["svc"]["workstation"]["qty"] == 20, "a sent contract can't be changed")

# ---- the client signs: code first
c = requests.Session()
t = c.get(link).text
ok("Email me a code" in t and "Network upgrade" not in t and q("select viewed_at from contracts where id=%s", kid)[0]["viewed_at"], "the link asks for a code before showing the contract; opening is recorded")
ok("Opened by the client" in tech.get(B + "/contracts").text, "the list shows it's been opened")
r = c.post(link + "/code", data={"_csrf": portal_csrf(c, link)})
ok("emailed a code to j" in flash(r.text), "code sent: " + flash(r.text))
code = re.search(r"letter-spacing:6px[^>]*>(\d{6})<", last_mail("Your code to sign")["body"]["content"]).group(1)
r = c.post(link + "/verify", data={"_csrf": portal_csrf(c, link), "code": "000000" if code != "000000" else "111111"})
ok("isn't right" in flash(r.text) and "Email me a code" not in r.text and "Send a new code" in r.text, "a wrong code is refused")
r = c.post(link + "/verify", data={"_csrf": portal_csrf(c, link), "code": code})
t = r.text
ok("Sign the contract" in t and 'name="f[billing_name]"' in t and 'form="sign-form"' in t and "Network upgrade &lt;script&gt;" in t and not errs(t), "the right code opens the contract, with boxes for the client's fields")
ok('name="start"' not in t and "November 1, 2026" in t and "C-%04d · Starts November 1, 2026" % kid in t, "your values are filled in and can't be edited")
ok('value="Dr. Jordan Ellis" data-sig-name' in t and 'value="Owner"' in t, "the signer's name and title are filled in for them")
r = c.post(link, data={"_csrf": portal_csrf(c, link), "f[billing_name]": "Pat Billing", "f[billing_email]": "billing@cedar.example", "sig_kind": "type", "sig_typed": "Jordan Ellis", "sig_name": "Jordan Ellis", "sig_title": "Owner"})
ok("tick the box" in flash(r.text) and 'value="Pat Billing"' in r.text, "no consent: refused, and what they typed is kept")
r = c.post(link, data={"_csrf": portal_csrf(c, link), "f[billing_email]": "not-an-email", "sig_kind": "type", "sig_typed": "Jordan Ellis", "sig_name": "Jordan Ellis", "consent": "1"})
ok("Billing contact" in flash(r.text) and q("select status from contracts where id=%s", kid)[0]["status"] == "sent", "a required field left empty: refused: " + flash(r.text))
r = c.post(link, data={"_csrf": portal_csrf(c, link), "f[billing_name]": "Pat <b>Billing</b>", "f[billing_email]": "billing@cedar.example", "start": "1999-01-01", "f[start_date]": "1999-01-01", "f[hourly_rate]": "1",
                       "sig_kind": "type", "sig_typed": "Jordan Ellis", "sig_name": "Jordan Ellis", "sig_title": "Owner", "consent": "1"})
ok("you've signed" in r.text and "countersign next" in r.text, "signed; it waits for the countersignature")
k = q("select * from contracts where id=%s", kid)[0]; v = json.loads(k["vals"]); sig = json.loads(k["client_signature"])
ok(k["status"] == "client_signed" and v["f"]["billing_name"] == "Pat <b>Billing</b>" and v["start"] == "2026-11-01" and v["f"]["hourly_rate"] == "165", "the client's fields saved; they couldn't change yours")
ok(sig["kind"] == "typed" and sig["consent"] and sig["ip"] and sig["agent"].startswith("python-requests"), "their signature, consent, IP and browser are recorded")
align("mail:run", "--quiet")
staff = last_mail("countersign")
ok(staff and "Dr. Jordan Ellis" not in staff["subject"] and "Jordan Ellis signed" in staff["subject"], "the sender is told it needs their countersignature")
t = a.get(B + "/").text
ok(re.search(r'href="/contracts"[^>]*>.*?nav-badge[^>]*>1<', t, re.S), "the menu shows 1 contract waiting for you")
t = a.get(B + f"/contracts/{kid}").text
ok("Your countersignature" in t and "Pat &lt;b&gt;Billing&lt;/b&gt;" in t and not errs(t), "the contract page asks for the countersignature; client values are escaped")
r = a.post(B + f"/contracts/{kid}/countersign", data={"_csrf": csrf(a, f"/contracts/{kid}"), "sig_kind": "draw", "sig_png": "data:image/png;base64,AAAA", "sig_name": "Alex Admin"})
ok("tick the box" in flash(r.text).lower(), "countersigning needs the consent too: " + flash(r.text))
r = a.post(B + f"/contracts/{kid}/countersign", data={"_csrf": csrf(a, f"/contracts/{kid}"), "sig_kind": "draw", "sig_png": "data:image/png;base64,AAAA", "sig_name": "Alex Admin", "consent": "1"})
ok("draw your signature" in flash(r.text), "an empty or broken drawing is refused")
r = a.post(B + f"/contracts/{kid}/countersign", data={"_csrf": csrf(a, f"/contracts/{kid}"), "sig_kind": "draw", "sig_png": drawn_png(), "sig_name": "Alex Admin", "sig_title": "vCIO", "consent": "1"})
ok("The contract is complete" in flash(r.text), "countersigned: " + flash(r.text))
r = a.post(B + f"/contracts/{kid}/countersign", data={"_csrf": csrf(a, f"/contracts/{kid}"), "sig_kind": "type", "sig_typed": "Alex", "sig_name": "Alex Admin", "consent": "1"})
ok("isn't waiting for your signature" in flash(r.text) and q("select count(*) n from contract_events where contract_id=%s and event='completed'", kid)[0]["n"] == 1, "countersigning again does nothing: one completion, one PDF")
r = a.post(B + f"/contracts/{kid}/send", data={"_csrf": csrf(a, f"/contracts/{kid}"), "action": "link"})
ok("can't be sent" in flash(r.text) and q("select status from contracts where id=%s", kid)[0]["status"] == "completed", "a signed contract can't be sent again or reopened")
k = q("select * from contracts where id=%s", kid)[0]
ok(k["status"] == "completed" and k["pdf_file"] and k["content_hash"] and k["signed_on"], "completed, with the signed PDF and its fingerprint")
f = open(UPLOADS + "/contracts/" + k["pdf_file"], "rb").read()
txt = pdf_text(f)
ok(hashlib.sha256(f).hexdigest() == k["pdf_hash"] and f[:5] == b"%PDF-", "the stored PDF matches its recorded SHA-256")
ok("Signature certificate" in txt and k["content_hash"] in txt and "jordan@cedarridgedental.example" in txt and "entered the one-time code" in txt and "Jordan Ellis" in txt and "Alex Admin" in txt,
   "the certificate: fingerprint, signers, email check, both names")
ok("Pat <b>Billing</b>" in txt and "Network upgrade <script>" in txt and "DRAFT" not in txt and "Backup and recovery" not in txt, "the signed PDF has the client's values, no draft mark, and no switched-off section")
ok("/XObject" in f.decode("latin-1") and "Initials" not in txt, "the drawn signature is an image; no initials footer on this template")
signed = last_mail("Signed: Managed IT Services Agreement")
ok(signed and signed["toRecipients"][0]["emailAddress"]["address"] == "jordan@cedarridgedental.example" and base64.b64decode(signed["attachments"][0]["contentBytes"]) == f, "the signer gets the signed PDF by email")
d = c.get(link + "/pdf")
ok(d.content == f and "attachment" in d.headers.get("Content-Disposition", ""), "and can download it from the link")
ok("Download the signed PDF" in c.get(link).text, "the link shows it's signed by everyone")
other = requests.Session()
t = other.get(link).text
ok("Email me a code" in t and "Pat &lt;b&gt;Billing" not in t and other.get(link + "/pdf").content[:5] != b"%PDF-", "someone else with the link still needs the code to see the signed copy")
t = a.get(B + f"/contracts/{kid}").text
ok("Signed PDF" in t and k["pdf_hash"] in t and "Email verified with the code" in t and "Signed by the provider" in t and not errs(t), "the contract page: PDF, fingerprint, history")
ok(a.get(B + f"/contracts/{kid}/pdf").content == f, "staff open the same PDF")
ok(">Onboarding<" in t or "/clients/%d/onboarding" % cid in t, "a signed client contract links to their onboarding")
r = a.post(B + "/contracts/verify", data={"_csrf": csrf(a, "/contracts/verify")}, files={"file": ("copy.pdf", f, "application/pdf")})
ok("It matches." in r.text and "C-%04d" % kid in r.text, "Check a signed PDF: the copy matches")
r = a.post(B + "/contracts/verify", data={"_csrf": csrf(a, "/contracts/verify")}, files={"file": ("copy.pdf", f.replace(b"Owner", b"Ownex", 1) if b"Owner" in f else f + b" ", "application/pdf")})
ok("No match." in r.text, "a changed copy doesn't")
t = tech.get(B + f"/clients/{cid}/documents").text
ok('id="contracts"' in t and "Managed IT Services Agreement" in t and f"/contracts/{kid}/pdf" in t, "the client's Documents page lists the contract")

# ---- a new client (lead), link only, no code, only the client signs
r = tech.post(B + "/contracts", data={"_csrf": csrf(tech, "/contracts"), "template_id": tid2, "for": "lead", "lead_company": "Harbor Point <Law> Group"})
lid = int(re.search(r"/contracts/(\d+)$", r.url).group(1))
ok(q("select client_id, lead_company, signer_email from contracts where id=%s", lid)[0] == {"client_id": None, "lead_company": "Harbor Point <Law> Group", "signer_email": None}, "a lead: no client, no signer yet")
L = {"_csrf": csrf(tech, f"/contracts/{lid}"), "then": "save", "client_id": "", "lead_company": "Harbor Point <Law> Group", "lead_address": "9 Harbor Rd", "lead_phone": "555-0101",
     "signer_name": "Morgan Reyes", "signer_title": "Partner", "signer_email": "morgan@harborpoint.example", "start": "2026-12-01", "f[term_months]": "12", "f[response_time]": "4 hours"}
tech.post(B + f"/contracts/{lid}", data=L)
r = tech.post(B + f"/contracts/{lid}/send", data={"_csrf": csrf(tech, f"/contracts/{lid}"), "action": "link"})
ok("shown only once" in flash(r.text) and 'id="ct-link"' in r.text, "link only: shown once to copy")
link2 = local(re.search(r'id="ct-link" readonly value="([^"]+)"', r.text).group(1))
ok('id="ct-link"' not in tech.get(B + f"/contracts/{lid}").text, "and not again")
ok("Harbor Point &lt;Law&gt; Group" in tech.get(B + "/contracts").text, "the lead's name is escaped in the list")
c2 = requests.Session()
t = c2.get(link2).text
ok("Sign the contract" in t and "Email me a code" not in t and "Harbor Point &lt;Law&gt; Group" in t, "no code asked for this template")
r = c2.post(link2, data={"_csrf": portal_csrf(c2, link2), "f[billing_name]": "Morgan Reyes", "f[billing_email]": "ap@harborpoint.example", "sig_kind": "draw", "sig_png": drawn_png(), "sig_name": "Morgan Reyes", "sig_title": "Partner", "consent": "1"})
k2 = q("select * from contracts where id=%s", lid)[0]
ok("Signed by everyone" in r.text and k2["status"] == "completed" and k2["pdf_file"] and not k2["provider_signature"], "only the client signs: completed straight away")
txt2 = pdf_text(open(UPLOADS + "/contracts/" + k2["pdf_file"], "rb").read())
ok("Provider:" not in txt2 and "Opened the private signing link" in txt2, "no provider signature box; the certificate says the link was used")
t = tech.get(B + "/onboarding").text
ok("Harbor Point &lt;Law&gt; Group</b> <span class=\"badge text-bg-light border\">not a client yet" in t and f'action="/contracts/{lid}/client"' in t, "New clients lists the signed lead, with Add as a client")
r = tech.post(B + f"/contracts/{lid}/client", data={"_csrf": csrf(tech, f"/contracts/{lid}")})
nc = q("select * from clients where name='Harbor Point <Law> Group'")
ok(nc and r.url.endswith("/clients/%d/onboarding" % nc[0]["id"]) and nc[0]["source"] == "manual" and nc[0]["address"] == "9 Harbor Rd", "Add as a client: made from the lead, then on to their onboarding")
ok(q("select name, email, is_primary from contacts where client_id=%s", nc[0]["id"]) == [{"name": "Morgan Reyes", "email": "morgan@harborpoint.example", "is_primary": 1}], "with the signer as the main contact")
t = tech.get(B + "/onboarding").text
ok("Signed, ready to onboard" in t and "Harbor Point &lt;Law&gt; Group</a>" in t and "Send the welcome email" in t and not errs(t), "once a client, New clients offers the welcome email")
r = tech.post(B + f"/contracts/{lid}/client", data={"_csrf": csrf(tech, f"/contracts/{lid}")})
ok(len(q("select id from clients where name='Harbor Point <Law> Group'")) == 1, "a second click doesn't make a second client")
q("delete from contacts where client_id=%s", nc[0]["id"])

# ---- declined, cancelled, expired, reminders
def new_sent(signer):
    r = tech.post(B + "/contracts", data={"_csrf": csrf(tech, "/contracts"), "template_id": tid2, "for": "client", "client_id": cid}); i = int(re.search(r"/contracts/(\d+)$", r.url).group(1))
    tech.post(B + f"/contracts/{i}", data={"_csrf": csrf(tech, f"/contracts/{i}"), "then": "save", "client_id": str(cid), "signer_name": signer, "signer_email": "x@cedar.example", "start": "2027-01-01", "f[term_months]": "12", "f[response_time]": "1 hour"})
    r = tech.post(B + f"/contracts/{i}/send", data={"_csrf": csrf(tech, f"/contracts/{i}"), "action": "link"})
    return i, local(re.search(r'id="ct-link" readonly value="([^"]+)"', r.text).group(1))
did, dlink = new_sent("Dana Decline")
c3 = requests.Session()
r = c3.post(dlink + "/decline", data={"_csrf": portal_csrf(c3, dlink), "reason": "Price too high"})
ok("You declined" in r.text and q("select status, decline_reason from contracts where id=%s", did)[0] == {"status": "declined", "decline_reason": "Price too high"}, "the client can decline, with a reason")
align("mail:run", "--quiet")
ok(last_mail("declined") is not None, "the sender is told")
ok("Dana Decline declined" in tech.get(B + f"/contracts/{did}").text, "the contract page says so")
vid, vlink = new_sent("Val Void")
tech.post(B + f"/contracts/{vid}/void", data={"_csrf": csrf(tech, f"/contracts/{vid}"), "reason": "Wrong pricing"})
ok(q("select status from contracts where id=%s", vid)[0]["status"] == "void" and requests.get(vlink).status_code == 404, "cancelled: the link stops working")
eid, elink = new_sent("Eve Expire")
q("update contracts set token_expires_at = NOW() - INTERVAL 1 HOUR where id=%s", eid)
r = requests.get(elink)
ok(r.status_code == 404 and "expired" in r.text and q("select status from contracts where id=%s", eid)[0]["status"] == "expired", "an expired link shows that, and marks the contract")
rid, rlink = new_sent("Rae Remind")
ok(not php('echo json_encode(Align\\Contracts\\Contracts::hourly());').stdout.count("reminder"), "no reminder right after sending")
q("update contracts set sent_at = NOW() - INTERVAL 4 DAY, last_reminder_at = NOW() - INTERVAL 4 DAY where id=%s", rid)
out = php('echo json_encode(Align\\Contracts\\Contracts::hourly());').stdout
align("mail:run", "--quiet")
ok("reminder sent" in out and q("select reminder_count from contracts where id=%s", rid)[0]["reminder_count"] == 1, "automatic reminder after 3 days: " + out[:120])
rem = last_mail("Reminder: please sign")
ok(rem and link_from(rem) == rlink and requests.get(rlink).status_code == 200, "the reminder sends the same link, which keeps working")
out = php('echo json_encode(Align\\Contracts\\Contracts::hourly());').stdout
ok("reminder" not in out, "not again the same day")
ok(requests.get(B + "/portal/sign/" + "A" * 43).status_code == 404 and requests.get(B + "/portal/sign/short").status_code == 404, "made-up links don't open anything")
r = tech.post(B + f"/contracts/{kid}/delete", data={"_csrf": csrf(tech, f"/contracts/{kid}"), "confirm": "C-%04d" % kid})
ok(q("select 1 from contracts where id=%s", kid) and "Only an admin" in flash(r.text), "techs can't delete a signed contract")
r = a.post(B + f"/contracts/{kid}/delete", data={"_csrf": csrf(a, f"/contracts/{kid}")})
ok(q("select 1 from contracts where id=%s", kid) and "Type the contract number" in flash(r.text), "an admin has to type its number to delete a signed one")
ok('data-bs-target="#modal-delete-contract"' in a.get(B + f"/contracts/{kid}").text and 'modal-delete-contract' not in tech.get(B + f"/contracts/{kid}").text, "admins get Delete… on a signed contract (techs don't)")
r = tech.post(B + f"/contracts/{rid}/delete", data={"_csrf": csrf(tech, f"/contracts/{rid}")})
ok(q("select 1 from contracts where id=%s", rid) and "Cancel it first" in flash(r.text), "one out for signature has to be cancelled first")
r = tech.post(B + f"/contracts/{did}/delete", data={"_csrf": csrf(tech, f"/contracts/{did}")})
ok(not q("select 1 from contracts where id=%s", did) and not q("select 1 from contract_events where contract_id=%s", did), "a declined one: any tech can delete it, with its history")

# ---- uploaded (signed elsewhere)
up = php('$p = new Align\\Pdf\\Pdf(612, 792); $p->addPage(); $p->text(72, 72, "Signed on paper"); echo base64_encode($p->output());').stdout.strip()
r = tech.post(B + "/contracts/upload", data={"_csrf": csrf(tech, "/contracts"), "client_id": str(cid), "title": "Old MSA (DocuSeal)", "signed_on": "2025-06-01", "ends_on": "2027-06-01", "back": "/contracts"},
              files={"file": ("old-msa.pdf", base64.b64decode(up), "application/pdf")})
uid = int(re.search(r"/contracts/(\d+)$", r.url).group(1)); u = q("select * from contracts where id=%s", uid)[0]
ok("uploaded" in flash(r.text) and u["source"] == "uploaded" and u["status"] == "completed" and str(u["ends_on"]) == "2027-06-01" and u["pdf_hash"] == hashlib.sha256(base64.b64decode(up)).hexdigest(), "upload a signed PDF with its dates")
r = tech.post(B + "/contracts/upload", data={"_csrf": csrf(tech, "/contracts"), "client_id": str(cid), "signed_on": "2025-06-01"}, files={"file": ("x.pdf", b"<html>not a pdf", "application/pdf")})
ok("Only PDF" in flash(r.text), "a file that isn't a PDF is refused")
t = tech.get(B + "/contracts?show=signed").text
ok("Old MSA (DocuSeal)" in t and "Signed (uploaded)" in t and "Ends" in t, "it's listed with the signed ones")
ok(tech.post(B + f"/contracts/{uid}/delete", data={"_csrf": csrf(tech, f"/contracts/{uid}")}).url.endswith(f"/contracts/{uid}"), "techs can't delete an uploaded contract")
r = a.post(B + f"/contracts/{uid}/delete", data={"_csrf": csrf(a, f"/contracts/{uid}"), "confirm": "c-%04d " % uid})
ok(not q("select 1 from contracts where id=%s", uid) and not os.path.exists(UPLOADS + "/contracts/" + u["pdf_file"]), "admins can (typing its number), and the file goes too")
ok(q("select 1 from audit_log where action='contract.deleted_signed' and detail like %s", "C-%04d%%" % uid), "and the audit log keeps a note of it")

# ---- in the browser: the builder and the signing page
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1440, "height": 1000})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
    pg.goto(B + f"/contracts/templates/{tid}"); pg.wait_for_selector(".ct-block .ql-editor"); pg.wait_for_timeout(800)
    ok(pg.locator(".ct-block").count() == len(json.loads(q("select def from contract_templates where id=%s", tid)[0]["def"])["blocks"]) and "Managed IT Services" in pg.locator("[data-ct-preview]").inner_text(),
       "the builder shows every block and the preview")
    pg.click("[data-bs-target='#ct-tab-fields']"); pg.click("[data-ct-add-field]")
    pg.locator("#ct-fields .ct-item").last.locator("[data-f=label]").fill("Site visit day"); pg.locator("#ct-fields .ct-item").last.locator("[data-f=label]").press("Tab")
    ok("{{site_visit_day}}" in pg.locator("#ct-fields").inner_text(), "a new field gets its placeholder name from its label")
    pg.locator(".ct-block .ql-editor").first.click(); pg.keyboard.press("End"); pg.keyboard.type(" Visits on ")
    pg.locator("#ct-fields .ct-item").last.locator("[data-f=insert]").click(); pg.wait_for_timeout(900)
    ok("{{site_visit_day}}" in pg.locator(".ct-block .ql-editor").first.inner_text() and "Site visit day" in pg.locator("[data-ct-preview]").inner_text(), "Insert puts it in the text; the preview shows it")
    pg.click("[data-bs-target='#ct-tab-style']"); pg.fill("[data-ct-style=footer]", "Confidential · {{company_name}}")
    with pg.expect_navigation(): pg.click("#tpl-form button.btn-primary:has-text('Save')")
    dd = json.loads(q("select def from contract_templates where id=%s", tid)[0]["def"])
    ok("{{site_visit_day}}" in dd["blocks"][0]["html"] and dd["fields"][-1]["key"] == "site_visit_day" and dd["style"]["footer"] == "Confidential · {{company_name}}" and not errors, "saved from the builder: " + str(errors[:2]))
    # the client signs in the browser: fill the boxes, draw a signature
    sid, slink = new_sent("Sam Signer")
    pg2 = b.new_page(viewport={"width": 1100, "height": 900})
    pg2.goto(slink); pg2.wait_for_selector("#sign-form")
    left = pg2.locator("[data-cf-left]").inner_text()
    ok("2 required fields left" in left, "the page counts the boxes left to fill in: " + left)
    pg2.fill("input[name='f[billing_name]']", "Sam Signer"); pg2.fill("input[name='f[billing_email]']", "sam@cedar.example")
    ok("All required fields" in pg2.locator("[data-cf-left]").inner_text(), "and updates as they're filled in")
    pg2.click("[data-sig-tab=draw]")
    pg2.locator("[data-sig-canvas]").scroll_into_view_if_needed(); box = pg2.locator("[data-sig-canvas]").bounding_box()
    pg2.mouse.move(box["x"] + 30, box["y"] + 100); pg2.mouse.down()
    for i in range(1, 12): pg2.mouse.move(box["x"] + 30 + i * 35, box["y"] + 100 - (i % 3) * 30)
    pg2.mouse.up()
    pg2.check("#sg-consent")
    with pg2.expect_navigation(): pg2.click("#sign-form button.btn-primary")
    k = q("select status, client_signature from contracts where id=%s", sid)[0]
    ok(k["status"] == "completed" and json.loads(k["client_signature"])["kind"] == "drawn" and json.loads(k["client_signature"])["name"] == "Sam Signer", "signed in the browser with a drawn signature, under their own name")
    ok("Signed by everyone" in pg2.content(), "and sees the download")
    b.close()

# ---- a template on your own PDF: the pages stay as uploaded, boxes go on top
FIX = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "fixtures")
plain_pdf = open(FIX + "/contract-sample.pdf", "rb").read()
objstm_pdf = open(FIX + "/contract-sample-objstm.pdf", "rb").read()
up_tpl = lambda name, data: a.post(B + "/contracts/templates", data={"_csrf": csrf(a, "/contracts/templates"), "start": "pdf", "name": "Sample MSP agreement"}, files={"file": (name, data, "application/pdf")})
ok("isn't a PDF" in flash(up_tpl("x.pdf", b"<html>hello</html>").text), "a file that isn't a PDF is refused")
ok("password-protected" in flash(up_tpl("locked.pdf", plain_pdf.replace(b"trailer\n<< /Size", b"trailer\n<< /Encrypt 1 0 R /Size")).text), "a password-protected PDF is refused, with what to do")
r = up_tpl("Sample MSP agreement.pdf", objstm_pdf)
pid = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
pd = json.loads(q("select def from contract_templates where id=%s", pid)[0]["def"])
ok("Uploaded 3 pages" in flash(r.text) and len(pd["pdf"]["pages"]) == 3 and pd["pdf"]["pages"][0] == [612, 792] and pd["places"] == [] and not pd["services"]["rows"] and not pd["blocks"],
   "upload: a PDF with compressed object streams is read (3 pages); nothing is on it yet")
ok('data-pv-add' in r.text and f'data-src="/contracts/templates/{pid}/source"' in r.text and not errs(r.text), "the builder shows the PDF's pages")
src = a.get(B + f"/contracts/templates/{pid}/source")
ok(src.content == objstm_pdf and "sandbox" in src.headers.get("Content-Security-Policy", ""), "the PDF is served as uploaded, sandboxed")
ok(tech.get(B + f"/contracts/templates/{pid}/source").status_code == 403, "only admins get a template's PDF")
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1440, "height": 1000})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
    pg.goto(B + f"/contracts/templates/{pid}"); pg.wait_for_selector(".pv-page"); pg.wait_for_timeout(1500)
    ok(pg.locator(".pv-page").count() == 3 and pg.evaluate("document.querySelector('.pv-canvas').width") > 400, "PDF.js draws the pages in the builder")
    pg.click("[data-pv-suggest]"); pg.wait_for_selector(".pv-suggest"); pg.wait_for_timeout(300)
    names = [x.inner_text().strip() for x in pg.locator(".pv-suggest").all()]
    ok(len(names) >= 14 and "Client name" in names and "Contract start date" in names and "Client signature" in names and "Your signature" in names and "Date the client signed" in names and "Monthly total" in names,
       "Find the blanks: the [ ], ____ and $ spots, named from the words beside them: " + ", ".join(n for n in names if n))
    pg.click("[data-pv-accept-all]"); pg.wait_for_timeout(300)
    ok(pg.locator(".pv-box").count() == len([n for n in names if n]) and pg.locator(".pv-suggest").count() == len([n for n in names if not n]), "Add them: a box for each named blank; the price spots stay to choose")
    ok("Contract start date</b> box on page 1 is narrow for a date" in pg.locator("[data-pv-checks]").inner_html(), "the checks say when a date box is too narrow for a date")
    # 2.7.6: the list and the checks are built as elements (code scanning): every box listed by page, a click selects it
    links = pg.locator("[data-pv-list] a[data-goto]")
    ok(links.count() == pg.locator(".pv-box").count() and "Page 1:" in pg.locator("[data-pv-list]").inner_text(), "the list names every box, by page")
    links.first.click(); pg.wait_for_timeout(200)
    ok(pg.locator(".pv-box.selected").count() == 1, "clicking a name in the list selects its box")
    # a box placed by hand: your picture, on the last page
    pg.select_option("[data-pv-add]", "photo.provider"); pg.click("[data-pv-place]")
    box = pg.locator(".pv-page").nth(2).bounding_box()
    pg.locator(".pv-page").nth(2).scroll_into_view_if_needed(); box = pg.locator(".pv-page").nth(2).bounding_box()
    pg.mouse.click(box["x"] + box["width"] * 0.15, box["y"] + box["height"] * 0.72)
    ok(pg.locator(".pv-box.selected .pv-box-label").inner_text() == "Your profile picture" and not pg.locator("[data-pv-edit]").is_hidden(), "Place it, then click: the box is added and selected")
    sel = pg.locator(".pv-box.selected"); bb = sel.bounding_box()
    pg.mouse.move(bb["x"] + 10, bb["y"] + 10); pg.mouse.down(); pg.mouse.move(bb["x"] + 60, bb["y"] + 10, steps=5); pg.mouse.up()
    ok(abs(pg.locator(".pv-box.selected").bounding_box()["x"] - bb["x"] - 50) < 4, "drag a box to move it")
    pg.keyboard.press("Delete")
    ok(not any(x.inner_text() == "Your profile picture" for x in pg.locator(".pv-box-label").all()), "Delete removes the selected box")
    pg.select_option("[data-pv-add]", "photo.provider"); pg.click("[data-pv-place]"); pg.mouse.click(box["x"] + box["width"] * 0.15, box["y"] + box["height"] * 0.72)
    # Just boxes: no Fields tab; a box can be a new blank, named and set up right on the box
    ok(pg.locator("[data-bs-target='#ct-tab-fields']").count() == 0, "the PDF builder has no separate Fields tab")
    pg.select_option("[data-pv-add]", "__new"); pg.click("[data-pv-place]"); pg.mouse.click(box["x"] + box["width"] * 0.5, box["y"] + box["height"] * 0.9)
    ok(pg.evaluate("document.activeElement && document.activeElement.dataset.b") == "label" and pg.locator("[data-pv-blank]").is_visible(), "New blank: the box is placed and asks for its name")
    pg.fill("[data-b=label]", "Onsite rate"); pg.press("[data-b=label]", "Tab")
    pg.select_option("[data-b=type]", "money"); pg.select_option("[data-b=by]", "client"); pg.check("[data-b=required]"); pg.fill("[data-b=help]", "Per hour")
    ok(pg.locator(".pv-box.selected .pv-box-label").inner_text() == "Onsite rate" and pg.locator("[data-b-row=default]").is_hidden(), "the box shows its name; a client's blank has no default")
    pg.locator(".pv-page").nth(2).scroll_into_view_if_needed(); box = pg.locator(".pv-page").nth(2).bounding_box()  # the name field took the focus (and may have scrolled)
    pg.select_option("[data-pv-add]", "onsite_rate"); pg.click("[data-pv-place]"); pg.mouse.click(box["x"] + box["width"] * 0.7, box["y"] + box["height"] * 0.8)
    ok("Also in 1 other box" in pg.locator("[data-b-also]").inner_text() and pg.input_value("[data-b=label]") == "Onsite rate", "the same blank in a second box: one blank, both boxes")
    pg.locator(".pv-page").nth(2).scroll_into_view_if_needed(); box = pg.locator(".pv-page").nth(2).bounding_box()
    pg.select_option("[data-pv-add]", "__new"); pg.click("[data-pv-place]"); pg.mouse.click(box["x"] + box["width"] * 0.3, box["y"] + box["height"] * 0.8)
    pg.fill("[data-b=label]", "Throwaway"); pg.press("[data-b=label]", "Tab")
    pg.select_option("[data-pv-key]", "client_name")
    ok(pg.locator("[data-pv-blank]").is_hidden() and not pg.locator("[data-pv-add] option[value=throwaway]").count(), "a blank no box shows any more is gone")
    with pg.expect_navigation(): pg.click("#tpl-form button.btn-primary:has-text('Save')")
    pd = json.loads(q("select def from contract_templates where id=%s", pid)[0]["def"])
    onsite = [f for f in pd["fields"] if f["key"] == "onsite_rate"]
    ok(onsite and onsite[0]["type"] == "money" and onsite[0]["by"] == "client" and onsite[0]["required"] and onsite[0]["help"] == "Per hour"
       and sum(1 for x in pd["places"] if x["key"] == "onsite_rate") == 2 and not any(f["key"] == "throwaway" for f in pd["fields"]), "saved: the blank with its settings, in two boxes; the throwaway one gone")
    pd["places"] = [x for x in pd["places"] if x["key"] != "onsite_rate"]
    a.post(B + f"/contracts/templates/{pid}", data={"_csrf": csrf(a, f"/contracts/templates/{pid}"), "name": "Sample MSP agreement", "is_active": "1", "def": json.dumps(pd)})
    pd = json.loads(q("select def from contract_templates where id=%s", pid)[0]["def"])
    ok(not any(f["key"] == "onsite_rate" for f in pd["fields"]), "removing its boxes removes the blank (saved without them)")
    keys = [x["key"] for x in pd["places"]]
    ok(all(k in keys for k in ["client_name", "start_date", "sig.client", "sig.provider", "name.client", "name.provider", "title.client", "date.client", "monthly_total", "photo.provider"])
       and not any(f["key"] == "start_date" for f in pd["fields"]) and not errors, "saved: the boxes (the start date is the built-in, no blank made for it): " + ", ".join(keys) + " " + str(errors[:2]))
    b.close()
# The services and their boxes (as the builder would save them), the client's initials, no emailed code
cn = next(x for x in pd["places"] if x["key"] == "client_name" and x["page"] == 0)
ok(any(x["key"] == "client_name" and x["page"] == 2 and x["x"] > 300 for x in pd["places"]), "the [ ] opposite your company over the signatures is the client's name too")
ok(cn["page"] == 0 and 395 < cn["x"] < 435 and 125 < cn["y"] < 140 and cn["w"] > 60, "the client name box sits inside the [ ] on page 1: %s" % {k: cn[k] for k in ("x", "y", "w", "h")})
pd["services"]["rows"] = [{"key": "users", "label": "Users", "price": 95, "period": "month", "auto": "users"}, {"key": "workstations", "label": "Workstations", "price": 10, "period": "month", "auto": "workstations"}]
for i, k in enumerate(["users", "workstations"]):
    pd["places"] += [{"page": 2, "x": 200, "y": 132 + i * 20, "w": 60, "h": 14, "key": f"svc.{k}.qty"}, {"page": 2, "x": 310, "y": 132 + i * 20, "w": 60, "h": 14, "key": f"svc.{k}.price", "plain": True}]
pd["places"].append({"page": 1, "x": 400, "y": 124, "w": 40, "h": 18, "key": "initials.client", "size": 12})
pd["places"].append({"page": 0, "x": 72, "y": 260, "w": 300, "h": 14, "key": "nope.nothing"})
pd["signing"]["verify_code"] = False
a.post(B + f"/contracts/templates/{pid}", data={"_csrf": csrf(a, f"/contracts/templates/{pid}"), "name": "Sample MSP agreement", "is_active": "1", "def": json.dumps(pd)})
pd = json.loads(q("select def from contract_templates where id=%s", pid)[0]["def"])
ok(len([x for x in pd["places"] if x["key"].startswith("svc.")]) == 4 and not any(x["key"] == "nope.nothing" for x in pd["places"]), "boxes for services saved; a box for something that doesn't exist is dropped")
sp = a.get(B + f"/contracts/templates/{pid}/pdf").content
ok(sp.startswith(objstm_pdf) and "Example Client, Inc." in pdf_text(sp) and "[Your signature]" in pdf_text(sp) and "DRAFT - not signed" in pdf_text(sp), "Sample PDF: your PDF untouched, with the box labels and a draft mark added on top")
# A contract on it: a new client; you fill in, they fill in and sign over the real pages
r = tech.post(B + "/contracts", data={"_csrf": csrf(tech, "/contracts"), "template_id": pid, "for": "lead", "lead_company": "Pine Valley Clinic"})
xid = int(re.search(r"/contracts/(\d+)$", r.url).group(1))
ok(f'data-src="/contracts/{xid}/source"' in r.text and 'name="start"' in r.text and "Contract start date" in r.text and not errs(r.text), "prepare: the PDF's pages with the boxes, and your fields to fill in")
X = {"_csrf": csrf(tech, f"/contracts/{xid}"), "then": "save", "client_id": "", "lead_company": "Pine Valley Clinic", "signer_name": "Robin Vale", "signer_title": "Practice manager",
     "signer_email": "robin@pinevalley.example", "start": "2026-12-01", "svc[users][on]": "1", "svc[users][qty]": "9", "svc[users][price]": "95", "svc[workstations][on]": "1", "svc[workstations][qty]": "12", "svc[workstations][price]": "10"}
pv = tech.post(B + f"/contracts/{xid}/preview", data=X).text
items = json.loads(H2.unescape(re.search(r'<script type="application/json" class="pv-data">(.*?)</script>', pv, re.S).group(1)))["items"]
txt = {i["key"]: i["text"] for i in items}
ok(txt["client_name"] == "Pine Valley Clinic" and txt["svc.users.qty"] == "9" and txt["svc.users.price"] == "95" and txt["monthly_total"] == "975" and txt["start_date"] == "December 1, 2026",
   "live preview: the boxes get the values (9 users at 95 and 12 workstations at 10: 975 a month)")
tech.post(B + f"/contracts/{xid}", data=X)
r = tech.post(B + f"/contracts/{xid}/send", data={"_csrf": csrf(tech, f"/contracts/{xid}"), "action": "link"})
xlink = local(re.search(r'id="ct-link" readonly value="([^"]+)"', r.text).group(1))
xtok = xlink.rsplit("/", 1)[1]
cx = requests.Session()
t = cx.get(xlink).text
it = {i["key"]: i for i in json.loads(H2.unescape(re.search(r'<script type="application/json" class="pv-data">(.*?)</script>', t, re.S).group(1)))["items"]}
ok(f'data-src="/portal/sign/{xtok}/source"' in t and it["sig.client"]["kind"] == "sighere" and it["initials.client"]["kind"] == "initials"
   and it["name.client"]["kind"] == "input" and it["name.client"]["field"]["role"] == "sig_name" and it["name.client"]["field"]["value"] == "" and it["name.client"]["text"] == ""
   and it["sig.provider"]["kind"] == "blank" and it["client_name"]["text"] == "Pine Valley Clinic" and "data-guided" in t,
   "the client's page: their PDF with Sign and Initial steps, an empty box for their name (nothing filled in for them), your signature still to come")
today = php('echo Align\\Fmt::date(date("Y-m-d"));').stdout.strip()
ok(it["date.client"]["text"] == today and it["date.client"].get("auto") and it["date.provider"]["text"] == "", "their date box already shows today's date (filled in when they sign): " + today)
ok(cx.get(xlink + "/source").content == objstm_pdf and requests.get(B + "/portal/sign/" + "B" * 43 + "/source").status_code == 404, "the signing link serves the PDF; a made-up one doesn't")
r = cx.post(xlink, data={"_csrf": portal_csrf(cx, xlink), "sig_kind": "type", "sig_typed": "Robin Vale", "sig_name": "Robin Vale", "sig_title": "Practice manager", "initials": "RV", "consent": "1"})
ok("initial every Initial box" in flash(r.text) and q("select status from contracts where id=%s", xid)[0]["status"] == "sent", "initials typed once aren't enough: every Initial box has to be clicked")
r = cx.post(xlink, data={"_csrf": portal_csrf(cx, xlink), "sig_kind": "type", "sig_typed": "Robin Vale", "sig_name": "Robin Vale", "initials": "RV", "initialed[]": [it["initials.client"]["id"]], "consent": "1"})
ok("fill in your title" in flash(r.text), "and the title, when the PDF has a box for it")
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1100, "height": 900})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(xlink); pg.wait_for_selector(".pv-page"); pg.wait_for_timeout(1200)
    ok(pg.locator('[data-step="sign"]').count() == 1 and pg.locator('[data-step="initials"]').count() == 1 and pg.input_value('[data-role="sig_name"]') == ""
       and pg.evaluate("document.querySelector('.pv-canvas').width") > 300 and pg.locator("[data-guide-next]").inner_text().strip() == "Start", "in the browser: the pages with yellow Sign and Initial tags, and Start")
    # 2.7.7 (code scanning): signature and picture images are drawn from their decoded bytes (a blob: URL); anything
    # that isn't a PNG or JPEG data: URL shows no image at all
    PNG1 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
    res = pg.evaluate("""async (png) => {
        const v = window.AlignPdf.mount(document.querySelector('[data-pv]'));
        const keep = v.items;
        v.setItems([...keep, {page: 0, x: 40, y: 40, w: 120, h: 40, kind: 'sig', img: 'data:image/png;base64,' + png, key: 't1'},
                    {page: 0, x: 40, y: 100, w: 60, h: 60, kind: 'photo', img: 'javascript:alert(1)', key: 't2'},
                    {page: 0, x: 120, y: 100, w: 60, h: 60, kind: 'photo', img: '/users/1/avatar', key: 't3'}]);
        const imgs = [...document.querySelectorAll('.pv-sig img, .pv-photo img')];
        await new Promise((r) => setTimeout(r, 300));
        const out = imgs.map((i) => [i.getAttribute('src') || '', i.naturalWidth]);
        v.setItems(keep);
        return out;
    }""", PNG1)
    ok(len(res) == 3 and res[0][0].startswith("blob:") and res[0][1] == 1 and res[1][0] == "" and res[2][0] == "",
       f"a signature image shows from its own bytes; a script or outside address shows nothing: {res}")
    pg.click("[data-guide-next]"); pg.wait_for_timeout(600)
    ok(pg.locator(".pv-current").count() == 1 and pg.locator("[data-guide-next]").inner_text().strip() == "Next" and " of " in pg.locator("[data-guide-status]").inner_text(),
       "Start takes them to the first place to fill in: " + pg.locator("[data-guide-status]").inner_text())
    pg.fill('[data-role="sig_name"]', "Robin Vale"); pg.fill('[data-role="sig_title"]', "Practice manager")
    pg.locator('[data-step="initials"]').scroll_into_view_if_needed(); pg.click('[data-step="initials"]'); pg.wait_for_selector("#adopt-ini.show")
    ok(pg.input_value("[data-ai=initials]") == "RV", "the first Initial box asks for their initials (suggested from the name they typed)")
    pg.click("[data-ai=adopt]"); pg.wait_for_timeout(500)
    ok("done" in pg.get_attribute('[data-step="initials"]', "class") and pg.locator('[data-step="initials"]').inner_text() == "RV", "adopted and put in that box")
    pg.click("[data-guide-next]"); pg.wait_for_timeout(600)
    ok(pg.locator('.pv-current [data-step="sign"]').count() == 1, "Next goes on to the Sign box")
    pg.click('[data-step="sign"]'); pg.wait_for_selector("#adopt-sig.show")
    ok(pg.input_value("[data-ad=name]") == "Robin Vale" and pg.input_value("[data-ad=typed]") == "Robin Vale", "Sign: their signature, from the name they typed")
    pg.click("[data-ad-tab=draw]")
    cv = pg.locator("[data-ad=canvas]").bounding_box()
    pg.mouse.move(cv["x"] + 40, cv["y"] + 90); pg.mouse.down(); pg.mouse.move(cv["x"] + 200, cv["y"] + 30, steps=8); pg.mouse.move(cv["x"] + 360, cv["y"] + 110, steps=8); pg.mouse.up()
    pg.click("[data-ad=adopt]"); pg.wait_for_timeout(500)
    ok(pg.locator('[data-step="sign"] img').count() == 1 and pg.locator("[data-guide-next]").inner_text().strip() == "Finish", "drawn and adopted: the signature is in its box, and Next becomes Finish")
    pg.click("[data-guide-next]"); pg.wait_for_selector("#sign-finish.show")
    pg.check("#sg-consent")
    with pg.expect_navigation(): pg.click("#sign-finish button[form=sign-form]")
    k = q("select status, client_signature from contracts where id=%s", xid)[0]
    ev = q("select detail from contract_events where contract_id=%s and event='client_signed'", xid)[0]["detail"]
    ok(k["status"] == "client_signed" and json.loads(k["client_signature"])["kind"] == "drawn" and "initialed 1 box one by one" in ev and not errors,
       "signed in the browser, step by step: waiting for your signature " + str(errors[:2]))
    b.close()
r = a.post(B + f"/contracts/{xid}/countersign", data={"_csrf": csrf(a, f"/contracts/{xid}"), "sig_kind": "draw", "sig_png": drawn_png(), "sig_name": "Alex Admin", "sig_title": "vCIO", "consent": "1"})
k = q("select * from contracts where id=%s", xid)[0]
f = open(UPLOADS + "/contracts/" + k["pdf_file"], "rb").read()
ok(str(k["starts_on"]) == "2026-12-01", "the contract's Starts date is its contract start date: %s" % k["starts_on"])
ok(k["status"] == "completed" and f.startswith(objstm_pdf), "countersigned: the signed PDF is your original file, byte for byte, with the signing added after it")
info = json.loads(php(f'$d = new Align\\Pdf\\PdfDoc(file_get_contents("{UPLOADS}/contracts/{k["pdf_file"]}")); echo json_encode(["pages" => count($d->pages())]);').stdout)
txt = pdf_text(f)
ok(info["pages"] >= 4 and "Signature certificate" in txt and "Pine Valley Clinic" in txt and "Robin Vale" in txt and "RV" in txt and "Agreement Terms" in txt and "975" in txt and "DRAFT" not in txt and "December 1, 2026" in txt,
   "it reads back (a value too long for its box is shrunk, never cut): the 3 pages with the values, initials and names, and the certificate after them (%s pages)" % info["pages"])
r = a.post(B + "/contracts/verify", data={"_csrf": csrf(a, "/contracts/verify")}, files={"file": ("copy.pdf", f, "application/pdf")})
ok("It matches." in r.text, "Check a signed PDF works for these too")
# a new version of the PDF: boxes kept where they fit; the contract keeps its own copy
r = a.post(B + f"/contracts/templates/{pid}/pdf", data={"_csrf": csrf(a, f"/contracts/templates/{pid}")}, files={"file": ("v2.pdf", plain_pdf, "application/pdf")})
pd2 = json.loads(q("select def from contract_templates where id=%s", pid)[0]["def"])
kd = json.loads(q("select def from contracts where id=%s", xid)[0]["def"])
ok("New PDF uploaded" in flash(r.text) and pd2["pdf"]["file"] != kd["pdf"]["file"] and len(pd2["places"]) == len(pd["places"]) and os.path.exists(UPLOADS + "/contracts/" + kd["pdf"]["file"]),
   "a new version: the template uses it with the same boxes; the signed contract keeps the version it was made with")
# a builder left open in another tab saves its old copy: the PDF stays the new one
a.post(B + f"/contracts/templates/{pid}", data={"_csrf": csrf(a, f"/contracts/templates/{pid}"), "name": "Sample MSP agreement", "is_active": "1", "def": json.dumps(pd)})
ok(json.loads(q("select def from contract_templates where id=%s", pid)[0]["def"])["pdf"]["file"] == pd2["pdf"]["file"], "saving from a stale builder doesn't point the template back at the old PDF")
# export carries the PDF, so the template works on another server; the import keeps it as its own file
ex = a.get(B + f"/contracts/templates/{pid}/export").json()
ok(base64.b64decode(ex["template"]["pdf_base64"]) == plain_pdf, "export includes the PDF")
r = a.post(B + "/contracts/templates/import", data={"_csrf": csrf(a, "/contracts/templates")}, files={"file": ("t.json", json.dumps(ex).encode(), "application/json")})
iid = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
idf = json.loads(q("select def from contract_templates where id=%s", iid)[0]["def"])
ok(idf["pdf"]["file"] != pd2["pdf"]["file"] and idf["pdf"]["hash"] == pd2["pdf"]["hash"] and len(idf["places"]) == len(pd2["places"]) and a.get(B + f"/contracts/templates/{iid}/source").content == plain_pdf,
   "import: the same boxes on its own copy of the PDF")
ex["template"].pop("pdf_base64")
ok("doesn't include its PDF" in flash(a.post(B + "/contracts/templates/import", data={"_csrf": csrf(a, "/contracts/templates")}, files={"file": ("t.json", json.dumps(ex).encode(), "application/json")}).text),
   "an export without its PDF is refused, never pointed at a file already on the server")
# PDFs made the way Word makes them: a page only listed in the companion cross-reference stream
def hybrid_pdf():
    cc = b"BT /F1 24 Tf 72 700 Td (Hello hybrid) Tj ET"
    hdr = b"3 0 "
    osz = zlib.compress(hdr + b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>")
    objs = {1: b"<< /Type /Catalog /Pages 2 0 R >>", 2: b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            4: b"<< /Length %d >>\nstream\n" % len(cc) + cc + b"\nendstream", 5: b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
            6: b"<< /Type /ObjStm /N 1 /First %d /Filter /FlateDecode /Length %d >>\nstream\n" % (len(hdr), len(osz)) + osz + b"\nendstream"}
    out, off = b"%PDF-1.5\n", {}
    for i, body in objs.items():
        off[i] = len(out); out += b"%d 0 obj\n" % i + body + b"\nendobj\n"
    off[7] = len(out); z = zlib.compress(b"\x02\x00\x06\x00")
    out += b"7 0 obj\n<< /Type /XRef /Size 8 /Index [3 1] /W [1 2 1] /Filter /FlateDecode /Length %d >>\nstream\n" % len(z) + z + b"\nendstream\nendobj\n"
    x = len(out); out += b"xref\n0 8\n0000000000 65535 f \n"
    for i in range(1, 8): out += b"0000000000 00001 f \n" if i in (3, 7) else b"%010d 00000 n \n" % off[i]
    return out + b"trailer\n<< /Size 8 /Root 1 0 R /XRefStm %d >>\nstartxref\n%d\n%%%%EOF\n" % (off[7], x)
r = up_tpl("hybrid.pdf", hybrid_pdf())
ok("Uploaded 1 page" in flash(r.text), "a hybrid-reference PDF (as Word saves them) is read: %s" % flash(r.text))
# Align's own output uploaded as a template (its names are already on the pages) still stamps cleanly
r = up_tpl("again.pdf", sp)
aid = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
ad = json.loads(q("select def from contract_templates where id=%s", aid)[0]["def"])
ad["places"] = [{"page": 0, "x": 72, "y": 300, "w": 200, "h": 14, "key": "client_name"}]
a.post(B + f"/contracts/templates/{aid}", data={"_csrf": csrf(a, f"/contracts/templates/{aid}"), "name": "Again", "is_active": "1", "def": json.dumps(ad)})
sp2 = a.get(B + f"/contracts/templates/{aid}/pdf").content
ok(sp2.startswith(sp) and pdf_text(sp2).count("DRAFT - not signed") >= 2 * 3, "a PDF that came out of Align can be used again as a template")


# ---- security (2.2 audit): who can do what, the code's limits, links that stop, hostile input and PDFs
# Roles: viewers see nothing; techs can't touch templates
for meth, path in [("get", f"/contracts/{xid}"), ("get", f"/contracts/{xid}/pdf"), ("get", f"/contracts/{xid}/source"), ("get", "/contracts/verify"),
                   ("post", f"/contracts/{xid}/send"), ("post", f"/contracts/{xid}/countersign"), ("post", f"/contracts/{xid}/void"), ("post", f"/contracts/{xid}/delete"),
                   ("post", f"/contracts/{xid}/client"), ("post", f"/contracts/{xid}/details"), ("post", "/contracts/upload"), ("post", "/contracts")]:
    r = viewer.get(B + path) if meth == "get" else viewer.post(B + path, data={"_csrf": csrf(viewer, "/")})
    ok(r.status_code == 403, f"viewer: {meth.upper()} {path} is refused ({r.status_code})")
for meth, path in [("post", "/contracts/templates"), ("post", "/contracts/templates/import"), ("post", "/contracts/templates/settings"), ("post", f"/contracts/templates/{pid}"),
                   ("post", f"/contracts/templates/{pid}/preview"), ("post", f"/contracts/templates/{pid}/duplicate"), ("post", f"/contracts/templates/{pid}/delete"),
                   ("post", f"/contracts/templates/{pid}/pdf"), ("get", f"/contracts/templates/{pid}/export"), ("get", f"/contracts/templates/{pid}/pdf"), ("get", f"/contracts/templates/{pid}")]:
    r = tech.get(B + path) if meth == "get" else tech.post(B + path, data={"_csrf": csrf(tech, "/contracts")})
    ok(r.status_code == 403, f"tech: {meth.upper()} {path} is refused ({r.status_code})")
ok(requests.get(B + f"/contracts/{xid}/source", allow_redirects=False).status_code in (302, 303) and requests.get(B + f"/contracts/{xid}/pdf", allow_redirects=False).status_code in (302, 303), "signed out: contract files send you to sign in")

# A signed contract can't be moved to another client, and its dates only change once signed
other = q("select id from clients where id <> %s and is_archived = 0 limit 1", cid)[0]["id"]
before = q("select client_id from contracts where id=%s", kid)[0]["client_id"]
r = tech.post(B + f"/contracts/{kid}/client", data={"_csrf": csrf(tech, f"/contracts/{kid}"), "client_id": str(other)})
ok(q("select client_id from contracts where id=%s", kid)[0]["client_id"] == before and "isn't linked to a client yet" in flash(r.text), "a signed contract linked to a client can't be moved to another one")
sid, slink = new_sent("Sam Sent")
r = tech.post(B + f"/contracts/{sid}/client", data={"_csrf": csrf(tech, f"/contracts/{sid}"), "client_id": str(other)})
ok(q("select client_id from contracts where id=%s", sid)[0]["client_id"] == cid, "nor one that's out for signature")
r = tech.post(B + f"/contracts/{sid}/details", data={"_csrf": csrf(tech, f"/contracts/{sid}"), "starts_on": "2026-02-31", "notes": "x"})
ok(q("select notes from contracts where id=%s", sid)[0]["notes"] is None, "dates and notes wait until it's signed")
r = tech.post(B + f"/contracts/{kid}/details", data={"_csrf": csrf(tech, f"/contracts/{kid}"), "starts_on": "2026-02-31", "ends_on": "2027-02-28"})
k = q("select starts_on, ends_on from contracts where id=%s", kid)[0]
ok(str(k["ends_on"]) == "2027-02-28" and str(k["starts_on"]) != "2026-02-31" and not errs(r.text), "an impossible date (February 31) is ignored, not a server error")

# What was sent is what's signed: Settings changed after sending don't change it
old_name = q("select value from settings where name='company_name'")[0]["value"]
q("update settings set value='Renamed Company LLC' where name='company_name'")
t = requests.get(slink).text
q("update settings set value=%s where name='company_name'", old_name)
ok("Renamed Company LLC" not in json.dumps(json.loads(q("select vals from contracts where id=%s", sid)[0]["vals"])["print"]) and json.loads(q("select vals from contracts where id=%s", sid)[0]["vals"])["print"]["company_name"] == old_name,
   "the company's details are kept as they were when it was sent")

# The emailed code: tries, the wait between codes, expiry; the old link after sending again
q("update contracts set verify_code = 1 where id=%s", sid)
cs = requests.Session()
ok("Email me a code" in cs.get(slink).text and cs.get(slink + "/source").status_code == 403 and cs.get(slink + "/pdf", allow_redirects=False).status_code in (302, 303), "with a code: nothing opens before it")
r = cs.post(slink + "/decline", data={"_csrf": portal_csrf(cs, slink), "reason": "x"})
ok(q("select status from contracts where id=%s", sid)[0]["status"] == "sent", "nor can it be declined before the code")
cs.post(slink + "/code", data={"_csrf": portal_csrf(cs, slink)})
code = re.search(r"letter-spacing:6px[^>]*>(\d{6})<", last_mail("Your code to sign")["body"]["content"]).group(1)
r = cs.post(slink + "/code", data={"_csrf": portal_csrf(cs, slink)})
ok("just sent" in flash(r.text), "a second code right away is refused")
wrong = "000000" if code != "000000" else "111111"
for _ in range(5):
    cs.post(slink + "/verify", data={"_csrf": portal_csrf(cs, slink), "code": wrong})
r = cs.post(slink + "/verify", data={"_csrf": portal_csrf(cs, slink), "code": code})
ok("Sign the contract" not in r.text and q("select code_attempts from contracts where id=%s", sid)[0]["code_attempts"] == 5, "after 5 wrong tries even the right code is refused")
q("update contracts set code_sent_at = NOW() - INTERVAL 1 MINUTE where id=%s", sid)
cs.post(slink + "/code", data={"_csrf": portal_csrf(cs, slink)})
code2 = re.search(r"letter-spacing:6px[^>]*>(\d{6})<", last_mail("Your code to sign")["body"]["content"]).group(1)
q("update contracts set code_expires_at = NOW() - INTERVAL 1 MINUTE where id=%s", sid)
r = cs.post(slink + "/verify", data={"_csrf": portal_csrf(cs, slink), "code": code2})
ok("Sign the contract" not in r.text, "an expired code is refused")
q("update contracts set code_sent_count = 10, code_sent_at = NOW() - INTERVAL 1 MINUTE where id=%s", sid)
r = cs.post(slink + "/code", data={"_csrf": portal_csrf(cs, slink)})
ok("Too many codes" in flash(r.text), "at most 10 codes a day")
q("update contracts set code_sent_at = NOW() - INTERVAL 25 HOUR where id=%s", sid)
cs.post(slink + "/code", data={"_csrf": portal_csrf(cs, slink)})
code3 = re.search(r"letter-spacing:6px[^>]*>(\d{6})<", last_mail("Your code to sign")["body"]["content"]).group(1)
r = cs.post(slink + "/verify", data={"_csrf": portal_csrf(cs, slink), "code": code3})
ok("Sign the contract" in r.text and q("select code_sent_count from contracts where id=%s", sid)[0]["code_sent_count"] == 1, "a day later codes can be sent again, and the new one works")
r = cs.post(slink, data={"sig_kind": "type", "sig_typed": "Sam", "sig_name": "Sam", "consent": "1"})
ok(r.status_code == 419 and q("select status from contracts where id=%s", sid)[0]["status"] == "sent", "signing without the form's CSRF token is refused")
r = cs.post(slink, data={"_csrf": portal_csrf(cs, slink), "sig_kind": "draw", "sig_png": "data:image/svg+xml;base64," + base64.b64encode(b"<svg onload=alert(1)></svg>").decode(), "sig_name": "Sam", "consent": "1"})
ok("draw your signature" in flash(r.text), "a drawn signature that isn't a PNG is refused")
blank = php('$i = imagecreate(400, 100); imagecolorallocatealpha($i, 0, 0, 0, 127); ob_start(); imagepng($i); echo base64_encode(ob_get_clean());').stdout.strip()
r = cs.post(slink, data={"_csrf": portal_csrf(cs, slink), "sig_kind": "draw", "sig_png": "data:image/png;base64," + blank, "sig_name": "Sam", "consent": "1"})
ok("draw your signature" in flash(r.text), "an empty drawing (a palette PNG) is refused too")
r = tech.post(B + f"/contracts/{sid}/send", data={"_csrf": csrf(tech, f"/contracts/{sid}"), "action": "link"})
ok(requests.get(slink).status_code == 404 and cs.get(slink).status_code == 404, "sent again: the old link stops working, even in a browser that had the code")
nlink = local(re.search(r'id="ct-link" readonly value="([^"]+)"', r.text).group(1))
r = cs.get(nlink)
ok("Email me a code" in r.text, "and the new link asks for a code again")
# The opened event can't be flooded
for _ in range(5):
    requests.get(nlink)
ok(q("select count(*) n from contract_events where contract_id=%s and event='opened'", sid)[0]["n"] <= 3, "opening the link many times isn't written to the history every time")
cv = requests.Session(); cv.get(nlink)
tech.post(B + f"/contracts/{sid}/void", data={"_csrf": csrf(tech, f"/contracts/{sid}"), "reason": "test"})
ok(all(cv.get(nlink + p).status_code == 404 for p in ("", "/source", "/pdf")) and cv.post(nlink + "/code", data={"_csrf": "x"}).status_code in (404, 419), "cancelled: the link and its files are gone")
r = tech.post(B + f"/contracts/{sid}/void", data={"_csrf": csrf(tech, f"/contracts/{sid}")})
ok("can be cancelled" in flash(r.text), "cancelling twice says nothing was cancelled")
# A decline reason with markup is shown as text
did2, dlink2 = new_sent("Dee <b>Decline</b>")
c4 = requests.Session()
c4.post(dlink2 + "/decline", data={"_csrf": portal_csrf(c4, dlink2), "reason": "<script>alert(1)</script>"})
t = tech.get(B + f"/contracts/{did2}").text
ok("&lt;script&gt;alert(1)&lt;/script&gt;" in t and "<script>alert(1)" not in t and "Dee &lt;b&gt;Decline" in t, "a decline reason and a signer name with markup are escaped")
ok(php(f'echo json_encode(Align\\Contracts\\Contracts::decline(Align\\Contracts\\Contracts::load({did2}), "again"));').stdout.strip() == "false", "declining twice changes nothing (and says so)")
# Uploads over the size limit get a clear message
big = b"%PDF-1.4\n" + os.urandom(26 * 1024 * 1024)
r = tech.post(B + "/contracts/upload", data={"_csrf": csrf(tech, "/contracts"), "client_id": str(cid), "back": "/contracts"}, files={"file": ("big.pdf", big, "application/pdf")})
ok(("larger than" in flash(r.text) or "larger than this server accepts" in r.text) and r.status_code in (200, 413), "a file over 25 MB is refused with a clear message (%s)" % r.status_code)
# Hostile PDFs: the stamp can't be clipped away; scripts, forms and covering annotations stay out of contracts
def mini_pdf(content, catalog=b"", page=b"", extra=b""):
    objs = {1: b"<< /Type /Catalog /Pages 2 0 R " + catalog + b" >>", 2: b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3: b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R " + page + b" >>",
            4: b"<< /Length %d >>\nstream\n" % len(content) + content + b"\nendstream"}
    for n, body in enumerate(extra, 5): objs[n] = body
    out, off = b"%PDF-1.4\n", {}
    for n in sorted(objs): off[n] = len(out); out += b"%d 0 obj\n" % n + objs[n] + b"\nendobj\n"
    x = len(out); out += b"xref\n0 %d\n0000000000 65535 f \n" % (len(objs) + 1) + b"".join(b"%010d 00000 n \n" % off[n] for n in sorted(objs))
    return out + b"trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (len(objs) + 1, x)
hostile = mini_pdf(b"Q Q q 0 0 10 10 re W n q 1 0 0 1 0 0 cm (q Q) Tj % Q\n",
                   catalog=b"/OpenAction 5 0 R /AcroForm << /Fields [] >>", page=b"/Annots [6 0 R 7 0 R]",
                   extra=[b"<< /S /JavaScript /JS (app.alert(1)) >>", b"<< /Type /Annot /Subtype /Square /Rect [0 0 612 792] /IC [1 1 1] >>",
                          b"<< /Type /Annot /Subtype /Link /Rect [0 0 10 10] /A << /S /URI /URI (https://example.com) >> >>"])
ok(php("echo json_encode(Align\\Contracts\\PdfStamp::balance('Q Q q 0 0 10 10 re W n q 1 0 0 1 0 0 cm (q Q) Tj % Q\\n'));").stdout.strip() == "[-2,0]", "the save depth counts q and Q outside strings and comments")
r = up_tpl("hostile.pdf", hostile)
hid = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
ok("leaves those out" in flash(r.text), "upload: Align says form fields, scripts and annotations are left out: " + flash(r.text))
hd = json.loads(q("select def from contract_templates where id=%s", hid)[0]["def"])
hd["places"] = [{"page": 0, "x": 72, "y": 72, "w": 200, "h": 14, "key": "client_name"}]
a.post(B + f"/contracts/templates/{hid}", data={"_csrf": csrf(a, f"/contracts/templates/{hid}"), "name": "Hostile", "is_active": "1", "def": json.dumps(hd)})
hs = a.get(B + f"/contracts/templates/{hid}/pdf").content
open("/tmp/msp-align-tests/hostile-out.pdf", "wb").write(hs)
info = json.loads(php('$d = new Align\\Pdf\\PdfDoc(file_get_contents("/tmp/msp-align-tests/hostile-out.pdf")); $c = $d->catalog()->d; $p = $d->pages()[0];'
                      ' echo json_encode(["open" => isset($c["OpenAction"]), "form" => isset($c["AcroForm"]), "annots" => count((array) $d->resolve($p["dict"]->d["Annots"] ?? [])),'
                      ' "balance" => Align\\Contracts\\PdfStamp::balance($d->pageContent($p["dict"]))]);').stdout)
ok(hs.startswith(hostile) and not info["open"] and not info["form"] and info["annots"] == 1 and info["balance"][0] >= 0,
   "the stamped copy: the script, the form and the covering annotation are gone (the link stays), and the page can't pop the stamp's state: %s" % info)
for bad, why in [(mini_pdf(b"q", page=b"/MediaBox [0 0 1e400 792]"), "a page size that isn't a number"), (mini_pdf(b"q").replace(b"/Kids [3 0 R]", b"/Kids [3 0 R 3 0 R]").replace(b"/Count 1", b"/Count 2"), "a page listed twice")]:
    r = up_tpl("bad.pdf", bad)
    ok(r.url.endswith("/contracts/templates") and flash(r.text) and not errs(r.text) or "pages" in flash(r.text), f"{why}: handled ({flash(r.text)[:80]})")

done()
