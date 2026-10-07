"""2.2.1 review: staff contracts, the welcome (onboarding) link and onboarding requests. Each check fails on 2.2.0.
Staff status actions (only complete/reopen/delete, delete for admins, from the right state), the onboarding page view
audited, the public page's guards (finish only when done, review recorded once, real dates, control characters,
unverified requesters on the emailed request, PSA contacts by email, a daily cap on new people, the guide's file name),
and contracts (a deleted client's contract, the draft PDF audited, the delete confirmation's pattern)."""
from lib import *

def local(u): return re.sub(r"^https?://[^/]+", B, u)
def mock(path, body=None): return requests.post(M + path, json=body or {}).json()

CID = 1
# Put back what this suite changes (settings, client 1's onboarding and service requests) when it ends
import atexit
_names = ("client_requests", "company_email", "onboarding_link_days", "psa_two_way")
_settings = q("select * from settings where name in %s", _names)
_onb = q("select * from client_onboardings where client_id=%s", CID)
_reqs = q("select * from service_requests where client_id=%s", CID)
def _restore():
    try: mock("/mock/ticket-create-fail", {"on": False})
    except Exception: pass
    q("delete from settings where name in %s", _names)
    for r in _settings:
        q("insert into settings (" + ",".join(r) + ") values (" + ",".join(["%s"] * len(r)) + ")", *r.values())
    q("delete from client_onboardings where client_id=%s", CID)
    for r in _onb:
        q("insert into client_onboardings (" + ",".join(r) + ") values (" + ",".join(["%s"] * len(r)) + ")", *r.values())
    q("delete from service_requests where client_id=%s", CID)
    for r in _reqs:
        q("insert into service_requests (" + ",".join(r) + ") values (" + ",".join(["%s"] * len(r)) + ")", *r.values())
atexit.register(_restore)
setting("client_requests", "1"); setting("company_email", "service@examplemsp.example"); setting("onboarding_link_days", "30"); setting("psa_two_way", "1")
q("delete from service_requests where client_id=%s", CID)
admin = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")

def new_link():
    r = tech.post(B + f"/clients/{CID}/onboarding/send", data={"_csrf": csrf(tech, f"/clients/{CID}/onboarding"), "action": "link", "subject": "Welcome",
                                                              "body": "<p>{{onboarding_link}}</p>", "onsite_week": ""})
    return local(H.unescape(re.search(r'id="onb-link" readonly value="([^"]+)"', r.text).group(1)))
def status(s, action): return s.post(B + f"/clients/{CID}/onboarding/status", data={"_csrf": csrf(s, f"/clients/{CID}/onboarding"), "action": action})
def onb(): return (q("select * from client_onboardings where client_id=%s", CID) or [None])[0]
def audits(action, like="%"): return q("select count(*) n from audit_log where action=%s and detail like %s", action, like)[0]["n"]

# ---- staff: onboarding status actions
q("delete from client_onboardings where client_id=%s", CID)
new_link()
word = "zzonbq%d" % int(time.time())
r = status(tech, word + "<b>")
ok(audits("onboarding." + word + "b") == 0 and audits("onboarding." + word) == 0 and "isn't something you can do" in flash(r.text),
   "an unknown status action is refused and not written to the audit log as onboarding.<word>")
q("update client_onboardings set completed_at=now(), completed_by='Jordan Ellis' where client_id=%s", CID)
r = status(tech, "complete")
ok(onb()["completed_by"] == "Jordan Ellis" and "already complete" in flash(r.text), "marking a finished onboarding complete again keeps who finished it")
r = status(tech, "reopen"); ok(onb()["completed_at"] is None and "reopened" in flash(r.text), "reopen still works")
# 2.4.1 Remove onboarding: techs until the client opens it, admins any time; the button follows the same rule
ok('value="delete"' in tech.get(B + f"/clients/{CID}/onboarding").text, "a tech sees Remove onboarding while the client hasn't opened it")
q("update client_onboardings set opened_at=now() where client_id=%s", CID)
ok('value="delete"' not in tech.get(B + f"/clients/{CID}/onboarding").text and 'value="delete"' in admin.get(B + f"/clients/{CID}/onboarding").text,
   "once opened, only admins see it")
r = status(tech, "delete")
ok(onb() is not None and "only an admin" in flash(r.text), "a tech can't remove one the client has opened (and what the client entered)")
r = status(admin, "delete"); ok(onb() is None and "removed" in flash(r.text), "an admin can")
new_link()
ok(onb() is not None and onb()["opened_at"] is None, "a new link starts a fresh onboarding")
r = status(tech, "delete"); ok(onb() is None and "removed" in flash(r.text) and audits("onboarding.delete") >= 2, "a tech can remove one started by mistake (not opened yet), audited")
ok("/clients/%d/onboarding" % CID not in tech.get(B + f"/clients/{CID}").text.split("THEIR IT")[0], "the client menu drops the Onboarding item")

# ---- staff: the onboarding page is audited like the other client pages
n0 = audits("view.onboarding", f"#{CID} %")
v2 = login("viewer@example.com", "ViewerPassword123!")
ok(v2.get(B + f"/clients/{CID}/onboarding").status_code == 200 and audits("view.onboarding", f"#{CID} %") == n0 + 1, "viewing a client's onboarding page is in the audit log")

# ---- the public page: finish and review
link = new_link()
c = requests.Session()
cs = lambda: csrf(c, link.replace(B, ""))
r = c.post(link + "/finish", data={"_csrf": cs(), "your_name": "Early Bird"})
ok(onb()["completed_at"] is None and "finish the steps" in flash(r.text), "finishing before the steps are done is refused (the page only offers it after)")
c.post(link + "/review", data={"_csrf": cs(), "ack": "1", "ack_name": "First Person"})
r = c.post(link + "/review", data={"_csrf": cs(), "ack": "1", "ack_name": "Second Person"})
ok(onb()["reviewed_by"] == "First Person" and "already confirmed by First Person" in flash(r.text), "the review acknowledgement is recorded once; a later post doesn't replace who confirmed it")

# ---- the public page: requests
def req_count(): return q("select count(*) n from service_requests where client_id=%s", CID)[0]["n"]
base = {"last_name": "Lee", "job_title": "Assistant", "supervisor": "Pat Rivera", "by_name": "Jordan Ellis"}
n0 = req_count()
r = c.post(link + "/request/new_user", data={"_csrf": cs(), **base, "first_name": "Sam", "start_date": "2026-02-31"})
ok(req_count() == n0 and "Start date isn't a valid date" in flash(r.text), "a date that doesn't exist (February 31) is refused, not sent with the date left blank")
r = c.post(link + "/request/new_user", data={"_csrf": cs(), **base, "first_name": "Sam\r\nX-Injected: 1", "start_date": "2026-10-20"})
t = q("select title from service_requests where client_id=%s order by id desc limit 1", CID)
ok(req_count() == n0 + 1 and t and "\n" not in t[0]["title"] and "\r" not in t[0]["title"] and "X-Injected" in t[0]["title"],
   "control characters in a one-line field don't reach the ticket title: " + repr(t[0]["title"] if t else None))
mock("/mock/ticket-create-fail", {"on": True})
r = c.post(link + "/request/termination", data={"_csrf": cs(), "employee": "Old Tech", "disable_at": "2026-10-01T17:00", "by_name": "Jordan Ellis", "by_email": "jordan@example.test"})
m = q("select body_html from mail_queue where kind='service_request' order by id desc limit 1")
ok(m and "not verified" in (m[0]["body_html"] or ""), "a request emailed to the service address (PSA down) says the requester's name and email were typed, not verified")
mock("/mock/ticket-create-fail", {"on": False})

# ---- the public page: contacts
DUP = "pat.dup@cedarridgedental.example"
q("delete from contacts where client_id=%s and email=%s", CID, DUP)
q("insert into contacts (client_id, source, psa_id, name, title, email) values (%s, 'psa', 'onbq-990001', 'Pat Dup', 'Old title', %s)", CID, DUP)
setting("psa_two_way", "0")
r = c.post(link + "/contacts", data={"_csrf": cs(), "your_name": "Jordan", "contacts_json": json.dumps([{"id": "", "first": "Pat", "last": "Dup", "email": DUP.upper(), "title": "New title"}])})
k = q("select title, align_notes from contacts where client_id=%s and email=%s", CID, DUP)[0]
ok(k["title"] == "Old title" and "New title" in (k["align_notes"] or ""),
   "a new row with a PSA contact's email follows the PSA rules (noted for staff), not written over the PSA's details")
setting("psa_two_way", "1")
q("delete from contacts where client_id=%s and email=%s", CID, DUP)

with db.cursor() as cur:
    cur.executemany("insert into contacts (client_id, source, name, align_notes, archived_at) values (%s, 'manual', %s, %s, now())",
                    [(CID, f"Onbq cap {i}", "Added during onboarding by onbq on today.") for i in range(500)])
r = c.post(link + "/contacts", data={"_csrf": cs(), "your_name": "Jordan", "contacts_json": json.dumps([{"id": "", "first": "Over", "last": "Limit", "email": ""}])})
ok(not q("select id from contacts where client_id=%s and name='Over Limit'", CID) and "wasn't added" in flash(r.text),
   "the onboarding page can add at most 500 people a day to a client")
q("delete from contacts where client_id=%s and (align_notes like 'Added during onboarding by onbq%%' or name='Over Limit')", CID)

# ---- the public page: a guide PDF's file name goes through content_filename
r = admin.post(B + "/settings/onboarding/templates", data={"_csrf": csrf(admin, "/settings/onboarding"), "title": "Onbq guide"})
gid = int(r.url.rsplit("/", 1)[1])
admin.post(B + f"/settings/onboarding/templates/{gid}", data={"_csrf": csrf(admin, f"/settings/onboarding/templates/{gid}"), "title": "Onbq guide", "body": "<p>Read me</p>",
                                                             "is_active": "1", "sort": "90", "action": "save"},
           files={"file": ("Onbq Guide.pdf", b"%PDF-1.4\n%%EOF\n", "application/pdf")})
g = c.get(link + f"/guide/{gid}")
cd = g.headers.get("Content-Disposition", "")
ok(g.status_code == 200 and "filename*=UTF-8''Onbq%20Guide.pdf" in cd and g.headers.get("X-Content-Type-Options") == "nosniff",
   "the guide PDF's name is sent with content_filename (RFC 6266): " + cd)
admin.post(B + f"/settings/onboarding/templates/{gid}", data={"_csrf": csrf(admin, f"/settings/onboarding/templates/{gid}"), "action": "delete"})
q("delete from client_onboardings where client_id=%s", CID)

# ---- contracts: a contract whose client was deleted
r = tech.post(B + "/contracts/upload", data={"_csrf": csrf(tech, "/contracts"), "lead_company": "Gone Example Co", "title": "Onbq old MSA", "signed_on": "2025-06-01"},
              files={"file": ("old.pdf", b"%PDF-1.4\n%%EOF\n", "application/pdf")})
kid = int(re.search(r"/contracts/(\d+)$", r.url).group(1))
q("insert into contract_events (contract_id, event, detail) values (%s, 'client_deleted', 'Client deleted: Gone Example Co')", kid)
other = q("select id from clients where is_archived=0 order by id limit 1")[0]["id"]
ok(f'action="/contracts/{kid}/client"' not in tech.get(B + f"/contracts/{kid}").text, "a tech isn't offered Add as a client / Link for a contract whose client was deleted")
r = tech.post(B + f"/contracts/{kid}/client", data={"_csrf": csrf(tech, f"/contracts/{kid}"), "client_id": str(other)})
ok(q("select client_id from contracts where id=%s", kid)[0]["client_id"] is None and "Only an admin" in flash(r.text),
   "a tech can't link a deleted client's signed contract to another client")
t = admin.get(B + f"/contracts/{kid}").text
num = "C-%04d" % kid
ok(f'pattern="{num}"' in t and "\\-" not in re.search(r'id="del-confirm"[^>]*>', t).group(0), "the typed confirmation's pattern is valid in a browser (no \\- escape)")
r = admin.post(B + f"/contracts/{kid}/client", data={"_csrf": csrf(admin, f"/contracts/{kid}"), "client_id": str(other)})
ok(q("select client_id from contracts where id=%s", kid)[0]["client_id"] == other, "an admin can (to put back a client deleted by mistake)")
admin.post(B + f"/contracts/{kid}/delete", data={"_csrf": csrf(admin, f"/contracts/{kid}"), "confirm": num})
ok(not q("select id from contracts where id=%s", kid), "cleaned up")

# ---- contracts: the draft PDF is audited
r = admin.post(B + "/contracts/templates", data={"_csrf": csrf(admin, "/contracts/templates"), "start": "blank", "name": "Onbq template"})
tpl = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
r = tech.post(B + "/contracts", data={"_csrf": csrf(tech, "/contracts"), "template_id": str(tpl), "for": "lead", "lead_company": "Draft Example Co"})
did = int(re.search(r"/contracts/(\d+)$", r.url).group(1))
n0 = audits("view.contract.pdf", "%(draft copy)")
p = tech.get(B + f"/contracts/{did}/pdf")
ok(p.status_code == 200 and p.content[:5] == b"%PDF-" and audits("view.contract.pdf", "%(draft copy)") == n0 + 1, "opening a draft's PDF is in the audit log")
tech.post(B + f"/contracts/{did}/delete", data={"_csrf": csrf(tech, f"/contracts/{did}")})
admin.post(B + f"/contracts/templates/{tpl}/delete", data={"_csrf": csrf(admin, f"/contracts/templates/{tpl}")})
ok(not q("select id from contracts where id=%s", did) and not q("select id from contract_templates where id=%s", tpl), "cleaned up (an unused template is deleted, not hidden)")

done()
