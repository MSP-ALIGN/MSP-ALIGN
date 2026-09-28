import re, requests, subprocess, pymysql, html as H, time, json, email, base64
from email import policy
from lib import *  # helpers: q, ok, csrf, flash, align, setting, login, form, errs
def gstate(): return requests.get(M+"/mock/graph").json().get("google",{"mail":[],"events":{},"calls":[],"revoked":0})
def parse(raw): return email.message_from_string(raw, policy=policy.default)
sa=json.load(open(WORK+"/sa.json"))

requests.get(M+"/mock/graph-reset"); q("delete from mail_queue"); q("delete from meetings where title like 'G-%%'")
q("delete from settings where name in ('g_sa_json','g_refresh_token','g_token_cache','g_client_id','g_client_secret','g_connected_as','g_connected_name','g_connected_at','g_calendar_granted')")
for k,v in {"g_token_url":M+"/google/token","g_auth_url":M+"/google/auth","g_gmail_base":M+"/google/gmail","g_calendar_base":M+"/google/calendar","g_revoke_url":M+"/google/revoke","mail_meeting_mode":"calendar","mail_meeting_organizer":"owner","mail_teams_links":"1","notif_client_meeting_invite":"1","notif_client_portal_invite":"1"}.items(): setting(k,v)
st=login("chris@example.com","LongPassword123!")

# ---- service account
F=form(st,"/integrations/email")
r=st.post(B+"/integrations/email",data={**F,"mail_provider":"google","mail_mode":"app","g_sa_json":'{"type":"user"}',"mail_from":"alerts@examplemsp.example"})
ok("service_account" in flash(r.text),"non-service-account key refused")
r=st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"mail_provider":"google","mail_mode":"app","g_sa_json":json.dumps(sa),"mail_from":"alerts@examplemsp.example"})
t=st.get(B+"/integrations/email").text
ok("saved" in flash(r.text).lower() and "align@align-test.iam.gserviceaccount.com" in t and "109876543210987654321" in t and "Ready" in t,"service account saved; client ID shown for delegation")
ok("gmail.send,https://www.googleapis.com/auth/calendar.events" in H.unescape(t),"scopes to authorize shown")
raw=q("select value from settings where name='g_sa_json'")[0]["value"]; ok("PRIVATE KEY" not in raw,"key stored encrypted")
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"})
g=gstate(); m=parse(g["mail"][-1]["raw"]) if g["mail"] else None
ok("Test email sent" in flash(r.text) and m and g["mail"][-1]["user"]=="alerts@examplemsp.example" and m["To"]=="chris@example.com" and "Google Workspace" in m.get_body(("html",)).get_content(),"test sent through Gmail API as the From mailbox")
bad=dict(sa); bad["client_email"]="other@x.iam.gserviceaccount.com"
st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"g_sa_json":json.dumps(bad)})
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"})
ok("Domain-wide delegation is not set up" in flash(r.text) and "109876543210987654321" in flash(r.text),"delegation missing explained: "+flash(r.text)[:100])
st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"g_sa_json":json.dumps(sa),"mail_from":"someone@otherdomain.example"})
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"}); ok("doesn't recognise" in flash(r.text),"unknown mailbox explained")
st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"mail_from":"alerts@examplemsp.example"})

# ---- MIME: inline image + attachment
out=subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; echo Align\\Mail\\Google::mime("a@examplemsp.example","Example MSP — Ünïcode",[["address"=>"b@x.example","name"=>"Bö \\"Q\\""]],[["address"=>"c@x.example","name"=>""]],"r@x.example","Hé\\r\\nBcc: evil@x.example",\'<p>Hi <img src="cid:brandlogo"></p>\',[["name"=>"logo.png","type"=>"image/png","content"=>"PNGDATA","inline_id"=>"brandlogo"],["name"=>"invite.ics","type"=>"text/calendar; charset=utf-8; method=REQUEST","content"=>"BEGIN:VCALENDAR\\r\\nMETHOD:REQUEST\\r\\nEND:VCALENDAR\\r\\n"]]);'],env=ENV,capture_output=True,text=True).stdout
m=parse(out)
ok(m.get_content_type()=="multipart/mixed" and m["Subject"]=="Hé Bcc: evil@x.example" and m["Bcc"] is None,"MIME structure; header injection neutralised")
parts=[p.get_content_type() for p in m.walk()]
ok(parts==["multipart/mixed","multipart/related","text/html","image/png","text/calendar"],"parts: "+str(parts))
ok(m["From"].addresses[0].display_name=="Example MSP — Ünïcode" and m["To"].addresses[0].display_name=='Bö "Q"' and m["Reply-To"]=="r@x.example","encoded names and reply-to")
img=[p for p in m.walk() if p.get_content_type()=="image/png"][0]; ok(img["Content-ID"]=="<brandlogo>" and img.get_content()==b"PNGDATA","inline image with Content-ID")

# ---- Google Calendar invitations (owner's calendar, Meet link, update, cancel)
day=time.strftime("%Y-%m-%d",time.localtime(time.time()+3*86400))
r=st.post(B+"/meetings",data={"_csrf":csrf(st,"/meetings"),"client_id":"1","title":"G-Review","type":"qbr","date":day,"time":"10:00","duration":"60","attendees":"Jordan <jordan@client.example>, sam@client.example","agenda":"Backups","send_invites":"1","owner_id":"1"})
mt=q("select * from meetings where title='G-Review'")[0]; ev=gstate()["events"].get(mt["graph_event_id"] or "",{})
ok("through Google Calendar with a Google Meet link" in flash(r.text) and ev.get("organizer",{}).get("email")=="chris@example.com" and ev.get("sendUpdates")=="all" and len(ev.get("attendees",[]))==2,"event in owner's calendar, guests notified: "+flash(r.text)[:90])
ok(mt["video_url"] and "meet.google.com" in mt["video_url"] and mt["graph_mailbox"]=="google:chris@example.com","Meet link saved on the meeting")
st.post(B+f"/meetings/{mt['id']}",data={"_csrf":csrf(st,f"/meetings/{mt['id']}"),"action":"save","client_id":"1","title":"G-Review","type":"qbr","date":day,"time":"11:00","duration":"30","attendees":"jordan@client.example","send_invites":"1","owner_id":"1","video_url":mt["video_url"]})
ev=gstate()["events"][mt["graph_event_id"]]; ok(ev["status"]=="updated" and ev["sendUpdates"]=="all" and len(ev["attendees"])==1,"update sent")
r=st.post(B+f"/meetings/{mt['id']}",data={"_csrf":csrf(st,f"/meetings/{mt['id']}"),"action":"cancel"})
ev=gstate()["events"][mt["graph_event_id"]]; ok(ev["status"]=="cancelled" and ev["cancelSendUpdates"]=="all","cancellation sent")
# owner without a mailbox in the domain -> falls back to the From mailbox
q("update users set email='tech@elsewhere.example' where email='tech@example.com'")
tid=q("select id from users where email='tech@elsewhere.example'")[0]["id"]
r=st.post(B+"/meetings",data={"_csrf":csrf(st,"/meetings"),"client_id":"1","title":"G-Fallback","type":"qbr","date":day,"time":"13:00","duration":"30","attendees":"jordan@client.example","send_invites":"1","owner_id":str(tid)})
q("update users set email='tech@example.com' where id=%s",tid)
mt2=q("select * from meetings where title='G-Fallback'")[0]; ok(mt2["graph_mailbox"]=="google:alerts@examplemsp.example","falls back to the From mailbox as organizer")
# ICS mode through Gmail
setting("mail_meeting_mode","ics")
st.post(B+"/meetings",data={"_csrf":csrf(st,"/meetings"),"client_id":"1","title":"G-ICS","type":"qbr","date":day,"time":"15:00","duration":"30","attendees":"jordan@client.example","send_invites":"1","owner_id":"1"})
m=[parse(x["raw"]) for x in gstate()["mail"] if "G-ICS" in str(parse(x["raw"])["Subject"])][-1]
cal=[p for p in m.walk() if p.get_content_type()=="text/calendar"]
ok(cal and "METHOD:REQUEST" in cal[0].get_content() and cal[0].get_filename()=="invite.ics","ICS invitation through Gmail")
setting("mail_meeting_mode","calendar")

# ---- portal invite through Gmail
pe=f"gp{int(time.time())}@client.example"
r=st.post(B+"/clients/1/portal",data={"_csrf":csrf(st,"/clients/1/portal"),"name":"G Portal","email":pe,"send_email":"1","can_devices":"1"})
raws=[x["raw"] for x in gstate()["mail"] if pe in x["raw"]]
link=re.search(r'href="([^"]*/portal/invite/[a-f0-9]{64})"', parse(raws[-1]).get_body(("html",)).get_content()) if raws else None
ok(link and "Set" in requests.get(H.unescape(link.group(1))).text,"portal invite emailed through Gmail and link works")

# ---- Connect with Google (delegated)
F=form(st,"/integrations/email")
r=st.post(B+"/integrations/email",data={**F,"mail_mode":"delegated","g_client_id":"nope"}); ok("googleusercontent.com" in flash(r.text),"client ID validated")
st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"mail_mode":"delegated","g_client_id":"123456789012-abcdef.apps.googleusercontent.com","g_client_secret":"g-secret","mail_from":""})
r=st.get(B+"/settings/email/connect",allow_redirects=False); loc=r.headers.get("Location","")
u=requests.utils.unquote(loc)
ok("access_type=offline" in loc and "code_challenge=" in loc and "gmail.send" in u and "calendar.events" in u,"connect redirects to Google with offline access + PKCE")
cb=requests.get(loc,allow_redirects=False).headers["Location"]
r=st.get(cb); ok("Connected to Google Workspace as alerts@examplemsp.example" in flash(r.text),"callback connects: "+flash(r.text)[:80])
q("delete from settings where name='g_token_cache'")
r=st.post(B+"/integrations/email/test",data={"_csrf":csrf(st,"/integrations/email"),"to":"chris@example.com"}); g=gstate()
ok("Test email sent" in flash(r.text) and g["calls"][-1]=="refresh_token" and parse(g["mail"][-1]["raw"])["From"].addresses[0].addr_spec=="alerts@examplemsp.example","delegated send uses the refresh token")
st.post(B+"/meetings",data={"_csrf":csrf(st,"/meetings"),"client_id":"1","title":"G-Delegated","type":"qbr","date":day,"time":"16:00","duration":"30","attendees":"jordan@client.example","send_invites":"1","owner_id":"1"})
md=q("select * from meetings where title='G-Delegated'")[0]; ok(md["graph_mailbox"]=="google:" and gstate()["events"][md["graph_event_id"]]["organizer"]["email"]=="alerts@examplemsp.example","delegated: event in the connected account's calendar")
r=st.post(B+"/integrations/email/disconnect",data={"_csrf":csrf(st,"/integrations/email")})
ok(not q("select * from settings where name='g_refresh_token'") and gstate()["revoked"]==1,"disconnect revokes the Google grant")

# ---- back to Microsoft; a Google event isn't touched by Microsoft
st.post(B+"/integrations/email",data={**form(st,"/integrations/email"),"mail_provider":"microsoft","mail_mode":"app","mail_from":"alerts@examplemsp.example"})
r=st.post(B+f"/meetings/{md['id']}",data={"_csrf":csrf(st,f"/meetings/{md['id']}"),"action":"save","client_id":"1","title":"G-Delegated","type":"qbr","date":day,"time":"16:30","duration":"30","attendees":"jordan@client.example","send_invites":"1","owner_id":"1"})
md2=q("select * from meetings where id=%s",md["id"])[0]; ok(md2["graph_mailbox"].startswith("/users/") and "through Outlook" in flash(r.text),"after switching, a new Outlook event is created")
for pth in ["/integrations/email","/settings/notifications/log","/account"]:
    r=st.get(B+pth); ok(r.status_code==200 and not errs(r.text),pth)
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
