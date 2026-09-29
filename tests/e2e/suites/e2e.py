import re, requests, subprocess, pymysql, html as H
from lib import *
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a); return c.fetchall()
fails=[]
def ok(c,m):
    print(("PASS " if c else "FAIL ")+m)
    if not c: fails.append(m)
def errs(t): return re.findall(r'(Warning:|Notice:|Deprecated:|Fatal error|Uncaught|RuntimeException)[^<]{0,200}',t)
def csrf(s,p): return re.search(r'name="_csrf" value="([^"]+)"', s.get(B+p).text).group(1)
def flash(t): return [H.unescape(x.strip()) for x in re.findall(r'alert alert-\w+[^>]*>(?:\s*<button[^>]*>[^<]*</button>)?\s*(?:<i[^>]*></i>)?([^<]+)',t)]
def form(s,p):
    t=s.get(B+p).text; f=t.split('<form method="post" action="'+p+'"')[1].split('</form>')[0]
    d={}
    for m in re.finditer(r'<input([^>]*)>',f):
        a=m.group(1); n=re.search(r'name="([^"]+)"',a); v=re.search(r'value="([^"]*)"',a)
        if not n or 'type="password"' in a: continue
        if 'type="checkbox"' in a and 'checked' not in a: continue
        d[H.unescape(n.group(1))]=H.unescape(v.group(1)) if v else ""
    for m in re.finditer(r'<select name="([^"]+)"[^>]*>(.*?)</select>',f,re.S):
        o=re.search(r'<option value="([^"]*)"[^>]*selected',m.group(2)); d[m.group(1)]=H.unescape(o.group(1)) if o else ""
    return d
def sync():
    return subprocess.run(["php",ALIGN,"sync"],env=ENV,capture_output=True,text=True).stdout
def login(email,pw):
    s=requests.Session(); s.post(B+"/login",data={"_csrf":csrf(s,"/login"),"email":email,"password":pw}); return s
q("delete from backup_exemptions")
staff=login("admin@example.com","LongPassword123!")
C3="33333333-3333-3333-3333-333333333333"; C1="11111111-1111-1111-1111-111111111111"

# settings
t=staff.get(B+"/integrations/veeam").text; ok("Veeam Service Provider Console" in t and 'name="veeam_url"' in t and "saved" in t,"integration page shows saved key")
F=form(staff,"/integrations/veeam")
r=staff.post(B+"/integrations/veeam",data={**F,"veeam_url":"not a url","backup_stale_hours":"12"}); ok(any("Console URL" in f and "https://" in f for f in flash(r.text)) and q("select value from settings where name='backup_stale_hours'")[0]["value"]!="12","bad URL refused, nothing saved")
r=staff.post(B+"/integrations/veeam/test",data={"_csrf":csrf(staff,"/integrations/veeam")}); ok(any("Veeam Service Provider Console: Connected. 4 companies" in f for f in flash(r.text)),"test connection "+str(flash(r.text)))
q("update settings set value='0' where name='backup_stale_hours'")
r=staff.post(B+"/integrations/veeam",data={**form(staff,"/integrations/veeam"),"backup_stale_hours":"9999"}); ok(q("select value from settings where name='backup_stale_hours'")[0]["value"]=="720","stale hours clamped")
staff.post(B+"/integrations/veeam",data={**form(staff,"/integrations/veeam"),"backup_stale_hours":"48"})
ok(q("select value from settings where name='veeam_url'")[0]["value"]=="http://127.0.0.1:8099" and q("select value from settings where name='ninja_client_id'")[0]["value"]=="ninja-id","saving one integration keeps the others")
r=staff.post(B+"/settings/test",data={"_csrf":csrf(staff,"/settings"),"target":"veeam"},allow_redirects=False); ok(r.headers.get("Location","").endswith("/integrations/veeam"),"old test address redirects")

v=login("viewer@example.com","ViewerPassword123!")
ok(v.get(B+"/clients/1/backups").status_code==200 and v.get(B+"/clients/1/report/backup").status_code==200,"viewer can read backups")
r=v.post(B+"/mapping",data={"_csrf":csrf(v,"/clients/1"),"veeam[1]":""}); ok(r.status_code==403 and q("select external_id from client_links where client_id=1 and provider='veeam'")[0]["external_id"]==C1,"viewer can't change mapping")
ok(v.post(B+"/integrations/veeam/test",data={"_csrf":csrf(v,"/clients/1")}).status_code==403 and v.get(B+"/integrations").status_code==403,"viewer can't open or test integrations")

# bad key: sync keeps going, error recorded, data kept
key=q("select value from settings where name='veeam_api_key'")[0]["value"]
subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; Align\\Settings::setSecret("veeam_api_key","wrong");'],env=ENV)
out=sync(); ok("Veeam backups FAILED" in out and "401" in out and "Warranty lookups" in out,"bad key: step fails, sync continues")
ok(q("select count(*) n from backup_jobs")[0]["n"]>0,"data kept after failed sync")
ok(sync().count("wrong")==0,"key not echoed in log")
q("update settings set value=%s where name='veeam_api_key'",key); sync()

# Microsoft 365
ok(q("select company_uid from backup_jobs where uid='m-3'")[0]["company_uid"]=="22222222-2222-2222-2222-222222222222","M365 job company from org mapping")
ok(q("select count(*) n from backup_m365_objects where company_uid='22222222-2222-2222-2222-222222222222'")[0]["n"]==5,"M365 objects mapped via tenant mapping (not provider org)")
ok(q("select company_uid from backup_workloads where uid='vm:vm-35'")[0]["company_uid"]==C1,"provider VM assigned by job mapping")
t=staff.get(B+"/clients/1/backups").text
ok("Microsoft 365" in t and "Former Employee" in t and "11 of 12 protected users" in t and "SharePoint &amp; Teams" in t,"M365 card on Backups page")
t=staff.get(B+"/clients/2/report/backup").text; ok("certificate has expired" in t and "5 Microsoft 365 items" not in t and "Northfield User 1" in t,"M365 failure + overdue in report")
t=staff.get(B+"/clients/1").text; ok("10/12 users current" in t,"overview M365 line")
# history rows recorded, audit logged
ok(q("select count(*) n from backup_job_runs where job_uid='j-1001' and run_at>now()-interval 1 day")[0]["n"]>=1,"run history recorded")
staff.get(B+"/clients/1/report/backup"); ok(q("select count(*) n from audit_log where action='report.backup'")[0]["n"]>0,"report audited")
ok(q("select count(*) n from audit_log where action like 'record.view%%' or detail like '%%backups%%'")[0]["n"]>=0,"access logged")
for p in ["/","/clients/1","/clients/1/backups","/clients/1/devices","/devices/28","/mapping","/reports/portfolio","/clients/2/report/backup?details=0&machines=0"]:
    r=staff.get(B+p); ok(r.status_code==200 and not errs(r.text),f"{p} {r.status_code}")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
