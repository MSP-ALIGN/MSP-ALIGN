"""Email: Microsoft 365 (Graph) connector, notifications, digests, meeting invitations."""
from lib import *

requests.get(M+"/mock/graph-reset"); q("delete from meetings where title in ('ICS Review','Q4 Business Review')"); q("delete from mail_queue"); q("delete from notify_state"); q("delete from login_attempts where email like 'reset:%%'"); q("delete from user_notification_prefs"); q("update users set notify_scope=NULL")
q("delete from settings where name like 'notif\\_%%' or name like 'm365\\_token\\_cache' or name like 'm365\\_connected%%' or name='m365_refresh_token'")
setting("mail_provider","microsoft"); setting("m365_login_base",M+"/login"); setting("m365_graph_base",M+"/graph/v1.0"); setting("mail_meeting_mode","calendar"); setting("notif_digest_hour","0")
q("update roadmap_items set status='proposed', decided_at=NULL, decided_by_name=NULL, decided_by_portal_user_id=NULL, decision_comment=NULL where client_id=1 and title='Upgrade firewall to FortiGate 60F'")
st=login("chris@example.com","LongPassword123!")

# ---- settings: validation + app-only secret
t=st.get(B+"/integrations/email").text; ok("Mail connection" in t and "Google Workspace" in t and not errs(t),"settings page renders")
F=form(st,"/integrations/email")
r=st.post(B+"/integrations/email",data={**F,"mail_mode":"app","m365_client_id":"not-a-guid"}); ok("GUID" in flash(r.text),"client id validated")
r=st.post(B+"/integrations/email",data={**F,"mail_mode":"app","m365_tenant":"examplemsp.onmicrosoft.com","m365_client_id":"11111111-2222-3333-4444-555555555555","m365_auth":"secret","m365_client_secret":"wrong-secret","mail_from":"alerts@examplemsp.example","mail_from_name":"Example MSP"})
ok("saved" in flash(r.text).lower(),"app settings saved")
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"})
ok("client secret is wrong" in flash(r.text),"wrong secret -> friendly error: "+flash(r.text)[:120])
r=st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"m365_client_secret":"m365-secret"})
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"})
g=graph(); ok("Test email sent" in flash(r.text) and g["mail"] and g["mail"][-1]["mailbox"]=="alerts@examplemsp.example" and g["mail"][-1]["message"]["from"]["emailAddress"]["name"]=="Example MSP","test email sent as the From mailbox")
ok("Ready" in st.get(B+"/integrations/email").text,"status shows Ready")
r=st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"mail_from":"missing@examplemsp.example"})
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"}); ok("Mailbox not found" in flash(r.text),"missing mailbox explained")
r=st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"mail_from":"denied@examplemsp.example"})
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"}); ok("Access denied" in flash(r.text) and "RBAC" in flash(r.text),"access denied explained")
st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"mail_from":"alerts@examplemsp.example"})
ok(q("select count(*) n from mail_queue where kind='security' and subject like '%%Email settings changed%%'")[0]["n"]>=1,"email settings change raised a security alert")
sec=q("select value from settings where name='m365_client_secret'")[0]["value"]; ok("m365-secret" not in sec,"secret stored encrypted")

# ---- certificate auth
subprocess.run("openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj /CN=AlignTest -keyout /tmp/t.key -out /tmp/t.crt 2>/dev/null",shell=True)
r=st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"m365_auth":"certificate","m365_cert_pem":"garbage"}); ok("PEM" in flash(r.text),"bad certificate refused")
r=st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"m365_auth":"certificate","m365_cert_pem":open("/tmp/t.crt").read(),"m365_key_pem":open("/tmp/t.key").read()})
t=st.get(B+"/integrations/email").text; ok("thumbprint" in t and "AlignTest" in t,"certificate details shown")
n0=len(graph()["mail"]); r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"})
g=graph(); ok(len(g["mail"])==n0+1 and g["calls"][-1]["auth"]=="cert","certificate (client assertion) sign-in works")
st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"m365_auth":"secret"})

# ---- notifications settings + account prefs
F=form(st,"/settings/notifications",'action="/settings/notifications"')
F["extra[backup_failed]"]="tickets@examplemsp.example, nope"
r=st.post(B+"/settings/notifications",data=F); ok("Not an email address: nope" in flash(r.text) and q("select value from settings where name='notif_backup_failed_extra'")[0]["value"]=="tickets@examplemsp.example","extra recipients validated")
F=form(st,"/settings/notifications",'action="/settings/notifications"'); F.pop("on[client_meeting_reminder]",None); F["on[client_meeting_reminder]"]="1"; F["roles[renewals][]"]=["admin"]
r=st.post(B+"/settings/notifications",data=F); ok(q("select value from settings where name='notif_client_meeting_reminder'")[0]["value"]=="1","notification switched on")
t=st.get(B+"/settings/notifications/preview/backup_digest").text; ok("Daily backup summary" in t,"digest preview")
ok(st.get(B+"/settings/notifications/preview/nope").status_code==404,"unknown preview 404")
tech=login("tech@example.com","TechPassword123!")
t=tech.get(B+"/account").text; ok("Email notifications" in t and "Security alerts" not in t,"tech account prefs (no security)")
ok(tech.get(B+"/integrations/email").status_code==403,"tech can't open email settings")
r=tech.post(B+"/account/notifications",data={"_csrf":csrf(tech,"/account"),"scope":"all","notif[backup_failed]":"1","notif[meeting_reminder]":"1"})
ok(q("select notify_scope from users where email='tech@example.com'")[0]["notify_scope"]=="all" and q("select enabled from user_notification_prefs p join users u on u.id=p.user_id where u.email='tech@example.com' and notif_key='renewals'")[0]["enabled"]==0,"account prefs saved")

# ---- backup failure alerts after sync (dedupe on the next sync)
align("sync")
rows=q("select * from mail_queue where kind='backup_failed' order by id")
ok(len(rows)>=1,"backup failure alert queued")
rc=set(a["address"] for r in rows for a in json.loads(r["recipients"]))
ok({"chris@example.com","tech@example.com","tickets@examplemsp.example"}<=rc,"sent to admin, opted-in tech and extra address: "+str(rc))
n1=len(rows); align("sync"); ok(len(q("select id from mail_queue where kind='backup_failed'"))==n1,"no repeat alert for the same failure")
out=align("mail:run"); ok(q("select count(*) n from mail_queue where status='queued' and send_after<=now()")[0]["n"]==0,"mail:run sent the queue: "+out.strip()[:80])

# ---- sync failure + recovery
key=q("select value from settings where name='veeam_api_key'")[0]["value"]; setting("veeam_api_key","broken",True)
align("sync"); ok(q("select count(*) n from mail_queue where kind='sync_failed' and subject like '%%had errors%%'")[0]["n"]==1,"sync problem email")
align("sync"); ok(q("select count(*) n from mail_queue where kind='sync_failed'")[0]["n"]==1,"not repeated while still failing")
q("update settings set value=%s where name='veeam_api_key'",key); align("sync")
ok(q("select count(*) n from mail_queue where kind='sync_failed' and subject like '%%recovered%%'")[0]["n"]==1,"recovery email")

# ---- security alert on new staff user
before=len(q("select id from mail_queue where kind='security'"))
em=f"new{int(time.time())}@example.com"
st.post(B+"/users",data={"_csrf":csrf(st,"/users"),"email":em,"name":"New Person","role":"tech"})
ok(len(q("select id from mail_queue where kind='security'"))==before+1 and "Staff account created" in q("select subject from mail_queue where kind='security' order by id desc limit 1")[0]["subject"],"security alert for new staff account")

# ---- portal invite email + forgot password
pe=f"pat{int(time.time())}@client.example"
r=st.post(B+"/clients/1/portal",data={"_csrf":csrf(st,"/clients/1/portal"),"name":"Pat Portal","email":pe,"send_email":"1","can_roadmap":"1","can_approve":"1","can_devices":"1"})
ok("emailed them the link" in flash(r.text),"invite emailed: "+flash(r.text)[:80])
msg=[m for m in graph()["mail"] if m["message"]["toRecipients"][0]["emailAddress"]["address"]==pe][-1]["message"]
link=H.unescape(re.search(r'href="([^"]*/portal/invite/[a-f0-9]{64})"',msg["body"]["content"]).group(1))
qr=q("select body_html,purged,status from mail_queue where kind='client_portal_invite' order by id desc limit 1")[0]
ok(qr["status"]=="sent" and qr["body_html"] is None and qr["purged"]==1,"invite body wiped after sending")
p=requests.Session(); t=p.get(link).text; ok("Set" in t and "password" in t.lower(),"emailed link opens set-password page")
p.post(link,data={"_csrf":csrf(p,link.replace(B,"")),"password":"Juniper-Canyon-Otter-42","confirm":"Juniper-Canyon-Otter-42"})
r=p.post(B+"/portal/account/2fa",data={"_csrf":csrf(p,"/portal/account"),"action":"begin"})
secp=re.search(r'<code[^>]*>([A-Z2-7 ]+)</code>',r.text).group(1).replace(" ","")
p.post(B+"/portal/account/2fa",data={"_csrf":csrf(p,"/portal/account"),"action":"confirm","code":totp(secp)})
q("update roadmap_items set status='proposed', decided_at=NULL, decided_by_portal_user_id=NULL, decided_by_name=NULL, decision_comment=NULL where id=32")  # a project waiting for the client
pid=q("select id from roadmap_items where client_id=1 and status='proposed' limit 1")
if pid:
    p.post(B+f"/portal/projects/{pid[0]['id']}/decide",data={"_csrf":csrf(p,"/portal/roadmap"),"decision":"approve","comment":"Yes please"})
    r2=q("select * from mail_queue where kind='portal_activity' order by id desc limit 1")
    ok(r2 and "approved" in r2[0]["subject"] and "chris@example.com" in r2[0]["recipients"],"portal approval emailed to vCIO")
else: ok(False,"no proposed project to approve")
# forgot password
t=requests.get(B+"/portal/login").text; ok("Forgot your password?" in t,"forgot link on portal login")
f=requests.Session(); n=len(q("select id from mail_queue where kind='client_portal_reset'"))
r=f.post(B+"/portal/forgot",data={"_csrf":csrf(f,"/portal/forgot"),"email":pe}); m1=flash(r.text)
r=f.post(B+"/portal/forgot",data={"_csrf":csrf(f,"/portal/forgot"),"email":"nobody@nowhere.example"}); m2=flash(r.text)
ok(m1==m2 and "If that email has a portal account" in m1,"same answer for known and unknown emails")
rows=q("select * from mail_queue where kind='client_portal_reset' order by id desc"); ok(len(rows)==n+1 and rows[0]["status"]=="queued","reset queued (not sent inline)")
align("mail:run")
msg=[m for m in graph()["mail"] if m["message"]["toRecipients"][0]["emailAddress"]["address"]==pe][-1]["message"]
ok("Reset your password" in msg["body"]["content"] and "1 hour" in msg["body"]["content"],"reset email sent by the timer with 1-hour link")
for i in range(4): f.post(B+"/portal/forgot",data={"_csrf":csrf(f,"/portal/forgot"),"email":pe})
ok(len(q("select id from mail_queue where kind='client_portal_reset'"))<=n+3,"reset requests rate limited")

# ---- meetings: Outlook calendar invitations
tok=csrf(st,"/meetings")
r=st.post(B+"/meetings",data={"_csrf":tok,"client_id":"1","title":"Q4 Business Review","type":"qbr","date":time.strftime("%Y-%m-%d",time.localtime(time.time()+3*86400)),"time":"10:00","duration":"60","attendees":"Jordan Ellis <jordan@client.example>, sam@client.example","agenda":"1. Backups\n2. Budget","send_invites":"1","owner_id":"1"})
fm=flash(r.text); mid=q("select id from meetings where title='Q4 Business Review' order by id desc limit 1")[0]["id"]
m=q("select * from meetings where id=%s",mid)[0]; ev=graph()["events"].get(m["graph_event_id"] or "",{})
ok("through Outlook" in fm and ev.get("subject","").endswith("Q4 Business Review") and len(ev.get("attendees",[]))==2,"Outlook event created with 2 attendees: "+fm[:100])
ok(m["video_url"] and "teams.microsoft.com" in m["video_url"] and ev.get("organizerMailbox")=="chris@example.com","Teams link saved; organized from the owner's calendar")
F=form(st,f"/meetings/{mid}",f'action="/meetings/{mid}"') if 'action="/meetings/%d"'%mid in st.get(B+f"/meetings/{mid}").text else {}
r=st.post(B+f"/meetings/{mid}",data={"_csrf":csrf(st,f"/meetings/{mid}"),"action":"save","client_id":"1","title":"Q4 Business Review","type":"qbr","date":time.strftime("%Y-%m-%d",time.localtime(time.time()+4*86400)),"time":"11:00","duration":"90","attendees":"jordan@client.example","agenda":"Moved","send_invites":"1","owner_id":"1","video_url":m["video_url"]})
ev=graph()["events"][m["graph_event_id"]]; ok(ev["status"]=="updated" and len(ev["attendees"])==1,"update sent through Outlook")
r=st.post(B+f"/meetings/{mid}",data={"_csrf":csrf(st,f"/meetings/{mid}"),"action":"cancel"})
ok(graph()["events"][m["graph_event_id"]]["status"]=="cancelled" and "Cancellation sent" in flash(r.text),"cancellation sent through Outlook")
# ICS mode
setting("mail_meeting_mode","ics")
r=st.post(B+"/meetings",data={"_csrf":csrf(st,"/meetings"),"client_id":"1","title":"ICS Review","type":"qbr","date":time.strftime("%Y-%m-%d",time.localtime(time.time()+5*86400)),"time":"09:00","duration":"30","attendees":"jordan@client.example","send_invites":"1","owner_id":"1"})
msg=[m for m in graph()["mail"] if "ICS Review" in m["message"]["subject"]][-1]["message"]; att=(msg.get("attachments") or [{}])[0]
ics=base64.b64decode(att.get("contentBytes","")).decode().replace("\r\n ","") if att else ""
ok("METHOD:REQUEST" in ics and "ATTENDEE" in ics and "jordan@client.example" in ics and att.get("name")=="invite.ics","ICS invitation emailed")
setting("mail_meeting_mode","calendar")
# reminders
q("update meetings set reminder_sent_at=NULL where title='ICS Review'"); q("update meetings set starts_at=now()+interval 2 hour, ends_at=now()+interval 3 hour where title='ICS Review'")
align("mail:run")
ok(q("select count(*) n from mail_queue where kind='meeting_reminder' and subject like '%%ICS Review%%'")[0]["n"]==1,"owner reminder sent")
ok(q("select count(*) n from mail_queue where kind='client_meeting_reminder' and subject like '%%ICS Review%%'")[0]["n"]==1,"attendee reminder sent (switched on)")
align("mail:run"); ok(q("select count(*) n from mail_queue where kind='meeting_reminder' and subject like '%%ICS Review%%'")[0]["n"]==1,"reminder not repeated")

# ---- delegated mode (Connect with Microsoft)
st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"mail_mode":"delegated","mail_from":""})
r=st.get(B+"/settings/email/connect",allow_redirects=False); loc=r.headers.get("Location","")
ok(r.status_code==302 and "code_challenge=" in loc and "offline_access" in requests.utils.unquote(loc),"connect redirects to Microsoft with PKCE")
r2=requests.get(loc,allow_redirects=False); cb=r2.headers["Location"]
bad=st.get(re.sub(r"state=[^&]+","state=forged",cb)); ok("did not match" in flash(bad.text),"forged state refused")
r=st.get(B+"/settings/email/connect",allow_redirects=False); cb=requests.get(r.headers["Location"],allow_redirects=False).headers["Location"]
r=st.get(cb); ok("Connected to Microsoft 365 as alerts@examplemsp.example" in flash(r.text),"callback connects: "+flash(r.text)[:80])
rt=q("select value from settings where name='m365_refresh_token'")[0]["value"]; ok(rt and "rt-1" not in rt,"refresh token stored encrypted")
q("delete from settings where name='m365_token_cache'")
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"}); g=graph()
ok("Test email sent" in flash(r.text) and g["mail"][-1]["delegated"] and g["rt"]==2,"delegated send refreshes and rotates the token")
q("delete from settings where name='m365_token_cache'")
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"}); ok("Test email sent" in flash(r.text) and graph()["rt"]==3,"rotated token used next time")
r=st.post(B+"/integrations/email/disconnect",data={"_csrf":csrf(st,"/integrations/email")}); ok(not q("select * from settings where name='m365_refresh_token'"),"disconnect removes the token")
st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"mail_mode":"app","mail_from":"alerts@examplemsp.example"})

# ---- log page, retry, off mode
t=st.get(B+"/settings/notifications/log").text; ok("Email log" in t and not errs(t),"log page")
fid=q("insert into mail_queue (kind,recipients,subject,body_html,status,attempts,send_after) values ('test','[{\"address\":\"chris@example.com\",\"name\":\"\"}]','Retry me','<p>x</p>','failed',6,now())") or q("select max(id) id from mail_queue")[0]["id"]
fid=q("select max(id) id from mail_queue")[0]["id"]
r=st.post(B+f"/settings/notifications/log/{fid}",data={"_csrf":csrf(st,"/settings/notifications/log"),"action":"retry"}); ok(q("select status from mail_queue where id=%s",fid)[0]["status"]=="sent","retry sends a failed message")
setting("mail_mode","off"); n=q("select count(*) n from mail_queue")[0]["n"]
align("sync"); st.post(B+"/users",data={"_csrf":csrf(st,"/users"),"email":f"x{int(time.time())}@example.com","name":"X","role":"viewer"})
ok(q("select count(*) n from mail_queue")[0]["n"]==n,"nothing queued when email is off")
setting("mail_mode","app")
for pth in ["/integrations/email","/settings/notifications/log","/account","/clients/1/portal","/meetings","/settings/notifications/preview/weekly_digest","/settings/notifications/preview/renewals"]:
    r=st.get(B+pth); ok(r.status_code==200 and not errs(r.text),f"{pth}")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
