"""2.21.0 Asset tags: techs set a device's tag on its form (viewers can't); a tag on two of a client's devices is saved
and flagged (warning, "duplicate" in the list, a badge on the device); the import (Devices & assets → Import asset
tags) matches serial numbers first, then names, shows every row before saving (unknown, ambiguous, blank, unchanged,
repeated rows) and sets the tags; tags show in the lists, global search, the CSV export, the portal, the asset report
and the closet's spares, and the API reads and sets them; migration 079 runs again."""
import io, json, re, requests
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
API = B + "/api/v1"
SEC = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP"

# A client with at least four RMM devices that have serials, no Align values yet and unique names and serials
devs = None
for c in q("select id, name from clients where is_archived=0 and planning_excluded=0 order by id"):
    rows = json.loads(php(f'echo json_encode(array_map(fn($d) => ["id" => (int) $d["id"], "name" => $d["name"], "display_name" => $d["display_name"], "serial" => $d["serial"], "source" => $d["source"], "type" => $d["device_type"], "owned" => Align\\Sync\\PsaAssetSync::owns($d)], (new Align\\Lifecycle\\Lifecycle())->devices({c["id"]})));').stdout or "[]")
    names = [r["display_name"] for r in rows]
    serials = [r["serial"] for r in rows]
    ok_rows = [r for r in rows if r["serial"] and r["display_name"] and not r["owned"] and names.count(r["display_name"]) == 1 and serials.count(r["serial"]) == 1
               and not q("select 1 from device_overrides where device_id=%s", r["id"])]
    if len(ok_rows) >= 4:
        C, CN, devs = c["id"], c["name"], ok_rows[:4]
        break
ok(devs is not None, "the seed has a client with four RMM devices to tag")
A, Bd, Cd, Dd = devs
ids = [d["id"] for d in devs]


def cleanup():
    q("delete from device_overrides where device_id in (%s, %s, %s, %s)", *ids)
    q("update devices set is_spare=0, spare_since=NULL where id=%s", Dd["id"])
    q("delete from portal_users where email='p221@tags.example'")
    q("delete from api_keys where name='tags221'")


cleanup()
import atexit
atexit.register(cleanup)


def save(sess, d, tag):
    return sess.post(B + f"/devices/{d['id']}", data={"_csrf": csrf(sess, f"/devices/{d['id']}"), "device_type": d["type"] or "", "asset_tag": tag})


# ---- one device at a time
r = save(tech, A, "  ACME-\t0042  ")
ok(q("select asset_tag from device_overrides where device_id=%s", A["id"])[0]["asset_tag"] == "ACME- 0042" and "Saved" in flash(r.text), "a tech sets a device's tag (spaces tidied)")
save(tech, A, "ACME-0042")
ok(save(viewer, A, "VIEWER").status_code == 403, "viewers can't")
t = tech.get(B + f"/devices/{A['id']}").text
ok("Asset tag" in t and "ACME-0042" in t and 'name="asset_tag"' in t and not errs(t), "the device page shows it and the form has the field")
r = save(tech, Bd, "acme-0042")
ok("is also on " + A["name"] in flash(r.text) and q("select asset_tag from device_overrides where device_id=%s", Bd["id"])[0]["asset_tag"] == "acme-0042", "the same tag (any case) on another device is saved with a warning")
t = tech.get(B + f"/clients/{C}/devices").text
ok("ACME-0042" in t and "acme-0042" in t and t.count(">duplicate</span>") >= 2 and not errs(t), "the list shows both tags flagged as duplicates")
ok("also on 1 other device" in tech.get(B + f"/devices/{A['id']}").text, "...and the device page says so")
save(tech, Bd, "")
ok(q("select asset_tag from device_overrides where device_id=%s", Bd["id"])[0]["asset_tag"] is None, "an empty field clears it")

# ---- search, export
ok(A["name"] in tech.get(B + "/search?q=ACME-0042").text, "the search box finds a device by its tag")
ok(A["name"] in tech.get(B + f"/clients/{C}/devices?q=ACME-0042").text and Cd["name"] not in tech.get(B + f"/clients/{C}/devices?q=ACME-0042").text, "...and so does the device list's search")
csvt = tech.get(B + f"/clients/{C}/export").text
ok("Asset tag" in csvt.splitlines()[0] and "ACME-0042" in csvt, "the CSV export has an Asset tag column")

# ---- the import
t = tech.get(B + f"/clients/import?kind=asset_tags&client={C}").text
ok('name="client_id"' in t and f'<option value="{C}" selected' in t and "Asset tag" in t and not errs(t), "Import asset tags opens with the client picked")
ok("Import asset tags" in tech.get(B + f"/clients/{C}/devices").text and "Import asset tags" not in viewer.get(B + f"/clients/{C}/devices").text, "linked from Devices & assets (techs)")
tmpl = tech.get(B + "/clients/import/template/asset_tags").text
import csv as _csv
ok(next(_csv.reader(io.StringIO(tmpl))) == ["Client", "Serial", "Device", "Asset tag"], "the template has the columns " + repr(tmpl[:120]))
csv = ("Serial,Device,Asset tag\n"
       f"{Cd['serial'].lower()[:2]} {Cd['serial'].lower()[2:]},,TAG-C\n"
       f",{Dd['display_name'].upper()},TAG-D\n"
       "NO-SUCH-SERIAL,,TAG-X\n"
       f"{A['serial']},,ACME-0042\n"
       f"{Bd['serial']},,\n"
       f",{Dd['display_name']},TAG-D2\n"
       f"{Bd['serial']},,TAG-C\n")
r = tech.post(B + "/clients/import", data={"_csrf": csrf(tech, "/clients/import?kind=asset_tags"), "kind": "asset_tags", "client_id": str(C)}, files={"file": ("tags.csv", csv.encode(), "text/csv")})
t = r.text
tok = re.search(r'name="token" value="([a-f0-9]{32})"', t)
ok(tok and "Set 3 asset tags" in t and "No device of" in t and "Already has this tag" in t and "No asset tag" in t and "Row 3 gives this device another tag (TAG-D)" in t
   and "also on " + Cd["name"] not in t and "same tag as row 2" in t and not errs(t), "the check lists every row before anything is saved " + str(re.findall(r'<td class="small text-muted">([^<]*)</td>', t)))
ok(q("select count(*) n from device_overrides where device_id in (%s, %s) and asset_tag is not null", Cd["id"], Dd["id"])[0]["n"] == 0, "...and nothing is saved yet")
r = tech.post(B + "/clients/import/run", data={"_csrf": csrf(tech, "/clients/import?kind=asset_tags"), "token": tok.group(1)})
tags = {x["device_id"]: x["asset_tag"] for x in q("select device_id, asset_tag from device_overrides where device_id in (%s, %s, %s, %s)", *ids)}
ok("Set 3 asset tags" in flash(r.text) and r.url.endswith(f"/clients/{C}/devices") and tags.get(Cd["id"]) == "TAG-C" and tags.get(Dd["id"]) == "TAG-D" and tags.get(Bd["id"]) == "TAG-C"
   and tags.get(A["id"]) == "ACME-0042", "importing sets them (serial first, then name; a repeated tag is still set) " + str(tags))
ok(q("select detail from audit_log where action='import.asset_tags' order by id desc limit 1")[0]["detail"].startswith("tags.csv: 0 added, 3 updated"), "the import is audited")
r = tech.post(B + "/clients/import", data={"_csrf": csrf(tech, "/clients/import?kind=asset_tags"), "kind": "asset_tags", "client_id": str(C)}, files={"file": ("x.csv", b"Serial,Name\nA,B\n", "text/csv")})
ok("No column for the asset tag" in flash(r.text) and "client=" + str(C) in r.url, "a file without a tag column is refused (the client stays picked)")
ok(viewer.post(B + "/clients/import", data={"_csrf": csrf(viewer, "/clients"), "kind": "asset_tags", "client_id": str(C)}, files={"file": ("x.csv", b"Serial,Asset tag\nA,B\n", "text/csv")}).status_code == 403,
   "viewers can't import")
r = tech.post(B + "/clients/import", data={"_csrf": csrf(tech, "/clients/import?kind=asset_tags"), "kind": "asset_tags", "client_id": str(C)},
              files={"file": ("x.csv", f"Client,Serial,Asset tag\nSomeone Else Ltd,{Cd['serial']},Q\n".encode(), "text/csv")})
ok("names another client" in r.text, "with a client picked, a row naming another client can't be imported")
r = tech.post(B + "/clients/import", data={"_csrf": csrf(tech, "/clients/import?kind=asset_tags"), "kind": "asset_tags"}, files={"file": ("x.csv", b"Serial,Asset tag\nA,B\n", "text/csv")})
ok("Pick the client" in flash(r.text), "...and one with no client picked or named")
r = tech.post(B + "/clients/import", data={"_csrf": csrf(tech, "/clients/import?kind=asset_tags"), "kind": "asset_tags"},
              files={"file": ("x.csv", f"Client,Serial,Asset tag\n\"{CN}\",{Cd['serial']},TAG-C\nNobody Inc,{Cd['serial']},Z\n".encode(), "text/csv")})
ok("Already has this tag" in r.text and "No client named" in r.text, "a Client column works for several clients")

# ---- the portal, the asset report, the closet's spares, the API
php(f'Align\\DB::insert("portal_users", ["client_id"=>{C},"email"=>"p221@tags.example","name"=>"Pat","password_hash"=>password_hash("Tags221-Pass-1", PASSWORD_DEFAULT),"is_active"=>1,"can_devices"=>1,"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
ps = requests.Session()
ps.post(B + "/portal/login", data={"_csrf": csrf(ps, "/portal/login"), "email": "p221@tags.example", "password": "Tags221-Pass-1"})
ps.post(B + "/portal/login/2fa", data={"_csrf": csrf(ps, "/portal/login/2fa"), "code": totp(SEC)})
ok("TAG-D" in ps.get(B + "/portal/devices").text, "the portal's Devices shows tags")
ok("Tag ACME-0042" in tech.get(B + f"/clients/{C}/report/assets?virtual=1").text, "so does the asset report")
q("update devices set is_spare=1, spare_since=NOW() where id=%s", Dd["id"])
ok("Tag TAG-D" in tech.get(B + f"/clients/{C}/closet").text, "...and a spare in the tech closet")
k = php('[$i,$t]=Align\\Api\\Keys::create("tags221", Align\\Api\\Keys::allScopes(), null, null, 5000, null, 1); echo $t;').stdout.strip()
hdr = {"Authorization": "Bearer " + k, "Content-Type": "application/json"}
g = requests.get(API + f"/devices/{A['id']}", headers=hdr).json()
ok((g.get("data") or g).get("asset_tag") == "ACME-0042", "the API reads the tag")
r = requests.patch(API + f"/devices/{A['id']}", headers=hdr, data=json.dumps({"asset_tag": "API-7"}))
ok(r.status_code == 200 and q("select asset_tag from device_overrides where device_id=%s", A["id"])[0]["asset_tag"] == "API-7", "...and sets it")
s_ = requests.get(API + "/devices?search=api-7", headers=hdr).json()
ok(any(x["id"] == A["id"] for x in (s_.get("data") or [])), "the API's search finds it by tag")
requests.patch(API + f"/devices/{A['id']}", headers=hdr, data=json.dumps({"notes": "x"}))
ok(q("select asset_tag from device_overrides where device_id=%s", A["id"])[0]["asset_tag"] == "API-7", "changing another field leaves the tag")
requests.patch(API + f"/devices/{A['id']}", headers=hdr, data=json.dumps({"asset_tag": ""}))
ok(q("select asset_tag from device_overrides where device_id=%s", A["id"])[0]["asset_tag"] is None, "an empty tag from the API clears it")
r = php('(require "' + ROOT + '/db/migrations/079_asset_tags.php")(); echo "ok";')
ok(r.stdout.strip() == "ok", "migration 079 can run again " + r.stderr[-200:])
done()
