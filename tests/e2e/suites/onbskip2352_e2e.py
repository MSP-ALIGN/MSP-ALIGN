"""2.35.2 Onboarding -> New clients: an existing client's new contract isn't "Signed, ready to onboard". A client already
in MSP Align 30 days or more before its contract was made is left out on its own; anything still listed has a
"Not needed" button (techs and admins, audited) that takes it off for that contract, with an Undo under "Not needed"."""
from lib import *

a = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")


def ready(t):
    """The "Signed, ready to onboard" card only (names also appear in the Start onboarding client picker)."""
    return t.split("Signed, ready to onboard</h3>")[1].split("</ul>")[0] if "Signed, ready to onboard</h3>" in t else ""


def cleanup():
    q("delete from contracts where title like 'OS2352 %%'")
    q("delete from clients where name like 'OS2352 %%'")


cleanup()
try:
    q("insert into clients (name, created_at) values ('OS2352 Long-time Client', now() - interval 400 day), ('OS2352 Brand New Client', now() - interval 2 day),"
      " ('OS2352 Second New Client', now() - interval 1 day)")
    ids = {r["name"]: r["id"] for r in q("select id, name from clients where name like 'OS2352 %%'")}
    for name, title in [("OS2352 Long-time Client", "OS2352 Renewal 2026"), ("OS2352 Brand New Client", "OS2352 New agreement"), ("OS2352 Second New Client", "OS2352 Another agreement")]:
        q("insert into contracts (source, status, title, client_id, completed_at, created_at) values ('built', 'completed', %s, %s, now() - interval 1 hour, now() - interval 1 day)", title, ids[name])
    kid = {r["title"]: r["id"] for r in q("select id, title from contracts where title like 'OS2352 %%'")}

    t = tech.get(B + "/onboarding").text
    ok("OS2352 Brand New Client" in ready(t) and "OS2352 Second New Client" in ready(t) and not errs(t), "new clients' signed contracts are ready to onboard")
    ok("OS2352 Long-time Client" not in ready(t), "a client already in MSP Align well before its contract (a renewal) isn't listed")
    ok(f'/contracts/{kid["OS2352 New agreement"]}/onboarding/skip' in t and "Not needed" in t, "each row has a Not needed button")
    nn = t.split('id="onb-skipped"')[1].split("</details>")[0] if 'id="onb-skipped"' in t else ""
    ok("OS2352 Long-time Client" in nn and "an existing client" in nn and f'/clients/{ids["OS2352 Long-time Client"]}/onboarding">Onboard anyway' in nn,
       "...it is listed under Not needed as an existing client, with Onboard anyway")

    r = tech.post(B + f'/contracts/{kid["OS2352 New agreement"]}/onboarding/skip', data={"_csrf": csrf(tech, "/onboarding")})
    ok("won't be listed" in flash(r.text), "Not needed: " + flash(r.text)[:80])
    ok("OS2352 Brand New Client" not in ready(r.text) and "OS2352 Second New Client" in ready(r.text), "...it leaves Signed, ready to onboard; the others stay")
    ok("Not needed (2)" in r.text and f'/contracts/{kid["OS2352 New agreement"]}/onboarding/unskip' in r.text, "...and is listed under Not needed, with Undo")
    ok(q("select onboarding_skipped_by from contracts where id=%s", kid["OS2352 New agreement"])[0]["onboarding_skipped_by"] == q("select id from users where email='tech@example.com'")[0]["id"],
       "who marked it is kept")
    ok(q("select 1 from audit_log where action='onboarding.skipped' and detail like %s", "%OS2352 New agreement%"), "audited")

    r = viewer.post(B + f'/contracts/{kid["OS2352 Another agreement"]}/onboarding/skip', data={"_csrf": csrf(viewer, "/")}, allow_redirects=False)
    ok(r.status_code == 403 and q("select onboarding_skipped_at from contracts where id=%s", kid["OS2352 Another agreement"])[0]["onboarding_skipped_at"] is None, "viewers can't")

    r = a.post(B + f'/contracts/{kid["OS2352 New agreement"]}/onboarding/unskip', data={"_csrf": csrf(a, "/onboarding")})
    ok("is back" in flash(r.text) and "OS2352 Brand New Client" in ready(r.text) and "Not needed (1)" in r.text, "Undo puts it back")
    ok(q("select 1 from audit_log where action='onboarding.unskipped' and detail like %s", "%OS2352 New agreement%"), "undo is audited")

    # the signing certificate's trail isn't touched
    ok(not q("select 1 from contract_events where contract_id=%s", kid["OS2352 New agreement"]), "nothing is added to the contract's signing trail")
finally:
    cleanup()
done()
