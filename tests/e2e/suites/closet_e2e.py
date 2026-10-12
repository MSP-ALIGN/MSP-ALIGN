"""2.15.0 Tech closet. Staff: the MSP's offices (admins) and its own stock by office; a client's closet; counted stock and
tracked items (validated; viewers read only); quantities changed with a note; checkouts to a client's contact or a
typed name, with due dates (overdue on the To do list with low stock); not more than is on the shelf; another
client's contact refused; the MSP's loaners to any client; check in (back on the shelf) and used up; retire refused
while some are out; every change in the item's history and the audit log. Spare devices: marked from the device page
or the client's closet (a plain button in the device page's header group), kept in plans and reports but left out of
stale flags and in-use measures, checked out and in, back in use. The portal: Tech closet only with its permission (nav
and page), check-out, check-in and requests only with the action permission, only the client's own items (no serials or
staff notes shown), an MSP loaner returned through the MSP; with the update permission, adding items and changing their
details, counts and removal (own client only; serials, notes, kind and office stay with staff; staff notes and names
stay out of the history shown), and portal permission saving."""
import re, json, requests, html as H
from datetime import date, timedelta
from lib import *


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def item_id(name): return q("select id from closet_items where name=%s order by id desc limit 1", name)[0]["id"]
def qty(name): return q("select quantity from closet_items where name=%s order by id desc limit 1", name)[0]["quantity"]


PW = "PortalPassword123!"
st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
C1 = q("select id from clients where name='Cedar Ridge Family Dental, Inc.'")[0]["id"]
C2 = q("select id from clients where id<>%s and is_archived=0 order by id limit 1", C1)[0]["id"]
CT1 = q("select id, name from contacts where client_id=%s and archived_at is null order by id limit 1", C1)[0]
CT2 = q("select id from contacts where client_id=%s and archived_at is null order by id limit 1", C2)[0]["id"]
# devices as Align sees them (most RMM devices belong to a client through its linked organization, not a client_id)
def first_device(cid, cls): return json.loads(php(f'foreach ((new Align\\Lifecycle\\Lifecycle())->devices({cid}) as $d) if (in_array($d["device_class"], {json.dumps(cls)}) && !$d["is_virtual"] && $d["status"] !== "excluded") {{ echo $d["id"]; break; }}').stdout or "0")
DEV = first_device(C1, ["desktop", "laptop"])
DEV2 = first_device(C2, ["desktop", "laptop", "server"])
yesterday = (date.today() - timedelta(days=1)).isoformat()
nextweek = (date.today() + timedelta(days=7)).isoformat()


def cleanup():
    q("delete from closet_checkouts"); q("delete from closet_events"); q("delete from closet_items"); q("delete from msp_offices")
    q("update devices set is_spare=0, spare_since=NULL where is_spare=1")
    q("delete from portal_users where email like '%%@closet.example'")
    q("delete from settings where name='spares_in_seats'")


cleanup()

# ---- pages
for s, who in [(st, "admin"), (tech, "tech"), (viewer, "viewer")]:
    for p in ["/closet", f"/clients/{C1}/closet"]:
        r = s.get(B + p); ok(r.status_code == 200 and not errs(r.text) and "Tech closet" in r.text, f"{who} opens {p}")
ok('href="/closet"' in st.get(B + "/").text and f'href="/clients/{C1}/closet"' in st.get(B + f"/clients/{C1}").text, "Tech closet is in the main menu and the client menu")

# ---- offices (admins)
r = tech.post(B + "/closet/offices", data={"_csrf": csrf(tech, "/closet"), "name": "Tech Office"}, allow_redirects=False)
ok(r.status_code in (302, 403) and not q("select 1 from msp_offices"), "techs can't add offices")
st.post(B + "/closet/offices", data={"_csrf": csrf(st, "/closet"), "name": "Main office", "address": "1 Main St", "action": "save"})
st.post(B + "/closet/offices", data={"_csrf": csrf(st, "/closet"), "name": "Branch office", "action": "save"})
O1 = q("select id from msp_offices where name='Main office'")[0]["id"]
O2 = q("select id from msp_offices where name='Branch office'")[0]["id"]
ok("Branch office" in st.get(B + "/closet").text, "admins add offices; they show as tabs")

# ---- items
r = st.post(B + "/closet/items", data={"_csrf": csrf(st, "/closet"), "name": "", "kind": "stock", "quantity": "3"})
ok("Give the item a name" in flash(r.text) and not q("select 1 from closet_items"), "an item needs a name")
r = viewer.post(B + "/closet/items", data={"_csrf": csrf(viewer, "/closet"), "name": "X", "kind": "stock"}, allow_redirects=False)
ok(r.status_code in (302, 403) and not q("select 1 from closet_items"), "viewers can't add items")
st.post(B + "/closet/items", data={"_csrf": csrf(st, "/closet"), "name": "Loaner laptop", "kind": "tracked", "category": "computers", "serial": "LOAN123", "office_id": O2, "quantity": "5"})
st.post(B + "/closet/items", data={"_csrf": csrf(st, "/closet"), "name": "HDMI cable", "kind": "stock", "category": "cables", "quantity": "10", "office_id": O1})
r = st.post(B + "/closet/items", data={"_csrf": csrf(st, "/closet"), "name": "Bad office", "kind": "stock", "quantity": "1", "office_id": 99999})
ok("Choose one of your offices" in flash(r.text), "an unknown office is refused")
ok(qty("Loaner laptop") == 1 and q("select office_id from closet_items where name='Loaner laptop'")[0]["office_id"] == O2, "a tracked item is one thing (quantity 1), in its office")
t = st.get(B + f"/closet?office={O1}").text
ok("HDMI cable" in t and "Loaner laptop" not in t, "an office tab lists only its stock")
tech.post(B + f"/clients/{C1}/closet/items", data={"_csrf": csrf(tech, f"/clients/{C1}/closet"), "name": "USB headset", "kind": "stock", "category": "audio", "quantity": "3",
    "min_quantity": "2", "location": "Shelf 2", "notes": "STAFF-ONLY-NOTE"})
tech.post(B + f"/clients/{C1}/closet/items", data={"_csrf": csrf(tech, f"/clients/{C1}/closet"), "name": "Spare monitor", "kind": "tracked", "category": "monitors", "serial": "MONSERIAL9"})
HS, MON, HDMI, LOAN = item_id("USB headset"), item_id("Spare monitor"), item_id("HDMI cable"), item_id("Loaner laptop")
ok(q("select client_id from closet_items where id=%s", HS)[0]["client_id"] == C1 and "USB headset" in st.get(B + f"/clients/{C1}/closet").text, "techs add to a client's closet")
r = st.get(B + f"/closet/items/{HS}"); ok(r.status_code == 200 and not errs(r.text) and "STAFF-ONLY-NOTE" in r.text, "the item page shows its details")

# ---- quantities and checkouts
st.post(B + f"/closet/items/{HS}", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "action": "adjust", "quantity": "4", "note": "Delivery"})
ok(qty("USB headset") == 4 and q("select quantity_change from closet_events where item_id=%s and action='adjusted'", HS)[0]["quantity_change"] == 1, "a quantity change is saved with its difference")
r = st.post(B + f"/closet/items/{HS}/checkout", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "quantity": "9", "contact_id": CT1["id"]})
ok("Only 4 on the shelf" in flash(r.text) and qty("USB headset") == 4, "can't check out more than is on the shelf")
r = st.post(B + f"/closet/items/{HS}/checkout", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "quantity": "1", "contact_id": CT2})
ok("Choose one of the client's contacts" in flash(r.text) and qty("USB headset") == 4, "another client's contact is refused")
r = st.post(B + f"/closet/items/{HS}/checkout", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "quantity": "3", "contact_id": CT1["id"], "due_on": yesterday, "note": "Loaner"})
co = q("select * from closet_checkouts where item_id=%s and in_at is null", HS)
ok("Checked out" in flash(r.text) and qty("USB headset") == 1 and co and co[0]["person"] == CT1["name"] and co[0]["quantity"] == 3, "checked out 3 to a contact: 1 left on the shelf")
t = text(tech.get(B + "/todo").text)
ok("overdue at Cedar Ridge" in t and "running low at Cedar Ridge" in t, "the overdue checkout and the low stock are on the To do list")
r = st.post(B + f"/closet/items/{HS}", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "action": "retire"})
ok("still checked out" in flash(r.text) and q("select retired_at from closet_items where id=%s", HS)[0]["retired_at"] is None, "an item with some out can't be retired")
st.post(B + f"/closet/checkouts/{co[0]['id']}/checkin", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "outcome": "used", "back": f"/closet/items/{HS}"})
ok(qty("USB headset") == 1 and q("select outcome from closet_checkouts where id=%s", co[0]["id"])[0]["outcome"] == "used", "used up: nothing goes back on the shelf")
r = st.post(B + f"/closet/items/{HDMI}/checkout", data={"_csrf": csrf(st, f"/closet/items/{HDMI}"), "quantity": "2", "borrower_client_id": C1, "person": "Front desk", "due_on": nextweek})
ok(qty("HDMI cable") == 8 and q("select borrower_client_id from closet_checkouts where item_id=%s", HDMI)[0]["borrower_client_id"] == C1, "the MSP's stock lends to a client's person")
ok("HDMI cable" in st.get(B + f"/clients/{C1}/closet").text, "the loaner shows on the client's closet page")
r = st.post(B + f"/closet/items/{LOAN}/checkout", data={"_csrf": csrf(st, f"/closet/items/{LOAN}"), "person": "Tech Tom"})
ok(qty("Loaner laptop") == 0, "a tracked item checks out to a typed name (one of the team)")
r = st.post(B + f"/closet/items/{LOAN}/checkout", data={"_csrf": csrf(st, f"/closet/items/{LOAN}"), "person": "Someone"})
ok("None are on the shelf" in flash(r.text), "it can't go out twice")
lco = q("select id from closet_checkouts where item_id=%s and in_at is null", LOAN)[0]["id"]
st.post(B + f"/closet/checkouts/{lco}/checkin", data={"_csrf": csrf(st, "/closet"), "outcome": "returned", "back": "/closet"})
ok(qty("Loaner laptop") == 1, "checked in: back on the shelf")
hist = [r["action"] for r in q("select action from closet_events where item_id=%s order by id", HS)]
ok(hist == ["added", "adjusted", "checked_out", "used"], "the item's history: %s" % hist)
t = st.get(B + f"/closet/items/{HS}").text
ok("Quantity changed" in t and "Used up" in t and "Delivery" in t, "the history shows on the item page")
ok(q("select count(*) n from audit_log where action like 'closet.%%'")[0]["n"] >= 8, "closet changes are audited")
# counted stock with 0 on the shelf but 5 out can't become "one item" (the return would put 5 on its shelf)
st.post(B + f"/closet/items/{HS}", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "action": "adjust", "quantity": "5", "note": ""})
st.post(B + f"/closet/items/{HS}/checkout", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "quantity": "5", "person": "Training room"})
r = st.post(B + f"/closet/items/{HS}", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "action": "save", "name": "USB headset", "kind": "tracked", "category": "audio"})
ok("can't become one item" in flash(r.text) and q("select kind from closet_items where id=%s", HS)[0]["kind"] == "stock", "stock with several out can't become one item")
tco = q("select id from closet_checkouts where item_id=%s and in_at is null", HS)[0]["id"]
st.post(B + f"/closet/checkouts/{tco}/checkin", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "outcome": "returned", "back": f"/closet/items/{HS}"})
r = st.post(B + f"/closet/checkouts/{tco}/checkin", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "outcome": "returned", "back": f"/closet/items/{HS}"})
ok("already checked in" in flash(r.text) and qty("USB headset") == 5, "checking in twice changes nothing")
r = st.post(B + f"/closet/checkouts/{tco}/checkin", data={"_csrf": csrf(st, "/closet"), "outcome": "returned", "back": "//evil.example/x"}, allow_redirects=False)
ok(r.headers.get("Location", "").startswith("/") and not r.headers.get("Location", "").startswith("//"), "the page to go back to must be on this site")
r = viewer.post(B + f"/closet/items/{HS}", data={"_csrf": csrf(viewer, f"/closet/items/{HS}"), "action": "adjust", "quantity": "99"}, allow_redirects=False)
ok(r.status_code in (302, 403) and qty("USB headset") == 5, "viewers can't change quantities")
# one item can't be used up (it comes back, or is retired)
st.post(B + f"/closet/items/{LOAN}/checkout", data={"_csrf": csrf(st, f"/closet/items/{LOAN}"), "person": "Tech Tom"})
lco = q("select id from closet_checkouts where item_id=%s and in_at is null", LOAN)[0]["id"]
r = st.post(B + f"/closet/checkouts/{lco}/checkin", data={"_csrf": csrf(st, "/closet"), "outcome": "used", "back": "/closet"})
ok("One item comes back" in flash(r.text) and q("select in_at from closet_checkouts where id=%s", lco)[0]["in_at"] is None, "one item can't be used up")
st.post(B + f"/closet/checkouts/{lco}/checkin", data={"_csrf": csrf(st, "/closet"), "outcome": "returned", "back": "/closet"})
# an item in an office retired since keeps that office when edited
st.post(B + "/closet/offices", data={"_csrf": csrf(st, "/closet"), "id": O2, "action": "retire"})
st.post(B + f"/closet/items/{LOAN}", data={"_csrf": csrf(st, f"/closet/items/{LOAN}"), "action": "save", "name": "Loaner laptop", "kind": "tracked", "category": "computers", "office_id": O2})
ok(q("select office_id from closet_items where id=%s", LOAN)[0]["office_id"] == O2, "an item in a retired office keeps it when edited")
st.post(B + "/closet/offices", data={"_csrf": csrf(st, "/closet"), "id": O2, "action": "restore"})

# ---- spare devices: still planned and reported; not stale; out of in-use measures
SNAP = (f'$l = new Align\\Lifecycle\\Lifecycle(); $d = $l->devices(null, false, {DEV})[0]; $all = $l->devices({C1});'
        f' echo json_encode([$d["status"], $d["stale"], $d["replace_by"], Align\\Lifecycle\\Lifecycle::inactive($d), Align\\Lifecycle\\Lifecycle::placement($d)["in_plan"],'
        f' Align\\Lifecycle\\Lifecycle::notInUse($d), Align\\Contracts\\Contracts::autoCounts({C1})["workstations"] ?? null, Align\\Lifecycle\\Lifecycle::summarize($all)["total"],'
        f' count(array_filter(Align\\Reports\\ReportData::devices({C1}), fn($x) => $x["id"] === {DEV}))]);')
q("update devices set last_contact = NOW() - INTERVAL 400 DAY where id=%s", DEV)  # long switched off
before = json.loads(php(SNAP).stdout)
t = st.get(B + f"/devices/{DEV}").text
grp = [g for g in re.findall(r'<div class="btn-group btn-group-sm">(.*?)</div>', t, re.S) if "modal-device-edit" in g]
ok(grp and 'form="device-spare-form"' in grp[0] and "<form" not in grp[0] and re.search(r'<form[^>]*/devices/%d/spare"[^>]*id="device-spare-form"' % DEV, t),
   "Mark spare is a plain button in the header group (its form sits outside it, so the buttons line up)")
r = st.post(B + f"/devices/{DEV}/spare", data={"_csrf": csrf(st, f"/devices/{DEV}"), "spare": "1"})
ok(q("select is_spare from devices where id=%s", DEV)[0]["is_spare"] == 1 and "Marked spare" in flash(r.text), "a device is marked spare from its page")
j = json.loads(php(SNAP).stdout)
ok(j[0] == before[0] and j[2] == before[2] and not j[3] and j[4] == before[4] and j[7] == before[7] and j[8] == 1,
   "a spare stays in the replacement plan, summaries and reports, with its lifecycle status: %s vs %s" % (j[:5] + j[7:], before[:5] + before[7:]))
ok(before[1] and not j[1], "a spare isn't flagged stale")
ok(j[5] and not before[5] and j[6] == before[6], "a spare is out of in-use measures, but still a contract seat by default: %s -> %s" % (before[6], j[6]))
# Settings → Planning: spares out of contract seat counts (each MSP bills them its own way)
F = {"_csrf": csrf(st, "/settings/planning"), "_tab": "planning", "spares_in_seats_present": "1"}
st.post(B + "/settings", data=F)
ok(q("select value from settings where name='spares_in_seats'")[0]["value"] == "0" and json.loads(php(SNAP).stdout)[6] == before[6] - 1, "with the setting off, a spare isn't a contract seat")
ok('id="spares-in-seats"' in st.get(B + "/settings/planning").text, "the setting is on the Planning tab")
st.post(B + "/settings", data={**F, "_csrf": csrf(st, "/settings/planning"), "spares_in_seats": "1"})
ok(q("select value from settings where name='spares_in_seats'")[0]["value"] == "1" and json.loads(php(SNAP).stdout)[6] == before[6], "and back on")
t = st.get(B + f"/devices/{DEV}").text
ok("Spare</span>" in t and "the tech closet" in t and not errs(t), "the device page says it's a spare")
ok(st.get(B + f"/clients/{C1}/closet").text.count(f'href="/devices/{DEV}"') >= 1, "it's listed in the client's closet")
st.post(B + f"/devices/{DEV}/checkout", data={"_csrf": csrf(st, f"/devices/{DEV}"), "contact_id": CT1["id"], "due_on": nextweek, "back": f"/devices/{DEV}"})
dco = q("select id from closet_checkouts where device_id=%s and in_at is null", DEV)
ok(dco and f"Checked out to <b>{H.escape(CT1['name'])}</b>" in st.get(B + f"/devices/{DEV}").text, "a spare device is checked out to a contact")
st.post(B + f"/closet/checkouts/{dco[0]['id']}/checkin", data={"_csrf": csrf(st, f"/devices/{DEV}"), "outcome": "returned", "back": f"/devices/{DEV}"})
ok(q("select is_spare from devices where id=%s", DEV)[0]["is_spare"] == 1 and not q("select 1 from closet_checkouts where device_id=%s and in_at is null", DEV), "returned: still a spare, on the shelf")
r = st.post(B + f"/clients/{C1}/closet/spare", data={"_csrf": csrf(st, f"/clients/{C1}/closet"), "device_id": DEV2})
ok("Choose one of this client's devices" in flash(r.text) and q("select is_spare from devices where id=%s", DEV2)[0]["is_spare"] == 0, "another client's device can't be marked from this closet")
# "Back in use" while it's checked out: the person keeps it, and the history says so
st.post(B + f"/devices/{DEV}/checkout", data={"_csrf": csrf(st, f"/devices/{DEV}"), "person": "Dana Temp", "back": f"/devices/{DEV}"})
st.post(B + f"/devices/{DEV}/spare", data={"_csrf": csrf(st, f"/devices/{DEV}"), "spare": "0"})
ok(not q("select 1 from closet_checkouts where device_id=%s and in_at is null", DEV) and q("select detail from closet_events where device_id=%s and action='used'", DEV)[0]["detail"].startswith("Kept by Dana Temp"),
   "back in use while checked out: kept by that person, in the history")
ok(q("select is_spare from devices where id=%s", DEV)[0]["is_spare"] == 0 and json.loads(php(SNAP).stdout)[1] and not json.loads(php(SNAP).stdout)[5],
   "back in use: stale again (it's switched off) and counted in in-use measures")
st.post(B + f"/clients/{C1}/closet/spare", data={"_csrf": csrf(st, f"/clients/{C1}/closet"), "device_id": DEV})
ok(q("select is_spare from devices where id=%s", DEV)[0]["is_spare"] == 1, "marked spare from the client's closet page")

# ---- portal
def mkportal(email, secret, closet, checkout, edit=0):
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{C1},"email"=>"{email}","name"=>"Pat Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        f'"can_roadmap"=>1,"can_budget"=>0,"can_devices"=>1,"can_documents"=>1,"can_approve"=>0,"can_contacts"=>0,"can_submit"=>0,"can_closet"=>{closet},"can_checkout"=>{checkout},"can_closet_edit"=>{edit},'
        f'"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{secret}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
def plogin(email, secret):
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(secret)})
    return s
mkportal("none@closet.example", "MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U", 0, 0)
mkportal("look@closet.example", "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ", 1, 0)
mkportal("use@closet.example", "KRSXG5CTMVRXEZLUKN2XAZLSKNSWG4TF", 1, 1)
pn = plogin("none@closet.example", "MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U")
ok(pn.get(B + "/portal/closet").status_code == 403 and 'href="/portal/closet"' not in pn.get(B + "/portal").text, "without the permission: no Tech closet page or menu item")
pl = plogin("look@closet.example", "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ")
t = pl.get(B + "/portal/closet").text
ok('id="portal-closet"' in t and "USB headset" in t and "Spare monitor" in t and 'href="/portal/closet"' in t and not errs(t), "with Tech closet: the client's items")
ok("STAFF-ONLY-NOTE" not in t and "MONSERIAL9" not in t and "Loaner laptop" not in t and "/closet/items" not in t, "...without staff notes, serials, other closets or staff links")
ok("/portal/closet/request" not in t and 'action="/portal/closet/items' not in t, "view-only users get no forms")
r = pl.post(B + f"/portal/closet/items/{MON}/checkout", data={"_csrf": csrf(pl, "/portal/closet"), "contact_id": "me"})
ok(r.status_code == 403 and qty("Spare monitor") == 1, "a view-only user can't check out")
pu = plogin("use@closet.example", "KRSXG5CTMVRXEZLUKN2XAZLSKNSWG4TF")
t = pu.get(B + "/portal/closet").text
ok("/portal/closet/request" in t and "loaner from us" in t and "Return it to us" in t, "the checkout user gets forms; the MSP's loaner shows as one")
r = pu.post(B + f"/portal/closet/items/{MON}/checkout", data={"_csrf": csrf(pu, "/portal/closet"), "contact_id": CT1["id"]})
pco = q("select * from closet_checkouts where item_id=%s and in_at is null", MON)
ok("Checked out Spare monitor" in flash(r.text) and pco and pco[0]["out_portal_user_id"] and qty("Spare monitor") == 0, "checked out from the portal to a contact")
r = pu.post(B + f"/portal/closet/items/{HDMI}/checkout", data={"_csrf": csrf(pu, "/portal/closet"), "contact_id": "me"})
ok("isn't in your closet" in flash(r.text) and qty("HDMI cable") == 8, "the MSP's stock can't be checked out from the portal")
r = pu.post(B + f"/portal/closet/devices/{DEV}/checkout", data={"_csrf": csrf(pu, "/portal/closet"), "contact_id": "me", "due_on": nextweek})
dme = q("select person, out_portal_user_id from closet_checkouts where device_id=%s and in_at is null", DEV)
ok(dme and dme[0]["person"] == "Pat Portal" and dme[0]["out_portal_user_id"], "a spare device checked out to 'me' (no contact with their email: their name)")
r = pu.post(B + f"/portal/closet/items/{HS}/checkout", data={"_csrf": csrf(pu, "/portal/closet"), "contact_id": CT2})
ok("Choose one of the client's contacts" in flash(r.text) and qty("USB headset") == 5, "the portal can't check out to another client's contact")
r = pu.post(B + f"/portal/closet/devices/{DEV2}/checkout", data={"_csrf": csrf(pu, "/portal/closet"), "contact_id": "me"})
ok("isn't one of your spare devices" in flash(r.text) and not q("select 1 from closet_checkouts where device_id=%s", DEV2), "the portal can't check out another client's device")
r = pu.post(B + f"/portal/closet/items/{HS}/checkout", data={"_csrf": csrf(pu, "/portal/closet"), "contact_id": "me", "due_on": yesterday})
ok("in the past" in flash(r.text) and qty("USB headset") == 5, "the portal refuses a due date in the past")
hco = q("select id from closet_checkouts where item_id=%s and in_at is null", HDMI)[0]["id"]
r = pu.post(B + f"/portal/closet/checkouts/{hco}/checkin", data={"_csrf": csrf(pu, "/portal/closet"), "outcome": "returned"})
ok("isn't checked out from your closet" in flash(r.text) and q("select in_at from closet_checkouts where id=%s", hco)[0]["in_at"] is None, "the MSP's loaner can't be checked in from the portal")
r = pu.post(B + f"/portal/closet/checkouts/{pco[0]['id']}/checkin", data={"_csrf": csrf(pu, "/portal/closet"), "outcome": "returned"})
ok("Checked back in" in flash(r.text) and qty("Spare monitor") == 1, "checked back in from the portal")
ev = q("select p.name from closet_events e join portal_users p on p.id = e.portal_user_id where e.item_id=%s", MON)
ok(len(ev) == 2, "the history records the portal user")
r = pu.post(B + "/portal/closet/request", data={"_csrf": csrf(pu, "/portal/closet"), "what": "2 USB headsets", "quantity": "2", "note": "New hires"})
rq = q("select detail from closet_events where action='requested'")
ok(rq and "2 USB headsets" in rq[0]["detail"] and ("Request sent" in flash(r.text) or "couldn't be sent" in flash(r.text)), "a request is sent and recorded: " + (rq[0]["detail"] if rq else ""))
r = pu.post(B + "/portal/closet/request", data={"_csrf": csrf(pu, "/portal/closet"), "what": ""})
ok("Say what you need" in flash(r.text), "a request needs a description")

# ---- portal: clients keep their closet up to date too (can_closet_edit)
r = pl.post(B + "/portal/closet/items", data={"_csrf": csrf(pl, "/portal/closet"), "name": "Sneaky add", "quantity": "1"})
ok(r.status_code == 403 and not q("select 1 from closet_items where name='Sneaky add'"), "a view-only user can't add items")
r = pu.post(B + "/portal/closet/items", data={"_csrf": csrf(pu, "/portal/closet"), "name": "Sneaky add", "quantity": "1"})
ok(r.status_code == 403 and not q("select 1 from closet_items where name='Sneaky add'"), "check-out alone doesn't allow adding")
ok(pu.get(B + f"/portal/closet/items/{HS}").status_code == 403, "...or opening an item to update it")
mkportal("edit@closet.example", "ONSWG4TFOQZDCMRTGQ2TMNZYHEYDCMRT", 1, 0, 1)
pe = plogin("edit@closet.example", "ONSWG4TFOQZDCMRTGQ2TMNZYHEYDCMRT")
t = pe.get(B + "/portal/closet").text
ok('id="closet-add"' in t and f'href="/portal/closet/items/{HS}"' in t and "/portal/closet/request" not in t and not errs(t), "the update permission: an Add form and item links (no check-out forms without that permission)")
r = pe.post(B + "/portal/closet/items", data={"_csrf": csrf(pe, "/portal/closet"), "name": "Desk phone cable", "category": "cables", "quantity": "4", "min_quantity": "2",
    "location": "Front desk drawer", "office_id": str(q("select id from msp_offices where retired_at is null order by id limit 1")[0]["id"]), "serial": "PORTAL-SERIAL", "notes": "PORTAL-NOTE", "client_id": "999"})
new = q("select * from closet_items where name='Desk phone cable'")
ok(new and new[0]["client_id"] == C1 and new[0]["quantity"] == 4 and new[0]["min_quantity"] == 2 and new[0]["office_id"] is None and new[0]["serial"] is None and new[0]["notes"] is None,
   "added from the portal to their own closet (no office, serial, notes or other client from the form)")
NEW = new[0]["id"] if new else 0
ok("Added Desk phone cable" in flash(r.text) and r.url.endswith(f"/portal/closet/items/{NEW}"), "...and opens the item's page")
ev = q("select action, user_id, portal_user_id from closet_events where item_id=%s", NEW)
ok(ev and ev[0]["action"] == "added" and ev[0]["portal_user_id"] and ev[0]["user_id"] is None, "the history records the portal user who added it")
r = pe.post(B + "/portal/closet/items", data={"_csrf": csrf(pe, "/portal/closet"), "name": "", "quantity": "1"})
ok("Give the item a name" in flash(r.text), "an item needs a name")
r = pe.post(B + "/portal/closet/items", data={"_csrf": csrf(pe, "/portal/closet"), "name": "Too many", "quantity": "-3"})
ok("whole number" in flash(r.text) and not q("select 1 from closet_items where name='Too many'"), "a bad quantity is refused")
t = pe.get(B + f"/portal/closet/items/{HS}").text
tname = q("select name from users where email='tech@example.com'")[0]["name"]
hist = t[t.find('id="closet-item-history"'):]
ok('id="closet-item-details"' in t and "STAFF-ONLY-NOTE" not in t and "Your IT provider" in hist and tname not in hist and not errs(t),
   "the item page: details to update and its history, without staff notes or staff names")
ok("MONSERIAL9" not in pe.get(B + f"/portal/closet/items/{MON}").text, "...or serials")
st.post(B + f"/closet/items/{HS}", data={"_csrf": csrf(st, f"/closet/items/{HS}"), "action": "adjust", "quantity": str(qty("USB headset") + 1), "note": "STAFF-COUNT-NOTE"})
hist = pe.get(B + f"/portal/closet/items/{HS}").text
ok("STAFF-COUNT-NOTE" not in hist and "(now " in hist, "a staff count change shows in the portal without the note staff typed")
tech.post(B + f"/clients/{C2}/closet/items", data={"_csrf": csrf(tech, f"/clients/{C2}/closet"), "name": "Other client toner", "quantity": "3"})
OTH = item_id("Other client toner")
r = pe.post(B + f"/portal/closet/items/{OTH}", data={"_csrf": csrf(pe, "/portal/closet"), "action": "adjust", "quantity": "0"})
ok("isn't in your closet" in flash(r.text) and qty("Other client toner") == 3 and pe.get(B + f"/portal/closet/items/{OTH}").url.endswith("/portal/closet"),
   "another client's item can't be opened or changed from the portal")
r = pe.post(B + f"/portal/closet/items/{MON}", data={"_csrf": csrf(pe, f"/portal/closet/items/{MON}"), "action": "adjust", "quantity": "5"})
ok("Only counted stock" in flash(r.text) and qty("Spare monitor") == 1, "a single item has no count to change")
r = pe.post(B + f"/portal/closet/items/{HS}", data={"action": "adjust", "quantity": "0"})
ok(qty("USB headset") > 0, "a change without the CSRF token is refused")
r = pe.post(B + "/portal/closet/items", data={"_csrf": csrf(pe, "/portal/closet"), "name": "<script>alert(1)</script> dongle", "quantity": "1"})
ok("<script>alert(1)</script>" not in r.text and "&lt;script&gt;" in r.text, "names are escaped on the portal item page")
r = pe.post(B + f"/portal/closet/items/{HS}", data={"_csrf": csrf(pe, f"/portal/closet/items/{HS}"), "action": "save", "name": "USB headset", "category": "audio",
    "location": "Bin 4", "min_quantity": "3", "notes": "", "serial": "X"})
hs = q("select location, min_quantity, notes, serial from closet_items where id=%s", HS)[0]
ok("Saved" in flash(r.text) and hs["location"] == "Bin 4" and hs["min_quantity"] == 3 and hs["notes"] == "STAFF-ONLY-NOTE" and hs["serial"] is None,
   "details saved from the portal; the staff notes are kept as they were")
r = pe.post(B + f"/portal/closet/items/{MON}", data={"_csrf": csrf(pe, f"/portal/closet/items/{MON}"), "action": "save", "name": "Spare monitor", "kind": "stock", "category": "monitors"})
mon = q("select kind, serial from closet_items where id=%s", MON)[0]
ok(mon == {"kind": "tracked", "serial": "MONSERIAL9"}, "the portal can't change what kind of item it is, or its serial")
before = qty("USB headset")
r = pe.post(B + f"/portal/closet/items/{HS}", data={"_csrf": csrf(pe, f"/portal/closet/items/{HS}"), "action": "adjust", "quantity": str(before + 2), "note": "Delivery"})
adj = q("select detail, portal_user_id from closet_events where item_id=%s and action='adjusted' order by id desc limit 1", HS)
ok("Count updated" in flash(r.text) and qty("USB headset") == before + 2 and adj and adj[0]["portal_user_id"] and "Delivery" in adj[0]["detail"], "the count updated from the portal, with the note")
r = pe.post(B + f"/portal/closet/items/{HDMI}", data={"_csrf": csrf(pe, "/portal/closet"), "action": "adjust", "quantity": "0"})
ok("isn't in your closet" in flash(r.text) and qty("HDMI cable") == 8, "the MSP's stock can't be changed from the portal")
r = pe.post(B + f"/portal/closet/items/{HDMI}", data={"_csrf": csrf(pe, "/portal/closet"), "action": "retire"})
ok(q("select retired_at from closet_items where id=%s", HDMI)[0]["retired_at"] is None, "...or removed")
r = pe.get(B + f"/portal/closet/items/{HDMI}")
ok("isn't in your closet" in flash(r.text) and "HDMI cable" not in r.text.split("</h1>")[0], "...or opened")
r = pe.post(B + f"/portal/closet/items/{NEW}", data={"_csrf": csrf(pe, f"/portal/closet/items/{NEW}"), "action": "retire"})
ok("Removed Desk phone cable" in flash(r.text) and q("select retired_at from closet_items where id=%s", NEW)[0]["retired_at"] is not None, "removed (retired) from the portal")
r = pe.post(B + f"/portal/closet/items/{NEW}", data={"_csrf": csrf(pe, "/portal/closet"), "action": "adjust", "quantity": "9"})
ok("isn't in your closet" in flash(r.text) and qty("Desk phone cable") == 4, "a removed item can't be changed from the portal")
r = pe.get(B + f"/portal/closet/items/{NEW}")
ok("isn't in your closet" in flash(r.text) and "Desk phone cable" not in pe.get(B + "/portal/closet").text, "a removed item leaves the portal")
t = st.get(B + f"/closet/items/{NEW}").text
ok("Pat Portal" in t and ">portal<" in t and "Restore" in t, "staff see who changed it (portal badge) and can bring it back")

# ---- portal permissions: checking out needs Tech closet
uid = q("select id from portal_users where email='look@closet.example'")[0]["id"]
st.post(B + f"/portal-users/{uid}", data={"_csrf": csrf(st, f"/clients/{C1}/portal"), "name": "Pat Portal", "can_roadmap": "1", "can_checkout": "1"})
ok(q("select can_closet, can_checkout from portal_users where id=%s", uid)[0] == {"can_closet": 0, "can_checkout": 0}, "check-out is dropped without Tech closet")
st.post(B + f"/portal-users/{uid}", data={"_csrf": csrf(st, f"/clients/{C1}/portal"), "name": "Pat Portal", "can_closet": "1", "can_checkout": "1"})
ok(q("select can_closet, can_checkout from portal_users where id=%s", uid)[0] == {"can_closet": 1, "can_checkout": 1}, "both save together")
st.post(B + f"/portal-users/{uid}", data={"_csrf": csrf(st, f"/clients/{C1}/portal"), "name": "Pat Portal", "can_roadmap": "1", "can_closet_edit": "1"})
ok(q("select can_closet, can_closet_edit from portal_users where id=%s", uid)[0] == {"can_closet": 0, "can_closet_edit": 0}, "updating the closet is dropped without Tech closet")
st.post(B + f"/portal-users/{uid}", data={"_csrf": csrf(st, f"/clients/{C1}/portal"), "name": "Pat Portal", "can_closet": "1", "can_closet_edit": "1"})
ok(q("select can_closet, can_checkout, can_closet_edit from portal_users where id=%s", uid)[0] == {"can_closet": 1, "can_checkout": 0, "can_closet_edit": 1}, "the update permission saves on its own")
t = st.get(B + f"/clients/{C1}/portal").text
ok("Tech closet" in t and "Add tech closet items and keep them up to date" in t, "the portal permissions list Tech closet and updating it")

cleanup()
done()
