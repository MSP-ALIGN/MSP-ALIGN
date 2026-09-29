from lib import *
import re, sitecustomize, subprocess
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a or None); return c.fetchall()
def login(email="admin@example.com"):
    s=requests.Session(); t=s.get(B+"/login").text; c=re.search(r'name="_csrf" value="([^"]+)"',t).group(1)
    r=s.post(B+"/login",data={"_csrf":c,"email":email,"password":"LongPassword123!"})
    if "code" in r.text.lower():
        c=re.search(r'name="_csrf" value="([^"]+)"',r.text).group(1); s.post(r.url,data={"_csrf":c,"code":sitecustomize.next_code(email)})
    return s
def sync():
    return subprocess.run(["php",ALIGN,"sync"],env=ENV,capture_output=True,text=True).stdout
def csrf(s,path): return re.search(r'name="_csrf" value="([^"]+)"',s.get(B+path).text).group(1)
q("delete from backup_assignments"); q("delete from settings where name='veeam_hosting_companies'"); q("insert into settings (name,value) values ('veeam_hosting_companies','[]')")
out=sync(); vline=[l for l in out.splitlines() if "Veeam" in l][0]
ok("4 hosted machines sorted into clients" in vline,"sync sorts hosted machines by device name: "+vline[-120:])
w={r["name"]:r for r in q("select name,client_id,client_how,device_id from backup_workloads where company_uid like '10000000%' and name not like 'INT-VM%'")}
ok(w["PC-0040"]["client_id"]==4 and w["PC-0040"]["client_how"]=="device" and w["pc-0082.vet.local"]["client_id"]==4 and w["pc-0082.vet.local"]["device_id"]==82,"Vet servers (FQDN too) matched to Vet and linked to devices")
ok(w["PC-0142"]["client_id"]==2 and w["HPLG-DC01"]["client_id"] is None,"shared job machine to Northfield; law firm unmatched")
jc={(r["job_uid"],r["client_id"]) for r in q("select job_uid,client_id from backup_job_clients")}
ok(("j-0101",2) in jc and ("j-0101",4) in jc and ("j-0100",4) in jc and not any(j=="j-0102" for j,_ in jc) and not any(j=="j-0001" for j,_ in jc),"jobs count for the clients of their machines; shared job for both")
ok(("j-1002",1) in jc and ("j-1001",1) in jc,"VSPC-mapped and company jobs unchanged")

st=login()
h=st.get(B+"/mapping/backups").text
ok("Hosted backups" in h and "Hosted - Vet servers" in h and "HPLG-DC01" in h and "Not matched" in h,"hosted page lists jobs and machines")
ok("PC-0040" not in h.split("hb-machines")[1].split("</table>")[0] and "HPLG-DC01" in h,"opens on Not matched: only unmatched machines listed")
ok("PC-0040" in st.get(B+"/mapping/backups?show=sorted").text and "INT-VM-001" in st.get(B+"/mapping/backups?show=all").text,"tabs: sorted and all")
side=st.get(B+"/").text
mp=st.get(B+"/mapping").text; td=H.unescape(st.get(B+"/todo").text)
ok('href="/mapping/backups"' in mp and re.search(r'Hosted backups <span class="badge badge-warning">622</span>',mp) and "622 hosted backup machines to match" in td,"Integrations tabs: Hosted backups with the unmatched count; To do lists them")
ok("622 machines on your backup server not matched to a client" in H.unescape(side),"dashboard Needs attention flags unmatched hosted machines")
# client page claim card: law firm (client 3) gets HPLG suggested first
law0=st.get(B+"/clients/3/backups").text
ok("Backed up on your own server?" in law0 and law0.find("Law firm hosted") < law0.find("INT-VM-001") and "Looks like a match" in law0,"client Backups page offers unmatched items, the law firm's (HPLG) first")
ok(law0.split("Show the other")[0].count("Looks like a match")>=3,"job and both HPLG machines suggested")
ok("mapping/backups" in st.get(B+"/mapping").text,"linked from client mapping")
# Vet client: backups page, report, dashboard summary
v=st.get(B+"/clients/4/backups").text
ok("Hosted - Vet servers" in v and "PC-0040" in v and "Hosted servers nightly" in v and "Shared · 2 clients" in v and "Hosted</span>" in v,"Vet backups page shows hosted jobs and machines with badges")
ok("Processing PC-0142 failed" in v,"staff page keeps the shared job's error")
rep=st.get(B+"/clients/4/report/backup").text
ok("Hosted servers nightly" in rep and "PC-0142" not in rep and "Our team has the details." in rep,"client report hides the shared job's message (names another client's machine)")
ok("/clients/4/backups" in st.get(B+"/clients/4").text,"Backups in Vet's client menu")
ok(not q("select 1 from client_links where client_id=4 and provider='veeam' and external_id is not null"),"Vet still has no Veeam company link")
# Manual: law firm job -> client 3, internal job -> ours, one INT VM -> client 1, hosting flag on c1? (c1 not ours)
r=st.post(B+"/clients/3/backups/claim",data={"_csrf":csrf(st,"/clients/3/backups"),"kind":"job","uid":"j-0102"})
ok("Law firm hosted (2 machines) now counts for Harbor Point Law Group LLP" in H.unescape(r.text) and "HPLG-FS01" in r.text,"This client's (whole job) from the client page")
ok(q("select 1 from backup_assignments where item_type='job' and item_uid='j-0102' and client_id=3"),"claim saved as a job assignment")
r=st.post(B+"/clients/3/backups/claim",data={"_csrf":csrf(st,"/clients/3/backups"),"kind":"workload","uid":"vm:vm-h40"})
ok(r.status_code==404,"can't claim a machine already matched to another client")
q("delete from backup_assignments")
subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; Align\\Sync\\BackupSync::assign();'],env=ENV)
# bulk: 3 INT VMs -> ours
r=st.post(B+"/mapping/backups/bulk",data={"_csrf":csrf(st,"/mapping/backups"),"ids[]":["vm:int-1","vm:int-2","vm:int-3"],"client":"none","show":"unmatched"})
ok("3 machines set to yours" in H.unescape(r.text) and q("select count(*) n from backup_workloads where uid in ('vm:int-1','vm:int-2','vm:int-3') and client_id is null and client_how='machine'")[0]["n"]==3,"bulk: selected machines marked ours")
ok("show=unmatched" in r.url,"bulk keeps the tab")
r=st.post(B+"/mapping/backups/bulk",data={"_csrf":csrf(st,"/mapping/backups"),"ids[]":["vm:int-1"],"client":"99999"})
ok("Pick a client" in r.text,"bulk refuses an unknown client")
r=st.post(B+"/mapping/backups/bulk",data={"_csrf":csrf(st,"/mapping/backups"),"ids[]":["vm:int-1","vm:int-2","vm:int-3"],"client":"auto"})
ok(not q("select 1 from backup_assignments"),"bulk back to automatic clears them")
jobs={"job[j-0102]":"3","job[j-0001]":"none","job[j-0100]":"auto","job[j-0101]":"auto"}
data={"_csrf":csrf(st,"/mapping/backups"),**jobs,"wl[vm:int-7]":"1"}
r=st.post(B+"/mapping/backups",data=data)
ok("Saved 3 change(s)" in r.text,"save reports the changes and the new sorting")
w={r["name"]:r for r in q("select name,client_id,client_how from backup_workloads where name in ('HPLG-DC01','HPLG-FS01','INT-VM-001','INT-VM-007')")}
ok(w["HPLG-DC01"]["client_id"]==3 and w["HPLG-DC01"]["client_how"]=="job" and w["HPLG-FS01"]["client_id"]==3,"job assigned by hand: its machines go to that client")
ok(w["INT-VM-001"]["client_id"] is None and w["INT-VM-001"]["client_how"]=="job","internal job marked ours: machines are ours, not unmatched")
ok(w["INT-VM-007"]["client_id"]==1 and w["INT-VM-007"]["client_how"]=="machine","a machine assigned by hand beats its job")
ok(q("select 1 from backup_job_clients where job_uid='j-0102' and client_id=3 and how='job'") and not q("select 1 from backup_job_clients where job_uid='j-0001'"),"job clients follow manual job assignment")
law=st.get(B+"/clients/3/backups").text
ok("Law firm hosted" in law and "HPLG-FS01" in law,"law firm (no Veeam company, no devices) now has backups")
# survives the next sync
sync()
ok(q("select 1 from backup_workloads where name='HPLG-DC01' and client_id=3") and q("select 1 from backup_workloads where name='INT-VM-007' and client_id=1"),"manual assignments survive a sync")
# Hosting flag: a linked company's machines get sorted. Link c0 to client 9 (as if the MSP itself were a client), flag it
q("insert into clients (name, source, is_archived, planning_excluded) select 'Example MSP (internal)','manual',0,0 from dual where not exists (select 1 from clients where name='Example MSP (internal)')"); MSPC=q("select id from clients where name='Example MSP (internal)'")[0]["id"]
q("replace into client_links (client_id, provider, external_id, match_method) values (%s,'veeam','10000000-0000-0000-0000-000000000000','manual')",MSPC)
q("delete from backup_assignments where item_type='job' and item_uid='j-0001'")
subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; Align\\Sync\\BackupSync::assign();'],env=ENV)
ok(q("select 1 from backup_workloads where name='PC-0040' and client_id=%s and client_how='company'",MSPC),"linked provider company without the flag: machines stay with that client")
data={"_csrf":csrf(st,"/mapping/backups"),"hosting[]":"10000000-0000-0000-0000-000000000000"}
st.post(B+"/mapping/backups",data=data)
ok(q("select 1 from backup_workloads where name='PC-0040' and client_id=4 and client_how='device'") and q("select 1 from backup_workloads where name='INT-VM-002' and client_id=%s and client_how='company'",MSPC),"flagged as our backup server: hosted machines sorted, the rest stay with the linked client")
q("delete from client_links where client_id=%s and provider='veeam'",MSPC); q("update settings set value='[]' where name='veeam_hosting_companies'")
# Exemption on a hosted machine works through the client
t=st.get(B+"/clients/4/backups").text; c=re.search(r'name="_csrf" value="([^"]+)"',t).group(1)
r=st.post(B+"/clients/4/backups/exempt",data={"_csrf":c,"kind":"workload","ref":"vm:vm-h124","reason":"Test VM, rebuilt from template"})
ok("marked as not needing a backup" in r.text and q("select 1 from backup_exemptions where client_id=4 and item_uid='vm:vm-h124'"),"hosted machine can be marked Not required on the client")
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":csrf(st,"/clients/1/backups"),"kind":"workload","ref":"vm:vm-h124","reason":"x"})
ok(r.status_code==404,"another client can't exempt it")
q("delete from backup_exemptions where item_uid='vm:vm-h124'")
# Viewer can't see or save hosted page
vw=requests.Session()
# portal: vet has no portal user; check has()
out=subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; echo Align\\Backup\\Backup::has(4)?"y":"n", Align\\Backup\\Backup::has(8)?"y":"n";'],env=ENV,capture_output=True,text=True).stdout
ok(out=="yn","Backup::has: hosted client yes, client without backups no")
# summaries include Vet
out=subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; $s=Align\\Backup\\Backup::summaries(); echo json_encode([$s[4]["jobs"]??null,$s[4]["protected"]??null,$s[4]["failed"]??null]);'],env=ENV,capture_output=True,text=True).stdout
ok(out=="[2,3,1]","dashboard/portfolio summary counts hosted jobs and machines for Vet: "+out)
q("delete from backup_assignments")
subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; Align\\Sync\\BackupSync::assign();'],env=ENV)
print("FAILURES:",len(fails))
