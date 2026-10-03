"""2.2.1 review (batch 5, planning pages): compliance, meetings, budgets, reports, dashboard. Each check fails on 2.2.0
unless it says "regression".
Compliance: answers compared strictly as text, real due/review dates, answers only for an assigned framework, only
active frameworks assigned, another client's document never shown as evidence, archived clients left out of the
overview, deleted controls and kept answers named in the audit log, a huge sort number refused cleanly.
Meetings: real dates and times, an attendee list refused rather than cut, an agenda cut to its column, monthly series
on the 31st, status changes once and only from the right state, a join link that isn't http(s) isn't linked, viewers
aren't offered a calendar feed.
Budgets: an amount too large for the column and impossible dates refused instead of a database error.
Reports: a QBR section switched off is left out of the executive summary too; the portfolio's grouped queries."""
from lib import *
import atexit, datetime

TAG = "PlanQ%d" % int(time.time())
CID = 1
admin = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")


def _cleanup():
    q("delete from meetings where title like %s", TAG + "%")
    q("delete from budget_lines where name like %s", TAG + "%")
    q("delete from compliance_frameworks where name like %s", TAG + "%")  # controls, answers, assignments cascade
    q("delete from documents where title like %s", TAG + "%")
    q("delete from clients where name like %s", TAG + "%")
atexit.register(_cleanup)


def audits(action, like):
    return q("select count(*) n from audit_log where action=%s and detail like %s", action, like)[0]["n"]


# ---------------------------------------------------------------- compliance
def framework(name, active=1, n=2):
    fid = q("select id from compliance_frameworks where slug=%s", name.lower()[:60])
    if not fid:
        with db.cursor() as c:
            c.execute("insert into compliance_frameworks (slug, name, is_active) values (%s,%s,%s)", (name.lower()[:60], name, active))
            fid = c.lastrowid
    else:
        fid = fid[0]["id"]
    ids = []
    for i in range(n):
        with db.cursor() as c:
            c.execute("insert into compliance_controls (framework_id, ref, section, title, sort) values (%s,%s,'General',%s,%s)", (fid, f"Q.{i + 1}", f"{name} control {i + 1}", (i + 1) * 10))
            ids.append(c.lastrowid)
    return fid, ids

FW1, (C1, C2) = framework(TAG + " FW One")
FW2, (C3, C4) = framework(TAG + " FW Two")
FW3, _ = framework(TAG + " FW Inactive", active=0, n=1)
q("insert into client_frameworks (client_id, framework_id, next_review) values (%s,%s,'2027-01-15')", CID, FW1)


def save_checklist(s, fid, rows, extra=None):
    data = {"_csrf": csrf(s, f"/clients/{CID}/compliance"), **(extra or {})}
    for k, r in rows.items():
        for f in ("status", "notes", "evidence", "owner", "due_date", "document_id"):
            data[f"c[{k}][{f}]"] = r.get(f, "")
    return s.post(B + f"/clients/{CID}/compliance/{fid}", data=data)

def answer(k):
    return (q("select * from client_control_status where client_id=%s and control_id=%s", CID, k) or [None])[0]

q("insert into client_control_status (client_id, control_id, status, notes) values (%s,%s,'partial','1e1')", CID, C1)
r = save_checklist(tech, FW1, {C1: {"status": "partial", "notes": "10"}})
ok(answer(C1)["notes"] == "10" and "Saved 1 change(s)" in flash(r.text),
   "a changed answer is compared as text: notes '1e1' -> '10' is saved (a loose == called them equal): " + flash(r.text))

r = save_checklist(tech, FW1, {C2: {"status": "met", "due_date": "2026-02-31"}}, {"next_review": "2026-02-30"})
a = answer(C2)
ok(r.status_code == 200 and not errs(r.text) and a and a["status"] == "met" and a["due_date"] is None,
   "an impossible due date (2026-02-31) is dropped and the answer saved, instead of a database error (HTTP %d)" % r.status_code)
ok(str(q("select next_review from client_frameworks where client_id=%s and framework_id=%s", CID, FW1)[0]["next_review"]) == "2027-01-15",
   "an impossible next review date (2026-02-30) leaves the review date as it was")

r = save_checklist(tech, FW2, {C3: {"status": "met", "notes": "should not land"}})
ok(answer(C3) is None and "isn't assigned" in flash(r.text), "answers can't be saved for a framework the client doesn't have: " + flash(r.text))

r = tech.post(B + f"/clients/{CID}/compliance", data={"_csrf": csrf(tech, f"/clients/{CID}/compliance"), "framework_id": str(FW3)})
ok(not q("select 1 from client_frameworks where client_id=%s and framework_id=%s", CID, FW3) and "inactive" in flash(r.text),
   "an inactive framework can't be assigned by posting its id: " + flash(r.text))

with db.cursor() as c:
    c.execute("insert into documents (client_id, title, status) values (2, %s, 'active')", (TAG + " Other Client Doc",))
    other_doc = c.lastrowid
q("update client_control_status set document_id=%s where client_id=%s and control_id=%s", other_doc, CID, C1)
t = tech.get(B + f"/clients/{CID}/compliance/{FW1}").text
ok(TAG + " Other Client Doc" not in H.unescape(t) and not errs(t), "another client's document is never shown as a control's evidence")
csv = tech.get(B + f"/clients/{CID}/compliance/{FW1}/export").text
ok(TAG + " Other Client Doc" not in csv, "...nor in the checklist CSV")

with db.cursor() as c:
    c.execute("insert into clients (source, name, is_archived) values ('manual', %s, 1)", (TAG + " Archived Co",))
    arch = c.lastrowid
q("insert into client_frameworks (client_id, framework_id) values (%s,%s)", arch, FW1)
q("insert into client_control_status (client_id, control_id, status) values (%s,%s,'not_met'), (%s,%s,'not_met')", arch, C1, arch, C2)
t = H.unescape(viewer.get(B + "/compliance").text)
m = re.search(re.escape(TAG + " FW One") + r'</span>\s*<span class="info-box-number">(\d+)%\s*<small[^>]*>avg across (\d+) client', t)
ok(m and m.group(1) == "75" and m.group(2) == "1",
   "the overview's framework average leaves out archived clients (75%% across 1 client, got %s)" % ((m.groups() if m else None),))

# framework editor: deleting a control with answers, a huge sort number, deleting a framework with kept answers
q("insert into client_control_status (client_id, control_id, status) values (%s,%s,'met')", arch, C4)
r = admin.post(B + f"/frameworks/{FW2}", data={"_csrf": csrf(admin, f"/frameworks/{FW2}"), "action": "save", "name": TAG + " FW Two", "is_active": "1",
                                                f"ctl[{C3}][title]": "Kept control", f"ctl[{C3}][sort]": "99999999999", f"ctl[{C3}][ref]": "Q.1",
                                                f"ctl[{C4}][title]": "Gone control", f"ctl[{C4}][delete]": "1"})
ok(r.status_code == 200 and not errs(r.text) and q("select sort from compliance_controls where id=%s", C3)[0]["sort"] == 1000000,
   "a huge sort number is capped instead of failing the whole save (HTTP %d)" % r.status_code)
ok(not q("select 1 from compliance_controls where id=%s", C4) and audits("framework.save", "%deleted 1 control(s): Q.2 " + TAG + " FW Two control 2 (1 answer(s))%") == 1,
   "deleting a control says in the audit log which one and how many answers went with it")
q("insert into client_control_status (client_id, control_id, status) values (%s,%s,'met')", CID, C3)
r = admin.post(B + f"/frameworks/{FW2}", data={"_csrf": csrf(admin, f"/frameworks/{FW2}"), "action": "delete"})
ok(not q("select 1 from compliance_frameworks where id=%s", FW2) and audits("framework.delete", TAG + " FW Two (with 1 kept answer(s))") == 1,
   "deleting a framework says how many kept answers (from earlier assignments) went with it")

# ---------------------------------------------------------------- QBR summary follows the section switches
t = H.unescape(tech.get(B + f"/clients/{CID}/report/qbr").text)
ok('kpi-lbl">Compliance</div>' in t, "regression: with Compliance on, the summary has the compliance tile")
t = H.unescape(tech.get(B + f"/clients/{CID}/report/qbr?s_compliance=0").text)
ok('kpi-lbl">Compliance</div>' not in t and "Compliance: " not in t and "Compliance in good shape" not in t and not errs(t),
   "with Compliance switched off, the executive summary leaves out the compliance score and highlight too")
t = H.unescape(tech.get(B + f"/clients/{CID}/report/qbr?s_assets=0").text)
ok('kpi-lbl">Devices healthy</div>' not in t and "past end of life</b>" not in t and not errs(t), "...and with Assets off, the device tiles and highlights")

# ---------------------------------------------------------------- meetings
def new_meeting(s, title, date, time_="10:00", **extra):
    return s.post(B + "/meetings", data={"_csrf": csrf(s, "/meetings"), "client_id": str(CID), "title": title, "type": "qbr", "date": date, "time": time_,
                                         "duration": "60", "owner_id": "", **extra})

for label, d, tm in (("month 13", "2026-13-45", "10:00"), ("February 30", "2026-02-30", "10:00"), ("25:99", "2026-11-10", "25:99")):
    r = new_meeting(tech, f"{TAG} bad {label}", d, tm)
    ok(not q("select id from meetings where title=%s", f"{TAG} bad {label}") and "Pick a date and time" in flash(r.text),
       f"a meeting on an impossible date or time ({label}) is refused, not saved in 1970 or the next month")

r = new_meeting(tech, TAG + " long list", "2027-02-10", attendees="x" * 3990 + ", pat@client.example")
ok(not q("select id from meetings where title=%s", TAG + " long list") and "too long" in flash(r.text),
   "an attendee list over 4,000 characters is refused, not cut (a cut address can reach someone else): " + flash(r.text))
r = new_meeting(tech, TAG + " emoji agenda", "2027-02-11", agenda="\U0001F600" * 20000)
row = q("select char_length(agenda) n from meetings where title=%s", TAG + " emoji agenda")
ok(r.status_code == 200 and row and 16000 <= row[0]["n"] <= 20000, "a 20,000-emoji agenda is cut to what its column holds instead of a database error (HTTP %d)" % r.status_code)

r = new_meeting(tech, TAG + " series", "2027-01-31", repeat="monthly", repeat_count="3")
got = [str(x["starts_at"]) for x in q("select starts_at from meetings where title=%s order by starts_at", TAG + " series")]
ok(got == ["2027-01-31 10:00:00", "2027-02-28 10:00:00", "2027-03-31 10:00:00"], "a monthly series from January 31 falls on the last day of shorter months: " + str(got))

def mk(title, status="scheduled", **cols):
    c2 = {"uid": os.urandom(16).hex(), "client_id": CID, "title": title, "type": "qbr", "status": status,
          "starts_at": "2027-05-04 10:00:00", "ends_at": "2027-05-04 11:00:00", **cols}
    with db.cursor() as c:
        c.execute("insert into meetings (" + ",".join(c2) + ") values (" + ",".join(["%s"] * len(c2)) + ")", tuple(c2.values()))
        return c.lastrowid
def act(s, mid, action):
    return s.post(B + f"/meetings/{mid}", data={"_csrf": csrf(s, f"/meetings/{mid}"), "action": action})
def status(mid): return q("select status from meetings where id=%s", mid)[0]["status"]

mid = mk(TAG + " cancel twice")
act(tech, mid, "cancel")
r = act(tech, mid, "cancel")
ok(status(mid) == "cancelled" and audits("meeting.cancel", TAG + " cancel twice") == 1 and "Nothing changed" in flash(r.text),
   "cancelling twice (double click, second tab) cancels once: one audit entry, one cancellation to attendees")
r = act(tech, mid, "complete")
ok(status(mid) == "cancelled" and audits("meeting.complete", TAG + " cancel twice") == 0, "a cancelled meeting can't be marked completed (the page only offers Reopen)")
mid2 = mk(TAG + " done", "completed")
act(tech, mid2, "cancel")
ok(status(mid2) == "completed" and audits("meeting.cancel", TAG + " done") == 0, "a completed meeting can't be cancelled (which emailed attendees a cancellation)")
inv_on = php('echo Align\\Mail\\Invites::enabled() ? "1" : "0";').stdout.strip() == "1"
mid3 = mk(TAG + " reopen done", "completed", attendees="pat@client.example", invites_sent_at=datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S"))
before = audits("meeting.invite", "%" + TAG + " reopen done%")
act(tech, mid3, "reopen")
ok(status(mid3) == "scheduled" and audits("meeting.invite", "%" + TAG + " reopen done%") == before,
   "reopening a completed meeting doesn't email the invitation again" + ("" if inv_on else " (invitations are off on this server: checked the state only)"))
act(tech, mid3, "reopen")
ok(audits("meeting.reopen", TAG + " reopen done") == 1, "reopening an already scheduled meeting does nothing")

mid4 = mk(TAG + " bad link", video_url="javascript:alert(document.domain)")
t = tech.get(B + f"/meetings/{mid4}").text
ok('href="javascript:' not in t and "javascript:alert(document.domain)" in t and not errs(t), "a join link that isn't http(s) is shown as text, not as a link")
mid5 = mk(TAG + " good link", video_url="https://teams.example/join/1")
ok('href="https://teams.example/join/1"' in tech.get(B + f"/meetings/{mid5}").text, "regression: an https join link is still a link")

t = viewer.get(B + "/calendar").text
ok("Create my feed link" not in t and "Subscribe in Outlook" not in t and not errs(t), "viewers aren't offered a calendar feed (only techs and admins can have one)")
ok("Subscribe in Outlook" in tech.get(B + "/calendar").text, "regression: techs still are")
ok(php('echo date("Y-m-d H:i", Align\\Meetings\\Meetings::addMonths(mktime(9, 30, 0, 1, 31, 2028), 1)), " ", date("Y-m-d", Align\\Meetings\\Meetings::addMonths(mktime(9, 30, 0, 3, 31, 2027), -13));').stdout
   == "2028-02-29 09:30 2026-02-28", "Meetings::addMonths keeps the time and clamps to the month's last day (leap years, going back)")

# ---------------------------------------------------------------- budgets
def budget_post(s, path, **f):
    data = {"_csrf": csrf(s, f"/clients/{CID}/budget"), "category": "connectivity", "frequency": "monthly", "back": f"/clients/{CID}/budget", **f}
    return s.post(B + path, data=data)

r = budget_post(tech, f"/clients/{CID}/budget", name=TAG + " huge", amount="1e12")
ok(r.status_code == 200 and not q("select id from budget_lines where name=%s", TAG + " huge") and "too large" in flash(r.text),
   "an amount larger than a budget line holds is refused with a message, not a database error (HTTP %d)" % r.status_code)
r = budget_post(tech, f"/clients/{CID}/budget", name=TAG + " dates", amount="120.50", start_date="2026-02-31", end_date="2026-13-01",
                contract_end="2026-04-31", renegotiate_date="2026-06-31", contract_term_months="12", notice_days="30")
row = (q("select * from budget_lines where name=%s", TAG + " dates") or [None])[0]
ok(r.status_code == 200 and row and float(row["amount"]) == 120.5 and row["start_date"] is None and row["end_date"] is None
   and row["contract_end"] is None and row["renegotiate_date"] is None and row["contract_term_months"] == 12,
   "impossible dates are dropped (and nothing is worked out from a bad start date), instead of a database error (HTTP %d)" % r.status_code)
if row:
    r = budget_post(tech, f"/budget-lines/{row['id']}", name=TAG + " dates", amount="99999999999", action="save")
    ok(float(q("select amount from budget_lines where id=%s", row["id"])[0]["amount"]) == 120.5 and "too large" in flash(r.text),
       "...also when editing a line: the amount stays as it was")
    r = budget_post(tech, f"/budget-lines/{row['id']}", name=TAG + " dates", amount="75", start_date="2027-02-28", action="save")
    ok(str(q("select start_date from budget_lines where id=%s", row["id"])[0]["start_date"]) == "2027-02-28", "regression: a real date is saved")

# ---------------------------------------------------------------- regressions: portfolio and dashboard after the query changes
mk(TAG + " last review", "completed", starts_at="2031-05-15 10:00:00", ends_at="2031-05-15 11:00:00")
want = php('echo Align\\Fmt::date("2031-05-15 10:00:00", "month");').stdout.strip()
t = H.unescape(tech.get(B + "/reports/portfolio?costs=1").text)
row1 = t.split(q("select name from clients where id=%s", CID)[0]["name"], 1)[-1].split("</tr>", 1)[0]
ok(want and want in row1 and not errs(t), "regression: the portfolio still shows each client's last review (%s) from the grouped query" % want)
soon = (datetime.date.today() + datetime.timedelta(days=10)).isoformat()
budget_post(tech, f"/clients/{CID}/budget", name=TAG + " renewal", amount="50", contract_end=soon, auto_renew="1")
_layout = q("select dashboard_layout from users where email='admin@example.com'")[0]["dashboard_layout"]
q("update users set dashboard_layout=NULL where email='admin@example.com'")  # every card, whatever another suite left
try:
    t = H.unescape(admin.get(B + "/").text)
finally:
    q("update users set dashboard_layout=%s where email='admin@example.com'", _layout)
ok(TAG + " renewal" in t.split('data-card="attention"', 1)[-1].split('data-card="', 1)[0] and not errs(t),
   "regression: a contract ending in 10 days is in the dashboard's Needs attention list (contract dates now read once)")

done()
