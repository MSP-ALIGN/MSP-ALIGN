from lib import *
def f2(s,p,action=None): return form(s,p,'action="%s"'%(action or p))
st=login("chris@example.com","LongPassword123!")

# ---- hub
t=st.get(B+"/integrations").text
ok(not errs(t) and all(n in t for n in ["ITFlow","NinjaOne","Veeam Service Provider Console","Microsoft 365 / Google Workspace","Dell TechDirect","Lenovo"]),"hub lists every integration")
ok(all(c in t for c in ["PSA &amp; documentation","RMM","Backup","Email &amp; calendar","Warranty"]),"grouped by category")
ok('href="/integrations/email"' in t and 'href="/mapping"' in t and 'href="/sync"' in t,"links to email page, mapping and sync")
for who,pw in [("tech@example.com","TechPassword123!"),("viewer@example.com","ViewerPassword123!")]:
    s2=login(who,pw); ok(s2.get(B+"/integrations").status_code==403 and s2.get(B+"/integrations/ninjaone").status_code==403 and "/integrations\"" not in s2.get(B+"/").text,f"{who.split('@')[0]}: no access, not in menu")
ok(st.get(B+"/integrations/nope").status_code==404,"unknown integration 404")

# ---- generic form: NinjaOne
q("delete from mail_queue where kind='security'")
t=st.get(B+"/integrations/ninjaone").text; ok("How to set it up" in t and "Client credentials" in t and 'name="ninja_instance"' in t and "Test connection" in t,"NinjaOne page")
F=f2(st,"/integrations/ninjaone")
r=st.post(B+"/integrations/ninjaone",data={**F,"ninja_instance":"eu.ninjarmm.com","ninja_client_secret":"new-secret-123"})
ok("NinjaOne saved" in flash(r.text) and q("select value from settings where name='ninja_instance'")[0]["value"]=="eu.ninjarmm.com","select saved")
raw=q("select value,is_secret from settings where name='ninja_client_secret'")[0]; ok(raw["is_secret"]==1 and "new-secret-123" not in raw["value"],"secret encrypted")
ok(q("select count(*) n from mail_queue where kind='security' and subject like '%%Integration keys changed%%'")[0]["n"]>=1 or not q("select value from settings where name='mail_mode' and value<>'off'"),"security alert on key change")
ok(q("select detail from audit_log where action='integration.save' order by id desc limit 1")[0]["detail"].startswith("NinjaOne: ninja_instance, ninja_client_secret"),"audited")
r=st.post(B+"/integrations/ninjaone",data={**f2(st,"/integrations/ninjaone"),"ninja_instance":"evil.example.com"}); ok(q("select value from settings where name='ninja_instance'")[0]["value"]=="eu.ninjarmm.com","unknown option ignored")
st.post(B+"/integrations/ninjaone",data={**f2(st,"/integrations/ninjaone"),"ninja_instance":"app.ninjarmm.com"})
q("update settings set value='http://127.0.0.1:8099' where name='ninja_instance'")
F=f2(st,"/integrations/ninjaone"); ok(F.get("ninja_instance")=="http://127.0.0.1:8099","a custom saved value stays selected")
st.post(B+"/integrations/ninjaone",data=F); ok(q("select value from settings where name='ninja_instance'")[0]["value"]=="http://127.0.0.1:8099","and survives saving the form")
r=st.post(B+"/integrations/ninjaone",data={**f2(st,"/integrations/ninjaone")}); ok("No changes" in flash(r.text),"blank secret keeps the saved one")
ok(st.get(B+"/integrations/ninjaone").text.count("saved (leave blank to keep)")==1,"saved secret shown as saved, never the value")

# ---- ITFlow: switches, checkboxes, url validation (nothing saved on error)
F=f2(st,"/integrations/itflow")
before=q("select value from settings where name='psa_writeback'")[0]["value"]
r=st.post(B+"/integrations/itflow",data={**F,"itflow_url":"ftp://bad","psa_writeback":"overwrite"})
ok("must start with https://" in flash(r.text) and "Nothing was saved" in flash(r.text) and q("select value from settings where name='psa_writeback'")[0]["value"]==before,"bad URL: nothing saved")
F=f2(st,"/integrations/itflow"); F.pop("psa_create_assets",None); F["psa_import_types[]"]=["network","printer","ups"]
r=st.post(B+"/integrations/itflow",data=F)
ok(q("select value from settings where name='psa_create_assets'")[0]["value"]=="0" and q("select value from settings where name='psa_import_types'")[0]["value"]=="network,printer,ups","switch off and checkboxes saved")
F=f2(st,"/integrations/itflow"); F["psa_create_assets"]="1"; F["psa_import_types[]"]=["network","printer","ups","storage"]; st.post(B+"/integrations/itflow",data=F)
ok(q("select value from settings where name='psa_create_assets'")[0]["value"]=="1","switch back on")
r=st.post(B+"/integrations/itflow/test",data={"_csrf":csrf(st,"/integrations/itflow")}); ok("ITFlow:" in flash(r.text) or "ITFlow test failed" in flash(r.text),"test runs: "+flash(r.text)[:80])
t=st.get(B+"/integrations/itflow").text; ok("Last 2-minute ITFlow check" in t,"notes shown")

# ---- status from the last sync
rid=q("select id from sync_runs where finished_at is not null order by id desc limit 1")[0]["id"]
old=q("select summary from sync_runs where id=%s",rid)[0]["summary"]
s2=json.loads(old); s2["Veeam backups"]="ERROR: 401 Unauthorized"; q("update sync_runs set summary=%s where id=%s",json.dumps(s2),rid)
t=st.get(B+"/integrations").text; ok("401 Unauthorized" in t and ">Error<" in t,"card shows last sync error")
ok(re.search(r'href="/integrations" class="nav-link[^"]*"><i[^>]*></i><p>Integrations <span class="badge badge-warning right">[1-9]<',st.get(B+"/").text),"menu badge counts integrations with errors")
q("update sync_runs set summary=%s where id=%s",old,rid)

# ---- email page moved, callback kept
t=st.get(B+"/integrations/email").text; ok("Mail connection" in t and "Send a test" in t and 'action="/integrations/email"' in t and 'notif_digest_hour' not in t,"email connection page (schedule moved)")
r=st.get(B+"/settings/email",allow_redirects=False); ok(r.headers.get("Location","").endswith("/integrations/email"),"old email address redirects")
r=st.get(B+"/settings/email/callback?error=access_denied&error_description=nope"); ok("Sign-in was not completed" in flash(r.text) and r.url.endswith("/integrations/email"),"OAuth callback URL unchanged")

# ---- settings tabs
t=st.get(B+"/settings").text
ok(all(x in t for x in ["General","Planning &amp; lifecycle","OS support dates","Notifications","Branding","Updates &amp; backups"]) and 'name="ninja_client_id"' not in t,"settings tabs; no integration fields")
lif=q("select value from settings where name='lifespan_desktop'")[0]["value"]
r=st.post(B+"/settings",data={**f2(st,"/settings","/settings"),"company_name":"Example MSP Group","session_idle_minutes":"20"})
ok(q("select value from settings where name='company_name'")[0]["value"]=="Example MSP Group" and q("select value from settings where name='lifespan_desktop'")[0]["value"]==lif and r.url.endswith("/settings"),"general save leaves planning values alone")
F=f2(st,"/settings/planning","/settings"); F["lifespan_desktop"]="6"; F["warranty_recheck_days"]="45"
r=st.post(B+"/settings",data=F)
ok(q("select value from settings where name='lifespan_desktop'")[0]["value"]=="6" and q("select value from settings where name='warranty_recheck_days'")[0]["value"]=="45" and q("select value from settings where name='company_name'")[0]["value"]=="Example MSP Group" and r.url.endswith("/settings/planning"),"planning save, back on planning tab")
F["lifespan_desktop"]=lif; st.post(B+"/settings",data=F)
for p in ["/settings/os","/settings/branding","/settings/system","/settings/notifications","/settings/notifications/log"]:
    t=st.get(B+p).text; ok('class="nav-tabs' in t.replace('nav nav-tabs','class="nav-tabs') and not errs(t),p+" has settings tabs")

# ---- notifications page saves schedule + meeting options
F=f2(st,"/settings/notifications"); F["notif_digest_hour"]="6"; F["mail_meeting_mode"]="ics"
r=st.post(B+"/settings/notifications",data=F)
ok(q("select value from settings where name='notif_digest_hour'")[0]["value"]=="6" and q("select value from settings where name='mail_meeting_mode'")[0]["value"]=="ics" and r.url.endswith("/settings/notifications"),"schedule and invitation options saved")
F=f2(st,"/settings/notifications"); F["notif_digest_hour"]="7"; F["mail_meeting_mode"]="calendar"; st.post(B+"/settings/notifications",data=F)

# ---- menus and sections
d=st.get(B+"/").text
side=d.split('main-sidebar')[1].split('</aside>')[0]
order=[m for m in re.findall(r'<li class="nav-header">([^<]+)</li>',side)]
ok(order==["PLANNING","MEETINGS &amp; REPORTS","COMPLIANCE","INTEGRATIONS","ADMIN"],"sidebar sections: "+str(order))
ok('href="/calendar"' not in side and 'href="/frameworks"' not in side and 'href="/settings/email"' not in side and 'href="/settings/branding"' not in side,"calendar, frameworks, email and branding moved out of the sidebar")
t=st.get(B+"/meetings").text; ok('href="/calendar"' in t and 'nav-tabs' in t,"meetings has a calendar tab")
t=st.get(B+"/compliance").text; ok('href="/frameworks"' in t,"compliance has a frameworks tab for admins")
v=login("viewer@example.com","ViewerPassword123!"); ok('href="/frameworks"' not in v.get(B+"/compliance").text,"viewers don't see the frameworks tab")

# ---- audit log filters and quick check
t=st.get(B+"/audit?group=settings&q=company").text; ok("settings.save" in t and "login.success" not in t,"audit filter by kind and text")
t=st.get(B+"/audit?user=1&group=login").text; ok("login.success" in t and "report." not in t,"audit filter by person")
r=st.post(B+"/audit/verify",data={"_csrf":csrf(st,"/audit")}); ok("intact" in flash(r.text),"full check on demand")
t=st.get(B+"/audit").text; ok("Tamper check passed" in t and "Whole log last checked" in t,"page shows quick check since the last full check")

# ---- help
t=st.get(B+"/help").text
ok(all(x in t for x in ["How-to guides","Where things are","Connect or change an integration","Back up and restore","Set up email and notifications"]) and not errs(t),"help has guides")
tv=v.get(B+"/help").text; ok("Update Align" not in tv and "Prepare a QBR pack" in tv,"viewers see only guides they can use")
setting("ninja_client_secret","ninja-secret",True)   # put the mock credentials back
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
