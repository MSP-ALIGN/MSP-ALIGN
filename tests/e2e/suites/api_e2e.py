from lib import *
import re, json, subprocess, sitecustomize, time
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a or None); return c.fetchall()
API=B+"/api/v1"
def mk(name, scopes, clients=None, rate=600, expires=None):
    php=f'require "{BOOTSTRAP}"; [$i,$t]=Align\\Api\\Keys::create({json.dumps(name)}, json_decode({json.dumps(json.dumps(scopes))},true), {("json_decode("+json.dumps(json.dumps(clients))+",true)") if clients is not None else "null"}, {json.dumps(expires) if expires else "null"}, {rate}, null, 1); echo $t;'
    return subprocess.run(["php","-r",php],env=ENV,capture_output=True,text=True).stdout.strip()
ALL=subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; echo json_encode(Align\\Api\\Keys::allScopes());'],env=ENV,capture_output=True,text=True).stdout
ALL=json.loads(ALL)
READ=[s for s in ALL if s.endswith(":read")]
def call(key, method, path, body=None, headers=None, raw=None, ctype="application/json"):
    h={"Authorization":"Bearer "+key} if key else {}
    h.update(headers or {})
    if body is not None or raw is not None: h["Content-Type"]=ctype
    return requests.request(method, API+path, headers=h, data=raw if raw is not None else (json.dumps(body) if body is not None else None))

chk=subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; $bad=[]; foreach (Align\\Api\\Routes::all() as $r) { preg_match_all("#\\{(\\w+)#", $r["path"], $m); $h=$r["handler"]; $ref=is_array($h) ? new ReflectionMethod($h[0],$h[1]) : new ReflectionFunction($h); $ps=array_map(fn($p)=>$p->getName(),$ref->getParameters()); if ($ps !== $m[1]) $bad[]=$r["method"]." ".$r["path"]; } echo json_encode($bad);'],env=ENV,capture_output=True,text=True).stdout
ok(chk=="[]","every route placeholder matches its handler parameters: "+chk)
q("delete from api_keys"); q("delete from api_requests"); q("delete from api_idempotency"); q("delete from api_rate"); q("delete from api_ip_rate")
q("update settings set value='0' where name='api_enabled'")
full=mk("e2e full", ALL)
r=call(full,"GET","/"); ok(r.status_code==404 and r.json()["error"]["code"]=="api_disabled","API off: 404 api_disabled")
q("update settings set value='1' where name='api_enabled'")

# ---- auth
r=call(None,"GET","/"); ok(r.status_code==401 and r.json()["error"]["code"]=="missing_key" and "Bearer" in r.headers.get("WWW-Authenticate",""),"no key: 401 missing_key")
r=call("msa_AAAAAAAA_"+"x"*32,"GET","/"); ok(r.status_code==401 and r.json()["error"]["code"]=="invalid_key","wrong key: 401 invalid_key")
r=call(full[:-1]+("a" if full[-1]!="a" else "b"),"GET","/"); ok(r.status_code==401,"real prefix, wrong secret: 401")
r=requests.get(API+"/",headers={"X-API-Key":full}); ok(r.status_code==200 and r.json()["data"]["name"]=="e2e full","X-API-Key header works")
r=call(full,"GET","/"); d=r.json()["data"]
ok(r.status_code==200 and d["scopes"]==ALL and d["client_ids"] is None and r.headers.get("X-Request-Id") and r.headers.get("X-RateLimit-Limit")=="600","GET /api/v1 describes the key, with request id and rate headers")
ok("no-store" in r.headers.get("Cache-Control","") and r.headers["Content-Type"].startswith("application/json") and "Set-Cookie" not in r.headers,"JSON, no-store, no cookies")
exp=mk("e2e expired", READ, expires="2020-01-01 00:00:00")
ok(call(exp,"GET","/").json()["error"]["code"]=="key_expired","expired key refused")
rev=mk("e2e revoked", READ); q("update api_keys set revoked_at=NOW() where name='e2e revoked'")
ok(call(rev,"GET","/").json()["error"]["code"]=="key_revoked","revoked key refused")
ok(q("select count(*) n from api_requests where status=401")[0]["n"]>=4,"failed attempts logged")

# ---- routing / body errors
ok(call(full,"GET","/nope").status_code==404,"unknown endpoint 404")
r=call(full,"PUT","/projects/1",{"title":"x"}); ok(r.status_code==405 and "PATCH" in r.headers.get("Allow",""),"wrong method 405 with Allow")
ok(call(full,"POST","/projects",raw="title=x",ctype="application/x-www-form-urlencoded").json()["error"]["code"]=="unsupported_media_type","form body refused: 415")
ok(call(full,"POST","/projects",raw="{bad").json()["error"]["code"]=="invalid_json","bad JSON: 400")
ok(call(full,"POST","/projects",raw="[1,2]").json()["error"]["code"]=="invalid_json","array body: 400")
r=call(full,"POST","/projects",raw=json.dumps({"x":"a"*1100000})); ok(r.status_code==413,"body over 1 MB: 413")

# ---- scopes
ro=mk("e2e read only", READ)
r=call(ro,"POST","/projects",{"client_id":1,"title":"Nope"}); ok(r.status_code==403 and r.json()["error"]["code"]=="insufficient_scope" and r.headers.get("X-Required-Scope")=="projects:write","read-only key can't write (403, X-Required-Scope)")
nodev=mk("e2e no devices", ["clients:read","projects:read"])
ok(call(nodev,"GET","/devices").status_code==403,"key without devices:read gets 403 on /devices")
d=call(nodev,"GET","/clients/1").json()["data"]
ok("projects" in d["summary"] and "devices" not in d["summary"] and "backups" not in d["summary"],"client summary only includes areas the key can read")

# ---- client restriction
lim=mk("e2e dental only", ALL, clients=[1])
r=call(lim,"GET","/clients"); ok([c["id"] for c in r.json()["data"]]==[1] and r.json()["meta"]["total"]==1,"limited key lists only its client")
ok(call(lim,"GET","/clients/2").status_code==404,"other client answers 404 (not 403)")
ok(all(x["client_id"]==1 for x in call(lim,"GET","/devices?per_page=200").json()["data"]),"devices limited to its client")
ok(call(lim,"GET","/devices?client_id=2").status_code==404,"filtering by another client: 404")
other=q("select id from roadmap_items where client_id=2 limit 1")
if other: ok(call(lim,"GET",f"/projects/{other[0]['id']}").status_code==404,"another client's project: 404")
ok(call(lim,"POST","/projects",{"client_id":2,"title":"x"}).status_code==404,"can't create for another client")
ok(call(lim,"GET","/backups/hosted").json()["error"]["code"]=="all_clients_required","hosted backups need an all-clients key")
ok(call(lim,"POST","/meetings",{"title":"x","starts_at":"2026-12-01T10:00:00"}).status_code==422,"limited key must give a client for meetings")

# ---- clients & contacts
r=call(full,"GET","/clients?per_page=2&page=1"); j=r.json()
ok(len(j["data"])==2 and j["meta"]["per_page"]==2 and j["meta"]["has_more"] is True,"pagination meta")
ok(call(full,"GET","/clients?per_page=500").status_code==422,"per_page over 200 refused")
d=call(full,"GET","/clients/1").json()["data"]
ok(d["name"].startswith("Cedar Ridge") and {"devices","projects","next_meeting","backups","compliance"}<=set(d["summary"]) and d["summary"]["devices"]["total"]>0,"client detail with health summary")
r=call(full,"GET","/clients/1/contacts"); ok(r.status_code==200 and all(c["client_id"]==1 for c in r.json()["data"]),"client contacts")
r=call(full,"GET","/contacts?role=decision_maker"); ok(r.status_code==200 and all("decision_maker" in c["roles"] for c in r.json()["data"]),"contacts filtered by role")
ok(call(full,"GET","/contacts?role=boss").status_code==422,"bad enum in query: 422")

# ---- devices
r=call(full,"GET","/devices?client_id=1&type=Server&per_page=5"); j=r.json()
ok(j["data"] and all(x["type"]=="Server" for x in j["data"]),"devices filtered by type")
did=j["data"][0]["id"]
att=call(full,"GET","/devices?client_id=1&attention=true&per_page=200").json()["data"]
ok(att and all(x["lifecycle"]["health"] in ("bad","warn") for x in att),"attention filter")
r=call(full,"PATCH",f"/devices/{did}",{"replacement_cost":7777,"replace_quarter":"2027-Q2","replace_note":"Budget next year","lifespan_years":8})
d=r.json()["data"]
ok(r.status_code==200 and d["lifecycle"]["replacement_cost"]==7777.0 and d["lifecycle"]["planned_replacement"]["quarter"]=="2027-04-01" and d["lifecycle"]["lifespan_years"]==8 and "itflow_sync" in d,"PATCH device: cost, planned quarter, lifespan (and ITFlow sync status)")
o=q("select replacement_cost, replace_on, replace_note, lifespan_years from device_overrides where device_id=%s",did)[0]
ok(float(o["replacement_cost"])==7777 and str(o["replace_on"])=="2027-04-01" and o["replace_note"]=="Budget next year","stored in device_overrides like the device page")
r=call(full,"PATCH",f"/devices/{did}",{"replace_quarter":None,"replacement_cost":None,"lifespan_years":None})
ok(r.json()["data"]["lifecycle"]["planned_replacement"] is None and q("select replace_note from device_overrides where device_id=%s",did)[0]["replace_note"] is None,"null clears overrides (note goes with the quarter)")
r=call(full,"PATCH",f"/devices/{did}",{"replacement_cost":"lots","colour":"red","replace_quarter":"2040-Q1"})
f=r.json()["error"]["fields"]
ok(r.status_code==422 and set(f)=={"replacement_cost","colour","replace_quarter"} and "3-year plan" in f["replace_quarter"],"validation: each bad field named, unknown fields refused")
ok(call(full,"PATCH",f"/devices/{did}",{}).status_code==422,"empty PATCH refused")

# ---- projects + idempotency
body={"client_id":1,"title":"API test project","target_quarter":"2027-01-15","cost":2500,"priority":"high"}
r=call(full,"POST","/projects",body,{"Idempotency-Key":"e2e-proj-1"}); p=r.json()["data"]
ok(r.status_code==201 and p["target_quarter"]=="2027-01-01" and p["status"]=="proposed" and p["category"]=="project" and p["cost"]==2500.0,"POST project: 201, quarter normalized, defaults")
r2=call(full,"POST","/projects",body,{"Idempotency-Key":"e2e-proj-1"})
ok(r2.status_code==201 and r2.json()["data"]["id"]==p["id"] and r2.headers.get("Idempotent-Replayed")=="true","retry with same Idempotency-Key replays, no duplicate")
ok(q("select count(*) n from roadmap_items where title='API test project'")[0]["n"]==1,"only one project created")
r3=call(full,"POST","/projects",{**body,"title":"Different"},{"Idempotency-Key":"e2e-proj-1"})
ok(r3.status_code==409 and r3.json()["error"]["code"]=="idempotency_conflict","same key, different body: 409")
ok(call(full,"POST","/projects",{"client_id":1}).json()["error"]["fields"]=={"title":"Required."},"missing required field named")
r=call(full,"PATCH",f"/projects/{p['id']}",{"status":"approved","recurring_monthly":49.5})
ok(r.json()["data"]["status"]=="approved" and r.json()["data"]["recurring_monthly"]==49.5,"PATCH project")
ok(call(full,"PATCH",f"/projects/{p['id']}",{"client_id":2}).status_code==422,"can't move a project to another client")
r=call(full,"GET","/projects?client_id=1&status=approved"); ok(any(x["id"]==p["id"] for x in r.json()["data"]),"list projects by status")
since=time.strftime("%Y-%m-%dT%H:%M:%S",time.localtime(time.time()-5))
r=call(full,"GET","/projects?updated_since="+since); ok(any(x["id"]==p["id"] for x in r.json()["data"]),"updated_since")
ok(call(full,"DELETE",f"/projects/{p['id']}").status_code==204 and call(full,"GET",f"/projects/{p['id']}").status_code==404,"DELETE project: 204, then 404")
a=q("select detail from audit_log where action='roadmap.create' order by id desc limit 1")[0]["detail"]
ok('via API key "e2e full"' in a,"audit log names the key: "+a[-60:])

# ---- budget
r=call(full,"GET","/clients/1/budget"); b=r.json()["data"]
ok(r.status_code==200 and len(b["years"])==3 and len(b["quarters"])==12 and b["lines"] and b["recurring_monthly"]>0,"budget summary: 3 years, 12 quarters, lines")
ok(call(full,"GET","/clients/1/budget?year=5").status_code==422,"budget year out of range")
r=call(full,"POST","/budget-lines",{"client_id":1,"name":"API fiber","category":"connectivity","amount":199.99,"start_date":"2026-01-01","contract_term_months":36,"notice_days":60})
l=r.json()["data"]
ok(r.status_code==201 and l["contract_end"]=="2028-12-31" and l["renegotiate_date"]=="2028-11-01" and l["frequency"]=="monthly","budget line created; contract end and renegotiate-by worked out like the form")
r=call(full,"PATCH",f"/budget-lines/{l['id']}",{"notice_days":90}); ok(r.json()["data"]["renegotiate_date"]=="2028-10-02","renegotiate-by recalculated on PATCH")
ok(any(x.get("budget_line_id")==l["id"] for x in call(full,"GET","/clients/1/budget").json()["data"]["lines"]),"new line appears in the budget")
ok(call(full,"DELETE",f"/budget-lines/{l['id']}").status_code==204,"delete budget line")

# ---- licenses
r=call(full,"GET","/licenses?client_id=1&unpriced=true"); ok(r.json()["data"] and all(x["unit_price"] is None for x in r.json()["data"]),"unpriced licenses")
r=call(full,"PATCH","/licenses/4",{"unit_price":8.5,"billing_cycle":"monthly","notes":"priced via API"})
d=r.json()["data"]; ok(d["unit_price"]==8.5 and d["priced"] and d["monthly"] is not None and d["notes"]=="priced via API","price an ITFlow license")
r=call(full,"PATCH","/licenses/4",{"seats":99,"name":"x"}); ok(r.status_code==422 and set(r.json()["error"]["fields"])=={"seats","name"},"ITFlow-owned fields refused")
ok(call(full,"DELETE","/licenses/4").json()["error"]["code"]=="managed_in_itflow","can't delete an ITFlow license (409)")
r=call(full,"PATCH","/licenses/4",{"retired":True}); ok(r.json()["data"]["retired"] is True,"retire"); call(full,"PATCH","/licenses/4",{"retired":False,"unit_price":None})
r=call(full,"POST","/licenses",{"client_id":1,"name":"API manual license","seats":5,"unit_price":10,"pricing":"per_seat","billing_cycle":"annual"})
ml=r.json()["data"]; ok(r.status_code==201 and ml["source"]=="manual" and ml["annual"]==50.0,"add a manual license (annual 5 × 10)")
ok(call(full,"DELETE",f"/licenses/{ml['id']}").status_code==204,"delete a manual license")

# ---- meetings
r=call(full,"POST","/meetings",{"client_id":1,"type":"qbr","starts_at":"2026-12-03T10:00:00","duration_minutes":90,"attendees":["Dr. Jordan Ellis <jordan@cedarridgedental.example>","sam@cedarridgedental.example"],"agenda":"Q4 review"})
m=r.json()["data"]
ok(r.status_code==201 and m["title"]=="Quarterly business review" and m["duration_minutes"]==90 and len(m["attendees"])==2 and m["owner"]["id"]==1 and m["invitations"] is None,"schedule a meeting (title from type, owner = key creator, no invites unless asked)")
ok(call(full,"POST","/meetings",{"starts_at":"soon"}).json()["error"]["fields"]["starts_at"].startswith("Must be a date"),"bad starts_at")
ok(call(full,"POST","/meetings",{"starts_at":"2026-12-03T10:00:00","attendees":["not-an-email"]}).status_code==422,"bad attendee refused")
r=call(full,"PATCH",f"/meetings/{m['id']}",{"status":"completed","notes":"Went well."}); ok(r.json()["data"]["status"]=="completed" and r.json()["data"]["notes"]=="Went well.","complete a meeting with notes")
r=call(full,"PATCH",f"/meetings/{m['id']}",{"starts_at":"2026-12-04T09:00:00-08:00"}); ok(r.json()["data"]["duration_minutes"]==90,"moving the start keeps the length")
ok(any(x["id"]==m["id"] for x in call(full,"GET","/meetings?client_id=1&from=2026-12-01&to=2026-12-31").json()["data"]),"list meetings by date range")
ok(call(full,"DELETE",f"/meetings/{m['id']}").status_code==204,"delete meeting")

# ---- compliance
r=call(full,"GET","/compliance/frameworks?per_page=200"); fws=r.json()["data"]; ok(len(fws)>=10,"frameworks")
cmmc=[f for f in fws if f["slug"]=="cmmc-l1"]
fw=cmmc[0]["id"] if cmmc else fws[-1]["id"]
q("delete from client_frameworks where client_id=1 and framework_id=%s",fw)
q("delete s from client_control_status s join compliance_controls c on c.id=s.control_id where s.client_id=1 and c.framework_id=%s",fw)
r=call(full,"POST","/clients/1/compliance",{"framework_id":fw}); ok(r.status_code==201 and r.json()["data"]["framework_id"]==fw,"assign framework")
ok(call(full,"POST","/clients/1/compliance",{"framework_id":fw}).status_code==200,"assigning again is harmless (200)")
ctl=call(full,"GET",f"/clients/1/compliance/{fw}/controls?per_page=200").json()["data"]
ok(len(ctl)>5 and "guidance" in ctl[0],"controls with guidance")
r=call(full,"PATCH",f"/clients/1/compliance/{fw}/controls/{ctl[0]['id']}",{"status":"met","evidence":"Policy v2","owner":"Sam"})
ok(r.json()["data"]["status"]=="met" and r.json()["data"]["owner"]=="Sam","update one control")
r=call(full,"PATCH",f"/clients/1/compliance/{fw}/controls",{"controls":[{"id":ctl[1]["id"],"status":"partial"},{"id":ctl[2]["id"],"status":"not_met","due_date":"2026-12-31"},{"id":ctl[0]["id"],"status":"met"}]})
j=r.json()["data"]; ok(j["updated"]==2 and j["unchanged"]==1 and j["assessment"]["score"]["met"]>=1,"bulk update: 2 changed, 1 unchanged, score returned")
r=call(full,"PATCH",f"/clients/1/compliance/{fw}/controls",{"controls":[{"id":ctl[3]["id"],"status":"met"},{"id":ctl[4]["id"],"status":"maybe"}]})
ok(r.status_code==422 and "controls[1].status" in r.json()["error"]["fields"] and q("select count(*) n from client_control_status where client_id=1 and control_id=%s and status='met'",ctl[3]["id"])[0]["n"]==0,"bulk is all-or-nothing")
ok(call(full,"PATCH",f"/clients/1/compliance/{fw}/controls/999999",{"status":"met"}).status_code==404,"control from another framework: 404")
ok(call(full,"DELETE",f"/clients/1/compliance/{fw}").status_code==204 and call(full,"GET",f"/clients/1/compliance/{fw}/controls").status_code==404,"unassign framework")

# ---- backups
r=call(full,"GET","/backups"); ok(r.status_code==200 and any(x["client_id"]==4 for x in r.json()["data"]),"backup health list (includes hosted-only client)")
r=call(full,"GET","/clients/4/backups"); d=r.json()["data"]
ok(r.status_code==200 and any(j["hosted"] for j in d["jobs"]) and any(w["hosted"] for w in d["machines"]) and len(d["history_30d"])==30,"client backups with hosted jobs and machines")
q("insert into clients (name, source, is_archived, planning_excluded) values ('Zz API No Backups','manual',0,0)"); nb=q("select id from clients where name='Zz API No Backups'")[0]["id"]
ok(call(full,"GET",f"/clients/{nb}/backups").json()["error"]["code"]=="no_backup_data","client without backups: 404 no_backup_data")
q("delete from clients where id=%s",nb)
wl=[w for w in d["machines"] if not w["not_required"]][0]
r=call(full,"POST","/clients/4/backup-exemptions",{"kind":"workload","item_uid":wl["uid"],"reason":"Test VM (API)"}); e=r.json()["data"]
ok(r.status_code==201 and e["kind"]=="workload","mark a machine not required")
ok(call(full,"POST","/clients/1/backup-exemptions",{"kind":"workload","item_uid":wl["uid"],"reason":"x"}).status_code==422,"can't exempt another client's machine")
ok(call(full,"DELETE",f"/clients/4/backup-exemptions/{e['id']}").status_code==204,"remove exemption")
r=call(full,"GET","/backups/hosted?show=unmatched"); j=r.json()
ok(r.status_code==200 and all(x["state"]=="unmatched" for x in j["data"]) and "jobs" in j["meta"],"hosted machines (unmatched) with jobs in meta")
r=call(full,"PUT","/backups/hosted/jobs/j-0102",{"assign":3}); ok(r.json()["data"]["client_ids"]==[3] and r.json()["data"]["machines"]==2,"assign hosted job to a client")
r=call(full,"PUT","/backups/hosted/machines/"+requests.utils.quote("vm:vm-svl1",safe=""),{"assign":"ours"}); ok(r.json()["data"]["state"]=="ours","assign hosted machine (URL-encoded uid)")
ok(call(full,"PUT","/backups/hosted/jobs/j-0102",{"assign":"x"}).status_code==422,"bad assign value")
call(full,"PUT","/backups/hosted/jobs/j-0102",{"assign":"auto"}); call(full,"PUT","/backups/hosted/machines/vm%3Avm-svl1",{"assign":"auto"})

# ---- service levels
r=call(full,"GET","/clients/1/service-levels?period=90"); d=r.json()
ok(r.status_code==200 and "stats" in d["data"] and "by_priority" in d["data"],"service levels")
ok(call(full,"GET","/clients/1/service-levels?period=7").status_code==422,"bad period")

# ---- rate limit
rl=mk("e2e rate", READ, rate=3)
codes=[call(rl,"GET","/").status_code for _ in range(4)]
r=call(rl,"GET","/")
ok(codes[:3]==[200,200,200] and codes[3]==429 and r.json()["error"]["code"]=="rate_limited" and int(r.headers["Retry-After"])>=1 and r.headers["X-RateLimit-Remaining"]=="0","rate limit: 4th request in a minute gets 429 with Retry-After")

# ---- review fixes
vet=mk("e2e vet only", ALL, clients=[4])
sj=[j for j in call(vet,"GET","/clients/4/backups").json()["data"]["jobs"] if j["shared_with_other_clients"]]
fj=[j for j in call(full,"GET","/clients/4/backups").json()["data"]["jobs"] if j["shared_with_other_clients"]]
ok(not sj and fj and fj[0]["message"],"shared hosted jobs left out for a client-limited key (1.45), shown with details to an all-clients key")
ok(call(lim,"POST","/meetings",{"client_id":1,"starts_at":"2026-12-03T10:00:00","owner_id":2}).json()["error"]["fields"].get("owner_id","").startswith("Only a key for all clients"),"client-limited key can't choose the meeting owner")
bad=mk("e2e corrupt", READ, clients=[1]); q("update api_keys set client_ids='garbage' where name='e2e corrupt'")
ok(call(bad,"GET","/clients").json()["meta"]["total"]==0,"unreadable client limit fails closed (no clients)")
ok(call(full,"GET","/clients?page=99999999999999999999").status_code==422,"huge page number: 422, not 500")
r1=call(full,"POST","/budget-lines",{"client_id":1,"name":"idem case","amount":1},{"Idempotency-Key":"case-key"})
r2=call(full,"POST","/budget-lines",{"client_id":1,"name":"idem case","amount":1},{"Idempotency-Key":"CASE-KEY"})
ok(r1.status_code==201 and r2.status_code==201 and r1.json()["data"]["id"]!=r2.json()["data"]["id"],"Idempotency-Key is case-sensitive")
kid=q("select id from api_keys where name='e2e full'")[0]["id"]
r=call(full,"POST","/budget-lines",{"client_id":1,"amount":1},{"Idempotency-Key":"retry-after-fail"})
r2=call(full,"POST","/budget-lines",{"client_id":1,"name":"after fail","amount":1},{"Idempotency-Key":"retry-after-fail"})
ok(r.status_code==422 and r2.status_code==201,"a failed request frees its Idempotency-Key for the corrected retry")
body={"client_id":1,"name":"in progress","amount":1}
import hashlib
q("insert into api_idempotency (key_id,idem_key,request_hash,status,body) values (%s,'busy-key',%s,0,'')",kid,hashlib.sha256(("/api/v1/budget-lines\n"+json.dumps(body,separators=(",",":"))).encode()).hexdigest())
r=call(full,"POST","/budget-lines",body,{"Idempotency-Key":"busy-key"})
ok(r.status_code==409 and r.json()["error"]["code"] in ("idempotency_in_progress","idempotency_conflict"),"a key still being processed answers 409: "+r.json()["error"]["code"])
for x in [r1,r2]:
    pass
q("delete from budget_lines where name in ('idem case','after fail','in progress')")
r=call(full,"POST","/meetings",{"client_id":1,"starts_at":"2026-12-10T10:00:00","attendees":["a@b.example"],"send_invites":True}); inv=r.json()["data"]["invitations"]
ok(r.status_code==201 and inv["status"] in ("sent","failed","not_sent") and "message" in inv,"invitation result is a status without provider error text: "+str(inv))
call(full,"DELETE","/meetings/"+str(r.json()["data"]["id"]))

# ---- OpenAPI
r=requests.get(API+"/openapi.json"); s=r.json()
ok(r.status_code==200 and s["openapi"]=="3.1.0" and "/api/v1/projects/{id}" in s["paths"] and "patch" in s["paths"]["/api/v1/devices/{id}"],"OpenAPI served without a key")
ids=[op["operationId"] for p in s["paths"].values() for op in p.values()]
ok(len(ids)==len(set(ids)),"operationIds unique: "+", ".join(sorted(ids)[:6])+"…")
pb=s["paths"]["/api/v1/projects"]["post"]["requestBody"]["content"]["application/json"]["schema"]
ok(pb["required"]==["client_id","title"] and pb["additionalProperties"] is False and "enum" in pb["properties"]["status"],"request schemas generated from the validation rules")
try:
    from openapi_spec_validator import validate
    validate(s); ok(True,"OpenAPI document validates")
except ImportError:
    print("SKIP openapi-spec-validator not installed")
except Exception as ex:
    ok(False,"OpenAPI validation: "+str(ex)[:300])

# ---- the docs site's API page (mspalign.org/api.html and openapi.json) is the same API, from the same code
import tempfile, shutil, subprocess as sp
site=tempfile.mkdtemp()
b=sp.run(["python3",ROOT+"/tools/docs/build.py",site],capture_output=True,text=True)
ok(b.returncode==0,"the docs site builds: "+(b.stdout+b.stderr)[-300:])
if b.returncode==0:
    d=json.load(open(site+"/openapi.json")); page=open(site+"/api.html").read()
    ok(d["paths"]==s["paths"] and d["components"]==s["components"],"the docs' OpenAPI description matches what the server serves")
    ok(all(f'id="{i}"' in page for i in ids) and 'href="releases.html"' in page,"the docs' API page has every endpoint")
    ok(d["components"]["schemas"]["Client"]["properties"]["psa_id"]["type"]==["string","null"] and "Ids from your PSA are text" in page,"and says PSA ids are text")
shutil.rmtree(site,ignore_errors=True)

# ---- request log & key usage
ok(q("select count(*) n from api_requests where key_id is not null")[0]["n"]>50 and q("select last_used_at from api_keys where name='e2e full'")[0]["last_used_at"],"requests logged, last used recorded")
print("FAILURES:",len(fails))
