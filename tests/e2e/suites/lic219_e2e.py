"""2.19.0 Who has which license, assigned by hand (Adobe, AutoCAD, any license that isn't from Microsoft 365): techs
assign several contacts at once and remove them, a contact of another client, an archived contact, a duplicate, a
retired license and viewers are refused; Licensing shows who has each license (and more assigned than bought), the
contacts list and a contact's window show their licenses and the devices they last signed in to (matched on the RMM's
last user), and the portal shows names under Licensing and licenses under Your team; audited; migration 077 runs
again. Microsoft 365 assignments are tested in m365_clients_e2e."""
import json, re, requests
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
C = q("select id from clients where is_archived=0 and planning_excluded=0 order by id limit 1")[0]["id"]
OTHER = q("select id from clients where id<>%s order by id limit 1", C)[0]["id"]
SEC = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP"


def cleanup():
    q("delete from licenses where name like 'Zz219%%'")
    q("delete from contacts where email like '%%@lic219.example'")
    q("delete from portal_users where email='p219@lic219.example'")


cleanup()
import atexit
atexit.register(cleanup)
q("insert into licenses (client_id, source, name, license_type, seats, category) values (%s, 'manual', 'Zz219 AutoCAD LT', 'user', 2, 'lob')", C)
q("insert into licenses (client_id, source, name, license_type, seats, category, retired_at) values (%s, 'manual', 'Zz219 Old Suite', 'user', 1, 'lob', NOW())", C)
L = q("select id from licenses where name='Zz219 AutoCAD LT'")[0]["id"]
OLD = q("select id from licenses where name='Zz219 Old Suite'")[0]["id"]
for n, e, cid in [("Avery Zed", "avery.zed@lic219.example", C), ("Blake Zed", "bzed@lic219.example", C), ("Casey Zed", "casey@lic219.example", C), ("Dana Elsewhere", "dana@lic219.example", OTHER), ("Ellis Gone", "ellis@lic219.example", C)]:
    q("insert into contacts (client_id, source, name, email) values (%s, 'manual', %s, %s)", cid, n, e)
K = {r["name"].split()[0]: r["id"] for r in q("select id, name from contacts where email like '%%@lic219.example'")}
q("update contacts set archived_at=NOW() where id=%s", K["Ellis"])

# ---- the people page and assigning
r = tech.get(B + f"/licenses/{L}/people")
ok(r.status_code == 200 and "Nobody is assigned yet" in r.text and 'name="contact_ids[]"' in r.text and "Avery Zed" in r.text and "Ellis Gone" not in r.text and "Dana Elsewhere" not in r.text and not errs(r.text),
   "techs see the license's people and the client's current contacts to assign")
tok = lambda s, p: csrf(s, p)
r = tech.post(B + f"/licenses/{L}/people", data=[("_csrf", tok(tech, f"/licenses/{L}/people")), ("action", "add"), ("contact_ids[]", str(K["Avery"])), ("contact_ids[]", str(K["Blake"]))])
rows = q("select contact_id, source, created_by from license_assignments where license_id=%s order by contact_id", L)
ok("Assigned Zz219 AutoCAD LT to Avery Zed, Blake Zed" in flash(r.text) and [x["contact_id"] for x in rows] == sorted([K["Avery"], K["Blake"]]) and all(x["source"] == "manual" for x in rows),
   "several contacts at once")
ok(q("select detail from audit_log where action='license.assign' order by id desc limit 1")[0]["detail"].endswith("to Avery Zed, Blake Zed"), "audited")
for cid, why, label in [(K["Avery"], "already has this license", "a duplicate"), (K["Dana"], "current contacts", "another client's contact"), (K["Ellis"], "current contacts", "an archived contact")]:
    r = tech.post(B + f"/licenses/{L}/people", data={"_csrf": tok(tech, f"/licenses/{L}/people"), "action": "add", "contact_ids[]": str(cid)})
    ok(why in flash(r.text), f"{label} is refused")
r = tech.post(B + f"/licenses/{OLD}/people", data={"_csrf": tok(tech, f"/licenses/{OLD}/people"), "action": "add", "contact_ids[]": str(K["Avery"])})
ok("retired" in flash(r.text), "a retired license can't be assigned")
ok(viewer.get(B + f"/licenses/{L}/people").status_code == 200 and 'name="contact_ids[]"' not in viewer.get(B + f"/licenses/{L}/people").text, "viewers see the people but can't change them")
ok(viewer.post(B + f"/licenses/{L}/people", data={"_csrf": tok(viewer, f"/licenses/{L}/people"), "action": "add", "contact_ids[]": str(K["Casey"])}).status_code == 403, "...and can't post")
tech.post(B + f"/licenses/{L}/people", data={"_csrf": tok(tech, f"/licenses/{L}/people"), "action": "add", "contact_ids[]": str(K["Casey"])})

# ---- Licensing shows who has it, and more people than seats
t = st.get(B + f"/clients/{C}/licenses").text
row = t.split("Zz219 AutoCAD LT")[1][:1500]
ok("Avery Zed, Blake Zed, Casey Zed" in row and "3 assigned, 2 bought" in row and f'href="/licenses/{L}/people"' in t and not errs(t), "Licensing lists the people and flags more assigned than bought")

# ---- removing
other = q("insert into licenses (client_id, source, name, license_type, category) values (%s, 'manual', 'Zz219 Other', 'user', 'lob')", C)
L2 = q("select id from licenses where name='Zz219 Other'")[0]["id"]
tech.post(B + f"/licenses/{L2}/people", data={"_csrf": tok(tech, f"/licenses/{L2}/people"), "action": "add", "contact_ids[]": str(K["Avery"])})
a2 = q("select id from license_assignments where license_id=%s", L2)[0]["id"]
r = tech.post(B + f"/licenses/{L}/people", data={"_csrf": tok(tech, f"/licenses/{L}/people"), "action": "remove", "assignment": str(a2)})
ok("Only people added by hand" in flash(r.text) and q("select 1 from license_assignments where id=%s", a2), "another license's assignment can't be removed from this page")
ca = q("select id from license_assignments where license_id=%s and contact_id=%s", L, K["Casey"])[0]["id"]
r = tech.post(B + f"/licenses/{L}/people", data={"_csrf": tok(tech, f"/licenses/{L}/people"), "action": "remove", "assignment": str(ca)})
ok("Removed Zz219 AutoCAD LT from Casey Zed" in flash(r.text) and not q("select 1 from license_assignments where id=%s", ca), "removing one")

# ---- contacts: licenses and the devices they last signed in to
did = php(f'$d = (new Align\\Lifecycle\\Lifecycle())->devices({C}); echo $d ? (int) $d[0]["id"] : 0;').stdout.strip()
dev = q("select id, last_user from devices where id=%s", int(did)) if did.isdigit() and int(did) else []
if dev:
    orig = dev[0]["last_user"]
    q("update devices set last_user=%s where id=%s", "CORP\\AveryZed", dev[0]["id"])
ok(dev, "the client has a device to match (the seed)")
t = tech.get(B + f"/clients/{C}/contacts").text
row = t.split("Avery Zed")[1][:2500]
ok("Licenses and devices" in t and "Zz219 AutoCAD LT" in row and "Zz219 Other" in row and not errs(t), "the contacts list shows each person's licenses")
ok("Licenses and devices" in viewer.get(B + f"/clients/{C}/contacts").text, "...to every staff role")
m = tech.get(B + f"/contacts/{K['Avery']}/form").text
ok("Zz219 AutoCAD LT" in m and "Devices last signed in to" in m, "a contact's window lists their licenses and devices")
devs = json.loads(php(f'echo json_encode(array_map(fn($ds) => array_column($ds, "name"), Align\\Licensing\\Assignments::devicesByContact(Align\\Contacts\\Contacts::load({C}), Align\\Lifecycle\\Lifecycle::lastUsers({C}))));').stdout or "{}")
if dev:
    ok(len(devs.get(str(K["Avery"]), [])) >= 1, "a device whose last user is CORP\\AveryZed belongs to Avery Zed " + str(devs.get(str(K["Avery"]))))
    q("update devices set last_user=%s where id=%s", orig, dev[0]["id"])
m = json.loads(php('echo json_encode(array_map(fn($ds) => array_column($ds, "name"), Align\\Licensing\\Assignments::devicesByContact('
                   '[["id" => 1, "name" => "John Smith", "email" => "jsmith@x.example"], ["id" => 2, "name" => "Al", "email" => null]], '
                   '[["name" => "A", "last_user" => "DOM\\\\jsmith"], ["name" => "B", "last_user" => "JohnSmith"], ["name" => "C", "last_user" => "john.smith@x.example"], ["name" => "D", "last_user" => "al"], ["name" => "E", "last_user" => ""]])));').stdout)
ok(m == {"1": ["A", "B", "C"]}, "devices match on the name or the email's first part, without domains; short names don't match " + str(m))

# ---- the portal: names under Licensing, licenses under Your team
php(f'Align\\DB::insert("portal_users", ["client_id"=>{C},"email"=>"p219@lic219.example","name"=>"Pat","password_hash"=>password_hash("Lic219-Pass-123", PASSWORD_DEFAULT),"is_active"=>1,"can_roadmap"=>1,"can_budget"=>1,"can_devices"=>1,"can_documents"=>1,"can_contacts"=>1,"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
ps = requests.Session()
ps.post(B + "/portal/login", data={"_csrf": csrf(ps, "/portal/login"), "email": "p219@lic219.example", "password": "Lic219-Pass-123"})
ps.post(B + "/portal/login/2fa", data={"_csrf": csrf(ps, "/portal/login/2fa"), "code": totp(SEC)})
t = ps.get(B + "/portal/licensing").text
ok("Avery Zed, Blake Zed" in t.split("Zz219 AutoCAD LT")[1][:1200] and "avery.zed@lic219.example" not in t and not errs(t), "the portal shows who has each license (names, no emails)")
t = ps.get(B + "/portal/contacts").text
ok("<th>Licenses</th>" in t and "Zz219 AutoCAD LT" in t.split("Avery Zed")[1][:1500], "Your team lists each person's licenses")
q("update portal_users set can_budget=0 where email='p219@lic219.example'")
t = ps.get(B + "/portal/contacts").text
ok("<th>Licenses</th>" not in t and "Zz219 AutoCAD LT" not in t, "...only for people who may see Licensing")
q("update portal_users set can_budget=1 where email='p219@lic219.example'")
q("update portal_users set client_id=%s where email='p219@lic219.example'", OTHER)
t = ps.get(B + "/portal/licensing").text
ok("Avery Zed" not in t and "Zz219" not in t, "another client's portal never sees them")
q("update portal_users set client_id=%s where email='p219@lic219.example'", C)

# ---- deleting the license or the contact removes the assignments; the migration again
q("delete from licenses where id=%s", L2)
ok(not q("select 1 from license_assignments where license_id=%s", L2), "deleting a license removes its assignments")
q("delete from contacts where id=%s", K["Blake"])
ok(not q("select 1 from license_assignments where contact_id=%s", K["Blake"]), "...and deleting a contact removes theirs")
r = php('(require "' + ROOT + '/db/migrations/077_license_assignments.php")(); echo "ok";')
ok(r.stdout.strip() == "ok", "migration 077 can run again " + r.stderr[-200:])
done()
