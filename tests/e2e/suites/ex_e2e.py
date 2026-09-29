import re, requests, pymysql, html as H
from lib import *
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a); return c.fetchall()
fails=[]
def ok(c,m):
    print(("PASS " if c else "FAIL ")+m)
    if not c: fails.append(m)
def errs(t): return re.findall(r'(Warning:|Notice:|Deprecated:|Fatal error|Uncaught|RuntimeException)[^<]{0,200}',t)
def csrf(s,p): return re.search(r'name="_csrf" value="([^"]+)"', s.get(B+p).text).group(1)
def login(e,p):
    s=requests.Session(); s.post(B+"/login",data={"_csrf":csrf(s,"/login"),"email":e,"password":p}); return s
def unprot(t):
    m=re.search(r'Servers with no backup \((\d+)\)',t); return int(m.group(1)) if m else 0
q("delete from backup_exemptions")
st=login("admin@example.com","LongPassword123!")
t=st.get(B+"/clients/1/backups").text; n0=unprot(t)
ok('data-target="#modal-bk-exempt"' in t and 'id="modal-bk-exempt"' in t,"exclude buttons + dialog shown to tech/admin")
dev=q("select d.id, d.display_name from devices d where d.rmm_org_id='101' and d.device_type='Server' and d.removed_at is null and d.display_name='PC-0064'")[0]
tok=csrf(st,"/clients/1/backups")
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":tok,"action":"add","kind":"device","ref":dev["id"],"reason":""})
ok(not q("select id from backup_exemptions") and "reason" in H.unescape(r.text).lower(),"reason required")
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":tok,"action":"add","kind":"device","ref":dev["id"],"reason":"Test server, no business data"})
t=r.text; ok(unprot(t)==n0-1 and "Backup not required (1)" in t and "Test server, no business data" in t,"device exempted: removed from unprotected list")
ok(q("select count(*) n from audit_log where action='backup.exempt'")[0]["n"]>=1,"exemption audited")
# workload + m365 exemptions
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":tok,"action":"add","kind":"workload","ref":"vm:vm-sql","reason":"Lab VM"})
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":tok,"action":"add","kind":"m365","ref":"o-1:user-11","reason":"Former employee, mailbox exported"})
t=r.text
ok("Backup not required (3)" in t,"three exemptions listed")
ok(re.search(r'SQL-TEST</td>\s*<td class="small">Virtual',t) is None and "No restore point" not in t.split("Backup not required")[0].split("Protected machines")[1],"SQL-TEST no longer listed as missing")
ok("Former Employee" not in t.split("Backup not required")[0].split("Microsoft 365")[-1] if "Microsoft 365" in t else True,"M365 former employee not overdue")
ok("11 / 11 current" in re.sub(r'<[^>]+>','',t).replace("\n"," ") or re.search(r'10\s*<small[^>]*>\s*/\s*11 current',t),"M365 users now counted without the exempt one")
# other clients' items are refused
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":tok,"action":"add","kind":"workload","ref":"vm:vm-16","reason":"x"}); ok(r.status_code==404,"can't exempt another client's machine")
d2=q("select id from devices where rmm_org_id='102' limit 1")[0]["id"]
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":tok,"action":"add","kind":"device","ref":d2,"reason":"x"}); ok(r.status_code==404,"can't exempt another client's device")
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":tok,"action":"add","kind":"bogus","ref":"1","reason":"x"}); ok(r.status_code==400,"bad kind refused")
# reports / devices / dashboard / portfolio
t=st.get(B+"/clients/1/report/backup").text
ok("Not requiring a backup" in t and "Lab VM" in t and "PC-0064" not in t.split("Not requiring a backup")[0],"report lists exemptions, not as issues")
t=st.get(B+"/clients/1/devices").text; ok("not required" in t,"device list shows not required")
t=st.get(B+f"/devices/{dev['id']}").text; ok("Not required" in t and "Test server" in t and "Monitor again" in t and not errs(t),"device page shows exemption")
t=st.get(B+"/devices/8").text; ok("Not required…" in t and 'id="modal-bk-exempt"' in t,"device page offers exclusion")
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":csrf(st,"/devices/8"),"action":"add","kind":"device","ref":"8","reason":"Kiosk","back":"/devices/8"})
ok(r.url.endswith("/devices/8") and "Kiosk" in r.text,"exclude from device page returns there")
t=st.get(B+"/clients/1/devices").text
m=re.search(r'PC-0008</a>.*?</tr>',t,re.S); ok(m and "not required" in m.group(0),"agent machine with exempt device shows not required")
t=st.get(B+"/").text; ok(not errs(t),"dashboard ok")
t=st.get(B+"/reports/portfolio").text; ok(not errs(t),"portfolio ok")
# viewer can't exempt
v=login("viewer@example.com","ViewerPassword123!")
t=v.get(B+"/clients/1/backups").text; ok('id="modal-bk-exempt"' not in t and "Monitor again" not in t and "Backup not required (4)" in t,"viewer sees list read-only")
r=v.post(B+"/clients/1/backups/exempt",data={"_csrf":csrf(v,"/clients/1/backups"),"action":"remove","exemption":q("select id from backup_exemptions limit 1")[0]["id"]})
ok(r.status_code==403 and q("select count(*) n from backup_exemptions")[0]["n"]==4,"viewer can't remove")
# remove
eid=q("select id from backup_exemptions where item_name='PC-0064'")[0]["id"]
r=st.post(B+"/clients/1/backups/exempt",data={"_csrf":csrf(st,"/clients/1/backups"),"action":"remove","exemption":eid})
ok(unprot(r.text)==n0 and q("select count(*) n from audit_log where action='backup.exempt_remove'")[0]["n"]>=1,"monitor again restores it")
# survives sync
import subprocess
subprocess.run(["php",ALIGN,"sync"],env={"ALIGN_CONFIG":CONFIG,"PATH":"/usr/bin:/bin"},capture_output=True)
ok(q("select count(*) n from backup_exemptions")[0]["n"]==3,"exemptions survive sync")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
