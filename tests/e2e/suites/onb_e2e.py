from lib import *
from playwright.sync_api import sync_playwright
import base64, hmac, hashlib, struct, time, os
PACK=os.path.join(os.path.dirname(os.path.abspath(__file__)),"..","fixtures","onboarding-templates.json")
def local(u): return re.sub(r"^https?://[^/]+", B, u)
def mock(path, body=None): return requests.post(M+path, json=body or {}).json()
requests.post(M+"/mock/reset"); requests.get(M+"/mock/graph-reset")
q("delete from client_onboardings"); q("delete from service_requests"); q("delete from mail_queue")
q("delete from contacts where client_id=1 and (source='manual' or email in ('jane@cedarridgedental.example','alex@cedarridgedental.example','bo@cedarridgedental.example'))")
q("update contacts set archived_at=NULL, archived_reason=NULL where client_id=1 and archived_reason='align'")
q("update contacts set title='Office manager', decision_maker=0 where client_id=1 and name like 'Sam%%'"); import os; os.path.exists('/tmp/itflow-updates.log') and os.remove('/tmp/itflow-updates.log')
setting("company_email","service@examplemsp.example"); setting("client_requests","1"); setting("onboarding_link_days","30")
st=login("chris@example.com","LongPassword123!")

# ---- settings & templates
t=st.get(B+"/settings/onboarding").text; ok(not errs(t) and "Welcome email" in t and "Onboarding page" in t,"Settings → Onboarding renders")
r=st.post(B+"/settings/onboarding/import",data={"_csrf":csrf(st,"/settings/onboarding")},files={"file":("pack.json",open(PACK,"rb"),"application/json")})
ok("Imported 5 templates" in flash(r.text),"template pack imported: "+flash(r.text)[:80])
pp=q("select * from onboarding_templates where slug='email-security'")[0]; ok(pp["file_stored"] and pp["file_name"]=="Email Security Guide.pdf","guide PDF attached")
r=st.post(B+"/settings/onboarding/import",data={"_csrf":csrf(st,"/settings/onboarding")},files={"file":("x.json",b'{"format":"nope"}',"application/json")}); ok("isn't an Align onboarding export" in flash(r.text),"bad import refused")
ex=st.get(B+"/settings/onboarding/export"); ok(ex.headers.get("Content-Type","").startswith("application/json") and len(ex.json()["templates"])>=5,"export")
tid=q("select id from onboarding_templates where slug='billing'")[0]["id"]
F={"_csrf":csrf(st,f"/settings/onboarding/templates/{tid}"),"title":"How our billing platform works","body":"<p>Pay at billing.example</p><script>alert(1)</script>","is_active":"1","sort":"30","action":"save"}
st.post(B+f"/settings/onboarding/templates/{tid}",data=F); b=q("select body_html from onboarding_templates where id=%s",tid)[0]["body_html"]
ok("billing.example" in b and "<script" not in b,"template saved and sanitized")
r=st.post(B+f"/settings/onboarding/templates/{tid}",data={**F,"_csrf":csrf(st,"/settings/onboarding")},files={"file":("x.pdf",b"not a pdf","application/pdf")}); ok("Only PDF" in flash(r.text),"non-PDF attachment refused")
st.post(B+"/settings/onboarding/import",data={"_csrf":csrf(st,"/settings/onboarding")},files={"file":("pack.json",open(PACK,"rb"),"application/json")})
import glob; ok(len(glob.glob(UPLOADS+"/onboarding/*.pdf"))==1,"re-importing replaces the PDF instead of piling up copies")
r=st.post(B+"/settings/onboarding/templates",data={"_csrf":csrf(st,"/settings/onboarding"),"title":"Our after-hours policy"}); ok("/settings/onboarding/templates/" in r.url,"add a guide page")
nid=int(r.url.rsplit("/",1)[1]); st.post(B+f"/settings/onboarding/templates/{nid}",data={"_csrf":csrf(st,r.url.replace(B,"")),"action":"delete"}); ok(not q("select 1 from onboarding_templates where id=%s",nid),"delete a page")

# ---- staff: send the welcome email
t=st.get(B+"/clients/1/onboarding").text
ok(not errs(t) and "Welcome to Example MSP, Cedar Ridge Family Dental, Inc.!" in H.unescape(t) and "{{onsite_week}}" in t and 'id="doc-editor"' in t,"send form prefilled from the template")
ok("Welcome &amp; onboarding" in st.get(B+"/clients/1").text,"client menu offers onboarding")
body=re.search(r'<template id="doc-initial">(.*?)</template>',t,re.S).group(1)
jordan=q("select id from contacts where client_id=1 and email='jordan@cedarridgedental.example'")[0]["id"]
r=st.post(B+"/clients/1/onboarding/send",data={"_csrf":csrf(st,"/clients/1/onboarding"),"action":"send","to[]":[str(jordan)],"to_other":"office@cedarridgedental.example","subject":"Welcome to Example MSP, Cedar Ridge Family Dental, Inc.!","onsite_week":"October 12","body":body,"cc_me":"1"})
ok("Welcome email sent to jordan@cedarridgedental.example, office@cedarridgedental.example" in flash(r.text),"sent: "+flash(r.text)[:120])
mail=[m for m in requests.get(M+"/mock/graph").json()["mail"] if "Welcome to Example MSP" in m["message"]["subject"]][-1]["message"]
html=mail["body"]["content"]
ok("Hi Dr. Ellis," in html and "October 12" in html and "Start onboarding" in html and "{{" not in html,"email: first name, onsite week, button filled in")
ok(len(mail.get("ccRecipients",[]))==1 and "glad to be looking after" in html,"copy to sender, the team's wording")
link=local(H.unescape(re.search(r'href="([^"]*/portal/welcome/[^"]+)"',html).group(1)))
o=q("select * from client_onboardings where client_id=1")[0]; ok(o["sent_at"] and o["token_hash"] and o["send_count"]==1 and "office@" in o["sent_to"],"onboarding recorded")
ok(re.search(r"/portal/welcome/([A-Za-z0-9_-]+)",link).group(1) not in str(o),"only a hash of the link is stored")
v=login("viewer@example.com","ViewerPassword123!"); ok(v.get(B+"/clients/1/onboarding").status_code==200 and v.post(B+"/clients/1/onboarding/send",data={"_csrf":csrf(v,"/clients/1/onboarding"),"action":"link"}).status_code==403,"viewers see progress but can't send")

# ---- the client's onboarding page
c=requests.Session()
t=c.get(link).text; ok(not errs(t) and "Welcome to Example MSP!" in t and "Your team&#039;s contacts" in t or "Your team's contacts" in H.unescape(t),"onboarding page opens without signing in")
ok("How to reach us" in t and "Email security basics" in t and "Open the full guide (PDF)" in t,"guides shown")
ok(q("select opened_at from client_onboardings where client_id=1")[0]["opened_at"] is not None,"opened recorded")
g=re.search(r'href="(/portal/welcome/[^"]+/guide/\d+)"',t); pdf=c.get(B+g.group(1)); ok(pdf.headers.get("Content-Type")=="application/pdf" and pdf.content[:5]==b"%PDF-","guide PDF downloads")
ok('name="robots" content="noindex' in t,"not indexed by search engines")
cs=lambda: csrf(c, link.replace(B,""))
rows=[]
for k in q("select id,name,title,email,phone,mobile,decision_maker,is_billing,is_technical from contacts where client_id=1 and archived_at is null"):
    parts=k["name"].split(); rows.append({"id":str(k["id"]),"first":" ".join(parts[:-1]) or parts[0],"last":parts[-1] if len(parts)>1 else "","title":k["title"] or "","email":k["email"] or "","phone":k["phone"] or "","mobile":k["mobile"] or "","approver":bool(k["decision_maker"]),"billing":bool(k["is_billing"]),"technical":bool(k["is_technical"])})
sam=[r_ for r_ in rows if "Sam" in r_["first"]][0]; sam["title"]="Practice manager"; sam["approver"]=True
front=[r_ for r_ in rows if r_["first"]=="Front"][0]; front["remove"]=True
rows.append({"id":"","first":"Jane","last":"Smith","title":"Hygienist","email":"jane@cedarridgedental.example","phone":"(555) 555-0101","mobile":"","approver":False,"billing":False,"technical":False})
rows.append({"id":"","first":"Bad","last":"Email","title":"","email":"not-an-email","phone":"","mobile":""})
rows.append({"id":"","first":"","last":"","title":"","email":""})
r=c.post(link+"/contacts",data={"_csrf":cs(),"contacts_json":json.dumps(rows),"your_name":"Jordan Ellis"})
fl=flash(r.text); ok("2 added, 1 updated, 1 removed" in fl and "doesn't look right" in fl,"contacts saved: "+fl[:160])
ok(q("select title from contacts where client_id=1 and name like 'Sam%%'")[0]["title"]=="Practice manager","existing contact updated")
ok(q("select archived_at from contacts where client_id=1 and name='Front desk'")[0]["archived_at"] is not None,"removed contact archived")
j=q("select source,psa_id from contacts where client_id=1 and email='jane@cedarridgedental.example'")
ok(j and j[0]["source"]=="psa" and j[0]["psa_id"],"new contact created in ITFlow too")
ok(q("select email from contacts where client_id=1 and name='Bad Email'")[0]["email"] is None,"invalid email left out")
upd=[json.loads(l) for l in open("/tmp/itflow-updates.log")] if __import__("os").path.exists("/tmp/itflow-updates.log") else []
ok(any("contact_update" in u and u["contact_update"].get("contact_title")=="Practice manager" for u in upd),"ITFlow contact updated")
r=c.post(link+"/contacts",data={"_csrf":cs(),"contacts_json":json.dumps([{"id":"","first":"Jane","last":"Smith","email":"JANE@cedarridgedental.example","title":"Lead hygienist"}]),"your_name":"Jordan"})
ok(q("select count(*) n from contacts where client_id=1 and email like 'jane@%%' and archived_at is null")[0]["n"]==1 and q("select title from contacts where email='jane@cedarridgedental.example'")[0]["title"]=="Lead hygienist","same email updates instead of duplicating")
r=c.post(link+"/contacts",data={"_csrf":cs(),"contacts_json":"[]","your_name":"Jordan"}); ok("at least one person" in flash(r.text),"empty list refused")
ok(c.post(link+"/contacts",data={"_csrf":"bad","contacts_json":"[]"}).status_code==419,"CSRF required")
r=c.post(link+"/review",data={"_csrf":cs(),"ack_name":"Jordan Ellis"}); ok("tick the box" in flash(r.text),"acknowledgement needs the box")
r=c.post(link+"/review",data={"_csrf":cs(),"ack":"1","ack_name":"Jordan Ellis"}); ok(q("select reviewed_by from client_onboardings where client_id=1")[0]["reviewed_by"]=="Jordan Ellis","review acknowledged")
r=c.post(link+"/transition",data={"_csrf":cs(),"provider":"Old IT Co","provider_contact":"Bob","provider_email":"bob@oldit.example","notified":"yes","onsite_week":"Week of Oct 12","pain_points":"Printer jams","your_name":"Jordan"})
tr=json.loads(q("select transition from client_onboardings where client_id=1")[0]["transition"]); ok(tr["provider"]=="Old IT Co" and tr["onsite_week"]=="Week of Oct 12","transition details saved")
# requests -> ITFlow ticket
r=c.post(link+"/request/new_user",data={"_csrf":cs(),"first_name":"Sam","last_name":"Lee","job_title":"Assistant","start_date":"2026-10-20","supervisor":"Sam Rivera","by_name":"Jordan Ellis","by_email":"jordan@cedarridgedental.example"})
ok("Request sent: NEW USER SETUP: Sam Lee" in flash(r.text),"new user request sent")
created=mock("/mock/tickets-created")["created"]; tk=created[-1]
ok(tk["ticket_subject"]=="NEW USER SETUP: Sam Lee" and tk["client_id"]==1 and "Sam Rivera" in tk["ticket_details"] and tk.get("ticket_contact_id")==2,"ITFlow ticket created with the details and the requester as contact")
r=c.post(link+"/request/new_user",data={"_csrf":cs(),"first_name":"","by_name":""}); ok("First name is required" in flash(r.text) and "Please enter your name" in flash(r.text),"required fields checked")
mock("/mock/ticket-create-fail",{"on":True})
r=c.post(link+"/request/termination",data={"_csrf":cs(),"employee":"Old Tech","disable_at":"2026-10-01T17:00","disable_account":"1","mail_access[0][who]":"sam@cedarridgedental.example","mail_access[0][type]":"full","by_name":"Jordan"})
sr=q("select * from service_requests order by id desc limit 1")[0]
ok(sr["delivery"]=="email" and sr["title"]=="USER TERMINATION: Old Tech","ITFlow down: request emailed to the service address instead")
ok(q("select count(*) n from mail_queue where kind='service_request' and recipients like '%%service@examplemsp.example%%'")[0]["n"]==1,"service email queued")
mock("/mock/ticket-create-fail",{"on":False})
ok("Full mailbox access" in json.dumps(json.loads(sr["data"])),"mailbox access captured")
r=c.post(link+"/finish",data={"_csrf":cs(),"your_name":"Jordan Ellis"}); ok("All done" in flash(r.text) and q("select completed_at from client_onboardings where client_id=1")[0]["completed_at"],"finished")
t=c.get(link).text; ok("You're all set" in H.unescape(t),"thank-you shown")
# staff progress
t=st.get(B+"/clients/1/onboarding").text
ok("Completed" in t and "2 added, 1 updated, 1 removed" in t or "added" in t,"staff page shows progress")
ok("Old IT Co" in t and "Week of Oct 12" in t and "NEW USER SETUP: Sam Lee" in t and "ITFlow ticket" in t and "emailed to service" in t,"staff page shows details and requests")
ok(q("select count(*) n from audit_log where action like 'onboarding.%%'")[0]["n"]>=5,"audited")
# resend: old link dies
old=link
r=st.post(B+"/clients/1/onboarding/send",data={"_csrf":csrf(st,"/clients/1/onboarding"),"action":"link","subject":"Welcome","body":"<p>Hi {{contact_first_name}}</p><p>{{onboarding_link}}</p>","onsite_week":""})
new=local(re.search(r'id="onb-link" readonly value="([^"]+)"',r.text).group(1))
e=requests.get(old); ok(e.status_code==404 and "expired or was replaced" in e.text,"old link stops working after resending")
ok(requests.get(new).status_code==200 and q("select completed_at from client_onboardings where client_id=1")[0]["completed_at"] is None,"new link works; onboarding reopened")
ok("shown only once" in r.text and 'id="onb-link"' not in st.get(B+"/clients/1/onboarding").text,"link only shown once")
st.post(B+"/clients/1/onboarding/revoke",data={"_csrf":csrf(st,"/clients/1/onboarding")}); ok(requests.get(new).status_code==404,"turn off link")
ok(requests.get(B+"/portal/welcome/short").status_code==404 and requests.get(B+"/portal/welcome/"+"A"*43).status_code==404,"unknown links refused")
q("update client_onboardings set token_hash=%s, token_expires_at=date_add(now(), interval 5 day), sent_at=date_sub(now(), interval 8 day), completed_at=NULL where client_id=1","0"*64)
t=st.get(B+"/").text; ok("Onboarding" in t and ("steps done" in t or "not opened" in t),"dashboard flags a stalled onboarding")
# portal requests
sec="JBSWY3DPEHPK3PXP"; _used={}
def totp(secret):
    while True:
        now=int(time.time())//30; step=max(now,_used.get(secret,-1)+1)
        if step<=now+1: break
        time.sleep(2)
    _used[secret]=step
    key=base64.b32decode(secret+"="*((8-len(secret)%8)%8)); h=hmac.new(key,struct.pack(">Q",step),hashlib.sha1).digest(); o_=h[-1]&15
    return "%06d"%((struct.unpack(">I",h[o_:o_+4])[0]&0x7fffffff)%1000000)
q("delete from portal_users where email='req@client.example'")
subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; Align\\DB::insert("portal_users",["client_id"=>1,"email"=>"req@client.example","name"=>"Rita Req","password_hash"=>password_hash("Portal-Pass-123!",PASSWORD_DEFAULT),"is_active"=>1,"can_documents"=>1,"can_contacts"=>1,"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("'+sec+'")]);'],env=ENV)
p=requests.Session(); r=p.post(B+"/portal/login",data={"_csrf":csrf(p,"/portal/login"),"email":"req@client.example","password":"Portal-Pass-123!"})
if r.url.endswith("/2fa"): p.post(B+"/portal/login/2fa",data={"_csrf":csrf(p,"/portal/login/2fa"),"code":totp(sec)})
t=p.get(B+"/portal/requests").text; ok("New user setup" in t and 'href="/portal/requests"' in t and not errs(t),"portal Requests page and menu")
r=p.post(B+"/portal/requests/new_user",data={"_csrf":csrf(p,"/portal/requests"),"first_name":"Pat","last_name":"Kim","job_title":"Front desk","start_date":"2026-11-02","supervisor":"Sam"})
ok("Request sent: NEW USER SETUP: Pat Kim" in flash(r.text) and q("select via, submitted_name from service_requests order by id desc limit 1")[0]["via"]=="portal","portal request sent as the portal user")
q("update portal_users set can_contacts=0 where email='req@client.example'")
ok(p.get(B+"/portal/requests").status_code==403 and 'href="/portal/requests"' not in p.get(B+"/portal").text,"no request forms without contact permission")
q("delete from portal_users where email='req@client.example'")
# browser: paste from a spreadsheet, mobile layout
q("delete from client_onboardings where client_id=1")
r=st.post(B+"/clients/1/onboarding/send",data={"_csrf":csrf(st,"/clients/1/onboarding"),"action":"link","subject":"Welcome","body":"<p>{{onboarding_link}}</p>","onsite_week":""})
link=local(re.search(r'id="onb-link" readonly value="([^"]+)"',r.text).group(1))
with sync_playwright() as pw:
    b=pw.chromium.launch(); pg=b.new_page(viewport={"width":1300,"height":1000}); errs_=[]; pg.on("pageerror", lambda e_: errs_.append(str(e_)))
    pg.goto(link); pg.click("text=Paste from a spreadsheet"); pg.wait_for_timeout(300)
    pg.fill("#paste-text","First Name\tLast Name\tTitle\tPhone Number (Cell optional)\tEmail Address\nAlex\tRivera\tAssistant\t(555) 555-0199\talex@cedarridgedental.example\nBo\tChen\tBilling\t\tbo@cedarridgedental.example")
    pg.click("[data-paste-add]"); pg.wait_for_timeout(200)
    ok("2 added below" in pg.inner_text("[data-paste-result]"),"paste adds rows (header skipped)")
    pg.locator("tr.welcome-new").last.locator("[data-f=billing]").check()
    pg.fill("#c-your-name","Jordan"); pg.click("text=Save contacts"); pg.wait_for_load_state(); pg.wait_for_timeout(300)
    ok(q("select is_billing from contacts where client_id=1 and email='bo@cedarridgedental.example'") and q("select is_billing from contacts where email='bo@cedarridgedental.example'")[0]["is_billing"]==1 and q("select title from contacts where email='alex@cedarridgedental.example'")[0]["title"]=="Assistant","pasted people saved with their flags")
    pg.click("text=User suspend / termination"); pg.wait_for_timeout(400); n0=pg.locator("#rq-termination [data-access-row]").count(); pg.click("#rq-termination [data-access-add]"); ok(pg.locator("#rq-termination [data-access-row]").count()==n0+1,"add another mailbox-access row")
    pg.screenshot(path=WORK+"/shots/onb_client_full.png", full_page=True)
    m=b.new_page(viewport={"width":390,"height":844}); m.goto(link); m.wait_for_timeout(300)
    ok(m.evaluate("document.documentElement.scrollWidth<=window.innerWidth+1"),"no horizontal scroll on a phone")
    ok(not errs_,"no script errors: "+str(errs_))
    b.close()
q("delete from contacts where client_id=1 and email in ('alex@cedarridgedental.example','bo@cedarridgedental.example','jane@cedarridgedental.example') or name='Bad Email'")
q("update contacts set archived_at=NULL, archived_reason=NULL where client_id=1 and name='Front desk'")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
