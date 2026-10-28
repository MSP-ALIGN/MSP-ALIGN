"""2.27.1 Feedback is off until an admin turns it on (Settings → General → Feedback). While off: no button or form
in the top bar, /feedback and every Feedback action answer 404 for techs and viewers (nothing is saved), and admins
are sent to the setting. Turning it on (admins only, audited) shows the button to everyone; turning it off again
hides it and keeps the reports already sent."""
import re
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")


def switch(who, on):
    d = {"_csrf": csrf(who, "/"), "_tab": "general", "feedback_enabled_present": "1"}
    if on:
        d["feedback_enabled"] = "1"
    return who.post(B + "/settings", data=d)


def stored():
    r = q("select value from settings where name='feedback_enabled'")
    return r[0]["value"] if r else None


q("delete from settings where name='feedback_enabled'")
q("delete from feedback")
try:
    # ======== off by default
    for who, name in [(viewer, "viewer"), (tech, "tech"), (st, "admin")]:
        t = who.get(B + "/").text
        ok('id="feedback-menu"' not in t and 'id="modal-feedback"' not in t and not errs(t), f"off by default: no Feedback button for a {name}")
    for who, name in [(viewer, "viewer"), (tech, "tech")]:
        r = who.get(B + "/feedback", allow_redirects=False)
        ok(r.status_code == 404, f"/feedback is 404 for a {name} ({r.status_code})")
        r = who.post(B + "/feedback", data={"_csrf": csrf(who, "/"), "kind": "bug", "title": "F2271 sneaky", "page": "/"}, allow_redirects=False)
        ok(r.status_code in (302, 303) and not q("select 1 from feedback"), f"and a {name} can't send one")
        ok("Feedback has been turned off" in flash(who.get(B + r.headers["Location"]).text), "(told why, back on the page they were on)")
        t = who.get(B + "/help").text
        ok('href="/feedback"' not in t and 'id="guide-feedback"' in t and not errs(t), f"Help doesn't link to the Feedback list while it's off ({name})")
    r = st.get(B + "/feedback")
    ok(r.url.split("#")[0].endswith("/settings") and "Feedback is turned off" in flash(r.text), "an admin is sent to the setting: " + flash(r.text)[:60])
    t = st.get(B + "/settings").text
    ok('id="feedback"' in t and 'name="feedback_enabled"' in t and 'id="feedback-enabled" checked' not in t and 'id="feedback-enabled">' in t and not errs(t),
       "Settings → General has the Feedback switch, off")

    # ======== only admins turn it on
    tech.post(B + "/settings", data={"_csrf": csrf(tech, "/"), "_tab": "general", "feedback_enabled_present": "1", "feedback_enabled": "1"})
    ok(stored() in (None, "0"), "a tech can't turn it on")
    r = switch(st, True)
    ok(stored() == "1" and "Settings saved" in flash(r.text), "an admin turns it on")
    ok(q("select 1 from audit_log where action='settings.save' and detail like %s", "%feedback_enabled%"), "audited")
    ok('id="feedback-enabled" checked' in st.get(B + "/settings").text, "the switch shows on")
    for who, name in [(viewer, "viewer"), (tech, "tech"), (st, "admin")]:
        ok('id="feedback-menu"' in who.get(B + "/").text, f"now a {name} has the button")
    r = viewer.post(B + "/feedback", data={"_csrf": csrf(viewer, "/"), "kind": "feature", "title": "F2271 After switching on", "priority": "normal", "page": "/devices"})
    fid = q("select id from feedback where title='F2271 After switching on'")
    ok(fid and r.url.endswith("/devices"), "and can send a report")
    ok("F2271 After switching on" in viewer.get(B + "/feedback").text, "and see the list")
    r = switch(st, True)
    ok("No changes" in flash(r.text), "saving it on again changes nothing")

    # ======== off again: hidden, reports kept
    switch(st, False)
    ok(stored() == "0", "an admin turns it off")
    ok('id="feedback-menu"' not in viewer.get(B + "/").text and viewer.get(B + f"/feedback/{fid[0]['id']}", allow_redirects=False).status_code == 404,
       "the button and the report pages are gone again")
    r = viewer.get(B + f"/feedback/{fid[0]['id']}/screenshot", allow_redirects=False)
    ok(r.status_code == 404 and "There is nothing at this address" in r.text and "F2271" not in r.text, "screenshots too, with the usual Page not found")
    ok(q("select 1 from feedback where title='F2271 After switching on'"), "the report sent earlier is kept")
    # other settings on the General tab don't switch it off: a form without the switch leaves it alone
    switch(st, True)
    st.post(B + "/settings", data={"_csrf": csrf(st, "/"), "_tab": "planning", "plan_start": "current"})
    ok(stored() == "1", "saving another settings tab leaves it as it is")
finally:
    q("delete from feedback")
    q("delete from settings where name='feedback_enabled'")
done()
