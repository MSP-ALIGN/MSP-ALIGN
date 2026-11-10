"""2.35.0 ITFlow's tickets, quotes and shared documents in the client portal. Tickets: a portal user sees their own
(the ticket's contact has their email, or they opened it here) and, with "All company tickets", every ticket of the
company; replies the client may see (never internal notes), cleaned; opening a ticket under their ITFlow contact, with a
limit; Reply / View & accept go to ITFlow's own guest page by a checked redirect, so its key is never in a page.
Quotes: sent ones only (no drafts, archived or other clients'). Documents: the ones ITFlow shares with the client, read
live and cleaned. Everything off until staff turn it on per user; another client's ids give 404."""
import atexit
import json
import os
from lib import *

STATE = "/tmp/itflow-mock-state.json"
PW = "Quartz-Lantern-Field-31"
SEC = {"sam": "ONSWG4TFORZWK3DPONSWG4TFORZWK3DP", "jordan": "OBQXE5DBNRSGK4TPOBQXE5DBNRSGK4TP", "pat": "IFBEGRCFIZDUQSKKIFBEGRCFIZDUQSKK"}


def state():
    return json.load(open(STATE)) if os.path.exists(STATE) else {}


def put(**kw):
    st = state()
    for k, v in kw.items():
        if v is None:
            st.pop(k, None)
        else:
            st[k] = v
    json.dump(st, open(STATE, "w"))


def cleanup():
    q("delete from settings where name in ('itflow_billing_url', 'psa_portal')")
    q("delete from portal_users where email in ('sam@cedarridgedental.example', 'jordan@cedarridgedental.example', 'pat@northfieldhardware.example', 'nomatch@portalpsa.example')")
    q("delete from login_attempts")
    put(tickets_created_readable=None, replies_404=None, ticket_create_fail=None)


cleanup()
atexit.register(cleanup)


def mkuser(email, secret, client=1, **perms):
    p = {"can_roadmap": 1, "can_documents": 1, **perms}
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{client},"email"=>"{email}","name"=>"{email.split("@")[0].title()} Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        + ",".join(f'"{k}"=>{v}' for k, v in p.items()) + f',"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{secret}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    return q("select id from portal_users where email=%s", email)[0]["id"]


def plogin(email, secret):
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(secret)})
    return s


cid1 = q("select id from clients where psa_id='1'")[0]["id"]
cid2 = q("select id from clients where psa_id='2'")[0]["id"]
sam_id = mkuser("sam@cedarridgedental.example", SEC["sam"], cid1, can_tickets=1, can_quotes=1, can_psa_docs=1, can_pay_bills=1)
jordan_id = mkuser("jordan@cedarridgedental.example", SEC["jordan"], cid1, can_tickets=1, can_all_tickets=1)
mkuser("nomatch@portalpsa.example", SEC["pat"], cid1)  # no Tickets or Quotes: the defaults
mkuser("pat@northfieldhardware.example", SEC["pat"], cid2, can_tickets=1, can_quotes=1, can_psa_docs=1)
ok(q("select can_tickets, can_quotes, can_psa_docs, can_all_tickets from portal_users where email='nomatch@portalpsa.example'")[0] == {"can_tickets": 0, "can_quotes": 0, "can_psa_docs": 0, "can_all_tickets": 0},
   "the new permissions are off unless staff turn them on")

summary = sync_now()
step = [v for k, v in summary.items() if "quotes & shared documents" in k]
ok(step and "3 quotes" in step[0] and "2 shared documents" in step[0], "the hourly sync reads quotes and shared documents: %s" % step)
ok({r["number"] for r in q("select number from psa_quotes where client_id=%s", cid1)} == {"QT-71", "QT-73"}, "sent quotes only: no draft, no archived one")
ok({r["name"] for r in q("select name from psa_shared_docs where client_id=%s", cid1)} == {"Wi-Fi guide for staff"}, "shared documents only: not hidden ones or templates")
ok(not q("select 1 from psa_quotes where scope like 'Draft%%' or scope like 'Archived%%'"), "no draft or archived quote stored, for anyone")
backup = q("select id, contact_psa_id from psa_tickets where subject='Server backup failing' and client_id=%s", cid1)[0]
imaging = q("select id, contact_psa_id from psa_tickets where subject='Imaging software crashes' and client_id=%s", cid1)[0]
other = q("select id from psa_tickets where client_id=%s and closed_at is null order by id desc limit 1", cid2)
ok(backup["contact_psa_id"] == "6" and imaging["contact_psa_id"] == "2", "tickets carry their ITFlow contact")

sam = plogin("sam@cedarridgedental.example", SEC["sam"])
jordan = plogin("jordan@cedarridgedental.example", SEC["jordan"])
nob = plogin("nomatch@portalpsa.example", SEC["pat"])
pat = plogin("pat@northfieldhardware.example", SEC["pat"])

# ---- navigation and permissions
home = sam.get(B + "/portal").text
ok('href="/portal/tickets"' in home and not errs(home), "the portal has a Tickets tab for a user with Tickets")
h = nob.get(B + "/portal").text
ok('href="/portal/tickets"' not in h and 'href="/portal/quotes"' not in h, "...and none without it")
ok(nob.get(B + "/portal/tickets").status_code == 403 and nob.get(B + "/portal/quotes").status_code == 403, "the pages refuse a user without the permission")
ok(jordan.get(B + "/portal/quotes").status_code == 403, "Quotes is its own permission")

# ---- tickets: own vs all
t = sam.get(B + "/portal/tickets").text
ok("Server backup failing" in t and "Imaging software crashes" not in t and "Email bouncing" not in t and not errs(t), "Sam sees his own ticket only")
t = jordan.get(B + "/portal/tickets").text
ok("Server backup failing" in t and "Imaging software crashes" in t and "Email bouncing" in t, "with All company tickets, Jordan sees every open ticket of the company")
ok(">yours<" in t.replace(" · yours", ">yours<"), "...and which are his")
t = jordan.get(B + "/portal/tickets?state=closed").text
ok("Resolved &amp; closed" in t and ("Closed" in t or "Resolved" in t) and not errs(t), "resolved and closed tickets are on their own tab")

r = sam.get(B + f"/portal/tickets/{imaging['id']}")
ok(r.status_code == 404, "Sam can't open someone else's ticket by its id")
if other:
    ok(jordan.get(B + f"/portal/tickets/{other[0]['id']}").status_code == 404, "nor can anyone open another client's ticket")
r = sam.get(B + f"/portal/tickets/{backup['id']}")
t = r.text
ok(r.status_code == 200 and "We are looking into it." in t and "it is still failing this morning" in t and not errs(t), "a ticket shows the replies sent to the client and the client's own")
ok("INTERNAL NOTE" not in t and "ARCHIVED REPLY" not in t, "internal notes and archived replies never show")
ok("<script>alert(1)" not in t and "onerror" not in t and 'href="javascript' not in t, "reply HTML is cleaned (no script, event handlers or script links)")
ok("Ticket details that Align never stores" in t, "the ticket's own details show (read live, not stored)")
ok("g-k_0000" not in t and "url_key" not in t, "the ITFlow guest key is not in the page")
ok(f'/portal/tickets/{backup["id"]}/reply' in t, "a Reply button")
r = sam.get(B + f"/portal/tickets/{backup['id']}/reply", allow_redirects=False)
loc = r.headers.get("Location", "")
ok(r.status_code == 303 and loc == M + f"/guest/guest_view_ticket.php?ticket_id={backup['id']}&url_key=g-k_" + str(backup["id"]).rjust(18, "0"), "Reply redirects to ITFlow's guest page for that ticket: " + loc)
ok(r.headers.get("Referrer-Policy") == "no-referrer", "...without sending a Referer")
ok(sam.get(B + f"/portal/tickets/{imaging['id']}/reply", allow_redirects=False).status_code == 404, "no redirect for a ticket that isn't yours")
ok(q("select 1 from audit_log where action='portal.ticket_reply_opened' and portal_user_id=%s", sam_id), "opening the reply page is audited")

put(replies_404=True)
t = sam.get(B + f"/portal/tickets/{backup['id']}").text
ok("Replies show in the emails" in t and not errs(t), "an ITFlow without the replies endpoint: the ticket still shows, with a note")
put(replies_404=None)

# ---- opening a ticket
put(tickets_created_readable=True)
before = len(state().get("tickets_created", []))
r = sam.post(B + "/portal/tickets", data={"_csrf": csrf(sam, "/portal/tickets"), "subject": "Front office printer jams <b>daily</b>",
                                          "details": "Every morning.\n<script>alert(1)</script>", "priority": "High"})
made = state().get("tickets_created", [])
new = made[-1] if len(made) > before else {}
ok(len(made) == before + 1 and new.get("client_id") == 1 and new.get("ticket_contact_id") == 6 and new.get("ticket_priority") == "High",
   "a ticket opened in the portal goes to ITFlow under the client and Sam's contact, at the priority chosen")
ok("&lt;script&gt;" in new.get("ticket_details", "") and "<script>" not in new.get("ticket_details", "") and "signed in" in new.get("ticket_details", ""),
   "its text is escaped and says who sent it")
ok(r.url.endswith(f"/portal/tickets/{new.get('ticket_id')}") and "sent to your IT team" in flash(r.text), "...and Sam lands on it: " + flash(r.text)[:80])
ok("Front office printer jams" in sam.get(B + "/portal/tickets").text, "it's in his list straight away")
ok(q("select 1 from portal_tickets_opened where portal_user_id=%s and psa_ticket_id=%s", sam_id, str(new.get("ticket_id"))), "and recorded as opened by him")
r = nob.post(B + "/portal/tickets", data={"_csrf": csrf(nob, "/portal"), "subject": "x", "details": "y"}, allow_redirects=False)
ok(r.status_code == 403, "a user without Tickets can't open one")
r = sam.post(B + "/portal/tickets", data={"_csrf": csrf(sam, "/portal/tickets"), "subject": "", "details": "no subject"})
ok("short subject" in flash(r.text) and "no subject" in r.text, "a ticket without a subject is refused, and the text typed is kept")
put(ticket_create_fail="1")
r = sam.post(B + "/portal/tickets", data={"_csrf": csrf(sam, "/portal/tickets"), "subject": "When ITFlow refuses", "details": "x"})
ok("couldn't be sent" in flash(r.text) and "Mock" not in r.text, "ITFlow refusing: a plain message, not ITFlow's error text")
put(ticket_create_fail=None)
for i in range(12):
    sam.post(B + "/portal/tickets", data={"_csrf": csrf(sam, "/portal/tickets"), "subject": f"Burst {i}", "details": "x"})
ok(q("select count(*) n from portal_tickets_opened where portal_user_id=%s", sam_id)[0]["n"] == 10, "at most 10 tickets an hour per person")
ok(q("select 1 from audit_log where action='portal.ticket_opened' and portal_user_id=%s", sam_id), "opening a ticket is audited")

# ---- quotes
t = sam.get(B + "/portal/quotes").text
ok("QT-71" in t and "Firewall replacement" in t and "QT-73" in t and "View &amp; accept" in t and not errs(t), "sent quotes are listed, the open one with View & accept")
ok("Draft laptops" not in t and "Archived quote" not in t and "Other client quote" not in t and "q-k_00" not in t, "no drafts, archived or other clients' quotes, and no guest key in the page")
ok("$2,450" in t, "with its amount")
r = sam.get(B + "/portal/quotes/71/open", allow_redirects=False)
ok(r.status_code == 303 and r.headers.get("Location") == M + "/guest/guest_view_quote.php?quote_id=71&url_key=q-k_0000000000000071", "View & accept redirects to ITFlow's guest page for the quote")
ok(sam.get(B + "/portal/quotes/72/open", allow_redirects=False).status_code == 404, "a draft can't be opened by its id")
ok(sam.get(B + "/portal/quotes/81/open", allow_redirects=False).status_code == 404, "nor another client's quote")
ok(pat.get(B + "/portal/quotes/71/open", allow_redirects=False).status_code == 404, "...from the other side either")
t = pat.get(B + "/portal/quotes").text
ok("QT-81" in t and "QT-71" not in t, "each client sees its own quotes")
ok(q("select 1 from audit_log where action='portal.quote_opened' and portal_user_id=%s", sam_id), "opening a quote is audited")

# ---- shared documents
t = sam.get(B + "/portal/documents").text
ok('data-cat="psa"' in t and "Wi-Fi guide for staff" in t and "Internal runbook" not in t and "A template" not in t and not errs(t), "Documents lists what ITFlow shares (2.36.0: its own tab, named for the MSP)")
ok('data-value="psa"' in t and "From Example MSP" in t and "From ITFlow" not in t, "...the tab names the MSP, not the PSA product")
r = sam.get(B + "/portal/shared/601")
ok(r.status_code == 200 and "Joining Wi-Fi" in r.text and "Cedar-Staff" in r.text and "<script>alert" not in r.text and "/uploads/documents/601" not in r.text,
   "a shared document is read live and cleaned (no script; ITFlow images left out)")
ok(sam.get(B + "/portal/shared/602").status_code == 404, "a document ITFlow doesn't share can't be opened by its id")
ok(sam.get(B + "/portal/shared/603").status_code == 404, "nor another client's")
ok(pat.get(B + "/portal/shared/601").status_code == 404, "...from the other side either")
t = jordan.get(B + "/portal/documents").text
ok('data-cat="psa"' not in t and jordan.get(B + "/portal/shared/601").status_code == 404, "ITFlow documents are their own permission: Documents access alone doesn't show them")

# ---- staff side: the permissions on the portal user form
a = login("admin@example.com", "LongPassword123!")
t = a.get(B + f"/clients/{cid1}/portal").text
ok('name="can_tickets"' in t and 'name="can_quotes"' in t and 'name="can_all_tickets"' in t and "All company tickets" in t and not errs(t), "staff turn the new permissions on per portal user")
r = a.post(B + f"/portal-users/{jordan_id}", data={"_csrf": csrf(a, f"/clients/{cid1}/portal"), "name": "Jordan Portal", "can_roadmap": "1", "can_all_tickets": "1"})
ok(q("select can_tickets, can_all_tickets from portal_users where id=%s", jordan_id)[0] == {"can_tickets": 0, "can_all_tickets": 0}, "All company tickets needs Tickets")
# ---- bill pay link (Integrations -> ITFlow; Pay bills permission)
PAY = "https://pay.example.com/cedar?account=42#top"
def save_itflow(**fields):
    d = form(a, "/integrations/itflow", 'action="/integrations/itflow"')
    d.update(fields)
    return a.post(B + "/integrations/itflow", data=d)
t = a.get(B + "/integrations/itflow").text
ok('name="itflow_billing_url"' in t and "Bill pay address" in t and not errs(t), "Integrations -> ITFlow has the bill pay address")
ok('Pay a bill' not in sam.get(B + "/portal").text, "no Pay a bill button until an address is set")
r = save_itflow(itflow_billing_url=PAY)
ok("saved" in flash(r.text).lower() and "Enter the" not in flash(r.text), "saved without typing the API key again: " + flash(r.text)[:80])
ok(q("select value from settings where name='itflow_billing_url'")[0]["value"] == PAY, "a ? and # are kept for a bill pay page")
for bad in ["http://pay.example.com", "javascript:alert(1)", "https://user:pw@pay.example.com", "ftp://pay.example.com", 'https://pay.example.com/a"b', "https://pay.example.com/<x>"]:
    r = save_itflow(itflow_billing_url=bad)
    ok(q("select value from settings where name='itflow_billing_url'")[0]["value"] == PAY, f"refused: {bad}")
save_itflow(itflow_billing_url="https://pay.example.com/p?return=/")
ok(q("select value from settings where name='itflow_billing_url'")[0]["value"] == "https://pay.example.com/p?return=/", "a / at the end of the query is kept")
save_itflow(itflow_billing_url=PAY)
home = sam.get(B + "/portal").text
ok(home.count('href="https://pay.example.com/cedar?account=42#top"') == 2 and 'rel="noopener noreferrer"' in home and "Pay a bill" in home,
   "with Pay bills, the button is in the top bar and on Home, opening the bill pay page")
ok('href="https://pay.example.com' in sam.get(B + "/portal/tickets").text, "the top bar button is on every page")
ok("pay.example.com" not in jordan.get(B + "/portal").text and "pay.example.com" not in nob.get(B + "/portal").text, "users without Pay bills don't see it")
ok("Open tickets" in home, "Home shows the open tickets count to users with Tickets")
save_itflow(itflow_billing_url="")
ok("pay.example.com" not in sam.get(B + "/portal").text, "emptied: no button")
# ---- the ITFlow card's "Client portal" switch: off keeps all of it out, whatever is ticked
t = a.get(B + "/integrations/itflow").text
ok('name="psa_portal"' in t and "Client portal: show ITFlow tickets, quotes and shared documents" in t, "the ITFlow card has the client portal switch (on by default)")
d = form(a, "/integrations/itflow", 'action="/integrations/itflow"'); d.pop("psa_portal", None)
r = a.post(B + "/integrations/itflow", data=d)
ok(q("select value from settings where name='psa_portal'")[0]["value"] == "0", "switched off: " + flash(r.text)[:60])
h = sam.get(B + "/portal").text
ok('href="/portal/tickets"' not in h and 'href="/portal/quotes"' not in h and "Open tickets" not in h, "off: no Tickets or Quotes in the portal")
ok(sam.get(B + "/portal/tickets").status_code == 404 and sam.get(B + "/portal/quotes").status_code == 404 and sam.get(B + "/portal/shared/601").status_code == 404
   and sam.get(B + f"/portal/tickets/{backup['id']}/reply", allow_redirects=False).status_code == 404, "off: their pages and redirects are gone")
ok('data-cat="psa"' not in sam.get(B + "/portal/documents").text, "off: no ITFlow documents")
t = a.get(B + f"/clients/{cid1}/portal").text
ok('name="can_tickets"' not in t and 'name="can_quotes"' not in t and 'name="can_psa_docs"' not in t, "off: the permissions aren't offered on the portal user form")
a.post(B + f"/portal-users/{sam_id}", data={"_csrf": csrf(a, f"/clients/{cid1}/portal"), "name": "Sam Portal", "can_roadmap": "1", "can_documents": "1"})
ok(q("select can_tickets, can_quotes, can_psa_docs from portal_users where id=%s", sam_id)[0] == {"can_tickets": 1, "can_quotes": 1, "can_psa_docs": 1},
   "...and saving a user meanwhile keeps what was ticked")
summary = sync_now()
ok(not q("select 1 from psa_quotes") and not q("select 1 from psa_shared_docs"), "off: the next sync clears the stored quotes and documents: %s" % [v for k, v in summary.items() if "client portal" in k])
d = form(a, "/integrations/itflow", 'action="/integrations/itflow"'); d["psa_portal"] = "1"
a.post(B + "/integrations/itflow", data=d)
sync_now()
ok('href="/portal/tickets"' in sam.get(B + "/portal").text and q("select 1 from psa_quotes where client_id=%s", cid1), "back on: everything returns as it was ticked")
done()
