"""1.29 RMM layer: neutral schema, NinjaOne as a provider, client links, mapping, API compatibility, no-RMM behavior, upgrade."""
from lib import *
import re, json, subprocess
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a or None); return c.fetchall()
S=WORK
API=B+"/api/v1"
def php(code): return subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; '+code],env=ENV,capture_output=True,text=True)
def align(*a): return subprocess.run(["php",ALIGN,*a],env=ENV,capture_output=True,text=True)
st=login("chris@example.com","LongPassword123!")

# ---- schema
left=q("select table_name t, column_name c from information_schema.columns where table_schema=database() and (column_name like '%%ninja%%' or table_name like 'ninja%%')")
ok(not left,"no ninja-named tables or columns: "+str(left))
ok(not q("select 1 from information_schema.columns where table_schema=database() and table_name='clients' and column_name='match_method'"),"clients.match_method moved to client_links")
ok(q("select count(*) n from devices where source='rmm' and rmm_provider='ninjaone' and rmm_device_id is not null")[0]["n"]>1000,"NinjaOne devices: source rmm, provider ninjaone")
ok(q("select count(*) n from rmm_orgs where provider='ninjaone'")[0]["n"]==5,"5 NinjaOne organizations in rmm_orgs")
links=q("select * from client_links where provider='ninjaone'"); ok(len(links)>=3,f"client links carried over ({len(links)})")

# ---- provider
out=php('$p=Align\\Providers\\Providers::rmm("ninjaone"); $d=$p->devices(); echo json_encode([get_class($p), count($d), array_keys($d[0]), is_string($d[0]["id"]), count($p->organizations()), Align\\Providers\\Providers::rmmNames()]);').stdout
j=json.loads(out); ok(j[0]=="Align\\Providers\\Rmm\\NinjaOneRmm" and j[1]>1000 and j[3] and j[4]==5 and j[5]=="NinjaOne","NinjaOne is an RMM provider with string ids: "+out[:100])
ok({"id","org_id","display_name","device_type","serial","os_name","last_contact","created_at","offline"} <= set(j[2]) and "ninja_device_id" not in j[2],"devices come back as neutral records")

# ---- a sync keeps every device row (no duplicates, same ids)
before={(r["rmm_device_id"]):r["id"] for r in q("select id, rmm_device_id from devices where rmm_provider='ninjaone'")}
r=align("sync","--quiet"); after={(r["rmm_device_id"]):r["id"] for r in q("select id, rmm_device_id from devices where rmm_provider='ninjaone'")}
ok(before==after,f"sync updates the same {len(after)} device rows")
run=json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
ok("NinjaOne organizations" in run and "NinjaOne devices" in run and "Match clients to organizations" in run,"sync steps keep their names: "+", ".join(k for k in run if "inja" in k or "Match" in k))

# ---- client mapping: the page, saving (new and pre-1.29 form fields), deliberate unlink
t=st.get(B+"/mapping").text
ok('name="link[ninjaone][' in t and "NinjaOne organization" in t and not errs(t),"mapping page has a NinjaOne column")
cid=links[0]["client_id"]; org=links[0]["external_id"]
F={"_csrf":csrf(st,"/mapping")}
for row in re.findall(r'name="(rmm\[ninjaone\]\[\d+\])"', t):
    m=re.search(re.escape(row)+r'".*?<option value="([^"]*)" selected', t, re.S)
    F[row]=""  # fill below
for c in q("select id from clients where is_archived=0 and planning_excluded=0"):
    l=q("select external_id from client_links where client_id=%s and provider='ninjaone'",c["id"])
    F[f"rmm[ninjaone][{c['id']}]"]=(l[0]["external_id"] or "") if l else ""
F[f"rmm[ninjaone][{cid}]"]=""
r=st.post(B+"/mapping",data=F); ok("Saved 1 change" in flash(r.text),"unlink one client: "+flash(r.text))
l=q("select * from client_links where client_id=%s and provider='ninjaone'",cid)
ok(l and l[0]["external_id"] is None and l[0]["match_method"]=="manual","deliberately unlinked is remembered")
align("sync","--quiet")
ok(q("select external_id from client_links where client_id=%s and provider='ninjaone'",cid)[0]["external_id"] is None,"auto-match leaves a deliberate unlink alone")
dcount=lambda: len(re.findall(r'href="/devices/\d+"', st.get(B+f"/clients/{cid}/devices").text))
own=q("select count(*) n from devices where client_id=%s and removed_at is null",cid)[0]["n"]
ok(dcount()==own,f"unlinked client shows only its own PSA/hand-added devices ({dcount()} of {own})")
F2={"_csrf":csrf(st,"/mapping")}; F2.update({k.replace("rmm[ninjaone]","org"):v for k,v in F.items() if k.startswith("rmm[")}); F2[f"org[{cid}]"]=org
r=st.post(B+"/mapping",data=F2); ok("Saved 1 change" in flash(r.text),"pre-1.29 org[] field still saves: "+flash(r.text))
ok(q("select external_id from client_links where client_id=%s and provider='ninjaone'",cid)[0]["external_id"]==org,"relinked")
ok(dcount()>0,f"devices back on the client ({dcount()})")
other=q("select client_id from client_links where provider='ninjaone' and external_id is not null and client_id<>%s limit 1",cid)[0]["client_id"]
F3=dict(F2); F3["_csrf"]=csrf(st,"/mapping"); F3[f"org[{other}]"]=org
r=st.post(B+"/mapping",data=F3); ok("only be linked to one client" in flash(r.text),"one organization can't go to two clients")
ok(q("select count(*) n from audit_log where action='mapping.save' and detail like '%%ninjaone%%'")[0]["n"]>0,"mapping changes audited")

# ---- API keeps v1 answers
php('$k=Align\\Api\\Keys::create("rmm e2e", Align\\Api\\Keys::allScopes(), null, null, 600, null, 1); file_put_contents("/tmp/rmm_key", $k[1]);'); key=open("/tmp/rmm_key").read().strip()
q("update settings set value='1' where name='api_enabled'")
dv=q("select id, rmm_device_id, rmm_org_id from devices where rmm_provider='ninjaone' and removed_at is null limit 1")[0]
d=requests.get(API+f"/devices/{dv['id']}",headers={"Authorization":"Bearer "+key}).json()["data"]
ok(d["source"]=="ninja" and d["ninja_device_id"]==int(dv["rmm_device_id"]) and d["rmm"]=={"provider":"ninjaone","device_id":dv["rmm_device_id"],"organization_id":dv["rmm_org_id"]},"device: source ninja, ninja_device_id, new rmm object")
ok(requests.get(API+"/openapi.json").json()["components"]["schemas"]["Device"]["properties"].get("rmm"),"OpenAPI documents rmm")
q("delete from api_keys where name='rmm e2e'")

# ---- screens
t=st.get(B+f"/devices/{dv['id']}").text
ok("/#/deviceDashboard/"+str(dv["rmm_device_id"])+"/overview" in t and "fa-user-ninja" in t and "Hardware details are owned by NinjaOne" in t and not errs(t),"device page: NinjaOne link, icon and wording")
t=st.get(B+"/clients").text; ok(not errs(t) and "fa-link" in t,"clients list shows linked organizations")
t=st.get(B+f"/clients/{cid}").text; ok("Linked to NinjaOne" in t and not errs(t),"planning checklist: Linked to NinjaOne")
t=st.get(B+"/sync").text; ok("NinjaOne organizations and devices" in t and not errs(t),"sync page wording")

# ---- no RMM connected: pages render, sync skips it
sid=q("select value from settings where name='ninja_client_id'")[0]["value"]
q("update settings set value='' where name='ninja_client_id'")
bad=[]
for p in ["/","/clients",f"/clients/{cid}",f"/clients/{cid}/devices",f"/devices/{dv['id']}","/mapping","/integrations","/integrations/ninjaone","/sync","/help"]:
    rr=st.get(B+p)
    if rr.status_code>=500 or errs(rr.text): bad.append((p,rr.status_code,errs(rr.text)[:1]))
ok(not bad,"pages render with no RMM connected: "+str(bad))
out=php('echo json_encode([Align\\Providers\\Providers::anyRmm(), Align\\Providers\\Providers::rmmNames()]);').stdout; ok(json.loads(out)==[False,"NinjaOne"],"no RMM set up: still named NinjaOne (the only RMM Align supports): "+out)
r=align("sync","--quiet"); run=json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
ok(run.get("NinjaOne")=="not configured" and "NinjaOne devices" not in run,"sync notes NinjaOne as not configured")
ok(q("select count(*) n from devices where rmm_provider='ninjaone' and removed_at is null")[0]["n"]>1000,"devices are kept while NinjaOne isn't connected")
q("update settings set value=%s where name='ninja_client_id'",sid)

# ---- upgrade a 1.28 install
upg=load_snapshot("main_128","align_upg")
r=subprocess.run(["php",ALIGN,"migrate"],env={**ENV,"ALIGN_CONFIG":upg},capture_output=True,text=True)
u=pymysql.connect(unix_socket=SOCKET,user="root",database="align_upg",cursorclass=pymysql.cursors.DictCursor)
with u.cursor() as c: c.execute("select count(*) n from client_links where provider=%s","ninjaone"); nl=c.fetchall()[0]["n"]
with u.cursor() as c: c.execute("select count(*) n from devices where rmm_provider='ninjaone'"); nd=c.fetchall()[0]["n"]
u.close(); mysql("drop database align_upg")
ok(r.returncode==0 and nl==3 and nd==1150,f"a 1.28 database upgrades: {nl} links, {nd} NinjaOne devices")

print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
