"""2.2.2 "Ready to start": a project's one QUOTE- ticket in the PSA is made only when someone asks: Make the QUOTE- ticket
now (off by default) when the project is made, or Ready to start later. Approved and scheduled projects without a ticket go
on To do on the first day of their quarter (overdue ones stay), Not yet hides one for 1, 2, 3 or 6 months, and Ready to
start works from To do, the project window and the Projects page, for hand-made and device projects alike."""
from lib import *
import json, re, html as H

def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
def created(): return mock("/mock/tickets-created")["created"]
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def proj(title): return (q("select * from roadmap_items where title = %s order by id desc limit 1", title) or [None])[0]
def pval(code): return php(code).stdout.strip()

cur = pval('echo Align\\Roadmap\\Plan::quarters()[Align\\Roadmap\\Plan::currentIndex()]["start"];')
nxt = pval('$q = Align\\Roadmap\\Plan::quarters(); echo $q[Align\\Roadmap\\Plan::currentIndex() + 1]["start"];')
prev = pval('echo Align\\Roadmap\\Plan::quarterFor(date("Y-m-d", strtotime(Align\\Roadmap\\Plan::quarters()[Align\\Roadmap\\Plan::currentIndex()]["start"] . " -1 day")))["start"];')
today = pval('echo date("Y-m-d");')
ok(re.match(r"^\d{4}-\d{2}-01$", cur) and nxt > cur > prev, "quarters for the checks: %s < %s < %s" % (prev, cur, nxt))

# ---- schema and helpers
cols = {r["column_name"] for r in q("select column_name from information_schema.columns where table_schema = database() and table_name = 'roadmap_items'")}
ok({"ticket_at", "ticket_by", "ticket_claimed_at", "ticket_snooze_until"} <= cols, "migration 052 adds the ticket columns")
ok(pval('echo Align\\Roadmap\\ProjectTickets::addMonths("2027-01-31", 1), " ", Align\\Roadmap\\ProjectTickets::addMonths("2027-11-30", 3), " ", Align\\Roadmap\\ProjectTickets::addMonths("2027-05-15", 6);')
   == "2027-02-28 2028-02-29 2027-11-15", "Not yet dates keep the day, or the month's last day when it has fewer")
ok(pval('echo implode(",", array_map([Align\\Roadmap\\ProjectTickets::class, "priority"], ["critical","high","medium","low","x"]));') == "High,High,Medium,Low,Medium",
   "the project's priority becomes the ticket's (critical and high are High)")

q("delete from roadmap_items where title like 'RTS %%'")
q("delete from clients where name = 'RTS Manual Example'")
mock("/mock/ticket-create-fail", {"on": False})
st = login("admin@example.com", "LongPassword123!")
admin_id = q("select id from users where email = 'admin@example.com'")[0]["id"]
tok = lambda: csrf(st, "/clients/1/roadmap")

def add(title, status="approved", quarter=None, ticket=False, cid=1, extra=None):
    d = {"_csrf": tok(), "title": title, "category": "security", "priority": "high", "status": status, "target_quarter": quarter or "", "cost": "1200",
         "description": "Turn on MFA for every account.\nThen the remote access.", "back": f"/clients/{cid}/roadmap"}
    if ticket: d["ticket"] = "1"
    d.update(extra or {})
    return st.post(B + f"/clients/{cid}/roadmap", data=d)

# ---- making projects: no ticket unless ticked
n0 = len(created())
add("RTS now", quarter=cur)
add("RTS later", quarter=nxt)
add("RTS proposed", status="proposed", quarter=cur)
add("RTS unscheduled")
add("RTS overdue", status="scheduled", quarter=prev)
add("RTS declined", status="declined", quarter=cur)
ok(len(created()) == n0 and all(proj(t)["psa_ticket_id"] is None for t in ["RTS now", "RTS later", "RTS proposed"]), "adding projects makes no tickets by default")
r = add("RTS ticked <b>x</b>", quarter=nxt, ticket=True)
p = proj("RTS ticked <b>x</b>"); new = created()[n0:]
ok(p["psa_ticket_id"] and len(new) == 1 and new[0]["ticket_subject"] == "QUOTE- RTS ticked <b>x</b>" and "ticket #" in flash(r.text),
   "Make the QUOTE- ticket now makes it straight away: " + flash(r.text)[:120])
ok(str(p["ticket_by"]) == str(admin_id) and p["ticket_at"] is not None and p["ticket_claimed_at"] is None, "who made it and when are kept; the claim is cleared")
ok("&lt;b&gt;x&lt;/b&gt;" in new[0]["ticket_details"] and "<b>x</b>" not in new[0]["ticket_details"] and "Turn on MFA" in new[0]["ticket_details"]
   and not new[0].get("ticket_contact_id") and new[0].get("ticket_priority") == "High",
   "the ticket body is escaped, holds the description, has no client contact and the project's priority")

# ---- To do: only what's due
todo = st.get(B + "/todo").text
ids = {t: proj(t)["id"] for t in ["RTS now", "RTS later", "RTS proposed", "RTS unscheduled", "RTS overdue", "RTS declined"]}
on = {t for t, i in ids.items() if f'data-todo="project-{i}"' in todo}
ok(on == {"RTS now", "RTS overdue"}, "To do lists approved and scheduled projects whose quarter is here or past, nothing later, proposed, unscheduled or declined: " + str(sorted(on)))
ok("overdue" in text(todo.split(f'data-todo="project-{ids["RTS overdue"]}"')[1][:1500]), "an overdue one says so")
ok('data-todo-head="projects"' in todo and "Ready to start: all" in todo and 'id="modal-start-all"' in todo, "with two or more, Ready to start: all opens one window for them")
ok(f'data-lazy-modal="/projects/{ids["RTS now"]}/start' in todo and f'action="/projects/{ids["RTS now"]}/snooze"' in todo, "each has Ready to start and Not yet")
pt = st.get(B + "/todo?show=projects").text
ok('href="/todo?show=projects"' in todo and f'data-todo="project-{ids["RTS now"]}"' in pt and 'data-todo="unpriced"' not in pt, "a Projects tab shows just the projects")

# ---- the confirm window
f = st.get(B + f"/projects/{ids['RTS now']}/start?back=/todo")
ok(f.status_code == 200 and f'id="modal-start-{ids["RTS now"]}"' in f.text and "QUOTE- RTS now" in f.text and "Create the ticket" in f.text and "Turn on MFA" in text(f.text),
   "Ready to start shows the subject, the description and Create the ticket")
f = st.get(B + f"/projects/{ids['RTS later']}/start")
ok("isn't due on To do yet" in text(f.text) and "Create the ticket" in f.text, "a later project can still be started from its window, which says it isn't due yet")
f = st.get(B + f"/projects/{proj('RTS ticked <b>x</b>')['id']}/start")
ok("Create the ticket" not in f.text and "has its ticket already" in text(f.text), "a project with a ticket offers no second one")

# ---- Ready to start
n1 = len(created())
r = st.post(B + f"/projects/{ids['RTS now']}/start", data={"_csrf": tok(), "back": "/todo"})
p = proj("RTS now")
ok(p["psa_ticket_id"] and len(created()) == n1 + 1 and "ticket #" in flash(r.text) and r.url.endswith("/todo"), "Ready to start makes the ticket and goes back to To do: " + flash(r.text)[:120])
ok(f'data-todo="project-{ids["RTS now"]}"' not in st.get(B + "/todo").text, "and the project leaves To do")
ok(q("select 1 from audit_log where action = 'roadmap.quote_ticket' and detail like %s", "%RTS now%"), "it is audited")
r = st.post(B + f"/projects/{ids['RTS now']}/start", data={"_csrf": tok(), "back": "/todo"})
ok(len(created()) == n1 + 1 and "already has" in flash(r.text), "pressing it again makes no second ticket: " + flash(r.text)[:100])

# a claim in progress blocks a second request; a stale one (a request that died) doesn't
q("update roadmap_items set ticket_claimed_at = now() where id = %s", ids["RTS overdue"])
r = st.post(B + f"/projects/{ids['RTS overdue']}/start", data={"_csrf": tok()})
ok(proj("RTS overdue")["psa_ticket_id"] is None and "being made" in flash(r.text), "while another request is making the ticket, a second one is refused")
q("update roadmap_items set ticket_claimed_at = now() - interval 20 minute where id = %s", ids["RTS overdue"])
ok(pval(f'echo Align\\Roadmap\\ProjectTickets::state(Align\\DB::one("SELECT * FROM roadmap_items WHERE id = {ids["RTS overdue"]}"))["key"];') == "due", "a claim older than 10 minutes no longer counts")

# PSA refuses: the claim is released and it stays on To do
mock("/mock/ticket-create-fail", {"on": True})
r = st.post(B + f"/projects/{ids['RTS overdue']}/start", data={"_csrf": tok(), "back": "/todo"})
p = proj("RTS overdue")
mock("/mock/ticket-create-fail", {"on": False})
tp = st.get(B + "/todo").text
ok(p["psa_ticket_id"] is None and p["ticket_claimed_at"] is None and "wasn't created" in flash(r.text) and f'data-todo="project-{ids["RTS overdue"]}"' in tp
   and "data-ticket-error" in tp.split(f'data-todo="project-{ids["RTS overdue"]}"')[1][:2500] and "refused" in tp.split(f'data-todo="project-{ids["RTS overdue"]}"')[1][:2500],
   "when the PSA refuses, the project stays on To do and shows the reason: " + flash(r.text)[:140])

# declined and done: refused
r = st.post(B + f"/projects/{ids['RTS declined']}/start", data={"_csrf": tok()})
ok(proj("RTS declined")["psa_ticket_id"] is None and "declined" in flash(r.text), "a declined project gets no ticket")

# ---- Not yet
r = st.post(B + f"/projects/{ids['RTS overdue']}/snooze", data={"_csrf": tok(), "months": "3", "back": "/todo"})
p = proj("RTS overdue")
want3 = pval(f'echo Align\\Roadmap\\ProjectTickets::addMonths("{today}", 3);')
ok(str(p["ticket_snooze_until"]) == want3 and f'data-todo="project-{ids["RTS overdue"]}"' not in st.get(B + "/todo").text and "back on To do" in flash(r.text),
   "Not yet for 3 months hides it until then: " + flash(r.text)[:100])
st.post(B + f"/projects/{ids['RTS overdue']}/snooze", data={"_csrf": tok(), "months": "7"})
ok(str(proj("RTS overdue")["ticket_snooze_until"]) == pval(f'echo Align\\Roadmap\\ProjectTickets::addMonths("{today}", 1);'), "a month count that isn't offered is read as 1")
q("update roadmap_items set ticket_snooze_until = %s where id = %s", today, ids["RTS overdue"])
ok(f'data-todo="project-{ids["RTS overdue"]}"' in st.get(B + "/todo").text, "on its day it is back on To do")
ok(q("select 1 from audit_log where action = 'roadmap.ticket_snooze' and detail like %s", "%RTS overdue%"), "Not yet is audited")

# ---- Ready to start: all (only the ones still due)
add("RTS all one", quarter=cur); add("RTS all two", quarter=cur)
a1, a2 = proj("RTS all one")["id"], proj("RTS all two")["id"]
n2 = len(created())
r = st.post(B + "/projects/start-all", data={"_csrf": tok(), "ids[]": [a1, a2, ids["RTS later"], ids["RTS proposed"]], "back": "/todo"})
ok(proj("RTS all one")["psa_ticket_id"] and proj("RTS all two")["psa_ticket_id"] and proj("RTS later")["psa_ticket_id"] is None and proj("RTS proposed")["psa_ticket_id"] is None
   and len(created()) == n2 + 2, "Ready to start: all makes tickets only for the ticked projects that are still due: " + flash(r.text)[:120])
r = st.post(B + "/projects/start-all", data={"_csrf": tok(), "back": "/todo"})
ok("Tick at least one" in flash(r.text), "with nothing ticked it says so")

# unclear answers (no response, a server error): the claim is kept, so nobody makes a second ticket by retrying at once
add("RTS unclear", quarter=cur); pu0 = proj("RTS unclear")
mock("/mock/ticket-create-fail", {"on": True, "mode": "500"})
r = st.post(B + f"/projects/{pu0['id']}/start", data={"_csrf": tok(), "back": "/todo"})
mock("/mock/ticket-create-fail", {"on": False})
pu1 = proj("RTS unclear")
ok(pu1["psa_ticket_id"] is None and pu1["ticket_claimed_at"] is not None and "isn't clear" in flash(r.text) and "may have been made" in (pu1["ticket_error"] or ""),
   "a server error: it isn't clear whether the ticket exists, so the claim is kept and To do says to check: " + flash(r.text)[:140])
n5 = len(created())
r = st.post(B + f"/projects/{pu0['id']}/start", data={"_csrf": tok(), "back": "/todo"})
ok(len(created()) == n5 and "being made" in flash(r.text), "pressing again right away makes no second ticket")
q("update roadmap_items set ticket_claimed_at = now() - interval 20 minute where id = %s", pu0["id"])
r = st.post(B + f"/projects/{pu0['id']}/start", data={"_csrf": tok(), "back": "/todo"})
pu2 = proj("RTS unclear")
ok(pu2["psa_ticket_id"] and pu2["ticket_error"] is None and pu2["ticket_error_at"] is None, "after 10 minutes it can be tried again, and a success clears the reason")

# Not yet is for a quarter: moving the project clears it
add("RTS moved", quarter=cur); pm = proj("RTS moved")
st.post(B + f"/projects/{pm['id']}/snooze", data={"_csrf": tok(), "months": "6"})
ok(proj("RTS moved")["ticket_snooze_until"] is not None, "(snoozed for 6 months)")
st.post(B + f"/clients/1/roadmap/{pm['id']}/move", data={"_csrf": tok(), "target_quarter": cur})
ok(proj("RTS moved")["ticket_snooze_until"] is not None, "moving it to the quarter it's already in keeps Not yet")
st.post(B + f"/clients/1/roadmap/{pm['id']}/move", data={"_csrf": tok(), "target_quarter": nxt})
ok(proj("RTS moved")["ticket_snooze_until"] is None, "moving it to another quarter clears Not yet")
st.post(B + f"/projects/{pm['id']}/snooze", data={"_csrf": tok(), "months": "6"})
st.post(B + f"/clients/1/roadmap/{pm['id']}", data={"_csrf": tok(), "action": "save", "title": "RTS moved", "category": "security", "priority": "high", "status": "approved", "target_quarter": cur, "cost": "1200"})
ok(proj("RTS moved")["ticket_snooze_until"] is None and str(proj("RTS moved")["target_quarter"]) == cur, "so does saving it with a new quarter")

# ---- a client not linked to the PSA
q("insert into clients (name, source) values ('RTS Manual Example', 'manual')")
mc = q("select id from clients where name = 'RTS Manual Example'")[0]["id"]
add("RTS unlinked", quarter=cur, cid=mc)
pu = proj("RTS unlinked")
td = st.get(B + "/todo").text
ok(pu and f'data-todo="project-{pu["id"]}"' in td, "a client not linked to the PSA still has its project on To do")
ok('id="modal-start-all"' in td and ">no ticket<" in td and ">ticket<" in td and "Start " in text(td), "Ready to start: all says which ones get a ticket and which are only marked started")
fw = st.get(B + f"/projects/{pu['id']}/start").text
ok("Mark as started" in fw and "isn't linked to ITFlow, so no ticket is made" in text(fw) and "Create the ticket" not in fw, "its confirm window marks it started, and says why there's no ticket")
n4 = len(created())
started_log = lambda: q("select count(*) n from audit_log where action = 'roadmap.project_started' and detail like %s", "%RTS unlinked%")[0]["n"]
a0 = started_log()
r = st.post(B + f"/projects/{pu['id']}/start", data={"_csrf": tok()})
pu = proj("RTS unlinked")
ok(pu["psa_ticket_id"] is None and pu["started_at"] is not None and pu["started_by"] == admin_id and pu["ticket_at"] is None and len(created()) == n4 and "Started" in flash(r.text),
   "Ready to start marks it started (when and who), no ticket: " + flash(r.text))
ok(f'data-todo="project-{pu["id"]}"' not in st.get(B + "/todo").text, "and it leaves To do")
r = st.post(B + f"/projects/{pu['id']}/start", data={"_csrf": tok()})
ok("already marked started" in flash(r.text), "a second press says it was already started")
ok(started_log() == a0 + 1, "marked once, audited once")
fr = st.get(B + f"/projects/{pu['id']}/form").text
ok('data-ticket-state="started"' in fr and "Started " in text(fr) and "Make the ticket" not in fr, "the project window says when and who; no ticket button while the client isn't linked")
# Not started undoes it: back on To do
r = st.post(B + f"/projects/{pu['id']}/unstart", data={"_csrf": tok(), "back": "/todo"})
ok(proj("RTS unlinked")["started_at"] is None and f'data-todo="project-{pu["id"]}"' in r.text and "not started" in flash(r.text), "Not started undoes it, and it's back on To do")
ok("wasn't marked started" in flash(st.post(B + f"/projects/{pu['id']}/unstart", data={"_csrf": tok()}).text), "Not started on a project that isn't started does nothing")
# the window said "mark started", then the client was linked: nothing happens, it says to reload
q("update clients set psa_id = %s where id = %s", "9" + str(mc), mc)  # a PSA id of its own (unique); the mock takes any
r = st.post(B + f"/projects/{pu['id']}/start", data={"_csrf": tok(), "mode": "mark"})
ok(proj("RTS unlinked")["started_at"] is None and len(created()) == n4 and "Something changed" in flash(r.text), "a window that said Mark as started does nothing once a ticket would be made: " + flash(r.text))
q("update clients set psa_id = NULL where id = %s", mc)
r = st.post(B + f"/projects/{pu['id']}/start", data={"_csrf": tok(), "mode": "ticket"})
ok(proj("RTS unlinked")["started_at"] is None and "Something changed" in flash(r.text), "and one that said Create the ticket doesn't mark it started once the client is unlinked")
r = st.post(B + f"/projects/{pu['id']}/start", data={"_csrf": tok(), "mode": "mark"})
first = proj("RTS unlinked")
# linked later: the ticket can follow, and the start keeps its date and name
q("update clients set psa_id = %s where id = %s", "9" + str(mc), mc)
q("update roadmap_items set ticket_claimed_at = now() where id = %s", pu["id"])
fr = st.get(B + f"/projects/{pu['id']}/form").text
ok('data-ticket-state="working"' in fr and "Make the ticket" not in fr, "while a ticket is being made, the project window offers no second try")
q("update roadmap_items set ticket_claimed_at = null, ticket_error = 'It may have been made: timeout', ticket_error_at = now() where id = %s", pu["id"])
fr = st.get(B + f"/projects/{pu['id']}/form").text
ok("Make the ticket" in fr and "No ticket was made then" in text(fr) and "It may have been made" in text(fr), "once it's linked, the project window offers Make the ticket, with the last try's problem")
q("update roadmap_items set started_at = '2026-01-10 09:00:00' where id = %s", pu["id"])
other = q("select id from users where email = 'tech@example.com'")[0]["id"]
q("update roadmap_items set started_by = %s where id = %s", other, pu["id"])
r = st.post(B + f"/projects/{pu['id']}/start", data={"_csrf": tok(), "mode": "ticket"})
pu = proj("RTS unlinked")
ok(pu["psa_ticket_id"] and len(created()) == n4 + 1 and str(pu["started_at"]) == "2026-01-10 09:00:00" and pu["started_by"] == other and pu["ticket_by"] == admin_id,
   "and makes it, keeping who started it and when: " + flash(r.text))
q("update clients set psa_id = NULL where id = %s", mc)
# a done project that was marked started still shows it
q("insert into roadmap_items (client_id, title, category, priority, status, target_quarter, cost, started_at, started_by) values (%s, 'RTS done started', 'security', 'high', 'done', %s, 100, now(), %s)", mc, cur, admin_id)
pds = proj("RTS done started")
fr = st.get(B + f"/projects/{pds['id']}/form").text
ok('data-ticket-state="started"' in fr and "Make the ticket" not in fr, "a done project marked started still shows when it was started")
# a pretend ticket copied off a test server counts as no ticket here
q("insert into roadmap_items (client_id, title, category, priority, status, target_quarter, cost, psa_ticket_id) values (1, 'RTS pretend copy', 'security', 'high', 'approved', %s, 100, 'TEST-999')", cur)
ppc = proj("RTS pretend copy")
ok(f'data-todo="project-{ppc["id"]}"' in st.get(B + "/todo").text, "a pretend TEST- ticket from a test server counts as none off it: the project is on To do")
r = st.post(B + f"/projects/{ppc['id']}/start", data={"_csrf": tok(), "mode": "ticket"})
ok(proj("RTS pretend copy")["psa_ticket_id"] not in (None, "TEST-999"), "and gets a real ticket: " + flash(r.text))

# ---- the project window and the Projects page
fr = st.get(B + f"/projects/{ids['RTS later']}/form").text
ok('data-ticket-state="later"' in fr and "Ticket: not yet" in fr and "Goes on To do" in text(fr) and f'/projects/{ids["RTS later"]}/start' in fr,
   "the project window says when it goes on To do, with Ready to start")
fr = st.get(B + f"/projects/{ids['RTS now']}/form").text
ok('data-ticket-state="ticket"' in fr and "ITFlow #" in fr and "Made " in text(fr), "and shows the ticket once made")
fr = st.get(B + f"/projects/{ids['RTS proposed']}/form").text
ok('data-ticket-state="proposed"' in fr and "approved" in text(fr), "a proposed project waits for approval, Ready to start still offered")
new_form = st.get(B + "/clients/1/roadmap").text
ok('name="ticket" value="1"' in new_form and "Make the QUOTE- ticket now" in new_form and not re.search(r'name="ticket" value="1"[^>]*checked', new_form),
   "Add project offers Make the QUOTE- ticket now, unticked")
dv = st.get(B + "/clients/1/devices").text
ok('id="mp-ticket"' in dv and not re.search(r'id="mp-ticket"[^>]*checked', dv) and "Make the QUOTE- ticket now" in dv, "Make projects: the ticket box starts unticked")

pg = st.get(B + "/projects?client=1&status=all").text
ok("<th>Ticket</th>" in pg and f'data-lazy-modal="/projects/{a1}/start' not in pg and f'data-lazy-modal="/projects/{ids["RTS later"]}/start' in pg,
   "Projects has a Ticket column, with Ready to start for projects not due yet too (but not for ones with a ticket)")
ok('data-ticket-state="ticket"' in pg and 'data-ticket-state="later"' in pg and 'data-ticket-state="unscheduled"' in pg, "each row says where its ticket stands")
rd = st.get(B + "/projects?client=1&ticket=ready").text
ok(f'data-lazy-modal="/projects/{ids["RTS overdue"]}/start' in rd and "RTS later" not in rd and "RTS now" not in rd, "the Ticket filter shows only projects ready to start")
hs = st.get(B + "/projects?client=1&ticket=has&status=all").text
ok("RTS now" in hs and "RTS later" not in hs, "and Started shows only projects with a ticket (or marked started)")

# ---- device projects work the same way
devs = json.loads(php('echo json_encode(array_values(array_map(fn($d) => ["id" => (int) $d["id"], "name" => $d["name"]], array_filter((new Align\\Lifecycle\\Lifecycle())->devices(1), fn($d) => $d["is_hardware"] && $d["status"] !== "excluded" && !$d["project"]))));').stdout)
if devs:
    d = devs[0]
    n3 = len(created())
    r = st.post(B + "/clients/1/devices/projects", data={"_csrf": csrf(st, "/clients/1/devices"), "ids[]": [d["id"]], "status": "approved", "quarter": cur,
                                                         "title": "RTS device", "note": "Client said yes", "back": "/clients/1/devices"})
    pd = proj("RTS device")
    ok(pd and pd["psa_ticket_id"] is None and len(created()) == n3 and f'data-todo="project-{pd["id"]}"' in st.get(B + "/todo").text,
       "Make projects without the box: no ticket, and it's on To do in its quarter")
    st.post(B + f"/projects/{pd['id']}/start", data={"_csrf": tok()})
    t = created()[-1] if len(created()) > n3 else {}
    ok(t.get("ticket_subject") == "QUOTE- RTS device" and d["name"] in t.get("ticket_details", "") and "Budgeted" in t.get("ticket_details", "")
       and "Client said yes" in t.get("ticket_details", "") and t.get("ticket_details", "").count("Replaces:") == 0,
       "its ticket lists the devices in a table (not the description's device list again) and the note")
else:
    ok(False, "client 1 has a device free for a project")

# ---- who and where
v = login("viewer@example.com", "ViewerPassword123!")
ok(v.post(B + f"/projects/{ids['RTS overdue']}/start", data={"_csrf": csrf(v, "/clients/1")}).status_code == 403
   and v.get(B + f"/projects/{ids['RTS overdue']}/start").status_code == 403
   and v.post(B + f"/projects/{ids['RTS overdue']}/snooze", data={"_csrf": csrf(v, "/clients/1"), "months": "1"}).status_code == 403,
   "viewers can't start or snooze projects")
ok(proj("RTS overdue")["psa_ticket_id"] is None, "and nothing was made")
r = st.post(B + f"/projects/{ids['RTS overdue']}/snooze", data={"_csrf": tok(), "months": "1", "back": "https://evil.example/x"})
ok(r.url.startswith(B) and "evil" not in r.url, "back can only be a page of this site")
ok(st.post(B + f"/projects/{ids['RTS overdue']}/start", data={"back": "/todo"}).status_code in (403, 419), "posting without the form's token is refused")
ok(not errs(st.get(B + "/todo").text) and not errs(st.get(B + "/projects?status=all").text), "no PHP warnings on To do or Projects")

# ---- in the browser: Not yet's menu, Ready to start from To do and from inside the project window
from playwright.sync_api import sync_playwright
add("RTS browser one", quarter=cur); add("RTS browser two", quarter=cur); add("RTS browser later", quarter=nxt)
b1, b2, b3 = proj("RTS browser one")["id"], proj("RTS browser two")["id"], proj("RTS browser later")["id"]
with sync_playwright() as pw:
    br = pw.chromium.launch(); pg = br.new_page(viewport={"width": 1440, "height": 1000})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
    pg.goto(B + "/todo")
    row = pg.locator(f"[data-todo='project-{b1}']")
    row.locator(".dropdown-toggle-split").click(); pg.wait_for_timeout(200)
    items = row.locator(".dropdown-menu .dropdown-item").all_inner_texts()
    ok(len(items) == 4 and items[0].startswith("1 month") and items[3].startswith("6 months"), "Not yet's menu offers 1, 2, 3 and 6 months, each with its date: " + str(items))
    with pg.expect_navigation(): row.locator(".dropdown-menu .dropdown-item", has_text="2 months").click()
    ok(str(proj("RTS browser one")["ticket_snooze_until"]) == pval(f'echo Align\\Roadmap\\ProjectTickets::addMonths("{today}", 2);') and pg.locator(f"[data-todo='project-{b1}']").count() == 0,
       "picking 2 months hides it until then")
    n4 = len(created())
    pg.locator(f"[data-todo='project-{b2}'] a", has_text="Ready to start").click()
    pg.wait_for_selector(f"#modal-start-{b2}.show"); pg.wait_for_timeout(300)
    ok("QUOTE- RTS browser two" in pg.locator(f"#modal-start-{b2} .modal-body").inner_text(), "Ready to start opens the window with what goes into the ticket")
    with pg.expect_navigation(): pg.click(f"#modal-start-{b2} button.btn-primary")
    ok(proj("RTS browser two")["psa_ticket_id"] and len(created()) == n4 + 1 and "/todo" in pg.url, "Create the ticket makes it and comes back to To do")
    # from inside the project window on the Projects page: the window closes and the confirm opens
    pg.goto(B + "/projects?client=1&status=all")
    pg.click(f"a[data-bs-target='#modal-roadmap-{b3}']"); pg.wait_for_selector(f"#modal-roadmap-{b3}.show"); pg.wait_for_timeout(300)
    strip = pg.locator(f"#modal-roadmap-{b3} [data-ticket-state='later']")
    ok(strip.count() == 1 and "Goes on To do" in strip.inner_text(), "the project window shows when it goes on To do")
    strip.locator("a", has_text="Ready to start").click()
    pg.wait_for_selector(f"#modal-start-{b3}.show"); pg.wait_for_timeout(400)
    ok(pg.locator(".modal.show").count() == 1 and pg.locator(f"#modal-roadmap-{b3}.show").count() == 0, "the project window closes and only the confirm window is open")
    with pg.expect_navigation(): pg.click(f"#modal-start-{b3} button.btn-primary")
    ok(proj("RTS browser later")["psa_ticket_id"] and "/projects" in pg.url, "a project not due yet can be started from its window, back on Projects")
    ok(not errors, "no script errors: " + str(errors[:2]))
    br.close()

q("delete from roadmap_items where title like 'RTS %%'")
q("delete from clients where name = 'RTS Manual Example'")
done()
