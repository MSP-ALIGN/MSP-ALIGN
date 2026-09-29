"""1.39 client portal update: clients suggest licenses and budget items, staff review them first (add, edited
if needed, or decline with a note), the client sees them as waiting; the new portal layout."""
import atexit, html as H
from lib import *

SEC = "JBSWY3DPEHPK3PXP"
q("delete from portal_submissions"); q("delete from portal_users where email like '%%@suggest.example'")
q("delete from licenses where name like 'SG %%'"); q("delete from budget_lines where name like 'SG %%'"); q("delete from mail_queue")
q("delete from settings where name='portal_submissions'"); setting("notif_client_submission_decided", "1")
setting("notif_portal_activity", "1"); q("delete from user_notification_prefs"); q("update users set notify_scope=NULL")  # earlier suites change who gets what
atexit.register(lambda: (q("delete from portal_users where email like '%%@suggest.example'"), q("delete from settings where name='portal_submissions'"),
    q("delete from licenses where name like 'SG %%'"), q("delete from budget_lines where name like 'SG %%'"), q("delete from portal_submissions")))

def mkuser(email, client=1, **perms):
    p = {"can_roadmap": 1, "can_budget": 1, "can_devices": 1, "can_documents": 1, "can_approve": 0, "can_contacts": 1, "can_submit": 1, **perms}
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{client},"email"=>"{email}","name"=>"Pat Portal","password_hash"=>password_hash("Suggest-Pass-123", PASSWORD_DEFAULT),"is_active"=>1,'
        + ",".join(f'"{k}"=>{v}' for k, v in p.items()) + f',"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    s = requests.Session()
    s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": "Suggest-Pass-123"})
    r = s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(SEC)})
    return s

# ---- staff permission
staff = login("admin@example.com", "LongPassword123!")
tok = csrf(staff, "/clients/1/portal")
staff.post(B + "/clients/1/portal", data={"_csrf": tok, "name": "Sam Suggest", "email": "sam@suggest.example", "can_budget": "1", "can_submit": "1"})
staff.post(B + "/clients/1/portal", data={"_csrf": csrf(staff, "/clients/1/portal"), "name": "Nia NoBudget", "email": "nia@suggest.example", "can_documents": "1", "can_submit": "1"})
u = {r["email"]: r for r in q("select email, can_submit, can_budget from portal_users where email in ('sam@suggest.example','nia@suggest.example')")}
ok(u["sam@suggest.example"]["can_submit"] == 1 and u["nia@suggest.example"]["can_submit"] == 0, "suggest permission saved, dropped without Budget & licensing")
t = staff.get(B + "/clients/1/portal").text; ok("Suggest licenses and budget items" in t and "Send new user and termination requests" in t and not errs(t), "permission shown on the portal users page")

# ---- client suggests
pu = mkuser("pat@suggest.example")
t = pu.get(B + "/portal/licensing").text
ok("Suggest a license" in t and 'id="modal-suggest"' in t and not errs(t), "licensing page offers Suggest a license")
ok('class="portal-sections' in t and 'aria-current="page"' in t, "new section bar with the current page marked")
r = pu.post(B + "/portal/suggest/license", data={"_csrf": csrf(pu, "/portal/licensing"), "name": "", "unit_price": "abc"})
ok("Enter the product name" in flash(r.text) and "must be a number" in flash(r.text) and not q("select id from portal_submissions"), "form checked: " + flash(r.text)[:80])
r = pu.post(B + "/portal/suggest/license", data={"_csrf": csrf(pu, "/portal/licensing"), "name": "SG QuickBooks <b>Online</b>", "vendor": "Intuit", "seats": "3", "unit_price": "90", "pricing": "flat",
    "billing_cycle": "monthly", "category": "lob", "license_type": "user", "expire_date": "2027-01-31", "notes": "Front office billing", "client_id": "2"})
s1 = q("select * from portal_submissions where title like 'SG QuickBooks%%'")
ok("Sent" in flash(r.text) and s1 and s1[0]["status"] == "pending" and s1[0]["client_id"] == 1 and s1[0]["kind"] == "license", "suggestion saved as waiting, for the user's own client only")
t = pu.get(B + "/portal/licensing").text
ok("Waiting for review" in t and "SG QuickBooks &lt;b&gt;Online&lt;/b&gt;" in t and "<b>Online</b>" not in t, "client sees it as waiting (text escaped)")
ok(not q("select id from licenses where name like 'SG %%'"), "nothing added to the licensing yet")
r = pu.post(B + "/portal/suggest/budget", data={"_csrf": csrf(pu, "/portal/budget"), "name": "SG Backup internet", "amount": "59.99", "frequency": "monthly", "category": "managed", "vendor": "Verizon"})
s2 = q("select * from portal_submissions where title='SG Backup internet'")[0]
ok(json.loads(s2["data"])["category"] == "other" and json.loads(s2["data"])["amount"] == 59.99, "budget item saved; a category clients can't pick falls back to Other")
r = pu.post(B + "/portal/suggest/budget", data={"_csrf": csrf(pu, "/portal/budget"), "name": "SG No amount", "amount": ""}); ok("Enter the amount" in flash(r.text), "budget item needs an amount")
r = pu.post(B + "/portal/suggest/nothing", data={"_csrf": csrf(pu, "/portal/budget"), "name": "x"}); ok(r.status_code == 403, "unknown kind refused")
r = pu.post(B + "/portal/suggest/license", data={"_csrf": csrf(pu, "/portal/licensing"), "name[]": "x", "category[]": "lob"})
ok(r.status_code == 200 and "Enter the product name" in flash(r.text), "fields sent as lists are treated as empty (no error page)")
r = pu.post(B + "/portal/suggest/license", data={"_csrf": csrf(pu, "/portal/licensing"), "name": "SG Bad date", "expire_date": "2026-02-31"})
bd = q("select data from portal_submissions where title='SG Bad date'"); ok(bd and json.loads(bd[0]["data"])["expire_date"] is None, "an impossible date is dropped")
q("delete from portal_submissions where title='SG Bad date'")
t = pu.get(B + "/portal").text; ok(("Suggestions sent" in t or "Proposed projects" in t) and "Your IT team" in t and "/portal/licensing#suggest" in t and not errs(t), "home shows waiting suggestions and the IT team")
ok(q("select count(*) n from mail_queue where kind='portal_activity' and subject like '%%suggested a license: SG QuickBooks%%'")[0]["n"] == 1, "staff notified")

# ---- staff review
t = H.unescape(staff.get(B + "/clients/1/licenses").text)
ok("Suggested by the client" in t and "SG QuickBooks <b>Online</b>" in t and "Front office billing" in t and 'id="modal-suggestion-' in t and not errs(t), "staff license page lists it with a filled-in Add form")
m = re.search(r'id="modal-suggestion-%d".*?</form>' % s1[0]["id"], staff.get(B + "/clients/1/licenses").text, re.S).group(0)
ok('name="submission_id" value="%d"' % s1[0]["id"] in m and 'value="Intuit"' in m and 'value="90' in m and "Suggested by Pat Portal" in m, "Add form filled in from the suggestion")
t = staff.get(B + "/").text; ok("suggested license to review" in t and "suggested budget item to review" in t, "dashboard: waiting suggestions")
# someone else's client can't take it
r = staff.post(B + "/clients/2/licenses", data={"_csrf": csrf(staff, "/clients/2/licenses"), "name": "SG Wrong client", "submission_id": str(s1[0]["id"]), "back": "/clients/2/licenses"})
ok("already reviewed" in flash(r.text) and not q("select id from licenses where name='SG Wrong client'"), "a suggestion can only be added to its own client")
# accept, edited
r = staff.post(B + "/clients/1/licenses", data={"_csrf": csrf(staff, "/clients/1/licenses"), "name": "SG QuickBooks Online Plus", "vendor": "Intuit", "category": "lob", "license_type": "user", "seats": "3",
    "pricing": "flat", "unit_price": "99", "billing_cycle": "monthly", "submission_id": str(s1[0]["id"]), "back": "/clients/1/licenses"})
lic = q("select * from licenses where name='SG QuickBooks Online Plus'")
sub = q("select * from portal_submissions where id=%s", s1[0]["id"])[0]
ok("client sees it as added" in flash(r.text) and lic and float(lic[0]["unit_price"]) == 99 and sub["status"] == "accepted" and sub["item_id"] == lic[0]["id"], "added as edited, linked to the new license")
r = staff.post(B + "/clients/1/budget", data={"_csrf": csrf(staff, "/clients/1/budget"), "name": "SG Wrong kind", "amount": "1", "submission_id": str(s1[0]["id"]), "back": "/clients/1/budget"})
ok("already reviewed" in flash(r.text) and not q("select id from budget_lines where name='SG Wrong kind'"), "a license suggestion can't be added as a budget line")
r = staff.post(B + "/clients/1/licenses", data={"_csrf": csrf(staff, "/clients/1/licenses"), "name": "SG Twice", "submission_id": str(s1[0]["id"]), "back": "/clients/1/licenses"})
ok("already reviewed" in flash(r.text) and not q("select id from licenses where name='SG Twice'"), "can't be added twice (nothing saved)")
mq = q("select * from mail_queue where kind='client_submission_decided'")
ok(len(mq) == 1 and "pat@suggest.example" in mq[0]["recipients"] and mq[0]["subject"].startswith("Added:"), "client told it was added")
t = pu.get(B + "/portal/licensing").text; ok("SG QuickBooks Online Plus" in t and ">Added<" in t, "client sees it added, in the licensing list")
# decline the budget item with a note
tech = login("tech@example.com", TECH_PASSWORD); viewer = login("viewer@example.com", "ViewerPassword123!")
r = viewer.post(B + f"/clients/1/suggestions/{s2['id']}/decline", data={"_csrf": csrf(viewer, "/clients/1/budget"), "note": "x"}); ok(r.status_code == 403, "viewers can't decline")
r = tech.post(B + f"/clients/2/suggestions/{s2['id']}/decline", data={"_csrf": csrf(tech, "/clients/2/budget"), "note": "wrong client"})
ok(q("select status from portal_submissions where id=%s", s2["id"])[0]["status"] == "pending", "can't be declined through another client")
r = tech.post(B + f"/clients/1/suggestions/{s2['id']}/decline", data={"_csrf": csrf(tech, "/clients/1/budget"), "note": "Already in the phone line bundle <i>", "back": "/clients/1/budget"})
ok("Declined" in flash(r.text) and q("select status from portal_submissions where id=%s", s2["id"])[0]["status"] == "declined" and not q("select id from budget_lines where name='SG Backup internet'"), "declined, nothing added")
t = H.unescape(pu.get(B + "/portal/budget").text); ok("Declined" in t and "Already in the phone line bundle <i>" in t, "client sees the note")
ok(q("select count(*) n from mail_queue where kind='client_submission_decided' and subject like 'Reviewed:%%'")[0]["n"] == 1, "client emailed about the decision")
r = tech.post(B + f"/clients/1/suggestions/{s2['id']}/decline", data={"_csrf": csrf(tech, "/clients/1/budget")}); ok("already reviewed" in flash(r.text), "can't decline twice")

# ---- budget item accepted through the budget form
pu.post(B + "/portal/suggest/budget", data={"_csrf": csrf(pu, "/portal/budget"), "name": "SG Hosted phones", "amount": "240", "frequency": "monthly", "category": "telecom", "start_date": "2026-11-01"})
s3 = q("select * from portal_submissions where title='SG Hosted phones'")[0]
t = staff.get(B + "/clients/1/budget").text; ok('id="modal-suggestion-%d"' % s3["id"] in t and "Add the suggested budget item" in t, "budget page offers it too")
r = staff.post(B + "/clients/1/budget", data={"_csrf": csrf(staff, "/clients/1/budget"), "name": "SG Hosted phones", "category": "telecom", "amount": "240", "frequency": "monthly", "start_date": "2026-11-01", "submission_id": str(s3["id"]), "back": "/clients/1/budget"})
bl = q("select * from budget_lines where name='SG Hosted phones'"); ok(bl and q("select item_id from portal_submissions where id=%s", s3["id"])[0]["item_id"] == bl[0]["id"], "budget line added from the suggestion")

# ---- withdraw, other clients, limits, switch
pu.post(B + "/portal/suggest/license", data={"_csrf": csrf(pu, "/portal/licensing"), "name": "SG Changed my mind"})
s4 = q("select id from portal_submissions where title='SG Changed my mind'")[0]["id"]
other = mkuser("olly@suggest.example", client=2)
r = other.post(B + f"/portal/suggestions/{s4}/withdraw", data={"_csrf": csrf(other, "/portal/licensing")})
ok(q("select status from portal_submissions where id=%s", s4)[0]["status"] == "pending", "another client can't withdraw it")
ok("SG Changed my mind" not in other.get(B + "/portal/licensing").text, "another client doesn't see it")
colleague = mkuser("colleague@suggest.example")
ok("SG Changed my mind" in colleague.get(B + "/portal/licensing").text and 'suggestions/%d/withdraw' % s4 not in colleague.get(B + "/portal/licensing").text, "a colleague sees it, without Withdraw")
colleague.post(B + f"/portal/suggestions/{s4}/withdraw", data={"_csrf": csrf(colleague, "/portal/licensing")})
ok(q("select status from portal_submissions where id=%s", s4)[0]["status"] == "pending", "and can't withdraw someone else's")
staff.post(B + "/portal-users/settings", data={"_csrf": csrf(staff, "/portal-users")})
r = pu.post(B + f"/portal/suggestions/{s4}/withdraw", data={"_csrf": csrf(pu, "/portal/licensing")})
staff.post(B + "/portal-users/settings", data={"_csrf": csrf(staff, "/portal-users"), "portal_submissions": "1"})
ok("Withdrew" in flash(r.text) and q("select status from portal_submissions where id=%s", s4)[0]["status"] == "withdrawn", "client withdraws a waiting suggestion")
nosub = mkuser("nosub@suggest.example", can_submit=0)
ok("Suggest a license" not in nosub.get(B + "/portal/licensing").text, "no button without the permission")
r = nosub.post(B + "/portal/suggest/license", data={"_csrf": csrf(nosub, "/portal/licensing"), "name": "SG Sneaky"}); ok(r.status_code == 403 and not q("select id from portal_submissions where title='SG Sneaky'"), "and the form is refused")
staff.post(B + "/portal-users/settings", data={"_csrf": csrf(staff, "/portal-users")})
ok(q("select value from settings where name='portal_submissions'")[0]["value"] == "0", "admin switches suggestions off")
r = pu.post(B + "/portal/suggest/license", data={"_csrf": csrf(pu, "/portal/licensing"), "name": "SG While off"}); ok(r.status_code == 403 and "Suggest a license" not in pu.get(B + "/portal/licensing").text, "off: no button, form refused")
r = tech.post(B + "/portal-users/settings", data={"_csrf": csrf(tech, "/portal-users"), "portal_submissions": "1"}); ok(r.status_code == 403, "only admins change the switch")
staff.post(B + "/portal-users/settings", data={"_csrf": csrf(staff, "/portal-users"), "portal_submissions": "1"})
q("update portal_submissions set created_at = now() where portal_user_id = (select id from portal_users where email='pat@suggest.example')")
for i in range(20):
    pu.post(B + "/portal/suggest/license", data={"_csrf": csrf(pu, "/portal/licensing"), "name": f"SG Burst {i}"})
ok(q("select count(*) n from portal_submissions where portal_user_id = (select id from portal_users where email='pat@suggest.example') and created_at > now() - interval 1 hour")[0]["n"] == 20, "at most 20 suggestions an hour per user")

t = staff.get(B + "/").text; ok("suggested budget item to review" not in t, "dashboard item gone once reviewed")
q("update clients set planning_excluded=1 where id=2"); other.post(B + "/portal/suggest/license", data={"_csrf": csrf(other, "/portal/licensing"), "name": "SG Outside planning"})
t = staff.get(B + "/").text; q("update clients set planning_excluded=0 where id=2")
ok("suggested license to review" in t and "Northfield" in t, "clients outside planning still show on the dashboard")
t = staff.get(B + "/audit").text; ok("portal.submission" in t and "portal.submission_accepted" in t and "portal.submission_declined" in t and not errs(t), "suggestions, additions and declines are in the audit log")

# ---- the rest of the portal in the new layout
for p in ["/portal", "/portal/roadmap", "/portal/budget", "/portal/licensing", "/portal/devices", "/portal/compliance", "/portal/documents", "/portal/contacts", "/portal/meetings", "/portal/requests", "/portal/account", "/portal/terms"]:
    r = pu.get(B + p); ok(r.status_code == 200 and not errs(r.text) and 'class="portal-sections' in r.text, p + " renders in the new layout")
t = pu.get(B + "/portal/contacts").text; ok("Add contact" not in t and "New user or termination request" in t, "contacts view-only, with the request link")
done()
