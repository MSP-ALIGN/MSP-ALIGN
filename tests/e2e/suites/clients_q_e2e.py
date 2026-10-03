"""2.2.1 review (clients, contacts, devices, licenses, projects and the roadmap): a regression check for each fix, plus
role and client-isolation checks next to them. Test data is made up (Zz Q names, .example domains) and removed at the
end; nothing here changes the seeded clients except one contact made and removed again."""
from lib import *
import os, json, subprocess

STATE = "/tmp/itflow-mock-state.json"
PHPB = 'require "' + BOOTSTRAP + '"; '


def mock_set(**kv):
    st = json.load(open(STATE)) if os.path.exists(STATE) else {}
    st.update(kv)
    json.dump(st, open(STATE, "w"))


def post(s, path, data, page="/clients", **kw):
    return s.post(B + path, data={"_csrf": csrf(s, page), **data}, **kw)


def audit(action):
    r = q("select detail from audit_log where action=%s order by id desc limit 1", action)
    return r[0]["detail"] if r else ""


def phpj(code, mem=None):
    """PHP with the app loaded; returns stdout+stderr (a fatal error shows up in it)."""
    args = ["php"] + (["-d", f"memory_limit={mem}"] if mem else []) + ["-r", PHPB + code]
    r = subprocess.run(args, env=ENV, capture_output=True, text=True, timeout=120)
    return (r.stdout + r.stderr).strip()


# a clean start (a run that stopped half way leaves these behind)
q("delete from devices where display_name like 'Zz Q %%'")
q("delete from clients where name like 'Zz Q %%'")
q("delete from contacts where name like 'Zz Q %%'")

admin = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
TECH_ID = q("select id from users where email='tech@example.com'")[0]["id"]
VIEWER_ID = q("select id from users where email='viewer@example.com'")[0]["id"]

# ---- a client and a device of our own, added by a tech
r = post(tech, "/clients", {"name": "Zz Q Example Clinic", "meeting_cadence": "annual"})
MID = q("select id from clients where name='Zz Q Example Clinic'")[0]["id"]
ok(r.status_code == 200 and q("select source from clients where id=%s", MID)[0]["source"] == "manual", "a tech adds a client by hand")
DEV_PAGE = f"/clients/{MID}/devices"
r = post(tech, DEV_PAGE, {"display_name": "Zz Q Desk 01", "device_type": "Desktop", "purchase_date": "2021-03-15", "align_only": "1"}, DEV_PAGE)
dev = q("select id, psa_sync from devices where display_name='Zz Q Desk 01'")
ok(r.status_code == 200 and dev and dev[0]["psa_sync"] == 0, "a device added as Align only is saved with PSA sync off (2.2.1: in the same insert, so a sync can't create it first)")
DID = dev[0]["id"]
OTHER_DEV = q("select d.id from devices d where d.client_id is not null and d.client_id <> %s and d.removed_at is null order by d.id limit 1", MID)[0]["id"]

# ---- viewers read, never change; deleting a client is for admins only
vt = viewer.get(B + DEV_PAGE).text
ok("Zz Q Desk 01" in vt and 'id="bulk-replace"' not in vt and 'data-bs-target="#modal-device"' not in vt,
   "a viewer sees the device list without the bulk bar or Add device")
for path, data in [("/clients/bulk", {"ids[]": [MID], "action": "exclude"}), (f"/devices/{DID}", {"display_name": "x"}),
                   (f"/clients/{MID}/devices/projects", {"ids[]": [DID]}), ("/devices/bulk-type", {"ids[]": [DID], "device_type": "Laptop"}),
                   (f"/clients/{MID}/devices/replacement", {"ids[]": [DID]}), (f"/clients/{MID}/roadmap", {"title": "Zz Q Viewer"}),
                   (f"/clients/{MID}/licenses", {"name": "Zz Q Viewer"}), (f"/clients/{MID}/contacts", {"name": "Zz Q Viewer"}),
                   (f"/clients/{MID}", {"name": "Zz Q Viewer"}), (f"/devices/{DID}/delete", {})]:
    r = post(viewer, path, data)
    ok(r.status_code == 403, f"a viewer can't POST {path} ({r.status_code})")
ok(q("select count(*) n from clients where name='Zz Q Viewer'")[0]["n"] == 0 and q("select device_type from devices where id=%s", DID)[0]["device_type"] == "Desktop",
   "...and nothing changed")
viewer.get(B + "/devices?q=zzqaudit")
ok(audit("view.devices") == 'all clients (search "zzqaudit")', "viewing every client's devices is audited, like one client's list (2.2.1): " + audit("view.devices"))
r = post(tech, f"/clients/{MID}/delete", {"confirm_name": "Zz Q Example Clinic"})
ok(r.status_code == 403 and q("select id from clients where id=%s", MID), "a tech can't delete a client (admins only)")

# ---- the vCIO must be an active admin or tech (the portal shows the vCIO's name and email to the client)
post(tech, f"/clients/{MID}", {"name": "Zz Q Example Clinic", "meeting_cadence": "annual", "vcio_user_id": str(VIEWER_ID)})
ok(q("select vcio_user_id from clients where id=%s", MID)[0]["vcio_user_id"] is None, "a viewer's id posted as the vCIO isn't saved (2.2.1)")
post(tech, f"/clients/{MID}", {"name": "Zz Q Example Clinic", "meeting_cadence": "annual", "vcio_user_id": str(TECH_ID)})
ok(q("select vcio_user_id from clients where id=%s", MID)[0]["vcio_user_id"] == TECH_ID, "...a tech is")

# ---- bulk remove from planning: the audit entry names the clients, and an id ticked twice counts once
r = post(tech, "/clients/bulk", {"ids[]": [MID, MID], "action": "exclude", "reason": "Internal / test"})
d = audit("client.bulk_exclude")
ok(q("select planning_excluded from clients where id=%s", MID)[0]["planning_excluded"] == 1 and d.startswith("1 client(s): ") and "Zz Q Example Clinic" in d,
   "bulk remove from planning is audited with the clients' names (2.2.1): " + d)
post(tech, "/clients/bulk", {"ids[]": [MID], "action": "restore"})
ok(q("select planning_excluded from clients where id=%s", MID)[0]["planning_excluded"] == 0 and "Zz Q Example Clinic" in audit("client.bulk_restore"), "...and restored, by name")

# ---- the client list's search box with ?q[]= (an array)
t = tech.get(B + "/clients?q[]=x").text
ok('value="Array"' not in t and not errs(t), "?q[]= on the client list doesn't put \"Array\" in the search box or a warning on the page (2.2.1)")

# ---- device form: dates must be real days, amounts must fit their columns
DPAGE = f"/devices/{DID}"
base = {"display_name": "Zz Q Desk 01", "device_type": "Desktop"}
r = post(tech, DPAGE, {**base, "purchase_date": "2026-02-31"}, DPAGE)
ok(r.status_code == 200 and q("select purchase_date from device_overrides where device_id=%s", DID)[0]["purchase_date"] is None,
   "a purchase date of 2026-02-31 is left out instead of failing the save (2.2.1): %d" % r.status_code)
r = post(tech, DPAGE, {**base, "purchase_date": "0000-00-00"}, DPAGE)
ok(r.status_code == 200 and q("select purchase_date from device_overrides where device_id=%s", DID)[0]["purchase_date"] is None,
   "0000-00-00 isn't stored as an in-service date (it made the device 2,000 years old)")
r = post(tech, DPAGE, {**base, "purchase_date": "2021-03-15", "replacement_cost": "1000000000000"}, DPAGE)
row = q("select purchase_date, replacement_cost from device_overrides where device_id=%s", DID)[0]
ok(r.status_code == 200 and row["replacement_cost"] is None and str(row["purchase_date"]) == "2021-03-15",
   "a replacement cost too large for its column is left out instead of failing the save (2.2.1): %d" % r.status_code)
r = post(tech, DPAGE, {**base, "purchase_date": "2021-03-15", "os_build": "9" * 100}, DPAGE)
ok(r.status_code == 200 and len(q("select os_build from devices where id=%s", DID)[0]["os_build"] or "") == 60, "a 100-digit OS build is cut to its column (it failed the save)")
r = post(tech, DPAGE, {**base, "purchase_date": "2021-03-15", "replace_on": "2027-02-31"}, DPAGE)
ok(r.status_code == 200 and q("select replace_on from device_overrides where device_id=%s", DID)[0]["replace_on"] is None,
   "a replacement quarter given as 2027-02-31 isn't moved into March and saved")

# ---- bulk replacement: only this client's devices, each once
CH = php('echo array_keys(Align\\Roadmap\\Plan::choices(6))[2];').stdout.strip()
r = post(tech, f"/clients/{MID}/devices/replacement", {"ids[]": [OTHER_DEV], "replace_on": CH}, DEV_PAGE)
ok("Tick the devices first" in flash(r.text) and str((q("select replace_on from device_overrides where device_id=%s", OTHER_DEV) or [{"replace_on": None}])[0]["replace_on"]) != CH,
   "another client's device posted to this client's bulk replacement is ignored")
r = post(tech, f"/clients/{MID}/devices/replacement", {"ids[]": [DID, DID], "replace_on": CH}, DEV_PAGE)
ok("Zz Q Desk 01 will be replaced" in flash(r.text) and str(q("select replace_on from device_overrides where device_id=%s", DID)[0]["replace_on"]) == CH,
   "a device ticked twice is one device, by name (2.2.1; it said \"2 devices\"): " + flash(r.text)[:120])
post(tech, f"/clients/{MID}/devices/replacement", {"ids[]": [DID], "replace_on": ""}, DEV_PAGE)

# ---- bulk type (Unassigned hardware): each device once, audited by name
r = post(tech, "/devices/bulk-type", {"ids[]": [DID, DID], "device_type": "Desktop", "back": "/devices/unassigned"}, "/devices/unassigned")
d = audit("device.bulk_type")
ok(r.status_code == 200 and d == "1 devices set to Desktop (Zz Q Desk 01)", "bulk type counts a device ticked twice once and names it in the audit log (2.2.1): " + d)

# ---- projects from devices: another client's device is ignored; a cost too large is replaced by the budget
r = post(tech, f"/clients/{MID}/devices/projects", {"ids[]": [OTHER_DEV], "status": "approved", "back": DEV_PAGE}, DEV_PAGE)
ok("No project was made" in flash(r.text) and not q("select 1 from roadmap_item_devices rid join roadmap_items ri on ri.id = rid.roadmap_item_id where rid.device_id=%s and ri.client_id=%s", OTHER_DEV, MID),
   "another client's device can't be made into this client's project")
r = post(tech, f"/clients/{MID}/devices/projects", {"ids[]": [DID], "status": "approved", "cost": "10000000000000", "back": DEV_PAGE}, DEV_PAGE)
pj = q("select ri.id, ri.cost from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id where rid.device_id=%s", DID)
ok(r.status_code == 200 and pj and float(pj[0]["cost"]) < 1e9, "a project cost too large for its column falls back to the budgeted cost instead of failing (2.2.1): %d %s" % (r.status_code, pj))
for p in pj:
    q("delete from roadmap_items where id=%s", p["id"])

# ---- roadmap projects: amounts, real quarters, quarters outside the plan
RPAGE = f"/clients/{MID}/roadmap"
r = post(tech, RPAGE, {"title": "Zz Q Project", "status": "proposed", "cost": "10000000000000", "recurring_monthly": "50"}, RPAGE)
rp = q("select id, cost, recurring_monthly from roadmap_items where client_id=%s and title='Zz Q Project'", MID)
ok(r.status_code == 200 and rp and rp[0]["cost"] is None and float(rp[0]["recurring_monthly"]) == 50.0, "a project budget too large for its column is left out instead of failing the save (2.2.1)")
RID = rp[0]["id"]
want = php('echo Align\\Roadmap\\Plan::quarterFor("2031-05-17")["start"];').stdout.strip()
post(tech, f"{RPAGE}/{RID}", {"title": "Zz Q Project", "status": "proposed", "target_quarter": "2031-05-17"}, RPAGE)
ok(str(q("select target_quarter from roadmap_items where id=%s", RID)[0]["target_quarter"]) == want, f"a target beyond the plan is stored as its quarter's first day ({want}), not the day typed (2.2.1)")
r = tech.post(B + f"{RPAGE}/{RID}/move", data={"_csrf": csrf(tech, RPAGE), "target_quarter": "2031-13-45"}, headers={"Accept": "application/json"})
ok(r.status_code == 422 and str(q("select target_quarter from roadmap_items where id=%s", RID)[0]["target_quarter"]) == want, "moving a project to 2031-13-45 is refused (422), not a database error (2.2.1): %d" % r.status_code)
other_client = q("select id from clients where id <> %s and is_archived = 0 order by id limit 1", MID)[0]["id"]
post(tech, f"/clients/{other_client}/roadmap/{RID}", {"title": "Zz Q Hijacked", "status": "proposed"}, RPAGE)
ok(q("select title from roadmap_items where id=%s", RID)[0]["title"] == "Zz Q Project", "a project can't be changed through another client's URL")
prev = php('echo Align\\Roadmap\\Plan::quarterFor(date("Y-m-d", strtotime(Align\\Roadmap\\Plan::quarters()[0]["start"] . " -1 day")))["start"];').stdout.strip()
q("update roadmap_items set target_quarter=%s where id=%s", prev, RID)
t = tech.get(B + f"/projects/{RID}/form").text
ok(f'<option value="{prev}" selected>' in t, f"the project window keeps a quarter from before the plan ({prev}) chosen, so saving it doesn't unschedule the project (2.2.1)")
quarters = json.loads(phpj('echo json_encode([Align\\Roadmap\\Plan::quarterFor("0000-00-00"), Align\\Roadmap\\Plan::quarterFor("2027-02-31"), Align\\Roadmap\\Plan::quarterStart("2031-02-31"), Align\\Roadmap\\Plan::quarterFor("2027-02-28 10:00:00")["start"] ?? null]);'))
ok(quarters[:3] == [None, None, None] and quarters[3] is not None, "Plan::quarterFor/quarterStart refuse days that don't exist (2.2.1): " + str(quarters))

# ---- licenses: real dates, prices that fit
LPAGE = f"/clients/{MID}/licenses"
r = post(tech, LPAGE, {"name": "Zz Q Lic A", "expire_date": "2026-02-30"}, LPAGE)
la = q("select expire_date from licenses where client_id=%s and name='Zz Q Lic A'", MID)
ok(r.status_code == 200 and la and la[0]["expire_date"] is None, "a license expiry of 2026-02-30 is left out instead of failing the save (2.2.1): %d" % r.status_code)
r = post(tech, LPAGE, {"name": "Zz Q Lic B", "unit_price": "10000000000000"}, LPAGE)
lb = q("select unit_price from licenses where client_id=%s and name='Zz Q Lic B'", MID)
ok(r.status_code == 200 and lb and lb[0]["unit_price"] is None, "a price too large for its column is left out instead of failing the save (2.2.1)")
r = post(tech, LPAGE, {"name": "Zz Q Lic C", "contract_start": "2026-13-01", "contract_term_months": "12"}, LPAGE)
lc = q("select contract_start from licenses where client_id=%s and name='Zz Q Lic C'", MID)
ok(r.status_code == 200 and lc and lc[0]["contract_start"] is None, "a contract start of 2026-13-01 is left out instead of failing the save (2.2.1): %d" % r.status_code)

# ---- contacts pushed to the PSA say so in the audit log
_cq_was = q("select value from settings where name='psa_two_way'")
import atexit
atexit.register(lambda: setting("psa_two_way", _cq_was[0]["value"]) if _cq_was else q("delete from settings where name='psa_two_way'"))
setting("psa_two_way", "1")
mock_set(contact_archive_fail=False, contact_archive_missing=False, contact_archive_403=False)
CID = q("select id from clients where psa_id is not null and is_archived=0 order by id limit 1")[0]["id"]
CPAGE = f"/clients/{CID}/contacts"
r = post(tech, CPAGE, {"name": "Zz Q Contact Person", "email": "zz.q.contact@example.com", "title": "Bookkeeper"}, CPAGE)
k = q("select * from contacts where name='Zz Q Contact Person'")
ok(k and k[0]["source"] == "psa" and audit("contact.create").endswith("(also in ITFlow)"), "a contact created in the PSA too is audited as such (2.2.1): " + audit("contact.create"))
if k:
    post(tech, f"/contacts/{k[0]['id']}", {"name": "Zz Q Contact Person", "email": "zz.q.contact@example.com", "title": "Office manager"}, CPAGE)
    ok(audit("contact.update").endswith("(also in ITFlow)"), "...and so is an edit sent to the PSA: " + audit("contact.update"))
    post(tech, f"/contacts/{k[0]['id']}", {"action": "archive"}, CPAGE)  # archived in the mock PSA too, so a later sync doesn't bring it back
    q("delete from contacts where id=%s", k[0]["id"])

# ---- CSV import
def preview(kind, name, data):
    return tech.post(B + "/clients/import", data={"_csrf": csrf(tech, "/clients/import"), "kind": kind}, files={"file": (name, data, "text/csv")})

r = preview("clients", "unicode.txt", b"\xff\xfe" + "Name\tIndustry\r\nZz Q Utf16 Clinic\tDental\r\n".encode("utf-16-le"))
ok("Zz Q Utf16 Clinic" in r.text and "1 to add" in r.text, "a UTF-16 file (Excel's Unicode text) is read (2.2.1; no column was recognized): " + flash(r.text)[:120])
out = phpj('$t = tempnam(sys_get_temp_dir(), "cq"); file_put_contents($t, "Name,Notes\\n\\"Zz Q\\x00 Ctl\\nClinic\\",\\"line one\\nline two\\x07\\"\\n");'
           '[$h, $rows] = Align\\Import\\CsvImport::read($t); unlink($t); [$m] = Align\\Import\\CsvImport::mapHeaders($h, Align\\Import\\CsvImport::CLIENT_COLUMNS);'
           '$p = Align\\Import\\CsvImport::planClients($rows, $m)[0]; echo json_encode([$p["name"], $p["values"]["notes"] ?? null]);')
try:
    got = json.loads(out)
except ValueError:
    got = None
ok(got == ["Zz Q Ctl Clinic", "line one\nline two"], "control characters are removed from imported values; a name's line break becomes a space, a note keeps its lines (2.2.1): " + out[:200])
out = phpj('$t = tempnam(sys_get_temp_dir(), "cq"); file_put_contents($t, "Name\\n" . str_repeat(",", 3000000) . "\\n");'
           'try { Align\\Import\\CsvImport::read($t); echo "read"; } catch (RuntimeException $e) { echo $e->getMessage(); } unlink($t);', mem="128M")
ok("columns" in out and "Fatal" not in out, "a row of 3 million commas is refused with a message instead of using up PHP's memory (2.2.1): " + out[:160])

# a client deleted between checking a contacts file and importing it: the rest is still imported
post(tech, "/clients", {"name": "Zz Q Gone Clinic", "meeting_cadence": "annual"})
GONE = q("select id from clients where name='Zz Q Gone Clinic'")[0]["id"]
r = preview("contacts", "people.csv", b"Client,Name,Email\nZz Q Gone Clinic,Pat Example,pat.q@example.com\nZz Q Example Clinic,Lee Example,lee.q@example.com\n")
tok = re.search(r'name="token" value="([a-f0-9]{32})"', r.text)
ok(tok is not None and "2 to add" in r.text, "the contacts file is checked: 2 to add")
post(admin, f"/clients/{GONE}/delete", {"confirm_name": "Zz Q Gone Clinic"})
ok(not q("select id from clients where id=%s", GONE), "an admin deletes one of the two clients meanwhile")
if tok:
    r = tech.post(B + "/clients/import/run", data={"_csrf": csrf(tech, "/clients/import"), "token": tok.group(1)})
    ok(r.status_code == 200 and "Imported 1 new contact" in flash(r.text) and q("select id from contacts where client_id=%s and name='Lee Example'", MID),
       "the import skips the deleted client's contact and imports the rest, instead of failing as a whole (2.2.1): %d %s" % (r.status_code, flash(r.text)[:120]))

# ---- clean up: an admin deletes the test client (its devices, licenses, projects and contacts go with it)
r = post(admin, f"/clients/{MID}/delete", {"confirm_name": "Zz Q Example Clinic"})
ok("Deleted Zz Q Example Clinic" in flash(r.text) and not q("select id from devices where id=%s", DID) and not q("select id from roadmap_items where id=%s", RID),
   "an admin deletes the client by typing its name; its devices and projects go with it")
q("delete from clients where name like 'Zz Q %%'")
done()
