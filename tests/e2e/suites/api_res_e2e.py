"""REST API resources (2.2.1 review): client search, device type reset, budget line dates, meeting attendees,
owner and date filters, compliance answers compared strictly, and limited-key device lists."""
from lib import *
import json, subprocess
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a or None); return c.fetchall()
API=B+"/api/v1"
def mk(name, scopes, clients=None):
    code=f'require "{BOOTSTRAP}"; [$i,$t]=Align\\Api\\Keys::create({json.dumps(name)}, json_decode({json.dumps(json.dumps(scopes))},true), {("json_decode("+json.dumps(json.dumps(clients))+",true)") if clients is not None else "null"}, null, 5000, null, 1); echo $t;'
    return subprocess.run(["php","-r",code],env=ENV,capture_output=True,text=True).stdout.strip()
ALL=json.loads(subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; echo json_encode(Align\\Api\\Keys::allScopes());'],env=ENV,capture_output=True,text=True).stdout)
def call(key, method, path, body=None):
    h={"Authorization":"Bearer "+key}
    if body is not None: h["Content-Type"]="application/json"
    return requests.request(method, API+path, headers=h, data=json.dumps(body) if body is not None else None, timeout=60)
def fields(r):
    try: return r.json().get("error",{}).get("fields",{}) or {}
    except Exception: return {}

q("delete from api_keys where name like 'res %'")
q("delete from api_rate"); _api_was=q("select value from settings where name='api_enabled'")
q("insert into settings (name,value,is_secret) values ('api_enabled','1',0) on duplicate key update value='1'")
import atexit
atexit.register(lambda: q("update settings set value=%s where name='api_enabled'",_api_was[0]["value"]) if _api_was else q("delete from settings where name='api_enabled'"))
full=mk("res full", ALL)
lim=mk("res client1", ALL, clients=[1])

# ---- clients and contacts: a search for "0" is a search, not "no filter"
want=q("select count(*) n from clients where is_archived=0 and name like '%0%'")[0]["n"]
r=call(full,"GET","/clients?search=0&include_excluded=1&per_page=1")
ok(r.status_code==200 and r.json()["meta"]["total"]==want,f"clients?search=0 matches names containing 0 ({r.json()['meta']['total']} vs {want})")
want=q("select count(*) n from contacts k join clients c on c.id=k.client_id where c.is_archived=0 and k.archived_at is null and (k.name like '%0%' or k.email like '%0%')")[0]["n"]
r=call(full,"GET","/contacts?search=0&per_page=1")
ok(r.status_code==200 and r.json()["meta"]["total"]==want,f"contacts?search=0 matches names/emails containing 0 ({r.json()['meta']['total']} vs {want})")

# ---- devices: a limited key's list is exactly its client's devices (evaluated per client now)
def all_ids(key, path):
    ids=set(); page=1
    while True:
        j=call(key,"GET",f"{path}{'&' if '?' in path else '?'}per_page=200&page={page}").json()
        ids|={x["id"] for x in j["data"]}
        if not j["meta"]["has_more"]: return ids
        page+=1
mine=all_ids(lim,"/devices"); theirs=all_ids(full,"/devices?client_id=1")
ok(mine and mine==theirs,f"limited key's /devices equals client 1's devices ({len(mine)} vs {len(theirs)})")

# ---- devices: device_type null goes back to the RMM's type; refused where the type is the device's own
rmm=None
for d in q("select id, device_type from devices where source='rmm' and removed_at is null order by id limit 20"):
    if call(full,"GET",f"/devices/{d['id']}").status_code==200: rmm=d; break
if rmm:
    keep=q("select device_type from device_overrides where device_id=%s",rmm["id"])
    other="Laptop" if rmm["device_type"]!="Laptop" else "Desktop"
    r=call(full,"PATCH",f"/devices/{rmm['id']}",{"device_type":other})
    ok(r.status_code==200 and r.json()["data"]["overrides"]["device_type"]==other,"device type override set")
    r=call(full,"PATCH",f"/devices/{rmm['id']}",{"device_type":None})
    ok(r.status_code==200 and r.json()["data"]["overrides"]["device_type"] is None and q("select device_type from device_overrides where device_id=%s",rmm["id"])[0]["device_type"] is None,
       "PATCH device_type null clears the override (was silently ignored)")
    ok('via API key "res full"' in q("select detail from audit_log where action='device.update' order by id desc limit 1")[0]["detail"],"device change audited with the key's name")
    if keep: q("update device_overrides set device_type=%s where device_id=%s",keep[0]["device_type"],rmm["id"])
    else: q("delete from device_overrides where device_id=%s",rmm["id"])
else:
    print("SKIP no RMM device visible through the API")
man=None
for d in q("select id from devices where source='manual' and removed_at is null and client_id is not null order by id limit 20"):
    if call(full,"GET",f"/devices/{d['id']}").status_code==200: man=d; break
if man:
    before=q("select device_type from devices where id=%s",man["id"])[0]["device_type"]
    r=call(full,"PATCH",f"/devices/{man['id']}",{"device_type":None})
    ok(r.status_code==422 and "device_type" in fields(r) and q("select device_type from devices where id=%s",man["id"])[0]["device_type"]==before,"device_type null refused for a device added in Align (its type is its own)")
else:
    print("SKIP no manual device visible through the API")

# ---- budget lines: end before start refused when either is sent
r=call(full,"POST","/budget-lines",{"client_id":1,"name":"res bad dates","amount":5,"start_date":"2027-03-01","end_date":"2027-01-01"})
ok(r.status_code==422 and "end_date" in fields(r) and not q("select id from budget_lines where name='res bad dates'"),"budget line ending before it starts refused (POST)")
r=call(full,"POST","/budget-lines",{"client_id":1,"name":"res good dates","amount":5,"start_date":"2027-01-01","end_date":"2027-12-31"})
ok(r.status_code==201,"budget line with a sane period created"); lid=r.json()["data"]["id"] if r.status_code==201 else None
if lid:
    r=call(full,"PATCH",f"/budget-lines/{lid}",{"start_date":"2028-01-01"})
    ok(r.status_code==422 and "start_date" in fields(r) and str(q("select start_date from budget_lines where id=%s",lid)[0]["start_date"])=="2027-01-01","moving the start past the end refused (PATCH)")
    q("update budget_lines set start_date='2027-06-01', end_date='2027-02-01' where id=%s",lid)  # saved that way on the web page
    r=call(full,"PATCH",f"/budget-lines/{lid}",{"notes":"other field"})
    ok(r.status_code==200 and r.json()["data"]["notes"]=="other field","a line with odd dates can still get other fields changed")
q("delete from budget_lines where name in ('res bad dates','res good dates')")

# ---- meetings: one address per attendee entry
made=[]
def meet(body):
    r=call(full,"POST","/meetings",{"client_id":1,"starts_at":"2026-12-04T10:00:00",**body})
    if r.status_code==201: made.append(r.json()["data"]["id"])
    return r
r=meet({"attendees":["x1@example.com, x2@example.com <y@example.com>"]})
ok(r.status_code==422 and "attendees" in fields(r),"attendee entry carrying more addresses in its name refused")
r=meet({"attendees":["a@example.com <b@example.com>"]})
ok(r.status_code==422 and "attendees" in fields(r),"attendee entry whose invited address differs from the checked one refused")
r=meet({"attendees":["Pat\u0007 Example <pat@example.com>"]})
ok(r.status_code==422 and "attendees" in fields(r),"attendee entry with a control character refused")
r=meet({"attendees":["Pat Example <pat@example.com>","sam@example.com"]})
ok(r.status_code==201 and [a["email"] for a in r.json()["data"]["attendees"]]==["pat@example.com","sam@example.com"],"normal attendee entries still accepted")
if made:
    r=call(full,"PATCH",f"/meetings/{made[-1]}",{"attendees":["one@example.com; two@example.com <three@example.com>"]})
    ok(r.status_code==422 and "attendees" in fields(r),"same rule on PATCH")

# ---- meetings: owner must be an active tech or admin (as on the meeting form)
q("delete from users where email in ('res-viewer@example.com','res-tech@example.com')")
q("insert into users (email,name,password_hash,role,is_active) values ('res-viewer@example.com','Res Viewer','x','viewer',1),('res-tech@example.com','Res Tech','x','tech',1)")
viewer=q("select id from users where email='res-viewer@example.com'")[0]["id"]; tech=q("select id from users where email='res-tech@example.com'")[0]["id"]
r=meet({"owner_id":viewer})
ok(r.status_code==422 and "owner_id" in fields(r),"a viewer can't be made the meeting owner (invitations could go out from their mailbox)")
r=meet({"owner_id":tech})
ok(r.status_code==201 and r.json()["data"]["owner"]["id"]==tech,"a tech can be the owner")
if made:
    r=call(full,"PATCH",f"/meetings/{made[-1]}",{"owner_id":viewer})
    ok(r.status_code==422 and "owner_id" in fields(r),"nor through PATCH")

# ---- meetings: impossible dates in from / to refused instead of rolled over
for k,v in [("from","2026-02-30"),("to","2026-02-31"),("from","2026-13-01T10:00")]:
    r=call(full,"GET",f"/meetings?{k}={v}")
    ok(r.status_code==422 and k in fields(r),f"meetings?{k}={v} -> 422 (got {r.status_code})")
ok(call(full,"GET","/meetings?from=2028-02-29&to=2028-03-01").status_code==200,"a real leap day is fine")
for mid in made: call(full,"DELETE",f"/meetings/{mid}")
q("delete from users where id in (%s,%s)",viewer,tech)

# ---- compliance: answers compared strictly ("10" and "1e1" are different notes)
fw=q("select framework_id from client_frameworks where client_id=1 order by framework_id limit 1")
assigned_here=False
if not fw:
    f1=call(full,"GET","/compliance/frameworks").json()["data"][0]["id"]
    call(full,"POST","/clients/1/compliance",{"framework_id":f1}); assigned_here=True
    fw=[{"framework_id":f1}]
fw=fw[0]["framework_id"]
ctl=call(full,"GET",f"/clients/1/compliance/{fw}/controls?per_page=1").json()["data"][0]["id"]
orig=q("select * from client_control_status where client_id=1 and control_id=%s",ctl)
r1=call(full,"PATCH",f"/clients/1/compliance/{fw}/controls/{ctl}",{"notes":"10","owner":"100"})
r2=call(full,"PATCH",f"/clients/1/compliance/{fw}/controls/{ctl}",{"notes":"1e1","owner":"1e2"})
row=q("select notes, owner from client_control_status where client_id=1 and control_id=%s",ctl)[0]
ok(r1.status_code==200 and r2.status_code==200 and row["notes"]=="1e1" and row["owner"]=="1e2" and r2.json()["data"]["notes"]=="1e1","numeric-looking notes/owner changes are saved (were seen as unchanged)")
r=call(full,"PATCH",f"/clients/1/compliance/{fw}/controls",{"controls":[{"id":ctl,"notes":"100"}]})
r=call(full,"PATCH",f"/clients/1/compliance/{fw}/controls",{"controls":[{"id":ctl,"notes":"1e2"}]})
ok(r.status_code==200 and r.json()["data"]["updated"]==1 and q("select notes from client_control_status where client_id=1 and control_id=%s",ctl)[0]["notes"]=="1e2","bulk update counts and saves it too")
r=call(full,"PATCH",f"/clients/1/compliance/{fw}/controls",{"controls":[{"id":ctl,"notes":"1e2"}]})
ok(r.status_code==200 and r.json()["data"]["updated"]==0,"an identical answer still counts as unchanged")
q("delete from client_control_status where client_id=1 and control_id=%s",ctl)
if orig:
    o=orig[0]; q("insert into client_control_status (client_id,control_id,status,notes,evidence,owner,due_date,document_id,updated_by) values (%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                 1,ctl,o["status"],o["notes"],o["evidence"],o["owner"],o["due_date"],o["document_id"],o["updated_by"])
if assigned_here: call(full,"DELETE",f"/clients/1/compliance/{fw}")

# ---- nothing crashed, audit chain intact
e=q("select k.name, r.method, r.path, r.status from api_requests r join api_keys k on k.id=r.key_id where k.name like 'res %' and r.status>=500")
ok(not e,"no 500 from this suite: "+str(e[:5]))
v=subprocess.run(["php",ALIGN,"audit:verify"],env=ENV,capture_output=True,text=True)
ok(v.returncode==0,"audit log hash chain intact: "+(v.stdout+v.stderr).strip()[-120:])
q("delete from api_keys where name like 'res %'"); q("delete from api_rate")
print("FAILURES:",len(fails))
