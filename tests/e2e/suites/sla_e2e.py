from lib import *
import base64, hmac, hashlib, struct, time
_used={}
def totp(secret):
    secret=secret.replace(" ","").upper()
    while True:
        now=int(time.time())//30; step=max(now,_used.get(secret,-1)+1)
        if step<=now+1: break
        time.sleep(2)
    _used[secret]=step
    key=base64.b32decode(secret+"="*((8-len(secret)%8)%8))
    h=hmac.new(key,struct.pack(">Q",step),hashlib.sha1).digest(); o=h[-1]&15
    return "%06d"%((struct.unpack(">I",h[o:o+4])[0]&0x7fffffff)%1000000)
def mock(path, body=None): return requests.post(M+path, json=body or {}).json()
def php(code): return subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; '+code],env=ENV,capture_output=True,text=True).stdout
def sync(): return [l for l in align("sync").splitlines() if "tickets & SLAs" in l]
def state(): return json.loads(q("select value from settings where name='psa_tickets_state'")[0]["value"])

requests.post(M+"/mock/reset"); setting("psa_sla_sync","1"); setting("sla_target","90")
q("delete from psa_tickets"); q("delete from settings where name in ('psa_tickets_state','psa_sla_supported')")
st=login("admin@example.com","LongPassword123!")
t=st.get(B+"/clients/1/service-levels").text; ok("No tickets yet" in t and not errs(t),"empty state before the first sync")

# ---- full read
out=sync(); ok(out and "full read" in out[0],"first sync reads every ticket: "+(out[0] if out else "none"))
n=q("select count(*) n from psa_tickets")[0]["n"]; ok(n>300,f"{n} tickets stored")
ok(not q("select 1 from psa_tickets where subject='Very old ticket'"),"tickets older than 36 months are skipped")
ok(q("select value from settings where name='psa_sla_supported'")[0]["value"]=="1","SLA fields detected")
cols=[r["Field"] for r in q("show columns from psa_tickets")]; ok("details" not in " ".join(cols),"ticket body is never stored")
ok(q("select count(*) n from psa_tickets where client_id is null")[0]["n"]==0,"tickets linked to Align clients")
total=state()["total"]

# ---- incremental: only the newest page, then open tickets one by one
mock("/mock/tickets-new",{"tickets":[{"ticket_subject":"Brand new ticket","ticket_client_id":2}]})
out=sync(); calls=mock("/mock/tickets-calls")["calls"]
ok("incremental" in out[0] and q("select 1 from psa_tickets where subject='Brand new ticket' and client_id=2"),"hourly sync picks up a new ticket: "+out[0])
ok(all(not c.startswith("page:") or int(c.split(":")[1])>=total-100 for c in calls) and "page:0" not in calls,"only the newest page is read: "+",".join(calls[:4]))
# an open ticket that's older than the newest page changes in ITFlow
old_open=q("select id from psa_tickets where closed_at is null order by id limit 1")[0]["id"]
q("update settings set value=%s where name='psa_tickets_state'",json.dumps({**state(),"total":10**6}))  # pretend the tail is far away
mock("/mock/ticket-edit",{"ticket_id":old_open,"fields":{"ticket_status":5,"ticket_resolved_at":"2026-01-01 10:00:00","ticket_closed_at":"2026-01-01 10:00:00"}})
out=sync(); ok(q("select closed_at from psa_tickets where id=%s",old_open)[0]["closed_at"] is not None and "open re-checked" in out[0],"open tickets are re-read one by one: "+out[0])
# deleted in ITFlow
victim=q("select id from psa_tickets where closed_at is null order by id desc limit 1")[0]["id"]
mock("/mock/ticket-delete",{"ticket_id":victim}); sync()
ok(not q("select 1 from psa_tickets where id=%s",victim),"open ticket deleted in ITFlow is removed")
closed_victim=q("select id from psa_tickets where closed_at is not null order by id limit 1")[0]["id"]
mock("/mock/ticket-delete",{"ticket_id":closed_victim})
q("update settings set value=%s where name='psa_tickets_state'",json.dumps({**state(),"full_at":"2020-01-01 00:00:00"}))
out=sync(); ok("full read" in out[0] and "removed" in out[0] and not q("select 1 from psa_tickets where id=%s",closed_victim),"daily full read removes deleted tickets: "+out[0])

# ---- metrics match the raw data
cid=1
exp=q("""select sum(response_met=1) rm, sum(response_met=0) rx, sum(resolution_met=1) sm, sum(resolution_met=0) sx, count(*) n from psa_tickets
  where client_id=%s and archived_at is null and created_at >= date_sub(curdate(), interval 89 day)""",cid)[0]
got=json.loads(php('[$f,$t]=Align\\Service\\Sla::range("90"); echo json_encode(Align\\Service\\Sla::stats(1,$f,$t));'))
ok(got["tickets"]==exp["n"] and got["resp_met"]==int(exp["rm"]) and got["res_missed"]==int(exp["sx"]),f"90-day stats match the tickets ({got['tickets']} tickets)")
pct=round(int(exp["rm"])*100/(int(exp["rm"])+int(exp["rx"])),1); ok(abs(got["resp_pct"]-pct)<0.05,f"response on time {got['resp_pct']}%")
oc=json.loads(php('echo json_encode(Align\\Service\\Sla::openCounts(1));')); ok(oc["breached"]>=2 and oc["warning"]>=1,"open tickets past target and close to target counted: "+str(oc))

# ---- client page
t=st.get(B+"/clients/1/service-levels").text
ok(not errs(t) and "Responded on time" in t and "sla-chart" in t and "Server backup failing" in t and "Past target" in t and "Close to target" in t,"client page: tiles, chart, open tickets with state")
ok("/agent/ticket.php?ticket_id=" in t,"tickets link to ITFlow")
ok('href="/clients/1/service-levels"' in t and "Service levels" in t,"client menu has Service levels")
for p in ["30","180","365","quarter","bogus"]:
    r=st.get(B+f"/clients/1/service-levels?period={p}"); ok(r.status_code==200 and not errs(r.text),"period "+p)
t=st.get(B+"/clients/4/service-levels").text; ok("None of this client" in t and "have an SLA" in t,"client with no SLA explains why")
t=st.get(B+"/clients/1").text; ok("Service levels" in t and "response on time" in t and "sla-months" in t,"overview card")

# ---- reports
t=st.get(B+"/clients/1/report/sla").text; ok(not errs(t) and "Service Level Report" in t and "Tickets that missed a target" in t and 'name="period"' in t,"client report with missed tickets and a period picker")
t=st.get(B+"/clients/1/report/sla?missed=0&period=365").text; ok("Tickets that missed a target" not in t and "Last 12 months" in t,"missed list can be switched off")
t=st.get(B+"/clients/1/report/qbr").text; ok(re.search(r'<b>\d\d</b>Service levels',t) and "Service levels" in t and "Tickets that missed a target" in t,"QBR has a Service levels section")
t=st.get(B+"/clients/1/report/qbr?s_assets=0&s_licensing=0&s_backup=0&s_compliance=0&s_roadmap=0&s_budget=0").text  # the other sections off, so their highlights don't crowd it out of the top six
ok(re.search(r'callout t-\w+"><b>Service levels',t) is not None,"QBR highlights mention service levels")
t=st.get(B+"/clients/1/report/qbr?s_sla=0").text; ok(not re.search(r'<b>\d\d</b>Service levels',t),"QBR section can be switched off")
t=st.get(B+"/reports/sla").text; ok(not errs(t) and "By client" in t and "Cedar Ridge Family Dental" in t and "Open tickets past or close to target" in t and "no SLA assigned" in t,"all-clients report")
t=st.get(B+"/reports").text; ok("/report/sla" in t and 'action="/reports/sla"' in t and '"sla":true' in t,"reports hub offers both SLA reports")
t=st.get(B+"/").text; ok('data-card="sla"' in t and "open past target" in t and "SLA targets met" in t,"dashboard card and tile")
mid=q("select id from meetings where client_id=1 limit 1")
if mid:
    t=st.get(B+f"/meetings/{mid[0]['id']}").text; ok("responded and" in t and "/report/sla" in t,"meeting talking points include service levels")

# ---- integration settings
t=st.get(B+"/integrations/itflow").text; ok('name="psa_sla_sync_present"' in t and 'name="sla_target"' in t and "Tickets for SLA reporting" in t,"ITFlow page has the SLA switch, goal and status")
st.post(B+"/integrations/itflow",data={"_csrf":csrf(st,"/integrations/itflow"),"sla_target":"95","psa_sla_sync_present":"1","psa_sla_sync":"1"})
ok(q("select value from settings where name='sla_target'")[0]["value"]=="95","goal saved")
# viewer
v=login("viewer@example.com","ViewerPassword123!"); ok(v.get(B+"/clients/1/service-levels").status_code==200 and v.get(B+"/reports/sla").status_code==200,"viewers can see service levels")

# ---- portal: summary only
sec="JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP"
q("delete from portal_users where email='sla@client.example'")
php('Align\\DB::insert("portal_users",["client_id"=>1,"email"=>"sla@client.example","name"=>"SLA Viewer","password_hash"=>password_hash("Portal-Pass-123!",PASSWORD_DEFAULT),"is_active"=>1,"can_devices"=>1,"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("'+sec+'")]);')
p=requests.Session()
r=p.post(B+"/portal/login",data={"_csrf":csrf(p,"/portal/login"),"email":"sla@client.example","password":"Portal-Pass-123!"})
if r.url.endswith("/2fa"): r=p.post(B+"/portal/login/2fa",data={"_csrf":csrf(p,"/portal/login/2fa"),"code":totp(sec)})
t=p.get(B+"/portal").text; ok("Support service levels" in t and "answered on time" in t and "Server backup failing" not in t,"portal home shows the summary, no ticket subjects")
t=p.get(B+"/portal/report/sla").text; ok("Service Level Report" in t and "Tickets that missed a target" not in t and 'id="opt-missed"' not in t and not errs(t),"portal SLA report has no ticket list")
t=p.get(B+"/portal/report/qbr").text; ok(re.search(r'<b>\d\d</b>Service levels',t) and "Tickets that missed a target" not in t,"portal QBR includes service levels without the ticket list")
q("update portal_users set can_devices=0 where email='sla@client.example'")
ok(p.get(B+"/portal/report/sla").status_code in (302,403) or "not" in p.get(B+"/portal/report/sla").text.lower(),"portal users without Devices & compliance can't open it")
ok("Support service levels" not in p.get(B+"/portal").text,"and don't see the card")
q("delete from portal_users where email='sla@client.example'")

# ---- old ITFlow without SLA fields
mock("/mock/tickets-nosla",{"on":True}); q("update settings set value=%s where name='psa_tickets_state'",json.dumps({**state(),"full_at":"2020-01-01 00:00:00"}))
out=sync(); ok("26.08" in out[0] and q("select value from settings where name='psa_sla_supported'")[0]["value"]=="0","older ITFlow is detected: "+out[0])
t=st.get(B+"/clients/1/service-levels").text; ok("doesn't have SLAs yet" in t,"page explains ITFlow needs updating")
mock("/mock/tickets-nosla",{"on":False}); q("update settings set value=%s where name='psa_tickets_state'",json.dumps({**state(),"full_at":"2020-01-01 00:00:00"})); sync()

# ---- switched off
setting("psa_sla_sync","0")
ok(not sync(),"no ticket step when switched off")
t=st.get(B+"/clients/1").text; ok("/service-levels" not in t,"menu item and card hidden when off")
ok("Service levels" not in st.get(B+"/clients/1/report/qbr").text.split('class="toc"')[1].split("</div>\n  </div>")[0],"QBR leaves the section out when off")
setting("psa_sla_sync","1"); setting("sla_target","90")
requests.post(M+"/mock/reset"); q("update settings set value=%s where name='psa_tickets_state'",json.dumps({**state(),"full_at":"2020-01-01 00:00:00"})); sync()
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
