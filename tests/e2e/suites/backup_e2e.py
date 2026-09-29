"""1.30 backup layer: neutral schema, Veeam as a provider, client links, mapping (new + legacy fields), hosted flags, no-backup behavior, upgrade."""
from lib import *
import re, json, subprocess, html as H
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a or None); return c.fetchall()
S=WORK
API=B+"/api/v1"
def php(code): return subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; '+code],env=ENV,capture_output=True,text=True)
def align(*a): return subprocess.run(["php",ALIGN,*a],env=ENV,capture_output=True,text=True)
st=login("admin@example.com","LongPassword123!")
C1="11111111-1111-1111-1111-111111111111"; C2="22222222-2222-2222-2222-222222222222"; C3="33333333-3333-3333-3333-333333333333"; C0="10000000-0000-0000-0000-000000000000"

# ---- schema
left=q("select table_name t, column_name c from information_schema.columns where table_schema=database() and (column_name like '%%veeam%%' or table_name like 'veeam%%')")
ok(not left,"no veeam-named tables or columns: "+str(left))
for t in ["backup_jobs","backup_workloads","backup_m365_orgs","backup_m365_objects"]:
    ok(q(f"select count(*) n from {t} where provider<>'veeam'")[0]["n"]==0 and q(f"select count(*) n from {t}")[0]["n"]>0,f"{t}: every row notes provider veeam")
ok(q("select column_default d from information_schema.columns where table_schema=database() and table_name='backup_jobs' and column_name='provider'")[0]["d"] in (None,"NULL"),"provider has no default (each sync must say)")
ok(q("select count(*) n from backup_companies where provider='veeam'")[0]["n"]==4,"4 Veeam companies in backup_companies")
ok(q("select external_id from client_links where client_id=1 and provider='veeam'")[0]["external_id"]==C1,"client 1's Veeam link moved to client_links")

# ---- provider
out=php('$p=Align\\Providers\\Providers::backup("veeam"); $s=$p->snapshot(fn($m)=>null); echo json_encode([get_class($p), array_keys($s), count($s["companies"]), count($s["jobs"]), count($s["workloads"]), array_keys($s["workloads"][0]), Align\\Providers\\Providers::backupNames(), $p->supports("m365")]);').stdout
j=json.loads(out); ok(j[0]=="Align\\Providers\\Backup\\VeeamBackup" and j[2]==4 and j[3]>0 and j[4]==634 and j[6]=="Veeam" and j[7],"Veeam is a backup provider: "+out[:160])
ok({"companies","cloud","jobs","job_lists","workloads","m365"} <= set(j[1]),"snapshot has the neutral parts")
ok("job_uids" in j[5] and "hostname" in j[5] and not any(k.startswith("veeam") for k in j[5]),"machines come back as neutral records")

# ---- a sync keeps every row
key=lambda: (sorted((r["uid"],r["client_id"],r["client_how"],r["device_id"]) for r in q("select uid, client_id, client_how, device_id from backup_workloads")),
             sorted((r["job_uid"],r["client_id"]) for r in q("select job_uid, client_id from backup_job_clients")))
before=key(); align("sync","--quiet"); after=key()
ok(before==after,f"sync keeps the same {len(after[0])} machines and job clients")
run=json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
ok("Veeam backups" in run and "4 companies" in run["Veeam backups"],"sync step keeps its name: "+run.get("Veeam backups","")[:80])

# ---- another provider's rows are never pruned by Veeam
q("insert into backup_jobs (uid, provider, company_uid, source, name, job_type, status, is_enabled, synced_at) values ('other:j1','othr',NULL,'server','Other job','Backup','success',1,now())")
q("insert into backup_companies (provider, uid, name, synced_at) values ('othr','other:c1','Other Co',now())")
align("sync","--quiet")
ok(q("select 1 from backup_jobs where uid='other:j1'") and q("select 1 from backup_companies where uid='other:c1'"),"a Veeam sync leaves another provider's jobs and companies alone")
q("delete from backup_jobs where provider='othr'"); q("delete from backup_companies where provider='othr'")

# ---- client mapping: page, save (new field), deliberate unlink, legacy veeam[] field
t=st.get(B+"/mapping").text
ok('name="link[veeam][' in t and "Veeam company" in t and not errs(t),"mapping page has a Veeam company column")
cids=[int(c["id"]) for c in q("select id from clients where is_archived=0 and planning_excluded=0")]
def form(prefix):
    F={"_csrf":csrf(st,"/mapping")}
    for c in cids:
        l=q("select external_id from client_links where client_id=%s and provider='veeam'",c)
        F[f"{prefix}[{c}]"]=(l[0]["external_id"] or "") if l else ""
    return F
F=form("backup[veeam]"); F["backup[veeam][2]"]=""
r=st.post(B+"/mapping",data=F); ok("Saved 1 change" in flash(r.text),"unlink one client: "+flash(r.text))
l=q("select * from client_links where client_id=2 and provider='veeam'")
ok(l and l[0]["external_id"] is None and l[0]["match_method"]=="manual","deliberately unlinked is remembered")
align("sync","--quiet")
ok(q("select external_id from client_links where client_id=2 and provider='veeam'")[0]["external_id"] is None,"auto-match leaves a deliberate unlink alone")
ok(q("select count(*) n from backup_workloads where client_id=2 and client_how is null")[0]["n"]==0,"unlinked client's company machines are no longer its own")
F=form("veeam"); F["veeam[2]"]=C2
r=st.post(B+"/mapping",data=F); ok("Saved 1 change" in flash(r.text),"pre-1.30 veeam[] field still saves: "+flash(r.text))
ok(q("select external_id, match_method from client_links where client_id=2 and provider='veeam'")[0]=={"external_id":C2,"match_method":"manual"},"relinked by hand")
F=form("backup[veeam]"); F["backup[veeam][3]"]=C2
r=st.post(B+"/mapping",data=F); ok("only be linked to one client" in flash(r.text),"one company can't go to two clients")
F=form("backup[veeam]"); F["backup[veeam][2]"]="not-a-company"
r=st.post(B+"/mapping",data=F); ok("No changes" in flash(r.text) and q("select external_id from client_links where client_id=2 and provider='veeam'")[0]["external_id"]==C2,"an unknown company leaves the link alone")
r=st.post(B+"/mapping",data={"_csrf":csrf(st,"/mapping"),"backup":"x"}); ok(r.status_code==200 and not errs(r.text),"malformed backup field is ignored")
ok(q("select count(*) n from audit_log where action='mapping.save' and detail like '%%veeam%%'")[0]["n"]>0,"mapping changes audited")

# ---- hosted backups: flags per provider
t=st.get(B+"/mapping/backups?show=all").text
ok("Which Veeam companies are your backup servers" in t and 'name="hosting[]"' in t and not errs(t),"hosted backups page")
q("insert into clients (name, source, is_archived, planning_excluded) select 'Example MSP (internal)','manual',0,0 from dual where not exists (select 1 from clients where name='Example MSP (internal)')"); MSPC=q("select id from clients where name='Example MSP (internal)'")[0]["id"]
q("replace into client_links (client_id, provider, external_id, match_method) values (%s,'veeam',%s,'manual')",MSPC,C0)
php('Align\\Sync\\BackupSync::assign();')
ok(q("select 1 from backup_workloads where name='PC-0040' and client_id=%s and client_how='company'",MSPC),"linked provider company: machines stay with that client")
r=st.post(B+"/mapping/backups",data={"_csrf":csrf(st,"/mapping/backups"),"hosting[]":[C0,"bogus"]})
ok(json.loads(q("select value from settings where name='veeam_hosting_companies'")[0]["value"])==[C0],"hosting flag saved under veeam_hosting_companies (unknown uids dropped)")
ok(q("select 1 from backup_workloads where name='PC-0040' and client_id=4 and client_how='device'"),"flagged: hosted machines sorted by device name")
st.post(B+"/mapping/backups",data={"_csrf":csrf(st,"/mapping/backups")})
q("delete from client_links where client_id=%s and provider='veeam'",MSPC); php('Align\\Sync\\BackupSync::assign();')
ok(json.loads(q("select value from settings where name='veeam_hosting_companies'")[0]["value"])==[],"flags cleared")

# ---- screens
t=st.get(B+"/clients/1/backups").text; ok("From Veeam Service Provider Console" in t and not errs(t),"client Backups page names the product")
t=st.get(B+"/clients/1/report/backup").text; ok("Veeam · updated" in t and not errs(t),"backup report names the product")
t=st.get(B+"/clients/4/backups").text; ok("From Veeam Service Provider Console" in t and "Hosted" in t,"hosted-only client (no company) still names the product")
t=st.get(B+"/clients/1/devices").text; ok('col-backup">Backup</th>' in t,"device list shows the Backup column for a linked client")
t=st.get(B+"/reports/backups").text; ok("Clients on Veeam" in t and not errs(t),"all-clients backup report")

# ---- API keeps v1 answers
php('$k=Align\\Api\\Keys::create("bk e2e", Align\\Api\\Keys::allScopes(), null, null, 600, null, 1); file_put_contents("/tmp/bk_key", $k[1]);'); k=open("/tmp/bk_key").read().strip()
q("update settings set value='1' where name='api_enabled'")
h={"Authorization":"Bearer "+k}
d=requests.get(API+"/clients/1/backups",headers=h).json()["data"]
ok(d["client_id"]==1 and d["microsoft_365"] is None or isinstance(d.get("microsoft_365"),(dict,type(None))),"API client backups answers")
ok(set(d)=={"client_id","health","synced_at","stale_after_hours","stats","history_30d","jobs","machines","servers_without_backup","microsoft_365","url"},"API backup fields unchanged: "+",".join(sorted(d)))
d2=requests.get(API+"/clients/2/backups",headers=h).json()["data"]; ok(d2["microsoft_365"] and d2["microsoft_365"]["protected_objects"]==5,"Microsoft 365 found through the client's companies")
q("insert into clients (name, source, is_archived, planning_excluded) values ('Zz No Backups','manual',0,0)"); nb=q("select id from clients where name='Zz No Backups'")[0]["id"]
r=requests.get(API+f"/clients/{nb}/backups",headers=h); ok(r.status_code==404 and "Veeam company" in r.text,"no backup data: 404 names the product")
q("delete from clients where id=%s",nb)
q("delete from api_keys where name='bk e2e'")

# ---- no backup product connected: pages render, sync skips it, data kept
url=q("select value from settings where name='veeam_url'")[0]["value"]
q("update settings set value='' where name='veeam_url'")
bad=[]
for p in ["/","/clients","/clients/1","/clients/1/backups","/clients/4/backups","/clients/1/devices","/mapping","/mapping/backups","/reports","/reports/backups","/integrations","/integrations/veeam","/sync","/help"]:
    rr=st.get(B+p)
    if rr.status_code>=500 or errs(rr.text): bad.append((p,rr.status_code,errs(rr.text)[:1]))
ok(not bad,"pages render with no backup product connected: "+str(bad))
out=php('echo json_encode([Align\\Providers\\Providers::anyBackup(), Align\\Providers\\Providers::backupNames()]);').stdout; ok(json.loads(out)==[False,"Veeam"],"no backup set up: still named Veeam (the only one Align supports): "+out)
align("sync","--quiet"); run=json.loads(q("select summary from sync_runs order by id desc limit 1")[0]["summary"])
ok("Veeam backups" not in run,"sync skips backups when none is connected")
ok(q("select count(*) n from backup_workloads")[0]["n"]==634,"backup data kept while Veeam isn't connected")
q("update settings set value=%s where name='veeam_url'",url)

# ---- migration: re-runs, and upgrades a 1.29 install
q("delete from schema_migrations where version='035_backup_neutral'")
r=align("migrate"); ok(r.returncode==0 and "035_backup_neutral" in r.stdout,"migration 035 can run again: "+(r.stdout+r.stderr)[-100:])
upg=load_snapshot("main_129","align_upg")
u=pymysql.connect(unix_socket=SOCKET,user="root",database="align_upg",cursorclass=pymysql.cursors.DictCursor)
with u.cursor() as c: c.execute("select id, veeam_company_uid u, veeam_match m from clients where veeam_company_uid is not null or veeam_match is not null order by id"); old=[(x["id"],x["u"],x["m"]) for x in c.fetchall()]
with u.cursor() as c: c.execute("select count(*) n from veeam_companies"); oc=c.fetchall()[0]["n"]
u.close()
r=subprocess.run(["php",ALIGN,"migrate"],env={**ENV,"ALIGN_CONFIG":upg},capture_output=True,text=True)
u=pymysql.connect(unix_socket=SOCKET,user="root",database="align_upg",cursorclass=pymysql.cursors.DictCursor)
with u.cursor() as c: c.execute("select client_id, external_id, match_method from client_links where provider='veeam' order by client_id"); new=[(x["client_id"],x["external_id"],x["match_method"]) for x in c.fetchall()]
with u.cursor() as c: c.execute("select count(*) n from backup_companies where provider='veeam'"); nc=c.fetchall()[0]["n"]
u.close(); mysql("drop database align_upg")
ok(r.returncode==0 and old==new and oc==nc and nc==4,f"a 1.29 database upgrades: links {new}, {nc} companies")
upg=load_snapshot("fresh_129","align_upg")
r=subprocess.run(["php",ALIGN,"migrate"],env={**ENV,"ALIGN_CONFIG":upg},capture_output=True,text=True)
mysql("drop database align_upg")
ok(r.returncode==0,"a fresh 1.29 install upgrades: "+(r.stdout+r.stderr)[-100:])

print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
