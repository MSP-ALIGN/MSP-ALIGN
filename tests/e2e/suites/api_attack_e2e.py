"""Adversarial checks for the REST API: permission and client-isolation matrices generated from the spec,
fuzzing, regressions for both security reviews, concurrency, contract (responses vs OpenAPI) and no 500s."""
from lib import *
import re, json, subprocess, time, threading, hashlib, urllib.parse
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a or None); return c.fetchall()
API=B+"/api/v1"
PHP='require "'+BOOTSTRAP+'"; '
def php(code): return subprocess.run(["php","-r",PHP+code],env=ENV,capture_output=True,text=True).stdout.strip()
def mk(name, scopes, clients=None, rate=5000, created_by=1):
    return php(f'[$i,$t]=Align\\Api\\Keys::create({json.dumps(name)}, json_decode({json.dumps(json.dumps(scopes))},true), {("json_decode("+json.dumps(json.dumps(clients))+",true)") if clients is not None else "null"}, null, {rate}, null, {created_by}); echo $t;')
ALL=json.loads(php('echo json_encode(Align\\Api\\Keys::allScopes());'))
READ=[s for s in ALL if s.endswith(":read")]
def call(key, method, path, body=None, headers=None, raw=None):
    h={"Authorization":"Bearer "+key} if key else {}
    h.update(headers or {})
    if body is not None or raw is not None: h["Content-Type"]="application/json"
    return requests.request(method, API+path, headers=h, data=raw if raw is not None else (json.dumps(body) if body is not None else None), timeout=60)

for t in ["api_keys","api_requests","api_idempotency","api_rate","api_ip_rate"]: q(f"delete from {t}")
q("update settings set value='1' where name='api_enabled'")
full=mk("atk full", ALL)
spec=requests.get(API+"/openapi.json").json()
ops=[(path,m.upper(),op) for path,item in spec["paths"].items() for m,op in item.items()]
ok(len(ops)==61,f"{len(ops)} operations in the spec")  # 2.3.0: +8 alignment; 2.4.0: +1 changes; 2.5.0: +2 health; 2.10.0: +3 vendors

# sample ids for path params
cid_a=1; cid_b=2
ids={"id":cid_a,"framework":q("select framework_id from client_frameworks where client_id=1 limit 1")[0]["framework_id"],
     "control":1,"exemption":1,"uid":"x","review":1}
def fill(path, over=None):
    d=dict(ids); d.update(over or {})
    return re.sub(r"\{(\w+)\}", lambda m: urllib.parse.quote(str(d[m.group(1)]),safe=""), path.replace("/api/v1",""))

# ---- 1. permission matrix: each operation refuses a key without its scope
bad=[]
for path,m,op in ops:
    scope=(op.get("security") or [{}])[0].get("bearer",[None])
    scope=scope[0] if scope else None
    if not scope: continue
    area=scope.split(":")[0]
    missing=[s for s in ALL if not s.startswith(area+":")] if scope.endswith(":read") else [s for s in ALL if s!=scope]
    k=mk("atk no "+scope, missing)
    r=call(k,m,fill(path),{} if m in ("POST","PATCH","PUT") else None)
    if not (r.status_code==403 and r.json()["error"]["code"]=="insufficient_scope" and r.headers.get("X-Required-Scope")==scope):
        bad.append(f"{m} {path} -> {r.status_code} {r.text[:80]}")
ok(not bad,"every operation refuses a key without its scope ("+str(len(ops)-1)+" checked): "+"; ".join(bad[:5]))

# ---- 2. client isolation matrix: a key for client 2 never sees client 1
lim=mk("atk client2", ALL, clients=[cid_b])
# 2.7.7: a meeting of client 1 to probe, made here when no earlier suite left one (the suites also run in groups)
if not q("select id from meetings where client_id=1 limit 1"):
    q("insert into meetings (uid, client_id, title, type, status, starts_at, ends_at) values (%s, 1, 'API isolation probe', 'qbr', 'scheduled', now() + interval 30 day, now() + interval 30 day + interval 1 hour)",
      "atk-" + __import__("secrets").token_hex(8))
    __import__("atexit").register(lambda: q("delete from meetings where title='API isolation probe' and client_id=1"))
one={"project":q("select id from roadmap_items where client_id=1 limit 1")[0]["id"],"line":q("select id from budget_lines where client_id=1 limit 1")[0]["id"],
     "license":q("select id from licenses where client_id=1 limit 1")[0]["id"],"meeting":q("select id from meetings where client_id=1 limit 1")[0]["id"],
     "contact":q("select id from contacts where client_id=1 limit 1")[0]["id"]}
dev1=call(full,"GET","/devices?client_id=1&per_page=1").json()["data"][0]["id"]
leaks=[]
probes=[("GET",f"/clients/{cid_a}"),("GET",f"/clients/{cid_a}/contacts"),("GET",f"/contacts/{one['contact']}"),("GET",f"/devices/{dev1}"),("PATCH",f"/devices/{dev1}",{"notes":"x"}),
        ("GET",f"/projects/{one['project']}"),("PATCH",f"/projects/{one['project']}",{"title":"x"}),("DELETE",f"/projects/{one['project']}"),
        ("GET",f"/clients/{cid_a}/budget"),("GET",f"/budget-lines/{one['line']}"),("PATCH",f"/budget-lines/{one['line']}",{"name":"x"}),("DELETE",f"/budget-lines/{one['line']}"),
        ("GET",f"/licenses/{one['license']}"),("PATCH",f"/licenses/{one['license']}",{"unit_price":1}),("GET",f"/meetings/{one['meeting']}"),("PATCH",f"/meetings/{one['meeting']}",{"title":"x"}),
        ("DELETE",f"/meetings/{one['meeting']}"),("GET",f"/clients/{cid_a}/compliance"),("POST",f"/clients/{cid_a}/compliance",{"framework_id":1}),
        ("GET",f"/clients/{cid_a}/compliance/{ids['framework']}/controls"),("PATCH",f"/clients/{cid_a}/compliance/{ids['framework']}/controls",{"controls":[{"id":1,"status":"met"}]}),
        ("GET",f"/clients/{cid_a}/backups"),("GET",f"/clients/{cid_a}/backup-exemptions"),("POST",f"/clients/{cid_a}/backup-exemptions",{"kind":"device","device_id":dev1,"reason":"x"}),
        ("GET",f"/clients/{cid_a}/service-levels"),("GET",f"/devices?client_id={cid_a}"),("GET",f"/projects?client_id={cid_a}"),("GET",f"/licenses?client_id={cid_a}"),
        ("POST","/projects",{"client_id":cid_a,"title":"x"}),("POST","/budget-lines",{"client_id":cid_a,"name":"x","amount":1}),("POST","/licenses",{"client_id":cid_a,"name":"x"}),
        ("POST","/meetings",{"client_id":cid_a,"starts_at":"2026-12-01T10:00:00"}),
        ("GET",f"/clients/{cid_a}/alignment"),("GET",f"/clients/{cid_a}/alignment/reviews"),("POST",f"/clients/{cid_a}/alignment/reviews",{}),
        ("GET",f"/clients/{cid_a}/changes"),("GET",f"/clients/{cid_a}/health"),("GET",f"/clients/{cid_a}/health/history")]
for p in probes:
    r=call(lim,p[0],p[1],p[2] if len(p)>2 else None)
    if r.status_code not in (404,):
        leaks.append(f"{p[0]} {p[1]} -> {r.status_code}")
ok(not leaks,f"client-2 key gets 404 on all {len(probes)} client-1 probes: "+"; ".join(leaks[:5]))
listing_leak=[]
for path in ["/clients","/contacts","/devices","/projects","/budget-lines","/licenses","/meetings","/backups"]:
    for x in call(lim,"GET",path+"?per_page=200").json()["data"]:
        if x.get("client_id",x.get("id") if path=="/clients" else None) not in (cid_b,):
            listing_leak.append(path); break
ok(not listing_leak,"lists for the client-2 key only hold client 2: "+str(listing_leak))
ok(call(lim,"GET","/backups/hosted").status_code==403 and call(lim,"PUT","/backups/hosted/jobs/j-0102",{"assign":2}).status_code==403,"hosted backups refused for a limited key")
ok(q("select count(*) n from roadmap_items where id=%s",one["project"])[0]["n"]==1 and q("select count(*) n from meetings where id=%s",one["meeting"])[0]["n"]==1,"nothing of client 1 was deleted")

# ---- 3. regressions from review 2
bud=mk("atk budget only", ["budget:read"])
b=call(bud,"GET","/clients/1/budget").json()["data"]
lic_named=[l for l in b["lines"] if l["source"] in ("licensing","projects") and (l["detail"] or l["name"] not in b["categories"].values())]
ok(not lic_named and not [d for d in b["contract_dates"] if d["name"]=="Dentrix G7"],"M1: budget-only key sees license/project amounts but not their names, prices or terms")
fb=call(full,"GET","/clients/1/budget").json()["data"]
ok(any(l["name"]=="Dentrix G7" for l in fb["lines"]),"M1: a key with licensing access still sees them")
bk=mk("atk backups only", ["backups:read"])
ok(call(bk,"GET","/clients/1/backups").json()["data"]["servers_without_backup"] is None,"M1: backups-only key doesn't get server names")
m=call(full,"POST","/meetings",{"client_id":2,"starts_at":"2026-12-02T09:00:00-08:00","attendees":["pat@northfieldhardware.example"],"send_invites":True}).json()["data"]
r=call(lim,"POST","/meetings",{"client_id":2,"starts_at":"2026-12-02T09:00:00-08:00","attendees":["x@evil.example"],"send_invites":True})
ok(r.status_code==422 and "send_invites" in r.json()["error"]["fields"],"M2: limited key can't send invitations")
r=call(lim,"PATCH",f"/meetings/{m['id']}",{"attendees":["attacker@evil.example"]})
ok(r.status_code==422 and "attendees" in r.json()["error"]["fields"],"M2: limited key can't change who's invited once invitations went out")
call(full,"PATCH",f"/meetings/{m['id']}",{"status":"cancelled"})
r=call(full,"PATCH",f"/meetings/{m['id']}",{"status":"scheduled","attendees":["someone@else.example"]})
ok(r.json()["data"]["invitations"] is None,"M2: reopening doesn't send anything unless send_invites is true")
call(full,"DELETE",f"/meetings/{m['id']}")
# archived client
q("insert into devices (source,client_id,display_name,device_type,device_class,created_at) values ('manual',5,'ARCH-PC','Desktop','desktop',now())"); arch_dev=q("select max(id) i from devices")[0]["i"]
q("insert into meetings (uid,client_id,title,type,status,starts_at,ends_at) values (uuid(),5,'Archived mtg','other','scheduled','2026-12-01 10:00','2026-12-01 11:00')"); arch_m=q("select max(id) i from meetings")[0]["i"]
arch_key=php('echo 1;') and mk("atk archived", ALL, clients=[5])
ok(call(full,"GET",f"/devices/{arch_dev}").status_code==404 and call(full,"GET",f"/meetings/{arch_m}").status_code==404 and call(arch_key,"PATCH",f"/devices/{arch_dev}",{"notes":"x"}).status_code==404,"M3: archived client's devices and meetings are 404, even for a key limited to it")
ok(not any(x["id"]==arch_m for x in call(full,"GET","/meetings?per_page=200&from=2026-11-30&to=2026-12-02").json()["data"]),"M3: archived meetings not listed")
q("delete from devices where id=%s",arch_dev); q("delete from meetings where id=%s",arch_m)
for body,path in [({"client_id":1,"starts_at":"2026-12-01 09:00 +100000 years"},"/meetings"),({"client_id":1,"starts_at":"0000-00-00 00:00"},"/meetings"),
                  ({"client_id":1,"starts_at":"9998-12-31 23:59","duration_minutes":600},"/meetings"),
                  ({"client_id":1,"name":"x","amount":1,"start_date":"9998-06-01","contract_term_months":240},"/budget-lines"),
                  ({"client_id":1,"name":"x","contract_start":"9998-06-01","contract_term_months":240},"/licenses"),
                  ({"client_id":1,"name":"x","amount":1,"contract_end":"1970-01-01","notice_days":730},"/budget-lines")]:
    r=call(full,"POST",path,body)
    ok(r.status_code==422,f"L1: {path} {list(body.items())[-1]} -> 422 (got {r.status_code})")
r=call(full,"POST","/meetings",{"client_id":1,"starts_at":"2026-12-01T10:00:00","attendees":["a"*60+f"{i}@example.com" for i in range(70)]})
ok(r.status_code==422 and "attendees" in r.json()["error"]["fields"],"L2: over-long attendee list refused instead of cut")
# L3 replay after restriction change
k3=mk("atk replay", ALL, clients=[2])
q("delete from client_frameworks where client_id=2 and framework_id=1")
r1=call(k3,"POST","/clients/2/compliance",{"framework_id":1},{"Idempotency-Key":"atk-k3"})
q("update api_keys set client_ids='[4]' where name='atk replay'")
r2=call(k3,"POST","/clients/2/compliance",{"framework_id":1},{"Idempotency-Key":"atk-k3"})
ok(r1.status_code==201 and r2.status_code==404,"L3: replay refused after the key lost that client")
q("delete from client_frameworks where client_id=2 and framework_id=1")
r=call(full,"POST","/meetings",{"starts_at":"2026-12-05T10:00:00","title":"Internal"},{"Idempotency-Key":"atk-internal"}); mid=r.json()["data"]["id"]
q("update api_keys set client_ids='[1]' where name='atk full'")
ok(call(full,"POST","/meetings",{"starts_at":"2026-12-05T10:00:00","title":"Internal"},{"Idempotency-Key":"atk-internal"}).status_code==404,"L3: internal meeting replay refused for a now-limited key")
q("update api_keys set client_ids=NULL where name='atk full'"); call(full,"DELETE",f"/meetings/{mid}")
# L4 floods
q("delete from api_ip_rate"); before=q("select count(*) n from api_requests")[0]["n"]
codes=[call("msa_AAAAAAAA_"+"x"*32,"GET","/clients").status_code for _ in range(40)]
after=q("select count(*) n from api_requests")[0]["n"]
ok(codes[:30]==[401]*30 and set(codes[30:])=={429},"L4: 31st failed request from one address gets 429")
ok(after-before<=31,f"L4: flood stops filling the request log ({after-before} rows for 40 requests)")
ok(call(full,"GET","/").status_code==200,"L4: valid keys from the same address still work")
ok(call(None,"GET","/nope").status_code in (401,429),"unknown routes need a key first (no route discovery without one)")
q("delete from api_ip_rate")
r=requests.get(API+"/clients",headers={"Authorization":"Basic abc"}); ok(r.status_code==401 and r.json()["error"]["code"]=="invalid_key","L4: malformed Authorization header is a failed attempt (invalid_key)")
# L5 creator disabled
q("insert into users (email,name,password_hash,role,is_active) values ('atk-admin@example.com','Temp Admin','x','admin',1)"); uid=q("select id from users where email='atk-admin@example.com'")[0]["id"]
k5=mk("atk temp admin key", READ, created_by=uid)
ok(call(k5,"GET","/").status_code==200,"L5: key works while its creator is active")
q("update users set is_active=0 where id=%s",uid)
ok(call(k5,"GET","/").json()["error"]["code"]=="key_owner_inactive","L5: key stops when its creator is disabled")
q("delete from api_keys where created_by=%s",uid); q("delete from users where id=%s",uid)
# L6 control characters
r=call(full,"POST","/projects",{"client_id":1,"title":"Hi\rATTENDEE;RSVP=TRUE:mailto:x@evil.example\rX","description":"line1\r\nline2\x07"})
p=r.json()["data"]; ok("\r" not in p["title"] and p["description"]=="line1\nline2","L6: control characters stripped (CRLF kept as newline)")
call(full,"DELETE",f"/projects/{p['id']}")
ics=php('$r=new ReflectionMethod(Align\\Meetings\\Ics::class,"esc"); $r->setAccessible(true); echo json_encode($r->invoke(null,"a\\rATTENDEE:x"));')
ok("\\r" not in ics and "\\\\n" in ics,"L6: calendar feed escapes a lone CR: "+ics)
# headers
r=call(full,"GET","/"); ok("X-Powered-By" not in r.headers,"no X-Powered-By header")
r=requests.post(API+"/projects",headers={"Authorization":"Bearer "+full,"Content-Type":"application/jsonp"},data='{"client_id":1,"title":"x"}'); ok(r.status_code==415,"Content-Type must be exactly application/json")

# ---- 4. fuzzing: no 500s
weird=[[],{},"x"*300000,"'; DROP TABLE clients; --",-1,1e308,True,None,"\u0000","../../etc/passwd","<script>alert(1)</script>",99999999999999999999,"2026-02-30","NaN"]
five=[]
for path,m,op in ops:
    if m=="DELETE": continue
    rb=op.get("requestBody",{}).get("content",{}).get("application/json",{}).get("schema",{}).get("properties",{})
    for field in list(rb)[:12]:
        for w in weird:
            r=call(full,m,fill(path,{"id":8 if "clients/{id}" in path else 999999}),{field:w})
            if r.status_code>=500: five.append(f"{m} {path} {field}={str(w)[:20]} -> {r.status_code}")
    for prm in [p["name"] for p in op.get("parameters",[]) if p["in"]=="query"]:
        for w in ["' OR 1=1 --","%00","-1","99999999999999999999","2026-13-45","<x>","a"*5000]:
            r=requests.get(API+fill(path)+"?"+urllib.parse.urlencode({prm:w}),headers={"Authorization":"Bearer "+full}) if m=="GET" else None
            if r is not None and r.status_code>=500: five.append(f"GET {path}?{prm}={w[:15]} -> {r.status_code}")
ok(not five,f"fuzzing every body field and query parameter: no 500s ({len(five)}): "+"; ".join(five[:6]))
q("delete from api_rate")
for pth in ["/devices/%2e%2e","/backups/hosted/machines/..%2F..%2Fclients","/backups/hosted/machines/%00","/clients/1%20OR%201=1","//clients","/clients/","/CLIENTS"]:
    r=call(full,"GET",pth); ok(r.status_code in (200,404,405),f"odd path {pth} -> {r.status_code}")
r=call(full,"POST","/projects",raw="["*40+"]"*40); ok(r.status_code==400,"deep JSON refused")
r=call(full,"POST","/projects",raw='{"client_id":1,"title":"a","title":"b"}'); ok(r.status_code==201 and r.json()["data"]["title"]=="b","duplicate JSON keys: last wins (like JSON.parse)"); call(full,"DELETE","/projects/"+str(r.json()["data"]["id"]))

q("delete from api_rate")
# ---- 5. concurrency
body={"client_id":1,"name":"concurrent line","amount":5}
res=[]
def post(): res.append(call(full,"POST","/budget-lines",body,{"Idempotency-Key":"atk-concurrent"}))
th=[threading.Thread(target=post) for _ in range(10)]; [t.start() for t in th]; [t.join() for t in th]
n=q("select count(*) n from budget_lines where name='concurrent line'")[0]["n"]
ok(n==1 and all(r.status_code in (201,409) for r in res),f"10 simultaneous retries with one Idempotency-Key create exactly 1 ({n}); others replay or 409: {sorted(r.status_code for r in res)}")
q("delete from budget_lines where name='concurrent line'")
rl=mk("atk rate10", READ, rate=10); res=[]
def get(): res.append(call(rl,"GET","/").status_code)
th=[threading.Thread(target=get) for _ in range(30)]; [t.start() for t in th]; [t.join() for t in th]
ok(res.count(200)==10 and res.count(429)==20,f"30 simultaneous requests on a 10/min key: exactly 10 served ({res.count(200)})")

q("delete from api_rate")
# ---- 6. contract: every GET response matches the documented schema
schemas=spec["components"]["schemas"]
def types_of(s):
    if "$ref" in s: return {"object"}
    t=s.get("type","object"); return set(t if isinstance(t,list) else [t])
def pytype(v): return "null" if v is None else "boolean" if isinstance(v,bool) else "integer" if isinstance(v,int) else "number" if isinstance(v,float) else "string" if isinstance(v,str) else "array" if isinstance(v,list) else "object"
drift=[]
getp={"/api/v1/clients/{id}/compliance/{framework}/controls":{"framework":ids["framework"]},"/api/v1/contacts/{id}":{"id":one["contact"]},"/api/v1/devices/{id}":{"id":dev1},
      "/api/v1/projects/{id}":{"id":one["project"]},"/api/v1/budget-lines/{id}":{"id":one["line"]},"/api/v1/licenses/{id}":{"id":one["license"]},"/api/v1/meetings/{id}":{"id":one["meeting"]}}
checked=0
# 2.3.0: a review to read back (a draft lists every standard)
rid=call(full,"POST","/clients/1/alignment/reviews",{}).json()["data"]["id"]
getp["/api/v1/clients/{id}/alignment/reviews/{review}"]={"review":rid}
for path,m,op in ops:
    if m!="GET" or "openapi" in path: continue
    r=call(full,"GET",fill(path,getp.get(path)))
    if r.status_code!=200: drift.append(f"{path} -> {r.status_code}"); continue
    sch=op["responses"]["200"]["content"]["application/json"]["schema"]["properties"]["data"]
    ref=(sch.get("items",sch)).get("$ref","").split("/")[-1]
    if not ref: continue
    props=schemas[ref].get("properties") or {}
    if "allOf" in schemas[ref]:
        props={**schemas["Client"]["properties"],**schemas[ref]["allOf"][1]["properties"]}
    data=r.json()["data"]; items=data if isinstance(data,list) else [data]
    for it in items[:20]:
        for k,v in it.items():
            if k not in props: drift.append(f"{path}: undocumented field {k}"); continue
            want=types_of(props[k])
            got=pytype(v)
            if got not in want and not (got=="integer" and "number" in want): drift.append(f"{path}.{k}: {got} not in {sorted(want)}")
    checked+=1
ok(not drift,f"contract: {checked} GET responses match the OpenAPI schemas: "+"; ".join(sorted(set(drift))[:8]))
call(full,"DELETE",f"/clients/1/alignment/reviews/{rid}")

# ---- 7. nothing crashed, audit chain intact
e=q("select method,path,status from api_requests where status>=500")
ok(not e,"no 500 anywhere in the request log: "+str(e[:5]))
v=subprocess.run(["php",ALIGN,"audit:verify"],env=ENV,capture_output=True,text=True)
ok(v.returncode==0,"audit log hash chain intact after all API writes: "+(v.stdout+v.stderr).strip()[-120:])
for t in ["api_keys","api_idempotency","api_rate","api_ip_rate"]: q(f"delete from {t}")
print("FAILURES:",len(fails))
