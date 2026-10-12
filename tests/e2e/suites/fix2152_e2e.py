"""2.15.2 code review fixes: a client's own Microsoft 365 app or Google Workspace key is connected over, confirmed or
disconnected only by admins (techs are refused and don't see Disconnect); a retired vendor never blocks a name, and
restoring one is refused while an active vendor has its name; the tech closet takes a field posted as an array without
a server error, names the client's contacts in the portal only for users who may see contacts (others check out to
themselves); ?office=abc shows all offices; health score settings can't be saved contradicting each other from a
partial form; migration 075's index exists and the migration can run again. (Curricula's long titles, odd replies and
long report histories are in curricula_e2e; Huntress's odd replies in huntress_e2e.)"""
import re, json, requests
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
C = q("select id from clients where is_archived=0 order by id limit 1")[0]["id"]
CNAME = q("select name from clients where id=%s", C)[0]["name"]


def cleanup():
    q("delete from client_m365 where client_id=%s", C)
    q("delete from client_gws where client_id=%s", C)
    q("delete from client_vendors where client_id=%s and name like 'Fix2152%%'", C)
    q("delete from portal_users where email like '%%@fix2152.example'")
    q("delete from closet_items where name like 'Fix2152%%'")


cleanup()
import atexit
atexit.register(cleanup)

# ---- a client's own Microsoft 365 app: techs can't connect over it, confirm another tenant or disconnect it
q("insert into client_m365 (client_id, status, mode, tenant_id, tenant_name, tenant_domain, app_id, connected_at) values (%s,'connected','own','8f0c2a1e-1111-4222-8333-944455556666','Own Tenant','own.example','app-own',NOW())", C)
t = tech.get(B + f"/clients/{C}/connectors").text
ok(f'action="/clients/{C}/m365/disconnect"' not in t and not errs(t), "techs don't see Disconnect for a client's own app")
for act in ["connect", "link", "confirm", "disconnect"]:
    r = tech.post(B + f"/clients/{C}/m365/{act}", data={"_csrf": csrf(tech, f"/clients/{C}/connectors")})
    row = q("select mode, app_id from client_m365 where client_id=%s", C)
    ok(row and row[0]["mode"] == "own" and row[0]["app_id"] == "app-own" and "only an admin" in flash(r.text), f"a tech's {act} is refused and the own app stays")
ok(f'action="/clients/{C}/m365/disconnect"' in st.get(B + f"/clients/{C}/connectors").text, "admins still see Disconnect")
st.post(B + f"/clients/{C}/m365/disconnect", data={"_csrf": csrf(st, f"/clients/{C}/connectors")})
ok(not q("select 1 from client_m365 where client_id=%s", C), "an admin can disconnect it")

# ---- a client's own Google Workspace key: techs can't disconnect it
q("insert into client_gws (client_id, status, mode, domain, admin_email, connected_at) values (%s,'connected','own','own.example','admin@own.example',NOW())", C)
ok(f'action="/clients/{C}/gws/disconnect"' not in tech.get(B + f"/clients/{C}/connectors").text and f'action="/clients/{C}/gws/disconnect"' in st.get(B + f"/clients/{C}/connectors").text,
   "techs don't see Disconnect for a client's own key (admins do)")
r = tech.post(B + f"/clients/{C}/gws/disconnect", data={"_csrf": csrf(tech, f"/clients/{C}/connectors")})
ok(q("select mode from client_gws where client_id=%s", C)[0]["mode"] == "own" and "only an admin" in flash(r.text), "a tech's Disconnect is refused and the key stays")
q("update client_gws set mode='msp' where client_id=%s", C)
tech.post(B + f"/clients/{C}/gws/disconnect", data={"_csrf": csrf(tech, f"/clients/{C}/connectors")})
ok(not q("select 1 from client_gws where client_id=%s", C), "a tech can still disconnect one on your service account")

# ---- vendors: one rule for duplicate names (a retired vendor never blocks a name)
def addv(name):
    return tech.post(B + f"/clients/{C}/vendors", data={"_csrf": csrf(tech, f"/clients/{C}/vendors"), "name": name, "back": f"/clients/{C}/vendors"})
addv("Fix2152 Telecom")
old = q("select id from client_vendors where client_id=%s and name='Fix2152 Telecom'", C)[0]["id"]
tech.post(B + f"/vendors/{old}", data={"_csrf": csrf(tech, f"/clients/{C}/vendors"), "action": "retire", "back": f"/clients/{C}/vendors"})
addv("Fix2152 Telecom")
new = q("select id from client_vendors where client_id=%s and name='Fix2152 Telecom' and retired_at is null", C)
ok(len(new) == 1 and new[0]["id"] != old, "a retired vendor's name can be added again")
r = tech.post(B + f"/vendors/{new[0]['id']}", data={"_csrf": csrf(tech, f"/clients/{C}/vendors"), "name": "Fix2152 Telecom", "account_number": "A-1", "back": f"/clients/{C}/vendors"})
ok(q("select account_number from client_vendors where id=%s", new[0]["id"])[0]["account_number"] == "A-1" and "already has" not in flash(r.text),
   "...and the new one can be edited (the retired one doesn't block its own name)")
r = tech.post(B + f"/vendors/{old}", data={"_csrf": csrf(tech, f"/clients/{C}/vendors"), "action": "restore", "back": f"/clients/{C}/vendors"})
ok(q("select retired_at from client_vendors where id=%s", old)[0]["retired_at"] is not None and "already has an active vendor" in flash(r.text),
   "restoring the retired one is refused while an active vendor has its name")
r = tech.post(B + f"/vendors/{old}", data={"_csrf": csrf(tech, f"/clients/{C}/vendors"), "name": "Fix2152 Telecom", "account_number": "OLD-9", "back": f"/clients/{C}/vendors"})
ok(q("select account_number from client_vendors where id=%s", old)[0]["account_number"] == "OLD-9", "the retired one can still be edited while an active vendor has its name")
r = addv("Fix2152 Telecom")
ok(len(q("select id from client_vendors where client_id=%s and name='Fix2152 Telecom' and retired_at is null", C)) == 1 and "already has" in flash(r.text),
   "two active vendors with one name are still refused")

# ---- tech closet: a field posted as an array, the portal's contacts list, ?office=abc
r = tech.post(B + f"/clients/{C}/closet/items", data={"_csrf": csrf(tech, f"/clients/{C}/closet"), "name": "Fix2152 cable", "category[]": "x", "quantity": "2", "back": f"/clients/{C}/closet"})
it = q("select category from closet_items where name='Fix2152 cable'")
ok(r.status_code == 200 and it and it[0]["category"] == "other", "a category posted as an array is taken as no category, not a server error")
SEC = "MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U"
PW = "PortalPassword123!"
q("delete from login_attempts")
php(f'Align\\DB::insert("portal_users", ["client_id"=>{C},"email"=>"nodocs@fix2152.example","name"=>"Pat Fix","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
    f'"can_roadmap"=>1,"can_budget"=>0,"can_devices"=>1,"can_documents"=>0,"can_approve"=>0,"can_contacts"=>0,"can_submit"=>0,"can_closet"=>1,"can_checkout"=>1,"can_closet_edit"=>0,'
    f'"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
pu = requests.Session()
pu.post(B + "/portal/login", data={"_csrf": csrf(pu, "/portal/login"), "email": "nodocs@fix2152.example", "password": PW})
pu.post(B + "/portal/login/2fa", data={"_csrf": csrf(pu, "/portal/login/2fa"), "code": totp(SEC)})
t = pu.get(B + "/portal/closet").text
contact = q("select id, name from contacts where client_id=%s and archived_at is null order by name limit 1", C)
ok("Fix2152 cable" in t and (not contact or contact[0]["name"] not in t) and not errs(t), "without the contacts permission the portal names no contacts in the closet")
iid = q("select id from closet_items where name='Fix2152 cable'")[0]["id"]
if contact:
    pu.post(B + f"/portal/closet/items/{iid}/checkout", data={"_csrf": csrf(pu, "/portal/closet"), "contact_id": str(contact[0]["id"]), "quantity": "1"})
    co = q("select person, contact_id from closet_checkouts where item_id=%s and in_at is null", iid)
    ok(co and co[0]["person"] == "Pat Fix" and co[0]["contact_id"] != contact[0]["id"], "...and a contact posted anyway is ignored: the checkout is for themselves")
r = st.get(B + "/closet?office=abc")
ok(r.status_code == 200 and not errs(r.text), "?office=abc shows the closet without errors")

# ---- health score settings: a partial form can't save contradicting bands or all-zero weights
good0 = q("select value from settings where name='health_good'")
warn0 = q("select value from settings where name='health_warn'")
st.post(B + "/settings", data={"_csrf": csrf(st, "/settings/planning"), "_tab": "planning", "health_good": "75", "health_warn": "40"})
r = st.post(B + "/settings", data={"_csrf": csrf(st, "/settings/planning"), "_tab": "planning", "health_warn": "80"})
w = q("select value from settings where name='health_warn'")
ok(w and int(w[0]["value"]) == 40 and "must be lower" in flash(r.text), "Needs attention can't be saved above Healthy from a form that posts only one of them")
pillars = json.loads(php('echo json_encode(array_keys(Align\\Health\\Health::PILLARS));').stdout)
saved = {k: q("select value from settings where name=%s", f"health_weight_{k}") for k in pillars}
st.post(B + "/settings", data={"_csrf": csrf(st, "/settings/planning"), "_tab": "planning", **{f"health_weight_{k}": "0" for k in pillars[:-1]}})
r = st.post(B + "/settings", data={"_csrf": csrf(st, "/settings/planning"), "_tab": "planning", f"health_weight_{pillars[-1]}": "0"})
ws = [q("select value from settings where name=%s", f"health_weight_{k}") for k in pillars]
ok(any(not x or int(x[0]["value"]) > 0 for x in ws), "the last weight can't be set to 0 when the others already are")
for k, v in saved.items():
    q("delete from settings where name=%s", f"health_weight_{k}") if not v else setting(f"health_weight_{k}", v[0]["value"])
q("delete from settings where name in ('health_good','health_warn')")
if good0:
    setting("health_good", good0[0]["value"])
if warn0:
    setting("health_warn", warn0[0]["value"])

# ---- migration 075
ok(q("select count(*) n from information_schema.statistics where table_schema=database() and table_name='closet_events' and index_name='idx_ce_portal_at'")[0]["n"] == 2,
   "migration 075 adds the portal index on closet events")
r = php('(require "' + ROOT + '/db/migrations/075_closet_events_portal_index.php")(); echo "ok";')
ok(r.stdout.strip() == "ok", "...and can run again " + r.stderr[-200:])
done()
