"""1.28 PSA layer: neutral schema, ITFlow as a provider, API compatibility, legacy URLs/commands, no-PSA behavior, migration safety."""
from lib import *
import re, json, subprocess, time
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a or None); return c.fetchall()
S=WORK
API=B+"/api/v1"
def php(code): return subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; '+code],env=ENV,capture_output=True,text=True)
def call(key, method, path, body=None):
    h={"Authorization":"Bearer "+key}
    if body is not None: h["Content-Type"]="application/json"
    return requests.request(method, API+path, headers=h, data=json.dumps(body) if body is not None else None)
st=login("admin@example.com","LongPassword123!")

# ---- schema: nothing ITFlow-named is left in the database
cols=q("select table_name t, column_name c from information_schema.columns where table_schema=database() and (column_name like '%%itflow%%' or table_name like 'itflow%%')")
ok(not cols,"no itflow-named tables or columns: "+str(cols[:5]))
enums=q("select table_name t, column_name c, column_type ty from information_schema.columns where table_schema=database() and data_type='enum' and column_type like '%%itflow%%'")
ok(not enums,"no enum still offers 'itflow': "+str(enums))
ok(not q("select name from settings where name in ('itflow_two_way','itflow_create_assets','itflow_import_types','itflow_sla_sync','itflow_sla_supported','itflow_tickets_state','itflow_writeback')"),"behavior settings renamed to psa_*")
ok(q("select value from settings where name='psa_provider'")[0]["value"]=="itflow","psa_provider = itflow")
ok(q("select count(*) n from clients where source='psa' and psa_id is not null")[0]["n"]>0 and q("select count(*) n from devices where source='psa' and psa_asset_id is not null")[0]["n"]>0,"PSA records carry source=psa and psa ids")

# ---- the provider layer
out=php('$p=Align\\Providers\\Providers::psa(); echo json_encode([get_class($p), $p->name(), Align\\Providers\\Providers::psaName(), Align\\Providers\\Providers::psaSupports("assets.write"), count($p->clients()) > 0, array_keys($p->assets()[0] ?? []), $p->assetStatus(true), $p->mapAssetType(["type"=>"Switch"])]);').stdout
j=json.loads(out); ok(j[0]=="Align\\Providers\\Psa\\ItflowPsa" and j[1]=="ITFlow" and j[2]=="ITFlow" and j[3] is True and j[4],"ITFlow is the PSA provider: "+out[:120])
ok({"id","client_id","name","type","serial","purchase_date","warranty_expire","status","archived","updated_at"} <= set(j[5]) and not any(k.startswith("asset_") for k in j[5]),"assets come back as neutral records: "+str(j[5]))
ok(j[6]=="Retired" and j[7][0]=="Switch","type and status rules come from the provider")
out=php('$n=0; Align\\Providers\\Providers::psa()->tickets(date("Y-m-d H:i:s", strtotime("-36 months")), ["force_full"=>true], function($r) use (&$n) { $n += count($r); if ($r) echo json_encode(array_keys($r[0])), "\\n"; }); echo $n;').stdout.strip().split("\n")
ok(int(out[-1])>100 and "response_met" in out[0] and "ticket_" not in out[0],f"tickets stream as neutral records ({out[-1]})")

# ---- API: same answers as before, plus psa_* fields
php('$k=Align\\Api\\Keys::create("psa e2e", Align\\Api\\Keys::allScopes(), null, null, 600, null, 1); file_put_contents("/tmp/psa_key", $k[1]);'); key=open("/tmp/psa_key").read().strip()
q("update settings set value='1' where name='api_enabled'")
c=call(key,"GET","/clients/1").json()["data"]
ok(c["source"]=="itflow" and c["psa_id"] and c["itflow_client_id"]==c["psa_id"],"client: source still 'itflow', psa_id + itflow_client_id alias")
dv=q("select id from devices where psa_asset_id is not null and removed_at is null and client_id=1 limit 1")[0]["id"]
d=call(key,"GET",f"/devices/{dv}").json()["data"]
ok(d["psa_asset_id"] and d["itflow_asset_id"]==d["psa_asset_id"],"device: psa_asset_id + itflow_asset_id alias")
ok(d["source"] in ("ninja","itflow","manual"),"device source keeps its v1 values: "+d["source"])
lic=q("select id from licenses where source='psa' limit 1")[0]["id"]
l=call(key,"GET",f"/licenses/{lic}").json()["data"]
ok(l["source"]=="itflow" and l["psa_id"]==l["itflow_software_id"] and "psa_notes" in l and "itflow_notes" in l,"license: psa_id/psa_notes + aliases")
r=call(key,"DELETE",f"/licenses/{lic}"); ok(r.status_code==409 and r.json()["error"]["code"]=="managed_in_itflow" and "ITFlow" in r.json()["error"]["message"],"PSA license can't be deleted: "+r.text[:100])
r=call(key,"PATCH",f"/devices/{dv}",{"replacement_cost":1234}); dd=r.json()["data"]
ok(r.status_code==200 and dd["psa_sync"]==dd["itflow_sync"] and "status" in dd["psa_sync"],"PATCH device answers psa_sync (+ itflow_sync alias)")
spec=requests.get(API+"/openapi.json").json()
ok("psa_id" in spec["components"]["schemas"]["Client"]["properties"] and "itflow_client_id" in spec["components"]["schemas"]["Client"]["properties"],"OpenAPI documents psa_id and the alias")
b=call(key,"GET","/clients/1/budget").json()["data"]
ok(any(x["key"]=="managed-itflow" and x["source"]=="itflow" for x in b["lines"]) or not any(x["category"]=="managed" and x["source"]!="manual" for x in b["lines"]),"budget: managed-services estimate keeps its v1 key/source")

# ---- web: legacy URLs, labels
t=st.get(B+"/clients/1/devices?filter=itflow").text; t2=st.get(B+"/clients/1/devices?filter=psa").text
cnt=lambda s: len(re.findall(r'href="/devices/\d+"',s))
ok(not errs(t) and cnt(t)==cnt(t2) and cnt(t)>0 and "From ITFlow" in t2,f"old ?filter=itflow links still work ({cnt(t)} devices)")
pd=q("select id from devices where source='psa' and removed_at is null limit 1")[0]["id"]
r=st.post(B+f"/devices/{pd}/itflow-sync",data={"_csrf":csrf(st,f"/devices/{pd}"),"on":"0"}); ok(q("select psa_sync from devices where id=%s",pd)[0]["psa_sync"]==0,"old /itflow-sync form URL still works")
r=st.post(B+f"/devices/{pd}/psa-sync",data={"_csrf":csrf(st,f"/devices/{pd}"),"on":"1"}); ok(q("select psa_sync from devices where id=%s",pd)[0]["psa_sync"]==1,"new /psa-sync URL")
ok(q("select action from audit_log where action='device.psa_sync' order by id desc limit 1"),"audited as device.psa_sync")
t=st.get(B+f"/devices/{dv}").text; ok("ITFlow sync" in t and "/agent/asset.php?client_id=" in t and not errs(t),"device page: ITFlow name and asset link from the provider")
t=st.get(B+"/clients/1").text; ok("/agent/client_overview.php?client_id=" in t and not errs(t),"client header links to the PSA client")

# ---- commands
r1=subprocess.run(["php",ALIGN,"psa:poll"],env=ENV,capture_output=True,text=True)
r2=subprocess.run(["php",ALIGN,"itflow:poll"],env=ENV,capture_output=True,text=True)
ok(r1.returncode==0 and "assets read" in r1.stdout and r2.returncode==0 and "assets read" in r2.stdout,"psa:poll and the old itflow:poll both run: "+r1.stdout[:80])
ok(q("select last_result from psa_poll_state where id=1")[0]["last_result"].startswith("342") or "assets read" in q("select last_result from psa_poll_state where id=1")[0]["last_result"],"poll state recorded")

# ---- no PSA connected: everything still renders, sync and poll skip it
url=q("select value from settings where name='itflow_url'")[0]["value"]
q("update settings set value='' where name='itflow_url'"); q("update settings set value='' where name='psa_provider'")
bad=[]
for p in ["/","/clients","/clients/1","/clients/1/devices","/clients/1/contacts","/clients/1/licenses","/clients/1/budget","/clients/1/service-levels",f"/devices/{dv}","/devices/unassigned","/integrations","/integrations/itflow","/sync","/help","/reports"]:
    t=st.get(B+p);
    if t.status_code>=500 or errs(t.text): bad.append((p,t.status_code,errs(t.text)[:1]))
ok(not bad,"pages render with no PSA connected: "+str(bad))
t=st.get(B+"/clients/1").text; ok("/agent/client_overview.php" not in t,"no PSA links without a PSA")
r=subprocess.run(["php",ALIGN,"psa:poll"],env=ENV,capture_output=True,text=True); ok(r.returncode==0 and r.stdout.strip()=="","psa:poll exits quietly without a PSA")
out=php('echo json_encode([Align\\Providers\\Providers::psaName(), Align\\Providers\\Providers::psaConfigured(), Align\\Sync\\PsaAssetSync::twoWay(), Align\\Contacts\\Contacts::canPush(["psa_id"=>1])]);').stdout
ok(json.loads(out)==["PSA",False,False,False],"without a PSA: name 'PSA', two-way and contact push off: "+out)
# saving the ITFlow connector makes it the PSA again
F={"_csrf":csrf(st,"/integrations/itflow"),"itflow_url":url}
st.post(B+"/integrations/itflow",data=F)
ok(q("select value from settings where name='psa_provider'")[0]["value"]=="itflow","setting up ITFlow makes it the PSA")
ok(q("select value from settings where name='itflow_url'")[0]["value"]==url,"ITFlow URL restored")

# ---- 1.34: PSA ids are text (another PSA may use GUIDs)
cols=q("select concat(table_name,'.',column_name) c, data_type t from information_schema.columns where table_schema=database() and concat(table_name,'.',column_name) in ('clients.psa_id','contacts.psa_id','licenses.psa_id','devices.psa_asset_id','psa_assets.psa_asset_id','psa_assets.psa_client_id','psa_assets.location_id','psa_tickets.id','psa_tickets.psa_client_id','service_requests.psa_ticket_id')")
ok(len(cols)==10 and all(c["t"]=="varchar" for c in cols),"every PSA id column is text: "+",".join(c["c"]+"="+c["t"] for c in cols if c["t"]!="varchar"))
ok(q("select psa_id from clients where psa_id is not null order by id limit 1")[0]["psa_id"].isdigit(),"numeric ids kept as they were")
G="7f3c2a90-1b4e-4c1d-9a55-0e2f6b8d1c3a"; GA="asset-"+G; GT="tkt-"+G
q("insert into clients (name, source, psa_id, is_archived, planning_excluded) values ('Zz Guid Client','psa',%s,0,0)",G); gc=q("select id from clients where psa_id=%s",G)[0]["id"]
q("insert into psa_assets (psa_asset_id, psa_client_id, name, type, serial, is_archived, location_id) values (%s,%s,'ZZ-GUID-PC','Laptop','ZZG1',0,'loc-1')",GA,G)
q("insert into devices (source, client_id, display_name, system_name, device_type, psa_asset_id, psa_sync) values ('manual',%s,'ZZ-GUID-PC','ZZ-GUID-PC','laptop',%s,1)",gc,GA); gd=q("select id from devices where psa_asset_id=%s",GA)[0]["id"]
q("insert into psa_tickets (id, psa_client_id, client_id, number, subject, created_at, synced_at) values (%s,%s,%s,'G-1','Text id ticket',now(),now())",GT,G,gc)
bad=[p for p in [f"/clients/{gc}",f"/devices/{gd}",f"/clients/{gc}/devices",f"/clients/{gc}/service-levels","/devices/unassigned"] if st.get(B+p).status_code>=500 or errs(st.get(B+p).text)]
ok(not bad,"pages render for a client, device and ticket with text PSA ids: "+str(bad))
t=st.get(B+f"/devices/{gd}").text; ok("Linked (#"+GA+")" in t,"device page shows the text asset id")
r=call(key,"GET",f"/clients/{gc}"); ok(r.status_code==200 and r.json()["data"]["psa_id"]==G and r.json()["data"]["itflow_client_id"] is None,"API returns a text PSA id as text (the old numeric alias is null)")
r=call(key,"GET","/clients/1"); ok(isinstance(r.json()["data"]["psa_id"],int),"API still returns ITFlow's numeric ids as numbers")
out=align("sync","--quiet"); ok(q("select count(*) n from psa_tickets where id=%s",GT)[0]["n"]==0 and q("select is_archived from clients where id=%s",gc)[0]["is_archived"]==1,"a sync handles text ids (ticket not in the PSA removed, client not in the PSA archived)")
q("delete from psa_tickets where id=%s",GT); q("delete from devices where id=%s",gd); q("delete from psa_assets where psa_asset_id=%s",GA); q("delete from clients where id=%s",gc)

# ---- migration: runs again safely, and upgrades a 1.27.1 database
q("delete from schema_migrations where version in ('033_psa_neutral','034_rmm_neutral','037_psa_text_ids')")
r=subprocess.run(["php",ALIGN,"migrate"],env=ENV,capture_output=True,text=True)
ok(r.returncode==0 and "033_psa_neutral" in r.stdout and "034_rmm_neutral" in r.stdout and "037_psa_text_ids" in r.stdout,"migrations 033, 034 and 037 can run again on an upgraded database: "+(r.stdout+r.stderr)[-120:])
upg=load_snapshot("fresh_1271","align_upg")
r=subprocess.run(["php",ALIGN,"migrate"],env={**ENV,"ALIGN_CONFIG":upg},capture_output=True,text=True)
u=pymysql.connect(unix_socket=SOCKET,user="root",database="align_upg",cursorclass=pymysql.cursors.DictCursor)
with u.cursor() as c: c.execute("select value from settings where name='psa_provider'"); pv=c.fetchall()
with u.cursor() as c: c.execute("select count(*) n from information_schema.columns where table_schema='align_upg' and column_name like '%%itflow%%'"); left=c.fetchall()[0]["n"]
ok(r.returncode==0 and left==0 and pv and pv[0]["value"]=="","fresh 1.27.1 install upgrades cleanly (no PSA set up: psa_provider empty)")
u.close(); mysql("drop database align_upg")

q("delete from api_keys where name='psa e2e'")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
