"""2.4.0 What changed since the last QBR: projects remember when they were finished (done_at, web and API); the client's
Since last QBR page compares with the newest completed review, an earlier one or a date (from dates, or exactly from
the review's snapshot); the QBR pack's "Since our last review" section; the client portal page and home card follow
the user's permissions (alignment stays staff-only); the REST API endpoint follows the key's scopes and client limit."""
import atexit, json, re, html as H
from datetime import date, datetime, timedelta
from lib import *

CID = 2
TAG = "ZzCH"
PW = "Quartz-Lantern-Field-31"
SEC = "KRUGKIDROVUWG2ZAMJZG653OEBTG66BA"
API = B + "/api/v1"


def ago(days, t="10:00:00"): return (datetime.now() - timedelta(days=days)).strftime("%Y-%m-%d ") + t
def day(days): return (date.today() - timedelta(days=days)).isoformat()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def phpo(code): r = php(code); return (r.stdout + r.stderr).strip()


def cleanup():
    q("delete from qbr_snapshots where meeting_id in (select id from meetings where title like %s)", TAG + "%")
    q("delete from meetings where title like %s", TAG + "%")
    q("delete from roadmap_items where title like %s", TAG + "%")
    q("delete from portal_users where email like %s", "%@changes.example")
    q("delete from api_keys where name like 'changes %%'"); q("delete from api_rate"); q("delete from api_ip_rate")
    q("delete from clients where name like %s", TAG + "%")
    q("delete from login_attempts")


cleanup()
atexit.register(cleanup)
st = login("admin@example.com", "LongPassword123!")

# ---- done_at: set when a project is marked done (web form), cleared when it is reopened; the migration back-fills
st.post(B + f"/clients/{CID}/roadmap", data={"_csrf": csrf(st, f"/clients/{CID}/roadmap"), "title": TAG + " web project", "category": "hardware", "status": "approved", "cost": "800"})
pid = q("select id from roadmap_items where title=%s", TAG + " web project")[0]["id"]
ok(q("select done_at from roadmap_items where id=%s", pid)[0]["done_at"] is None, "a new approved project has no done_at")
F = {"_csrf": csrf(st, f"/clients/{CID}/roadmap"), "title": TAG + " web project", "category": "hardware", "status": "done", "cost": "800"}
st.post(B + f"/clients/{CID}/roadmap/{pid}", data=F)
d1 = q("select done_at from roadmap_items where id=%s", pid)[0]["done_at"]
ok(d1 is not None and abs((datetime.now() - d1).total_seconds()) < 120, f"marking it done sets done_at ({d1})")
q("update roadmap_items set done_at=%s where id=%s", ago(12), pid)
st.post(B + f"/clients/{CID}/roadmap/{pid}", data={**F, "cost": "850"})
ok(str(q("select done_at from roadmap_items where id=%s", pid)[0]["done_at"]).startswith(day(12)), "saving a done project again keeps when it was finished")
st.post(B + f"/clients/{CID}/roadmap/{pid}", data={**F, "status": "scheduled"})
ok(q("select done_at from roadmap_items where id=%s", pid)[0]["done_at"] is None, "reopening it clears done_at")
st.post(B + f"/clients/{CID}/roadmap/{pid}", data=F)
q("update roadmap_items set done_at=%s where id=%s", ago(12), pid)
q("update roadmap_items set done_at=NULL where id=%s", pid)
php('(require "' + ROOT + '/db/migrations/058_project_done_at.php")();')
ok(q("select done_at from roadmap_items where id=%s", pid)[0]["done_at"] is not None, "the migration gives a done project without done_at its last-changed time")
q("update roadmap_items set done_at=%s, created_at=%s where id=%s", ago(12), ago(40), pid)

# ---- fixtures: a review 30 days ago (no snapshot), projects either side of it, a device whose warranty ran out since,
# one replaced by a finished project, one past its end of life since
q("insert into meetings (uid, client_id, title, type, status, starts_at, ends_at) values (%s, %s, %s, 'qbr', 'completed', %s, %s)",
  "zzch-old-" + str(int(time.time())), CID, TAG + " old review", ago(30), ago(30, "11:00:00"))
M_OLD = q("select id from meetings where title=%s", TAG + " old review")[0]["id"]
q("insert into roadmap_items (client_id, title, category, status, cost, priority, done_at, created_at) values (%s, %s, 'other', 'done', 500, 'medium', %s, %s)", CID, TAG + " finished before", ago(60), ago(90))
q("insert into roadmap_items (client_id, title, category, status, cost, priority, target_quarter, created_at) values (%s, %s, 'other', 'approved', 1200, 'medium', %s, %s)",
  CID, TAG + " slipped", phpo('echo Align\\Roadmap\\Plan::quarterStart(date("Y-m-d", strtotime("-100 days")));'), ago(120))
devs = q("select id from devices where client_id=%s and removed_at is null order by id", CID)
ok(len(devs) >= 3, f"client {CID} has devices to work with ({len(devs)})")
DW, DR, DE = devs[0]["id"], devs[1]["id"], devs[2]["id"]
keep_ovr = q("select * from device_overrides where device_id in (%s,%s,%s)", DW, DR, DE)
keep_dev = q("select id, removed_at, device_type from devices where id in (%s,%s,%s)", DW, DR, DE)
def restore():
    q("delete from device_overrides where device_id in (%s,%s,%s)", DW, DR, DE)
    for o in keep_ovr:
        cols = ", ".join(o.keys()); q(f"insert into device_overrides ({cols}) values ({', '.join(['%s'] * len(o))})", *o.values())
    for dv in keep_dev:
        q("update devices set removed_at=%s, device_type=%s where id=%s", dv["removed_at"], dv["device_type"], dv["id"])
atexit.register(restore)
for dv in (DW, DR, DE):
    q("insert into device_overrides (device_id, device_type) values (%s, 'Workstation') on duplicate key update device_type='Workstation', excluded=0", dv)
q("update device_overrides set warranty_end=%s, purchase_date=%s, lifespan_years=8 where device_id=%s", day(5), day(400), DW)
q("update device_overrides set purchase_date=%s, lifespan_years=8 where device_id=%s", day(400), DR)
q("update device_overrides set purchase_date=%s, lifespan_years=1, warranty_end=%s where device_id=%s", day(365 + 10), day(800), DE)
q("insert into roadmap_items (client_id, title, category, status, cost, priority, done_at, created_at) values (%s, %s, 'hardware', 'done', 1400, 'high', %s, %s)", CID, TAG + " replace pc", ago(4), ago(40))
rp = q("select id from roadmap_items where title=%s", TAG + " replace pc")[0]["id"]
q("insert into roadmap_item_devices (roadmap_item_id, device_id) values (%s, %s)", rp, DR)
q("update devices set removed_at=%s where id=%s", ago(3), DR)
names = {r["id"]: r["n"] for r in q("select id, coalesce(nullif(display_name,''), system_name) n from devices where id in (%s,%s,%s)", DW, DR, DE)}

# ---- the staff page, from dates
r = st.get(B + f"/clients/{CID}/changes")
t = text(r.text)
ok(r.status_code == 200 and not errs(r.text) and "Since last QBR" in t, f"the Since last QBR page opens ({r.status_code})")
ok(TAG + " web project" in t and TAG + " replace pc" in t and TAG + " finished before" not in t, "projects finished since the review are listed, earlier ones aren't")
ok(names[DR] in t and "Replaced" in t, "the device a finished project replaced is listed as replaced")
ok(names[DW] in t and "Warranty ran out" in t, "a warranty that ran out since is listed")
ok(names[DE] in t and "Reached end of life" in t, "a device past its end of life since is listed")
ok(TAG + " slipped" in t and "not done on time" in t, "an approved project planned for a quarter that has ended is flagged")
ok("worked out from their dates" in t and "(from dates)" in t, "without a snapshot it says the figures are worked out from dates")
ok('/clients/%d/report/qbr?since=m%d' % (CID, M_OLD) in r.text, "the QBR pack link starts from the same review")
ok('class="nav-link active"' in r.text and 'href="/clients/%d/changes"' % CID in r.text, "the client menu has Since last QBR")

# ---- a newer review with a snapshot: exact device list and project statuses
q("insert into meetings (uid, client_id, title, type, status, starts_at, ends_at) values (%s, %s, %s, 'qbr', 'completed', %s, %s)",
  "zzch-new-" + str(int(time.time())), CID, TAG + " new review", ago(8), ago(8, "11:00:00"))
M_NEW = q("select id from meetings where title=%s", TAG + " new review")[0]["id"]
q("insert into roadmap_items (client_id, title, category, status, cost, priority, created_at) values (%s, %s, 'other', 'approved', 300, 'medium', %s)", CID, TAG + " approved since", ago(20))
pa = q("select id from roadmap_items where title=%s", TAG + " approved since")[0]["id"]
snap = json.loads(phpo(f'echo json_encode(Align\\Alignment\\Snapshot::collect({CID}));'))
snap["device_ids"] = [i for i in snap["device_ids"] if i != DW] + [DR]   # DW "added" since, DR still there then
snap["projects"][str(pa)] = "proposed"
snap["projects"][str(rp)] = "approved"   # the replacement was still under way at that review
snap["devices"]["total"] = 99
q("insert into qbr_snapshots (client_id, meeting_id, taken_at, data) values (%s, %s, %s, %s)", CID, M_NEW, ago(8), json.dumps(snap))
r = st.get(B + f"/clients/{CID}/changes")
t = text(r.text)
ok(f'value="m{M_NEW}" selected' in r.text and "Since " + TAG + " new review" in t, "the newest review is the default starting point")
ok("99" in t and "worked out from their dates" not in t, "then-figures come from the snapshot")
ok("Approved (1): " + TAG + " approved since" in t, "a project proposed at the review and approved since is listed as approved")
ok("Added:" in t and names[DW] in t.split("Added:")[1][:400], "a device missing from the snapshot's list is listed as added")
ok(TAG + " web project" not in t and TAG + " replace pc" in t, "only projects finished after this review are listed")

# ---- a project a tech approves in the web app counts as approved when comparing with a date (status_changed_at)
q("insert into roadmap_items (client_id, title, category, status, cost, priority, created_at) values (%s, %s, 'other', 'proposed', 250, 'low', %s)", CID, TAG + " staff approved", ago(50))
sa = q("select id from roadmap_items where title=%s", TAG + " staff approved")[0]["id"]
php(f'Align\\Roadmap\\Roadmap::stampStatus({sa});')
st.post(B + f"/clients/{CID}/roadmap/{sa}", data={"_csrf": csrf(st, f"/clients/{CID}/roadmap"), "title": TAG + " staff approved", "category": "other", "status": "approved", "cost": "250"})
row = q("select status, status_changed_at, status_seen from roadmap_items where id=%s", sa)[0]
ok(row["status"] == "approved" and row["status_seen"] == "approved" and row["status_changed_at"] is not None, "approving in the web app records when the status changed")
t = text(st.get(B + f"/clients/{CID}/changes?since=date&date={day(20)}").text)
ok("Approved (" in t and TAG + " staff approved" in t.split("Approved (")[1][:300], "comparing with a date lists the project the tech approved")

# ---- the picker: an earlier review, a date, something invalid
t = text(st.get(B + f"/clients/{CID}/changes?since=m{M_OLD}").text)
ok(TAG + " web project" in t, "picking the earlier review compares with it")
t = text(st.get(B + f"/clients/{CID}/changes?since=date&date={day(100)}").text)
ok(TAG + " slipped" in t and ("Since " + phpo(f'echo fmt_date("{day(100)}");')) in t, "picking a date compares with that day")
r = st.get(B + f"/clients/{CID}/changes?since=m999999")
ok(r.status_code == 200 and "isn't one of this client's completed reviews" in text(r.text), "an unknown starting point falls back to the newest review with a note")
ok("isn't one" in text(st.get(B + f"/clients/{CID}/changes?since={date.today().isoformat()}").text), "today (not a past date) is refused the same way")
other = q("select id from meetings where client_id <> %s and status='completed' and type='qbr' limit 1", CID)
if other:
    ok("isn't one" in text(st.get(B + f"/clients/{CID}/changes?since=m{other[0]['id']}").text), "another client's review can't be used")

# ---- a client with no review yet
q("insert into clients (source, name, is_archived) values ('manual', %s, 0)", TAG + " Fresh Client")
FRESH = q("select id from clients where name=%s", TAG + " Fresh Client")[0]["id"]
t = text(st.get(B + f"/clients/{FRESH}/changes").text)
ok("No completed business review yet" in t, "a client never reviewed gets the explanation")
ok("Since our last review" not in st.get(B + f"/clients/{FRESH}/report/qbr").text, "...and no section in its QBR pack")

# ---- the QBR pack
r = st.get(B + f"/clients/{CID}/report/qbr")
t = text(r.text)
ok(r.status_code == 200 and not errs(r.text) and "Since our last review" in t and TAG + " replace pc" in t, "the QBR pack has the Since our last review section")
ok(t.index("Since our last review") < t.index("Assets & lifecycle") if "Assets & lifecycle" in t else True, "it comes right after the executive summary")
ok('name="s_changes"' in r.text, "it can be switched off from the toolbar")
ok("Since our last review" not in text(st.get(B + f"/clients/{CID}/report/qbr?s_changes=0").text), "switched off, it's left out")
t = text(st.get(B + f"/clients/{CID}/report/qbr?since=m{M_OLD}").text)
ok(TAG + " web project" in t, "?since= picks the starting point in the pack too")
t = text(st.get(B + f"/clients/{CID}/report/qbr?costs=0").text)
ok("Since our last review" in t and "$1,400" not in t.split("Since our last review")[2][:3000] if t.count("Since our last review") > 1 else "$1,400" not in t, "with costs off it shows no prices")

# ---- client portal: the page and the home card follow permissions; alignment never shows
def portal_user(email, **perms):
    p = {"can_roadmap": 0, "can_budget": 0, "can_devices": 0, "can_documents": 0, "can_approve": 0, "can_contacts": 0, "can_submit": 0, **perms}
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{CID},"email"=>"{email}","name"=>"Pat Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        + ",".join(f'"{k}"=>{v}' for k, v in p.items()) + f',"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(SEC)})
    q("delete from login_attempts")
    return s
pd = portal_user("dev@changes.example", can_devices=1)
r = pd.get(B + "/portal/changes")
t = text(r.text)
ok(r.status_code == 200 and not errs(r.text) and "Since your last review" in t and names[DW] in t, "a portal user with devices sees the device changes")
ok(TAG not in t and "Alignment" not in t and "(a project)" in t, "...but no project names (no roadmap permission) and never alignment: " + ", ".join(w for w in [TAG, "Alignment"] if w in t))
ok("Since your last review" in pd.get(B + "/portal").text, "the portal home has the Since your last review card")
time.sleep(1)
pr = portal_user("road@changes.example", can_roadmap=1)
t = text(pr.get(B + "/portal/changes").text)
ok(TAG + " replace pc" in t and names[DW] not in t and "$1,400" not in t, "a portal user with the roadmap only sees projects, without prices")
pn = portal_user("none@changes.example", can_documents=1)
t = text(pn.get(B + "/portal/changes").text)
ok("Once we've held a business review" in t and TAG not in t, "a portal user with none of those permissions sees nothing")
t = text(pr.get(B + "/portal/report/qbr").text)
ok("Since our last review" in t and names[DW] not in t.split("Since our last review")[-1][:4000], "the portal's QBR pack has the section with only the user's parts")

# ---- REST API: scopes decide the parts; ?since checked; client limits
def key(name, scopes, clients=None):
    return phpo(f'[$i,$t]=Align\\Api\\Keys::create({json.dumps(name)}, json_decode({json.dumps(json.dumps(scopes))},true), {("json_decode(" + json.dumps(json.dumps(clients)) + ",true)") if clients is not None else "null"}, null, 5000, null, 1); echo $t;')
q("insert into settings (name,value,is_secret) values ('api_enabled','1',0) on duplicate key update value='1'")
def call(k, path): return requests.get(API + path, headers={"Authorization": "Bearer " + k}, timeout=60)
k1 = key("changes clients", ["clients:read"])
k2 = key("changes all", ["clients:read", "devices:read", "projects:read", "alignment:read", "compliance:read", "licenses:read", "backups:read", "service:read"])
k3 = key("changes limited", ["clients:read", "devices:read"], [1])
r = call(k1, f"/clients/{CID}/changes")
d = r.json().get("data", {})
ok(r.status_code == 200 and d.get("since", {}).get("meeting_id") == M_NEW and d["since"]["snapshot"] is True and "devices" not in d and "projects" not in d, "clients:read alone: the starting point and headline only")
ok(TAG not in json.dumps(d.get("since")) + json.dumps(d.get("baselines")), "...without meeting titles (no meetings:read)")
k5 = key("changes meetings", ["clients:read", "meetings:read"])
ok(TAG + " new review" in call(k5, f"/clients/{CID}/changes").json()["data"]["since"]["label"], "a key with meetings:read gets the meeting's title")
r = call(k2, f"/clients/{CID}/changes?since=m{M_OLD}")
d = r.json().get("data", {})
ok(r.status_code == 200 and {"devices", "projects", "spend", "alignment", "compliance", "licenses", "tickets"} <= set(d) and any(p["title"] == TAG + " web project" for p in d["projects"]["done"]),
   "a key with every area gets every part (since the earlier review)")
ok(any(x["id"] == DR for x in d["devices"]["replaced"]) and d["since"]["snapshot"] is False, "the replaced device is in the API answer too")
dv = d["devices"]
ok(dv["then_estimated"] and dv["then"]["total"] == dv["now"]["total"] + dv["counts"]["removed"] - dv["counts"]["added"], "devices then = devices now + removed - added")
ok(d["licenses"]["then"].get("estimated") is True, "licenses then are worked out from dates too")
ok(call(k2, f"/clients/{CID}/changes?since=nope").status_code == 422 and call(k2, f"/clients/{CID}/changes?since={date.today().isoformat()}").status_code == 422, "a bad ?since is refused (422)")
ok(call(k3, f"/clients/{CID}/changes").status_code == 404, "a key limited to another client gets 404")
d = call(k2, f"/clients/{FRESH}/changes").json().get("data", {})
ok(d.get("since") is None and d.get("baselines") == [], "a client never reviewed: since is null")
spec = requests.get(API + "/openapi.json").json()
ok("/api/v1/clients/{id}/changes" in spec.get("paths", {}) and "Changes" in spec.get("components", {}).get("schemas", {}), "the OpenAPI spec documents it")
k4 = key("changes projects", ["projects:write"])
r = requests.patch(API + f"/projects/{pa}", headers={"Authorization": "Bearer " + k4, "Content-Type": "application/json"}, data=json.dumps({"status": "done"}), timeout=60)
ok(r.status_code == 200 and q("select done_at from roadmap_items where id=%s", pa)[0]["done_at"] is not None, "marking a project done through the API sets done_at")

# ---- audit
ok(q("select count(*) n from audit_log where action='view.changes' and detail like %s", f"#{CID} %")[0]["n"] >= 1 and q("select count(*) n from audit_log where action='view.portal_home' or action like 'view.portal%%'")[0]["n"] >= 1,
   "opening the staff and portal pages is audited")
done()
