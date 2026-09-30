import os, glob, stat, tarfile, io, shutil
from lib import *
import sitecustomize
SP=WORK; T=SP+"/sys"; APP=ROOT
AENV=dict(ENV, ALIGN_APP_DIR=APP, ALIGN_DATA_DIR=T+"/data", ALIGN_AGENT_DIR=T+"/agent", ALIGN_RUN_DIR=T+"/run", ALIGN_RECIPIENT=T+"/recipient.txt",
          ALIGN_RUNAS="root", ALIGN_SYSTEMCTL="none", ALIGN_LEGACY_BACKUPS=T+"/legacy", ALIGN_RELEASE_SIGNERS="none", ALIGN_AGENT_TEST="1")  # branch updates (signed releases: sign_e2e)
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


import threading
from playwright.sync_api import sync_playwright
st=login("admin@example.com","LongPassword123!")
ok(agent("check",ALIGN_APP_DIR=T+"/app").returncode==0,"agent check ran")
# progress estimate: moves within a step over time, jumps with the next step, never passes a stage
pr=lambda *a: int(subprocess.run(["php","-r",'require "'+APP+'/src/bootstrap.php"; echo Align\\System\\Agent::progress(...json_decode($argv[1], true));',json.dumps(list(a))],env=ENV,capture_output=True,text=True).stdout or -1)
import datetime
ago=lambda s: (datetime.datetime.now()-datetime.timedelta(seconds=s)).strftime("%Y-%m-%d %H:%M:%S")
a,b_,c_=pr("update","running","Installing 1.99.0",ago(1)),pr("update","running","Installing 1.99.0",ago(60)),pr("update","running","Installing 1.99.0",ago(3600))
ok(44<=a<b_<=93 and c_==93,f"installing creeps forward but stops at its stage ({a}, {b_}, {c_})")
ok(pr("update","running","Downloading the latest version",ago(0))<a+1 and pr("update","succeeded","Done",None)==100,"later steps are further along; done is 100")
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_page(viewport={"width":1300,"height":900}); errs_=[]; pg.on("pageerror", lambda e_: errs_.append(str(e_)))
    pg.goto(B+"/login"); pg.fill("input[name=email]","admin@example.com"); pg.fill("input[name=password]","LongPassword123!"); pg.click("button"); sitecustomize.after_login(pg,"admin@example.com")
    pg.goto(B+"/settings/system"); pg.wait_for_timeout(300)
    ok(not pg.locator("#job-overlay").is_visible(),"no overlay before updating")
    pg.click("text=Update to 1.99.0"); pg.wait_for_timeout(300)
    ok(not pg.locator("#job-overlay").is_visible(),"without the confirmation box nothing starts")
    pg.check("#upd-confirm", force=True)
    with pg.expect_navigation():
        pg.click("text=Update to 1.99.0")
        pg.wait_for_timeout(50)
    pg.wait_for_timeout(300)
    ok(pg.locator("#job-overlay").is_visible() and "Updating" in pg.inner_text("#job-overlay") and "refreshes on its own" in pg.inner_text("#job-overlay"),"overlay shows as soon as the update starts")
    pg.screenshot(path=WORK+"/shots/upd_queued.png")
    th=threading.Thread(target=lambda: agent(ALIGN_APP_DIR=T+"/app",ALIGN_INSTALL_CMD="sleep 7; echo installer ran")); th.start()
    seen=[]; steps=set()
    for k in range(30):
        pg.wait_for_timeout(700)
        if not pg.locator("#job-overlay").count(): break
        try:
            seen.append(int(pg.inner_text("[data-ov-pct]"))); steps.add(pg.inner_text("[data-ov-step]"))
        except Exception: pass
        if any("Installing" in s_ for s_ in steps) and len(seen)>2 and not os.path.exists(T+"/pmshot"):
            open(T+"/pmshot","w").close(); pg.screenshot(path=WORK+"/shots/upd_running.png")
            m=b.new_page(viewport={"width":1300,"height":900}); m.goto(B+"/clients"); t_=m.content()
            ok("Please wait" in t_ and "progress-bar" in t_ and "refreshes on its own" in t_,"other pages show the please-wait page with a progress bar"); m.screenshot(path=WORK+"/shots/upd_maintenance.png"); m.close()
        if "Done" in steps: break
    th.join()
    os.path.exists(T+"/pmshot") and os.remove(T+"/pmshot")
    ok(any("Installing" in s_ for s_ in steps),"steps shown: "+", ".join(sorted(steps))[:200])
    ok(seen==sorted(seen) and seen and seen[-1]>seen[0],"progress only goes forward: "+str(seen[:12]))
    pg.wait_for_timeout(3500)
    t=pg.content(); ok("Updated from" in t and not pg.locator("#job-overlay").is_visible(),"page reloads on its own and shows the result")
    ok(not errs_,"no script errors: "+str(errs_))
    b.close()
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
