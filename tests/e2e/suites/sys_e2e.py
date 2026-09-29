import os, glob, stat, tarfile, io, shutil
from lib import *
import sitecustomize
SP=WORK; T=SP+"/sys"; APP=ROOT
AENV=dict(ENV, ALIGN_APP_DIR=APP, ALIGN_DATA_DIR=T+"/data", ALIGN_AGENT_DIR=T+"/agent", ALIGN_RUN_DIR=T+"/run", ALIGN_RECIPIENT=T+"/recipient.txt",
          ALIGN_RUNAS="root", ALIGN_SYSTEMCTL="none", ALIGN_LEGACY_BACKUPS=T+"/legacy")
KEY=[l for l in open(T+"/key.txt") if l.startswith("AGE-SECRET")][0].strip()
REC=open(T+"/recipient.txt").read().strip()
def agent(*a, **env):
    return subprocess.run(["php",APP+"/scripts/agent.php",*(a or ["run"])],env=dict(AENV,**env),capture_output=True,text=True)
def job(st,i): return st.get(B+"/settings/system/jobs/"+i,headers={"Accept":"application/json"}).json()
def jid(r): m=re.search(r'job=([0-9a-f-]+)',r.url); return m.group(1) if m else None
def post(st,path,data=None,files=None,**kw): return st.post(B+path,data={"_csrf":csrf(st,"/settings/system"),**(data or {})},files=files,**kw)
upload_dir=UPLOADS

# ---- setup
for d in ["agent/jobs","agent/safety","data/downloads","data/restore","run/requests","legacy"]:
    shutil.rmtree(T+"/"+d,ignore_errors=True); os.makedirs(T+"/"+d)
for f in ["agent/update.json","agent/system.json","agent/maintenance.json"]:
    if os.path.exists(T+"/"+f): os.unlink(T+"/"+f)
# a clone whose origin is one release ahead
shutil.rmtree(T+"/app",ignore_errors=True); subprocess.run(f"git clone -q {T}/remote.git {T}/app && git -C {T}/app reset -q --hard HEAD~2",shell=True)
q("delete from settings where name in ('backup_last_download','backup_last_download_by','backup_reminder_days')"); q("delete from notify_state where k in ('update_notified','backup_reminder_at')")

NF=sum(len(f) for _,_,f in os.walk(UPLOADS))
st=login("chris@example.com","LongPassword123!")
t=st.get(B+"/settings/system").text
ok("Updates &amp; backups" in t and not errs(t) and "Not checked yet" in t,"page loads before the first check")
ok(agent("check",ALIGN_APP_DIR=T+"/app").returncode==0,"agent check ran")
t=st.get(B+"/settings/system").text
ok("Update to 1.99.0" in t and "Shiny new thing" in t and "Adds a thing." in t and "Co-Authored" not in t and "2 changes" in t,"update available with release notes")
d=st.get(B+"/").text
ok("MSP-ALIGN <b>1.99.0</b> is available" in d and 'badge badge-info right">new<' in d,"banner and nav badge for admins")
tech=login("viewer@example.com","ViewerPassword123!"); ok(tech.get(B+"/settings/system").status_code==403 and "is available" not in tech.get(B+"/").text,"non-admins can't see it")

# ---- backup for download
r=post(st,"/settings/system/backup"); i=jid(r)
req=f"{T}/run/requests/{i}.json"
ok(i and os.path.exists(req) and stat.S_IMODE(os.stat(req).st_mode)==0o600,"backup request queued (0600)")
ok(job(st,i)["state"]=="queued" and "Waiting" in st.get(B+"/settings/system?job="+i).text,"page shows it queued")
agent()
j=job(st,i); ok(j["state"]=="succeeded" and j["download"] and "file" not in j["result"] and j["result"]["filename"].startswith("msp-align-backup-align.test-"),"backup built: "+str(j.get("message")))
r=post(st,"/settings/system/download/"+i)
ok(r.headers.get("Content-Type")=="application/x-tar" and 'attachment; filename="msp-align-backup-' in r.headers.get("Content-Disposition","") and len(r.content)==j["result"]["size"],"downloaded as attachment")
bk=r.content; open(T+"/dl.tar","wb").write(bk)
ok(not os.path.exists(f"{T}/data/downloads/{i}.tar") and not glob.glob(T+"/agent/work/*"),"deleted from the server after download")
r=post(st,"/settings/system/download/"+i); ok("already downloaded or has expired" in flash(r.text),"second download refused")
names=tarfile.open(T+"/dl.tar").getnames(); ok(names==["manifest.json","db.sql.gz.age","uploads.tar.gz.age","app-key.age"],"standard tar: "+str(names))
ok(q("select value from settings where name='backup_last_download_by'")[0]["value"]=="Chris","last download recorded (who)")
ok(q("select count(*) n from audit_log where action='backup.downloaded'")[0]["n"]>0,"audited")
t=st.get(B+"/settings/system").text; ok("Last " in t and "never downloaded" not in t.lower(),"backup card shows last download")

# ---- upload, test, restore
r=st.post(B+"/settings/system/upload",data={"_csrf":csrf(st,"/settings/system")},files={"backup":("junk.tar",b"x"*2000)},headers={"Accept":"application/json"})
ok(r.status_code==422 and "not an MSP-ALIGN backup" in r.json()["error"],"junk upload refused: "+r.text[:80])
r=st.post(B+"/settings/system/upload",data={"_csrf":csrf(st,"/settings/system")},files={"backup":("mybackup.tar",bk)},headers={"Accept":"application/json"})
ok(r.json().get("ok") and "uploaded=1" in r.json()["redirect"],"upload accepted: "+r.text[:200])
t=st.get(B+"/settings/system").text
ok("mybackup.tar" in t and f"align.test, version {open(ROOT+'/VERSION').read().strip()}" in t and f"{NF} uploaded file" in t and "Test this backup" in t,"upload described from the manifest")
r=post(st,"/settings/system/verify",{"key":"nope"}); ok("starts with AGE-SECRET-KEY-1" in flash(r.text),"bad key format refused")
r=post(st,"/settings/system/verify",{"key":KEY.lower()}); i=jid(r)   # pasted in lower case: normalised
rq=json.load(open(f"{T}/run/requests/{i}.json")); ok(rq["key"]==KEY and "key" not in rq["params"],"key only in the RAM request file")
agent(); j=job(st,i)
ok(j["state"]=="succeeded" and j["result"]["backup"]["tables"]>30 and j["result"]["backup"]["uploads_files"]==NF,"backup tested: "+str(j.get("message")))
ok(not os.listdir(T+"/run/requests") and not os.listdir(T+"/run/keys") and KEY not in open(f"{T}/agent/jobs/{i}.log").read() and KEY not in json.dumps(j),"key gone after use and never logged")
other=subprocess.run("age-keygen 2>/dev/null | grep AGE-SECRET",shell=True,capture_output=True,text=True).stdout.strip()
i=jid(post(st,"/settings/system/verify",{"key":other})); agent(); ok("can't open the backup" in job(st,i)["message"],"wrong key explained")

q("insert into settings (name,value) values ('zz_sys_marker','after-backup') on duplicate key update value='after-backup'")
open(upload_dir+"/zz_after.txt","w").write("x")
r=post(st,"/settings/system/restore",{"key":KEY,"restore_db":"1","restore_uploads":"1","code":"000000","confirm":"restore"}); ok("Type RESTORE" in flash(r.text),"must type RESTORE")
r=post(st,"/settings/system/restore",{"key":KEY,"restore_db":"1","restore_uploads":"1","code":"000000","confirm":"RESTORE"}); ok("two-factor code is not right" in flash(r.text),"2FA code required")
r=post(st,"/settings/system/restore",{"key":KEY,"restore_db":"1","restore_uploads":"1","code":sitecustomize.next_code("chris@example.com"),"confirm":"RESTORE"}); i=jid(r)
ok(i and "Queued" in r.text,"restore queued")
# maintenance gate while the agent works
sl=subprocess.Popen(["sleep","30"]); json.dump({"since":time.strftime("%Y-%m-%dT%H:%M:%S%z"),"pid":sl.pid,"job":i,"action":"restore","message":"x","step":"Restoring the database"},open(T+"/agent/maintenance.json","w"))
r=requests.get(B+"/login"); ok(r.status_code==503 and "being restored from a backup" in r.text and "Restoring the database" in r.text and 'http-equiv="refresh"' in r.text,"maintenance page for everyone")
r=st.get(B+"/settings/system/jobs/"+i,headers={"Accept":"application/json"}); ok(r.status_code==503 and r.json()["maintenance"] and r.json()["step"]=="Restoring the database","watcher sees maintenance JSON")
tt=time.time(); align("mail:run"); ok(time.time()-tt<3,"scheduled jobs skip during maintenance")
sl.kill(); sl.wait(); a=agent(); ok(not os.path.exists(T+"/agent/maintenance.json"),"stale maintenance cleared by the agent")
j=json.load(open(f"{T}/agent/jobs/{i}.json"))
ok(j["state"]=="succeeded" and j["result"]["db"] and j["result"]["uploads"],"restore finished: "+str(j["message"]))
ok(not q("select * from settings where name='zz_sys_marker'") and not os.path.exists(upload_dir+"/zz_after.txt"),"data and files are back to the backup")
r=st.get(B+"/settings/system",allow_redirects=False); ok(r.status_code in (302,303) and "/login" in r.headers.get("Location",""),"everyone signed out")
ok(not glob.glob(T+"/data/restore/*") and not glob.glob(T+"/agent/safety/*"),"upload and safety copy removed after success")
st=login("chris@example.com","LongPassword123!")
ok(q("select count(*) n from audit_log where action='backup.restored'")[0]["n"]>0,"restore audited")
ok("Audit log intact" in align("audit:verify"),"audit chain intact after restore")

# ---- key check
i=jid(post(st,"/settings/system/keycheck",{"key":KEY})); agent(); ok("matches this server" in job(st,i)["message"],"key check: match")
i=jid(post(st,"/settings/system/keycheck",{"key":other})); agent(); j=job(st,i); ok("does NOT match" in j["message"] and j["result"]["public_key"].startswith("age1"),"key check: no match")

# ---- older nightly (db-only) backups, unsafe and newer backups
subprocess.run(f"mariadb-dump -ualign_test -ptestpass --single-transaction --no-tablespaces {DB_MAIN} | gzip | age -r {REC} -o {T}/db-legacy.sql.gz.age",shell=True)
r=st.post(B+"/settings/system/upload",data={"_csrf":csrf(st,"/settings/system")},files={"backup":("db-20260101.sql.gz.age",open(T+"/db-legacy.sql.gz.age","rb"))})
ok("Older nightly database backup" in r.text,"legacy db backup accepted")
i=jid(post(st,"/settings/system/verify",{"key":KEY})); agent(); ok(job(st,i)["state"]=="succeeded","legacy backup tests OK")
post(st,"/settings/system/upload/discard")
# uploads with a path escape
w=T+"/evil"; shutil.rmtree(w,ignore_errors=True); os.makedirs(w)
with tarfile.open(T+"/dl.tar") as tf: tf.extractall(w)
buf=io.BytesIO()
with tarfile.open(fileobj=buf,mode="w:gz") as tf:
    ti=tarfile.TarInfo("uploads/../../escape.txt"); ti.size=3; tf.addfile(ti,io.BytesIO(b"bad"))
subprocess.run(["age","-r",REC,"-o",w+"/uploads.tar.gz.age"],input=buf.getvalue())
subprocess.run(["php","-r",'require "'+APP+'/src/System/Tar.php"; $d=$argv[1]; Align\\System\\Tar::write("$d/evil.tar", ["manifest.json"=>"$d/manifest.json","db.sql.gz.age"=>"$d/db.sql.gz.age","uploads.tar.gz.age"=>"$d/uploads.tar.gz.age","app-key.age"=>"$d/app-key.age"]);',w])
st.post(B+"/settings/system/upload",data={"_csrf":csrf(st,"/settings/system")},files={"backup":("evil.tar",open(w+"/evil.tar","rb"))})
i=jid(post(st,"/settings/system/verify",{"key":KEY})); agent(); ok("unsafe entry" in job(st,i)["message"],"path escape in uploaded files refused")
post(st,"/settings/system/upload/discard")
m=json.load(open(w+"/manifest.json")); m["version"]="9.0.0"; json.dump(m,open(w+"/manifest.json","w")); os.unlink(w+"/evil.tar")
subprocess.run(["php","-r",'require "'+APP+'/src/System/Tar.php"; $d=$argv[1]; Align\\System\\Tar::write("$d/evil.tar", ["manifest.json"=>"$d/manifest.json","db.sql.gz.age"=>"$d/db.sql.gz.age"]);',w])
r=st.post(B+"/settings/system/upload",data={"_csrf":csrf(st,"/settings/system")},files={"backup":("newer.tar",open(w+"/evil.tar","rb"))},headers={"Accept":"application/json"})
ok("newer than this server" in r.json().get("error",""),"backup from a newer version refused")

# ---- requests the agent must ignore
open(T+"/run/requests/20260926-120000-abcdef.json","w").write(json.dumps({"id":"20260926-120000-abcdef","action":"rm -rf /"}))
os.symlink("/etc/passwd",T+"/run/requests/20260926-120001-abcdef.json")
a=agent(); ok("Ignored invalid request" in a.stderr and not os.path.exists(T+"/agent/jobs/20260926-120000-abcdef.json") and not os.listdir(T+"/run/requests"),"bad and symlinked requests ignored")

# ---- update from the page (real git fetch/reset against a local remote; installer replaced)
r=post(st,"/settings/system/update"); ok("Tick the box" in flash(r.text),"update needs confirmation")
was=open(T+"/app/VERSION").read().strip()
i=jid(post(st,"/settings/system/update",{"confirm":"1"})); agent(ALIGN_APP_DIR=T+"/app",ALIGN_INSTALL_CMD="echo installer ran")
j=job(st,i); lg=st.get(B+f"/settings/system/jobs/{i}/log").text
ok(j["state"]=="succeeded" and j["message"]==f"Updated from {was} to 1.99.0." and "installer ran" in lg and "Making a safety copy" in lg,"update ran: "+str(j["message"]))
ok(open(T+"/app/VERSION").read().strip()=="1.99.0" and not glob.glob(T+"/agent/safety/*"),"code updated; safety copy deleted on success")
u=json.load(open(T+"/agent/update.json")); ok(u["current"]=="1.99.0" and not u["available"],"re-checked after the update")
# a failing update keeps the safety copy
subprocess.run(f"cd {T}/work && echo 2.0.0 > VERSION && git -c user.name=t -c user.email=t@t commit -qam 'v2.0.0' && git push -q origin HEAD:main",shell=True)
i=jid(post(st,"/settings/system/update",{"confirm":"1"})); agent(ALIGN_APP_DIR=T+"/app",ALIGN_INSTALL_CMD="echo boom; exit 3")
j=job(st,i); sf=glob.glob(T+"/agent/safety/*")
ok(j["state"]=="failed" and "safety copy" in j["message"] and j["result"].get("safety") and len(sf)==1,"failed update keeps a safety copy: "+str(j["message"]))
t=st.get(B+"/settings/system").text; name=os.path.basename(sf[0])
ok("Safety copies kept after a failed job" in t and name in t,"safety copy listed")
r=post(st,f"/settings/system/safety/{name}/download"); ok(r.headers.get("Content-Type")=="application/x-tar" and r.content[:512].startswith(b"manifest.json"),"safety copy downloadable")
i=jid(post(st,f"/settings/system/safety/{name}/delete")); agent(); ok(not glob.glob(T+"/agent/safety/*"),"safety copy deleted")
subprocess.run(f"cd {T}/work && git reset -q --hard HEAD~1 && git push -q -f origin HEAD:main",shell=True)

# ---- old nightly backups on the server
for n in ["db-20260101-023000.sql.gz.age","config-20260101-023000.php.age"]: open(T+"/legacy/"+n,"w").write("x"*100)
agent("check",ALIGN_APP_DIR=T+"/app"); t=st.get(B+"/settings/system").text
ok("2 old nightly backup files" in t,"old server backups reported")
i=jid(post(st,"/settings/system/legacy/delete",{"confirm":"1"})); agent(); ok(not os.listdir(T+"/legacy") and "Deleted 2 old backup files" in job(st,i)["message"],"old server backups deleted")

# ---- optional version file (update_check_url): answers "nothing new" without asking GitHub
vf=T+"/versions"; shutil.rmtree(vf,ignore_errors=True); os.makedirs(vf)
cur=open(T+"/app/VERSION").read().strip()  # this clone's version (the failed update above left its code in place)
json.dump({"version":cur},open(vf+"/main.json","w"))
vs=subprocess.Popen(["php","-S","127.0.0.1:8097","-t",vf],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL); time.sleep(0.5)
try:
    agent("check",ALIGN_APP_DIR=T+"/app")  # up to date, nothing pending
    origin=subprocess.run(["git","-C",T+"/app","remote","get-url","origin"],capture_output=True,text=True).stdout.strip()
    subprocess.run(["git","-C",T+"/app","remote","set-url","origin",T+"/no-such-remote.git"])
    VU=dict(ALIGN_APP_DIR=T+"/app",ALIGN_UPDATE_CHECK_URL="http://127.0.0.1:8097")
    agent("check",**VU); u=json.load(open(T+"/agent/update.json"))
    ok(u.get("source")=="version file" and not u["error"] and u["latest"]==cur and not u["available"],"version file says nothing new: GitHub not asked")
    i=jid(post(st,"/settings/system/check")); agent(**VU); u=json.load(open(T+"/agent/update.json"))
    ok(u.get("source") is None and "Could not reach GitHub" in (u["error"] or ""),"Check now always asks GitHub")
    json.dump({**u,"error":None,"fetched_at":"2020-01-01T00:00:00+00:00"},open(T+"/agent/update.json","w"))
    agent("check",**VU); u=json.load(open(T+"/agent/update.json"))
    ok(u.get("source") is None,"GitHub asked at least once a day even when the version file says nothing new")
    json.dump({"version":"1.0.0"},open(vf+"/main.json","w"))
    json.dump({**u,"error":None,"behind":0,"fetched_at":time.strftime("%Y-%m-%dT%H:%M:%S%z")},open(T+"/agent/update.json","w"))
    agent("check",**VU); u=json.load(open(T+"/agent/update.json"))
    ok(u.get("source") is None,"an older listed version (a lagging file) isn't trusted")
    json.dump({"version":"9.0.0"},open(vf+"/main.json","w"))
    agent("check",**VU); u=json.load(open(T+"/agent/update.json"))
    ok(u.get("source") is None and "Could not reach GitHub" in (u["error"] or ""),"newer version listed: asks GitHub for the update itself")
    agent("check",ALIGN_APP_DIR=T+"/app",ALIGN_UPDATE_CHECK_URL="http://example.com"); u=json.load(open(T+"/agent/update.json"))
    ok(u.get("source") is None,"plain-http version file (not local) ignored")
    subprocess.run(["git","-C",T+"/app","remote","set-url","origin",origin])
    agent("check",ALIGN_APP_DIR=T+"/app",ALIGN_UPDATE_CHECK_URL="http://127.0.0.1:8096"); u=json.load(open(T+"/agent/update.json"))
    ok(u.get("source") is None and "Could not reach" not in (u["error"] or "") and u["latest"]=="1.99.0","version file unreachable: GitHub as usual")
finally:
    vs.terminate()

# ---- notifications
agent("check",ALIGN_APP_DIR=T+"/app")
subprocess.run(["php","-r",'require "'+APP+'/src/bootstrap.php"; Align\\Settings::set("backup_last_download", null);'],env=ENV)
q("delete from mail_queue"); q("delete from notify_state where k in ('update_notified','backup_reminder_at')")
json.dump({**json.load(open(T+"/agent/update.json")),"current":"1.13.0","latest":"1.99.0","available":True,"changes":[{"sha":"x","subject":"Shiny new thing","body":"","date":"2026-09-26"}]},open(T+"/agent/update.json","w"))
out=subprocess.run(["php","-r",'require "'+APP+'/src/bootstrap.php"; echo Align\\Mail\\Notify::updateAvailable(), Align\\Mail\\Notify::updateAvailable(), "|", Align\\Mail\\Notify::backupReminder(time());'],env=ENV,capture_output=True,text=True).stdout
mq=q("select kind, subject, body_html from mail_queue order by id")
ok(out.startswith("10|") and any(m["kind"]=="updates" and "1.99.0 is available" in m["subject"] and "Shiny new thing" in m["body_html"] for m in mq),"update email once per version: "+out)
ok(any(m["kind"]=="backup_reminder" and "No backup of MSP-ALIGN has been downloaded yet" in m["body_html"] for m in mq),"backup reminder email")
out=subprocess.run(["php","-r",'require "'+APP+'/src/bootstrap.php"; var_dump(Align\\Mail\\Notify::backupReminder(time()));'],env=ENV,capture_output=True,text=True).stdout
ok("NULL" in out,"reminder not repeated within the period")
r=post(st,"/settings/system/settings",{"backup_reminder_days":"0"}); ok(q("select value from settings where name='backup_reminder_days'")[0]["value"]=="0","reminder can be turned off")
t=st.get(B+"/settings/notifications").text; ok("Backup reminder" in t and "When overdue" in t and ">Updates<" in t,"listed under Email & notifications")

# ---- page after everything
t=st.get(B+"/settings/system").text; ok(not errs(t) and "Recent jobs" in t and "Restore" in t,"final page renders")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
