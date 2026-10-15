"""2.23.0 Staff Outlook calendars, two-way. With Align's email app set up (2.23.0), each person connects their own
Outlook calendar (their own sign-in, PKCE, single use state tied to them); their events (all pages) show on Align's
calendar with subject, place, organizer and attendees, private ones only as Busy; the Align meetings they own go into
their Outlook and are updated when changed in Align, set back when moved in Outlook, and removed when cancelled,
deleted or already in their calendar as an invitation. One Outlook account per person; a refused sign-in or an
unreadable calendar is shown and changes nothing; disconnecting (yourself, an admin, or removing the app) removes
Align's copies and Align's copy of the events. The calendar filters by person."""
import re, json, time, atexit, requests, html as H
from datetime import datetime, timedelta, timezone
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
ADMIN, TECH = 1, 2
KEEP = q("select name, value, is_secret from settings where name like 'm365\\_%%' or name like 'mail\\_%%'")
MINE = []  # meetings this suite made


def mock(body): return requests.post(M + "/mock/msmail", json=body).json()
def mstate(): return requests.get(M + "/mock/msmail-state").json()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def copies(acct="admin@example.com"):
    evs = mstate()["events"]
    evs = (evs.get(acct) if isinstance(evs, dict) else None) or {}
    return {k: v for k, v in (evs.items() if isinstance(evs, dict) else []) if v.get("created_by_align")}
def sync(force=True):
    """Runs the minute timer's calendar sync now (every connected person with $force)."""
    return php(f"echo json_encode(Align\\Calendar\\Outlook::syncAll({'true' if force else 'false'}));").stdout
def conn(uid): return (q("select * from user_calendars where user_id=%s", uid) or [None])[0]
def utc(days, hour=15): return (datetime.now(timezone.utc) + timedelta(days=days)).replace(hour=hour, minute=0, second=0, microsecond=0).strftime("%Y-%m-%dT%H:%M:%S")
def events(who=st, **qs): return who.get(B + "/calendar/events", params={"start": (datetime.now() - timedelta(days=40)).strftime("%Y-%m-%d"), "end": (datetime.now() + timedelta(days=60)).strftime("%Y-%m-%d"), **qs}).json()


def meeting(title, days, owner=ADMIN, **extra):
    start = (datetime.now() + timedelta(days=days)).replace(hour=10, minute=0, second=0, microsecond=0)
    mid = q("insert into meetings (uid, client_id, title, type, status, starts_at, ends_at, owner_id, location) values (%s, 1, %s, 'qbr', 'scheduled', %s, %s, %s, %s) returning id",
            "cal223-" + re.sub(r"\W", "", title) + str(int(time.time() * 1000)), title, start, start + timedelta(hours=1), owner, extra.get("location"))[0]["id"]
    MINE.append(mid)
    return mid


def cleanup():
    q("update users set is_active=1 where id=%s", TECH)
    if MINE:
        q("delete from meetings where id in (" + ",".join(str(i) for i in MINE) + ")")
    q("delete from calendar_pushes")
    q("delete from calendar_events")
    q("delete from user_calendars")
    q("delete from settings where name like 'm365\\_%%' or name like 'mail\\_%%'")
    for r in KEEP:
        q("insert into settings (name, value, is_secret) values (%s,%s,%s) on duplicate key update value=values(value), is_secret=values(is_secret)", r["name"], r["value"], r["is_secret"])
    requests.post(M + "/mock/msmail-reset")


atexit.register(cleanup)
requests.post(M + "/mock/msmail-reset")
q("delete from settings where name like 'm365\\_%%' or name in ('mail_mode','mail_from')")
setting("mail_provider", "microsoft")
setting("m365_login_base", M + "/msl")
setting("m365_graph_base", M + "/msg/v1.0")

# ---- not available until email is set up with Microsoft
a = st.get(B + "/account").text
ok("Outlook calendar" in a and "Set up with Microsoft" in a and "Connect my Outlook calendar" not in a and not errs(a), "Your account explains Outlook needs email set up first")
st.post(B + "/integrations/email/setup/start", data={"_csrf": csrf(st, "/integrations/email")})
mock({"approved": True})
st.post(B + "/integrations/email/setup/finish", data={"_csrf": csrf(st, "/integrations/email")})
st.get(B + "/integrations/email/connect")  # the sending mailbox (alerts@)
ok(q("select value from settings where name='m365_app_auto'")[0]["value"] == "1", "set up: Align's email app and the sending mailbox")

# ---- the person's Outlook: 5 events over 3 pages, one private, one all day
ev = {
    "o1": {"subject": "Dentist", "start": {"dateTime": utc(2), "timeZone": "UTC"}, "end": {"dateTime": utc(2, 16), "timeZone": "UTC"}, "location": {"displayName": "Main St"},
           "organizer": {"emailAddress": {"name": "Alex Admin", "address": "admin@example.com"}}, "attendees": [{"emailAddress": {"name": "Pat Vendor", "address": "pat@vendor.example"}, "status": {"response": "accepted"}}],
           "webLink": "https://outlook.office365.com/owa/?itemid=o1", "showAs": "busy", "iCalUId": "x1"},
    "o2": {"subject": "Secret thing", "sensitivity": "private", "start": {"dateTime": utc(3), "timeZone": "UTC"}, "end": {"dateTime": utc(3, 16), "timeZone": "UTC"},
           "location": {"displayName": "Hidden place"}, "attendees": [{"emailAddress": {"name": "Hidden Person", "address": "h@example.com"}}], "iCalUId": "x2"},
    "o3": {"subject": "Conference", "isAllDay": True, "start": {"dateTime": utc(5, 0), "timeZone": "UTC"}, "end": {"dateTime": utc(6, 0), "timeZone": "UTC"}, "iCalUId": "x3"},
    "o4": {"subject": "Cancelled one", "isCancelled": True, "start": {"dateTime": utc(4), "timeZone": "UTC"}, "end": {"dateTime": utc(4, 16), "timeZone": "UTC"}},
    "o5": {"subject": "<script>alert(1)</script> \x07Bell", "start": {"dateTime": utc(7), "timeZone": "UTC"}, "end": {"dateTime": utc(7, 16), "timeZone": "UTC"}, "webLink": "javascript:alert(1)"},
}
ev["o6"] = {"subject": "Old Align copy", "categories": ["Align meeting"], "start": {"dateTime": utc(9), "timeZone": "UTC"}, "end": {"dateTime": utc(9, 16), "timeZone": "UTC"}}
for k, v in ev.items():
    mock({"put_event": {"account": "admin@example.com", "id": k, "event": v}})
mock({"put_event": {"account": "tech@example.com", "id": "t1", "event": {"subject": "Site visit", "start": {"dateTime": utc(2), "timeZone": "UTC"}, "end": {"dateTime": utc(2, 16), "timeZone": "UTC"}}}})
m1 = meeting("Cal QBR one", 3, location="Client office")
m2 = meeting("Cal QBR two", 8)
m3 = meeting("Cal cancel me", 9)
m4 = meeting("Cal delete me", 10)
m5 = meeting("Cal already invited", 11)
q("update meetings set graph_event_id='inv-1', graph_mailbox='/users/admin%%40example.com' where id=%s", m5)
mt = meeting("Cal tech owns", 4, owner=TECH)
want = q("select count(*) n from meetings where owner_id=1 and status<>'cancelled' and ends_at > now() - interval 30 day and starts_at < now() + interval 365 day and not (coalesce(graph_event_id, '') <> '' and coalesce(graph_mailbox, '') = %s)", "/users/admin%40example.com")[0]["n"]

# ---- connecting
a = st.get(B + "/account").text
ok("Connect my Outlook calendar" in a, "Your account offers Connect my Outlook calendar")
r = st.get(B + "/account/calendar/callback", params={"code": "code-admin@example.com", "state": "forged"})
ok("didn't match" in text(r.text) and not conn(ADMIN), "a sign-in response not started in this session is refused")
mock({"user": "admin@example.com", "tid": "ffffffff-0000-4000-8000-0000000000ff"})
r = st.get(B + "/account/calendar/connect")
ok("isn't from your organization" in text(r.text) and not conn(ADMIN), "an account from another tenant is refused")
mock({"user": "viewer@example.com", "tid": "dddddddd-0000-4000-8000-00000000000d"})
r = st.get(B + "/account/calendar/connect")
ok("but your Align account is admin@example.com" in text(r.text) and not conn(ADMIN), "someone else's account is refused: only your own calendar")
mock({"user": "admin@example.com"})
r = st.get(B + "/account/calendar/connect", params={"return": "/calendar"})
c = conn(ADMIN)
ok(c and c["account"] == "admin@example.com" and c["status"] == "connected" and "Connected your Outlook calendar" in text(r.text) and r.url.endswith("/calendar"),
   "connecting signs in as the person and comes back to the calendar: " + flash(r.text)[:150])
la = mstate()["last_authorize"]
ok(la["redirect_uri"] == B + "/account/calendar/callback" and la["scope"].split() == ["offline_access", "openid", "profile", "email", "User.Read", "Calendars.ReadWrite"]
   and la["login_hint"] == "admin@example.com" and la["code_challenge_method"] == "S256", "only Calendars.ReadWrite (and sign-in), PKCE, the person's address as a hint")
ok(c["token_enc"] and "r.admin" not in c["token_enc"], "the refresh token is stored encrypted")

# ---- Outlook → Align
rows = {r["event_id"]: r for r in q("select * from calendar_events where user_id=%s", ADMIN)}
ok(set(rows) >= {"o1", "o2", "o3", "o5"} and "o4" not in rows and "o6" not in rows, "every page of events is read; cancelled ones and an untracked Align copy are left out: %s" % sorted(rows))
ok(rows["o1"]["subject"] == "Dentist" and rows["o1"]["location"] == "Main St" and "pat@vendor.example" in rows["o1"]["attendees"] and rows["o1"]["web_link"].startswith("https://outlook.office365.com/"),
   "full details: subject, place, attendees, the Outlook link")
ok(rows["o2"]["private"] == 1 and rows["o2"]["subject"] is None and rows["o2"]["location"] is None and rows["o2"]["attendees"] is None and rows["o2"]["organizer"] is None,
   "a private event keeps only its time")
tzname = php("echo date_default_timezone_get();").stdout.strip()
ok(tzname not in ("UTC", "Etc/UTC", ""), "the test server isn't on UTC (%s), so a date moved by the timezone would show" % tzname)
day = (datetime.now(timezone.utc) + timedelta(days=5)).strftime("%Y-%m-%d")
ok(rows["o3"]["all_day"] == 1 and rows["o3"]["starts_at"].strftime("%Y-%m-%d %H:%M") == day + " 00:00", "an all-day event keeps its date (not moved by the timezone): %s" % rows["o3"]["starts_at"])
ok(rows["o5"]["web_link"] is None and "\x07" not in rows["o5"]["subject"], "control characters removed; a link that isn't Outlook's is dropped")
start_local = rows["o1"]["starts_at"]
ok(abs((start_local - (datetime.now(timezone.utc) + timedelta(days=2)).replace(hour=15, minute=0, second=0, microsecond=0).astimezone().replace(tzinfo=None)).total_seconds()) < 60,
   "times are in this server's timezone")

# ---- Align → Outlook
cp = copies()
subj = sorted(v["subject"] for v in cp.values())
ok(len(cp) == want and any("Cal QBR one" in s for s in subj) and not any("already invited" in s for s in subj) and not any("tech owns" in s for s in subj),
   "the meetings they own go into their Outlook (not one already invited there, not someone else's): %s" % subj)
c1 = next(v for v in cp.values() if "Cal QBR one" in v["subject"])
ok(c1["location"]["displayName"] == "Client office" and c1["showAs"] == "busy" and "Change it in Align" in c1["body"]["content"] and c1["start"]["timeZone"] == "UTC",
   "a copy has the client and title, place, a link back, busy, UTC times")
ok(q("select count(*) n from calendar_pushes where user_id=%s", ADMIN)[0]["n"] == want, "the copies are remembered")

# ---- Align's calendar
sync()
e = events()
titles = [x["title"] for x in e if x["id"].startswith("o-")]
ok("Alex: Dentist" in titles and "Alex: Busy" in titles and not any("Cal QBR" in t for t in titles), "Align's calendar shows their Outlook events (private as Busy), not the copies of Align meetings: %s" % titles)
o1 = next(x for x in e if x["title"] == "Alex: Dentist")
ok(o1["extendedProps"]["outlook"]["attendees"] == ["Pat Vendor <pat@vendor.example>"] and o1["extendedProps"]["outlook"]["location"] == "Main St", "with the details for its window")
conf = next(x for x in e if x["title"] == "Alex: Conference")
ok(conf["allDay"] and conf["start"] == day, "the all-day event is on its own date in the calendar: %s" % conf["start"])
busy = next(x for x in e if x["title"] == "Alex: Busy")
ok(busy["extendedProps"]["outlook"]["attendees"] == [] and busy["extendedProps"]["outlook"]["location"] is None and busy["extendedProps"]["outlook"]["link"] is None, "a private event has no details")
ok(not [x for x in events(outlook="0") if x["id"].startswith("o-")] and not [x for x in events(person=str(TECH)) if x["id"].startswith("o-")]
   and not [x for x in events(client="1") if x["id"].startswith("o-")], "filters: no Outlook events, someone else's, or a client's view")
cal = st.get(B + "/calendar").text
ok("Everyone's Outlook" in cal and "Alex Admin's Outlook" in cal and "modal-outlook-event" in cal and not errs(cal), "the calendar page has the person filter and the details window")

# ---- changes go out
time.sleep(1.2)
q("update meetings set title='Cal QBR one renamed' where id=%s", m1)
q("update meetings set status='cancelled' where id=%s", m3)
q("delete from meetings where id=%s", m4)
sync(force=False)
cp = copies()
subj = [v["subject"] for v in cp.values()]
ok(any("Cal QBR one renamed" in s for s in subj) and not any("cancel me" in s for s in subj) and not any("delete me" in s for s in subj),
   "within a minute: renamed in Align updates the copy; cancelled and deleted meetings' copies are removed: %s" % subj)
# moved in Outlook: set back on the next full sync
cid = next(k for k, v in cp.items() if "Cal QBR one renamed" in v["subject"])
moved = dict(cp[cid])
moved["start"] = {"dateTime": utc(20), "timeZone": "UTC"}
moved["end"] = {"dateTime": utc(20, 16), "timeZone": "UTC"}
mock({"put_event": {"account": "admin@example.com", "id": cid, "event": moved}})
sync()  # reads the moved copy
sync()  # puts it back
back = mstate()["events"]["admin@example.com"][cid]
want_start = q("select starts_at from meetings where id=%s", m1)[0]["starts_at"].astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")
ok(back["start"]["dateTime"] == want_start, "a copy moved in Outlook is set back to Align's time: %s vs %s" % (back["start"]["dateTime"], want_start))
# the invitation arrives in their calendar: the copy goes
inv = next(k for k, v in copies().items() if "Cal QBR two" in v["subject"])
mrow = q("select uid, starts_at from meetings where id=%s", m2)[0]
mock({"put_event": {"account": "admin@example.com", "id": "real-invite", "event": {"subject": "Accepted: something else", "iCalUId": mrow["uid"] + "@align.test",
     "start": {"dateTime": utc(8), "timeZone": "UTC"}, "end": {"dateTime": utc(8, 16), "timeZone": "UTC"}}}})
sync()
ok(inv not in copies() and not q("select 1 from calendar_pushes where meeting_id=%s", m2), "when the invitation is in their calendar, Align's copy is removed")

# ---- one account per person; a second person
mock({"user": "admin@example.com"})
r = tech.get(B + "/account/calendar/connect")
ok("but your Align account is tech@example.com" in text(r.text) and not conn(TECH), "a colleague's Outlook account (already connected to them) is refused")
mock({"user": "tech@example.com"})
tech.get(B + "/account/calendar/connect")
ok(conn(TECH) and conn(TECH)["account"] == "tech@example.com" and any("tech owns" in v["subject"] for v in copies("tech@example.com").values()),
   "another person connects their own; their meetings go to their Outlook")
ev_t = [x for x in events(person=str(TECH)) if x["id"].startswith("o-")]
ok(ev_t and all(x["title"].startswith("Terry:") for x in ev_t) and any(x["title"] == "Terry: Site visit" for x in ev_t), "the person filter shows only theirs: %s" % [x["title"] for x in ev_t])

# ---- failures change nothing
before = q("select count(*) n from calendar_events where user_id=%s", ADMIN)[0]["n"]
mock({"fail": "calendarView"})
sync()
ok(conn(ADMIN)["error"] and "temporarily unavailable" in conn(ADMIN)["error"] and q("select count(*) n from calendar_events where user_id=%s", ADMIN)[0]["n"] == before,
   "an unreadable calendar is shown on the connection and keeps the events: %s" % conn(ADMIN)["error"])
mock({"fail": "token"})
sync()
ok(conn(ADMIN)["status"] == "error" and "Connect your calendar again" in (conn(ADMIN)["error"] or ""), "a refused sign-in asks the person to connect again")
a = st.get(B + "/account").text
ok("Connect again" in a, "Your account offers Connect again")
mock({"fail": ""})
mock({"user": "admin@example.com"})
st.get(B + "/account/calendar/connect")
ok(conn(ADMIN)["status"] == "connected" and not conn(ADMIN)["error"], "connecting again fixes it")

# ---- a switched-off account is disconnected (its copies removed)
q("update users set is_active=0 where id=%s", TECH)
sync()
ok(not conn(TECH) and not copies("tech@example.com"), "switching off an Align account disconnects its calendar and removes Align's copies")
q("update users set is_active=1 where id=%s", TECH)
mock({"user": "tech@example.com"})
tech = login("tech@example.com", TECH_PASSWORD)
tech.get(B + "/account/calendar/connect")
ok(conn(TECH), "connected again once back")

# ---- disconnecting
r = tech.post(B + f"/integrations/email/calendars/{ADMIN}/disconnect", data={"_csrf": csrf(tech, "/account")}, allow_redirects=False)
ok(r.status_code in (302, 403) and conn(ADMIN), "a tech can't disconnect someone else")
p = st.get(B + "/integrations/email").text
ok("Staff Outlook calendars" in p and "tech@example.com" in p and "admin@example.com" in p, "admins see who is connected")
n_admin = len(copies())
r = st.post(B + "/account/calendar/disconnect", data={"_csrf": csrf(st, "/account"), "return": "/account"})
ok(not conn(ADMIN) and n_admin and not copies() and not q("select 1 from calendar_events where user_id=%s", ADMIN) and "Disconnected" in text(r.text),
   "disconnecting removes Align's copies from Outlook and Align's copy of the events")
st.post(B + f"/integrations/email/calendars/{TECH}/disconnect", data={"_csrf": csrf(st, "/integrations/email")})
ok(not conn(TECH) and not copies("tech@example.com"), "an admin disconnects someone")
mock({"user": "tech@example.com"})
tech.get(B + "/account/calendar/connect")
st.post(B + "/integrations/email/setup/forget", data={"_csrf": csrf(st, "/integrations/email")})
ok(not conn(TECH), "removing the email app disconnects everyone")
ok(q("select 1 from audit_log where action='calendar.connected'") and q("select 1 from audit_log where action='calendar.disconnected'"), "connections are audited")
done()
