"""1.44.1: contacts sync both ways with ITFlow, including archiving and restoring, and every page says which way it syncs."""
import os
from lib import *

STATE = "/tmp/itflow-mock-state.json"
def mock():
    return json.load(open(STATE)) if os.path.exists(STATE) else {}
def mock_set(**kv):
    st = mock(); st.update(kv); json.dump(st, open(STATE, "w"))
def poll():
    return subprocess.run(["php", ALIGN, "psa:poll"], env=ENV, capture_output=True, text=True)
def post(s, path, data, page):
    return s.post(B + path, data={"_csrf": csrf(s, page), **data})

setting("psa_two_way", "1")
mock_set(contact_archive_fail=False, contact_archive_missing=False, contact_archive_403=False)
tech = login("tech@example.com", TECH_PASSWORD)
admin = login("admin@example.com", "LongPassword123!")
CID = q("select id from clients where psa_id is not null and is_archived=0 order by id limit 1")[0]["id"]
PAGE = f"/clients/{CID}/contacts"

# ---- the page and the Integrations switch say what syncs where
t = tech.get(B + PAGE).text
ok(not errs(t) and "Syncs both ways with ITFlow" in t and "archived or restored" in t, "linked client: contacts sync both ways (archiving too)")
t = admin.get(B + "/integrations/itflow").text
ok("Two-way sync (devices and contacts)" in t and "Contacts added, edited, archived or restored in Align are made in ITFlow too" in t, "the switch is named for devices and contacts")

# ---- created and edited here -> made in ITFlow
q("delete from contacts where name like 'Zz Sync %%'")
r = post(tech, PAGE, {"name": "Zz Sync Person", "email": "zz.sync@example.com", "title": "Bookkeeper"}, PAGE)
k = q("select * from contacts where name='Zz Sync Person'")[0]
made = [c for c in mock().get("contacts_created", []) if c.get("contact_name") == "Zz Sync Person"]
ok("Created in ITFlow too" in flash(r.text) and k["source"] == "psa" and made and str(made[-1]["contact_id"]) == str(k["psa_id"]), "a contact added in Align is created in ITFlow and linked to it")
KID = str(k["psa_id"])
r = post(tech, f"/contacts/{k['id']}", {"name": "Zz Sync Person", "email": "zz.sync@example.com", "title": "Office manager"}, PAGE)
ok(mock().get("contact_updates", {}).get(KID, {}).get("contact_title") == "Office manager", "an edit goes to ITFlow")
ok(poll().returncode == 0 and q("select title from contacts where id=%s", k["id"])[0]["title"] == "Office manager", "the next ITFlow check agrees")

# ---- archived here -> archived in ITFlow; restored here -> restored there
t = tech.get(B + f"/contacts/{k['id']}/form").text
ok("in Align and ITFlow?" in t and "client portal login" in t, "the archive button says it archives in ITFlow too, and what ITFlow does")
r = post(tech, f"/contacts/{k['id']}", {"action": "archive"}, PAGE)
row = q("select archived_at, archived_reason from contacts where id=%s", k["id"])[0]
ok("in Align and ITFlow" in flash(r.text) and mock().get("contact_archived", {}).get(KID) and row["archived_at"] and row["archived_reason"] == "psa", "archiving here archives it in ITFlow")
poll()
ok(q("select archived_at from contacts where id=%s", k["id"])[0]["archived_at"] is not None, "...and the next check keeps it archived")
r = post(tech, f"/contacts/{k['id']}", {"action": "restore"}, PAGE)
ok("active in ITFlow again" in flash(r.text) and mock()["contact_archived"][KID] is None and q("select archived_at from contacts where id=%s", k["id"])[0]["archived_at"] is None, "restoring here restores it in ITFlow")
poll()
ok(q("select archived_at from contacts where id=%s", k["id"])[0]["archived_at"] is None, "...and the next check keeps it active")

# ---- ITFlow refuses: archived in Align only, and it stays that way
mock_set(contact_archive_fail=True)
r = post(tech, f"/contacts/{k['id']}", {"action": "archive"}, PAGE)
row = q("select archived_at, archived_reason from contacts where id=%s", k["id"])[0]
ok("in Align only; ITFlow refused it" in flash(r.text) and row["archived_reason"] == "align" and not mock()["contact_archived"][KID], "ITFlow refuses: archived in Align only, with the reason")
poll()
ok(q("select archived_at from contacts where id=%s", k["id"])[0]["archived_at"] is not None, "...and the next check doesn't bring it back")
r = post(tech, f"/contacts/{k['id']}", {"action": "restore"}, PAGE)
ok(q("select archived_at from contacts where id=%s", k["id"])[0]["archived_at"] is None, "restoring a contact archived only in Align needs nothing from ITFlow")
mock_set(contact_archive_fail=False)

# ---- archived in ITFlow -> archived here; restoring here restores it there
st = mock(); st.setdefault("contact_archived", {})[KID] = "2026-09-30 10:00:00"; json.dump(st, open(STATE, "w"))
poll()
ok(q("select archived_reason from contacts where id=%s", k["id"])[0]["archived_reason"] == "psa", "archived in ITFlow: archived here on the next check")
r = post(tech, f"/contacts/{k['id']}", {"action": "restore"}, PAGE)
ok(mock()["contact_archived"][KID] is None and q("select archived_at from contacts where id=%s", k["id"])[0]["archived_at"] is None, "restoring it here restores it in ITFlow")

# ---- an older ITFlow without the archive endpoint
mock_set(contact_archive_missing=True)
r = post(tech, f"/contacts/{k['id']}", {"action": "archive"}, PAGE)
ok("this version of ITFlow can't archive contacts from other apps" in flash(r.text), "an ITFlow without archiving from other apps: says so")
mock_set(contact_archive_missing=False)
post(tech, f"/contacts/{k['id']}", {"action": "restore"}, PAGE)
mock_set(contact_archive_403=True)
r = post(tech, f"/contacts/{k['id']}", {"action": "archive"}, PAGE)
ok("ITFlow: API key does not have write access to module_client" in flash(r.text), "ITFlow's own reason is shown when it refuses (403)")
mock_set(contact_archive_403=False)
post(tech, f"/contacts/{k['id']}", {"action": "restore"}, PAGE)
# restoring a contact archived in ITFlow warns that it re-enables their portal login there
st = mock(); st["contact_archived"][KID] = "2026-09-30 09:00:00"; json.dump(st, open(STATE, "w"))
poll()
t = tech.get(B + f"/contacts/{k['id']}/form").text
ok("re-enables their client portal login" in t, "the Restore button warns it re-enables the portal login in ITFlow")
post(tech, f"/contacts/{k['id']}", {"action": "restore"}, PAGE)

# ---- two-way off: read only, and the page says so
setting("psa_two_way", "0")
t = tech.get(B + PAGE).text
ok("Two-way sync is off" in t and "Syncs both ways" not in t, "two-way off: the page says contacts come in only")
t = admin.get(B + PAGE).text
ok('href="/integrations/itflow"' in t, "admins get a link to turn it on")
n_before = len(mock().get("contact_archived", {}))
r = post(tech, f"/contacts/{k['id']}", {"action": "archive"}, PAGE)
ok("stays archived in Align even though it is still active in ITFlow" in flash(r.text) and not mock()["contact_archived"][KID], "two-way off: archiving stays in Align")
post(tech, f"/contacts/{k['id']}", {"action": "restore"}, PAGE)
st = mock(); st["contact_archived"][KID] = "2026-09-30 11:00:00"; json.dump(st, open(STATE, "w"))
poll()
r = post(tech, f"/contacts/{k['id']}", {"action": "restore"}, PAGE)
ok("couldn't be restored there" in flash(r.text) and q("select archived_at from contacts where id=%s", k["id"])[0]["archived_at"] is not None, "two-way off: a contact archived in ITFlow stays archived, with the way to fix it")
setting("psa_two_way", "1")
st = mock(); st["contact_archived"][KID] = None; json.dump(st, open(STATE, "w"))
poll()

# ---- a client not linked to ITFlow
q("delete from clients where name='Zz Unlinked Contacts'")
cur = db.cursor(); cur.execute("insert into clients (name, source, is_archived, planning_excluded) values ('Zz Unlinked Contacts','manual',0,0)"); UC = cur.lastrowid
t = tech.get(B + f"/clients/{UC}/contacts").text
ok("isn't linked to ITFlow, so contacts added here stay in Align" in t and 'href="/mapping?show=missing"' in t, "unlinked client: the page says contacts stay in Align, with a link to Client mapping")
r = post(tech, f"/clients/{UC}/contacts", {"name": "Zz Sync Local"}, f"/clients/{UC}/contacts")
ok("ITFlow" not in flash(r.text) and q("select source from contacts where name='Zz Sync Local'")[0]["source"] == "manual", "...and a contact added there stays in Align")

# ---- viewers can't archive
v = login("viewer@example.com", "ViewerPassword123!")
r = v.post(B + f"/contacts/{k['id']}", data={"_csrf": csrf(v, PAGE), "action": "archive"})
ok(r.status_code == 403 and q("select archived_at from contacts where id=%s", k["id"])[0]["archived_at"] is None, "viewers can't archive")

# leave ITFlow's copy archived so later syncs don't bring it back
st = mock(); st.setdefault("contact_archived", {})[KID] = "2026-09-30 12:00:00"; json.dump(st, open(STATE, "w"))
q("delete from contacts where name like 'Zz Sync %%'")
q("delete from clients where id=%s", UC)
done()
