import re, requests, subprocess, json, time, pymysql, html as H, hmac, hashlib, base64, struct
from lib import *
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a); return c.fetchall()
fails=[]
def ok(c,m):
    print(("PASS " if c else "FAIL ")+m)
    if not c: fails.append(m)
def errs(t): return re.findall(r'(Warning:|Notice:|Deprecated:|Fatal error|Uncaught|RuntimeException)[^<]{0,200}',t)
def csrf(sess,p): return re.search(r'name="_csrf" value="([^"]+)"', sess.get(B+p).text).group(1)
def flash(t): return [H.unescape(x.strip()) for x in re.findall(r'alert alert-\w+[^>]*>(?:\s*<button[^>]*>[^<]*</button>)?\s*(?:<i[^>]*></i>)?([^<]+)',t)]
_used={}
def totp(secret, fresh=True):
    """Code for the next unused time-step (the server refuses a step twice)."""
    secret=secret.replace(" ","").upper()
    while True:
        now=int(time.time())//30
        step=max(now,_used.get(secret,-1)+1) if fresh else now
        if step<=now+1: break
        time.sleep(2)
    if fresh: _used[secret]=step
    key=base64.b32decode(secret+"="*((8-len(secret)%8)%8))
    h=hmac.new(key,struct.pack(">Q",step),hashlib.sha1).digest(); o=h[-1]&15
    return "%06d"%((struct.unpack(">I",h[o:o+4])[0]&0x7fffffff)%1000000)
def enroll(sess):
    r=sess.post(B+"/portal/account/2fa",data={"_csrf":csrf(sess,"/portal/account"),"action":"begin"})
    sec=re.search(r'<code[^>]*>([A-Z2-7 ]+)</code>',r.text).group(1).replace(" ","")
    sess.post(B+"/portal/account/2fa",data={"_csrf":csrf(sess,"/portal/account"),"action":"confirm","code":totp(sec)})
    return sec
def plogin(sess,email,pw,sec):
    r=sess.post(B+"/portal/login",data={"_csrf":csrf(sess,"/portal/login"),"email":email,"password":pw})
    if r.url.endswith("/portal/login/2fa"):
        r=sess.post(B+"/portal/login/2fa",data={"_csrf":csrf(sess,"/portal/login/2fa"),"code":totp(sec)})
    return r

requests.get(M+"/mock/reset"); q("delete from portal_users"); q("delete from login_attempts where email like 'portal:%%'")
q("delete from roadmap_items where title like 'PT %%'"); q("delete from documents where title like 'PT %%'")
q("update clients set portal_require_2fa=0"); q("delete from contacts where name in ('New Hygienist','X')")
# fixtures: client 1 and 2 each get a proposed project, an active shared doc, an active hidden doc
for cid in (1,2):
    q("insert into roadmap_items (client_id,title,category,description,target_quarter,cost,priority,status) values (%s,%s,'security','Secret plan for client %s',%s,4200,'high','proposed')",cid,f"PT Project C{cid}",cid,time.strftime("%Y-%m-01"))
    q("insert into documents (client_id,title,category,status,body_html,portal_shared) values (%s,%s,'policy','active','<p>Policy body C%s</p>',1)",cid,f"PT Shared C{cid}",cid)
    q("insert into documents (client_id,title,category,status,body_html,portal_shared) values (%s,%s,'network','active','<p>Admin passwords C%s</p>',0)",cid,f"PT Hidden C{cid}",cid)
q("update meetings set notes='INTERNAL MEETING NOTES' where client_id=1")
q("insert into meetings (uid,client_id,title,type,status,starts_at,ends_at,notes) values (uuid(),1,'PT internal prep','internal','scheduled',now()+interval 3 day,now()+interval 3 day+interval 1 hour,'x')")
q("update device_overrides set notes='INTERNAL DEVICE NOTE' where device_id in (select id from devices where rmm_org_id is not null) limit 3")
p1=q("select id from roadmap_items where title='PT Project C1'")[0]["id"]; p2=q("select id from roadmap_items where title='PT Project C2'")[0]["id"]
d1=q("select id from documents where title='PT Shared C1'")[0]["id"]; d2=q("select id from documents where title='PT Shared C2'")[0]["id"]; h1=q("select id from documents where title='PT Hidden C1'")[0]["id"]

# ---- staff invites
staff=requests.Session()
staff.post(B+"/login",data={"_csrf":csrf(staff,"/login"),"email":"admin@example.com","password":"LongPassword123!"})
t=staff.get(B+"/clients/1/portal").text; ok("Portal users" in t and "Client portal" in t and not errs(t),"staff portal page "+str(errs(t)[:1]))
tok=csrf(staff,"/clients/1/portal")
r=staff.post(B+"/clients/1/portal",data={"_csrf":tok,"name":"Jordan Ellis","email":"Jordan@Client.example","can_roadmap":"1","can_budget":"1","can_devices":"1","can_documents":"1","can_approve":"1","can_contacts":"1"})
m=re.search(r'id="portal-link" value="([^"]+)"',r.text); ok(m and "/portal/invite/" in m.group(1),"invite link shown")
link1=H.unescape(m.group(1)); tok1=link1.rsplit("/",1)[1]
ok(q("select invite_token_hash from portal_users where email='jordan@client.example'")[0]["invite_token_hash"]==hashlib.sha256(tok1.encode()).hexdigest(),"only the token hash is stored")
ok("mailto:" in r.text,"mailto button")
r2=staff.get(B+"/clients/1/portal"); ok('id="portal-link"' not in r2.text,"link shown only once")
r=staff.post(B+"/clients/1/portal",data={"_csrf":tok,"name":"Dupe","email":"jordan@client.example"}); ok("already has" in H.unescape(r.text),"duplicate email refused")
r=staff.post(B+"/clients/1/portal",data={"_csrf":tok,"name":"Staff","email":"tech@example.com"}); ok("staff account" in H.unescape(r.text),"staff email refused")
r=staff.post(B+"/clients/1/portal",data={"_csrf":tok,"name":"Vic Viewer","email":"vic@client.example","can_devices":"1","can_approve":"1"})
link2=H.unescape(re.search(r'id="portal-link" value="([^"]+)"',r.text).group(1))
v=q("select * from portal_users where email='vic@client.example'")[0]; ok(v["can_approve"]==0 and v["can_roadmap"]==0 and v["can_budget"]==0,"approve dropped without roadmap section")
r=staff.post(B+"/clients/2/portal",data={"_csrf":csrf(staff,"/clients/2/portal"),"name":"Pat Quinn","email":"pat@northfield.example","can_roadmap":"1","can_documents":"1","can_budget":"1"})
link3=H.unescape(re.search(r'id="portal-link" value="([^"]+)"',r.text).group(1))

# ---- invite / password
a=requests.Session()
t=a.get(link1).text; ok("Set your password" in t or "Set a password" in t,"invite page")
r=a.post(link1,data={"_csrf":csrf(a,link1.replace(B,"")),"password":"short","confirm":"short"}); ok(q("select password_hash from portal_users where email='jordan@client.example'")[0]["password_hash"] is None,"short password refused")
r=a.post(link1,data={"_csrf":csrf(a,link1.replace(B,"")),"password":"ClientPassword123!","confirm":"ClientPassword123!"})
ok("Welcome" in r.text and r.url.endswith("/portal/account") and not errs(r.text),"password set -> straight to 2FA setup: "+r.url)
r=a.get(B+"/portal/roadmap"); ok(r.url.endswith("/portal/account") and "required" in r.text,"no data until 2FA is set up")
sec_a=enroll(a); ok(q("select totp_enabled from portal_users where email='jordan@client.example'")[0]["totp_enabled"]==1,"2FA enrolled")
t=requests.get(link1).text; ok("expired or was already used" in t,"invite link single-use")
# staff session cookie can't be used on portal and vice versa
t=a.get(B+"/clients/1").text; ok("Sign in" in t and "Cedar Ridge" not in t.split("login-box")[0] or "/login" in a.get(B+"/clients/1",allow_redirects=False).headers.get("Location",""),"portal session can't open staff pages")
ok(a.get(B+"/clients/1",allow_redirects=False).status_code in (302,303),"staff page redirects portal user to staff login")
ok(staff.get(B+"/portal",allow_redirects=False).headers.get("Location")=="/portal/login","staff session isn't a portal session")

# ---- pages
for p in ["/portal","/portal/roadmap","/portal/budget","/portal/licensing","/portal/devices","/portal/devices?filter=attention","/portal/compliance","/portal/documents","/portal/contacts","/portal/meetings","/portal/account",f"/portal/documents/{d1}",f"/portal/documents/{d1}?print=1","/portal/report/roadmap","/portal/report/budget","/portal/report/assets","/portal/report/backup","/portal/report/qbr"]:
    r=a.get(B+p); ok(r.status_code==200 and not errs(r.text),f"{p} {r.status_code} {errs(r.text)[:1]}")
    for bad in ["Northfield Hardware","PT Project C2","INTERNAL MEETING NOTES","INTERNAL DEVICE NOTE","Admin passwords","PT Hidden","PT internal prep","/clients/","/devices/"]:
        if bad in r.text: ok(False,f"{p} leaks {bad!r}")
fws=q("select framework_id from client_frameworks where client_id=1")
for fw in fws:
    r=a.get(B+f"/portal/compliance/{fw['framework_id']}"); ok(r.status_code==200 and not errs(r.text),"framework page")
t=a.get(B+"/portal/roadmap").text; ok("PT Project C1" in t and "Secret plan for client 1" in t and "$4,200" in t,"roadmap shows own proposal with cost")
t=a.get(B+"/portal/documents").text; ok("PT Shared C1" in t and "PT Hidden C1" not in t,"only shared docs listed")

# ---- tampering: other client's IDs
r=a.get(B+f"/portal/documents/{d2}"); ok(r.status_code==404 and "Policy body C2" not in r.text,"other client's document 404")
r=a.get(B+f"/portal/documents/{h1}"); ok(r.status_code==404,"own unshared document 404")
r=a.post(B+f"/portal/projects/{p2}/decide",data={"_csrf":csrf(a,"/portal/roadmap"),"decision":"approve"})
ok(q("select status from roadmap_items where id=%s",p2)[0]["status"]=="proposed","can't approve other client's project")
k2=q("select id,name from contacts where client_id=2 and archived_at is null limit 1")[0]
r=a.post(B+f"/portal/contacts/{k2['id']}",data={"_csrf":csrf(a,"/portal/contacts"),"action":"save","name":"HACKED"})
ok(r.status_code in (404,405) and q("select name from contacts where id=%s",k2["id"])[0]["name"]==k2["name"],"contacts can't be edited from the portal (1.39)")
a.post(B+f"/portal/contacts/{k2['id']}",data={"_csrf":csrf(a,"/portal/contacts"),"action":"remove"})
ok(q("select archived_at from contacts where id=%s",k2["id"])[0]["archived_at"] is None,"or removed")
fw_other=q("select id from compliance_frameworks where id not in (select framework_id from client_frameworks where client_id=1) limit 1")
if fw_other: ok(a.get(B+f"/portal/compliance/{fw_other[0]['id']}").status_code==404,"unassigned framework 404")
ok(a.get(B+"/portal/logo").status_code in (200,404),"own logo")
ok(a.get(B+"/clients/2/logo",allow_redirects=False).status_code in (302,303),"other client logo needs staff login")
r=a.post(B+"/portal/suggest/license",data={"name":"no csrf"}); ok(r.status_code==419,"CSRF enforced")

# ---- approve
r=a.post(B+f"/portal/projects/{p1}/decide",data={"_csrf":csrf(a,"/portal/roadmap"),"decision":"approve","comment":"Go ahead in Q1"})
it=q("select * from roadmap_items where id=%s",p1)[0]; ok(it["status"]=="approved" and it["decided_by_name"]=="Jordan Ellis" and it["decision_comment"]=="Go ahead in Q1","project approved with name + comment")
r=a.post(B+f"/portal/projects/{p1}/decide",data={"_csrf":csrf(a,"/portal/roadmap"),"decision":"decline"}); ok(q("select status from roadmap_items where id=%s",p1)[0]["status"]=="approved","can't re-decide")
t=staff.get(B+"/clients/1/roadmap").text; ok("Approved by Jordan Ellis" in t and "client" in t,"staff roadmap shows client decision")
t=staff.get(B+"/").text; ok("Client portal activity" in t and "approved a project" in t and not errs(t),"dashboard activity card")
t=staff.get(B+"/audit").text; ok("Jordan Ellis" in t and "client · Cedar Ridge" in t,"audit shows portal user")

# ---- contacts: view-only (1.39), changes go through requests or the IT team
t=a.get(B+"/portal/contacts").text
ok("Add contact" not in t and 'action="/portal/contacts' not in t and "/portal/requests" in t and not errs(t),"contacts page is view-only and points to requests")
r=a.post(B+"/portal/contacts",data={"_csrf":csrf(a,"/portal/contacts"),"name":"New Hygienist","email":"hyg@client.example"})
ok(r.status_code in (404,405) and not q("select id from contacts where name='New Hygienist'"),"no contact can be added from the portal")
t=staff.get(B+"/clients/1/portal").text; ok("Sends requests" in t or "Send new user and termination requests" in t,"staff see the permission as sending requests")

# ---- viewer-type portal user: permissions
b=requests.Session()
b.post(link2,data={"_csrf":csrf(b,link2.replace(B,"")),"password":"Maple-Harbor-Lamp-77","confirm":"Maple-Harbor-Lamp-77"}); sec_b=enroll(b)
t=b.get(B+"/portal").text; ok("Roadmap" not in re.sub(r'<title>.*?</title>','',t).split('class="portal-sections')[1].split("</ul>")[0] and "Devices" in t,"nav shows only allowed sections")
for p in ["/portal/roadmap","/portal/budget","/portal/licensing","/portal/documents","/portal/contacts","/portal/meetings","/portal/report/budget","/portal/report/roadmap",f"/portal/documents/{d1}"]:
    r=b.get(B+p); ok(r.status_code==403 and "PT Project" not in r.text and "Policy body" not in r.text,f"{p} blocked ({r.status_code})")
def visible_text(page):
    # the page body without inline <script> blocks (JSON data may hold a "$")
    out, i = [], 0
    while (j := page.find("<script", i)) != -1:
        out.append(page[i:j]); k = page.find("</script>", j); i = len(page) if k == -1 else k + len("</script>")
    return "".join(out) + page[i:]
t=b.get(B+"/portal/devices").text; ok("Est. cost" not in t and "$" not in visible_text(t.split('class="portal-main')[1]),"no prices without budget access")
t=b.get(B+"/portal/report/assets").text; ok('id="opt-costs"' not in t and 'id="opt-notes"' not in t,"report cost/notes toggles hidden")
t=b.get(B+"/portal/report/assets?costs=1&notes=1").text; ok(not re.search(r"<th[^>]*>\s*(Est\. cost|Replacement cost|Cost)",t) and not re.search(r"\$\s?[0-9]",t.split('class="content')[-1]) and "INTERNAL DEVICE NOTE" not in t,"costs=1 ignored without budget access")
r=b.get(B+"/portal/report/qbr?costs=1&s_budget=1&s_roadmap=1"); t=r.text
ok(r.status_code==200 and "Fleet at a glance" in t and "technology budget" not in t.lower().replace("technology budget</h2>","") and "Roadmap by quarter" not in t and "Software &amp; licensing" not in t,"portal QBR shows only permitted sections")
ok("$" not in re.sub(r"<(script|style)[^>]*>.*?</\1>","",t,flags=re.S),"portal QBR has no prices without budget access")
ok('id="opt-s_budget"' not in t and 'id="opt-costs"' not in t,"portal QBR toolbar hides sections/costs they can't see")
ok("Backup &amp; recovery" in t and "Servers nightly" in t and "File server" not in t,"portal QBR includes this client's backups only")
r=b.get(B+"/portal/report/backup"); ok(r.status_code==200 and "Servers nightly" in r.text and "Internal systems" not in r.text and not errs(r.text),"portal backup report")
t=b.get(B+"/portal/devices").text; ok("/portal/report/backup" in t,"devices page links backup report")
r=b.post(B+f"/portal/projects/{p1}/decide",data={"_csrf":csrf(b,"/portal"),"decision":"decline"}); ok(r.status_code==403,"approve blocked without permission")
r=b.post(B+"/portal/suggest/license",data={"_csrf":csrf(b,"/portal"),"name":"X"}); ok(r.status_code==403 and not q("select id from portal_submissions where title='X'"),"suggestions blocked without the permission")
# staff edits permission -> takes effect immediately
vid=q("select id from portal_users where email='vic@client.example'")[0]["id"]
staff.post(B+f"/portal-users/{vid}",data={"_csrf":csrf(staff,"/clients/1/portal"),"action":"save","name":"Vic Viewer","can_devices":"1","can_documents":"1"})
ok(b.get(B+"/portal/documents").status_code==200,"permission change applies immediately")
# disable -> signed out
staff.post(B+f"/portal-users/{vid}",data={"_csrf":csrf(staff,"/clients/1/portal"),"action":"disable"})
ok(b.get(B+"/portal",allow_redirects=False).headers.get("Location")=="/portal/login","disabled user signed out")
r=b.post(B+"/portal/login",data={"_csrf":csrf(b,"/portal/login"),"email":"vic@client.example","password":"Maple-Harbor-Lamp-77"}); ok("/portal/login" in r.url and "incorrect" in r.text,"disabled user can't sign in")

# ---- client 2 user sees only client 2
c=requests.Session(); c.post(link3,data={"_csrf":csrf(c,link3.replace(B,"")),"password":"River-Stone-Cedar-42","confirm":"River-Stone-Cedar-42"}); enroll(c)
t=c.get(B+"/portal/roadmap").text; ok("PT Project C2" in t and "PT Project C1" not in t and "Cedar Ridge" not in t,"client 2 user sees only client 2")
ok(c.get(B+f"/portal/documents/{d1}").status_code==404,"client 2 can't read client 1 doc")

# ---- login, lockout
a2=requests.Session()
r=plogin(a2,"jordan@client.example","ClientPassword123!",sec_a); ok(r.url.endswith("/portal"),"sign in works (password + code)")
t=a2.get(B+"/portal/terms").text; ok("Client portal terms of use" in t and "Only you and your IT provider" in t and not errs(t),"portal terms inside the portal")
a2.post(B+"/portal/logout",data={"_csrf":csrf(a2,"/portal")}); ok(a2.get(B+"/portal",allow_redirects=False).status_code==302,"sign out")
for i in range(5): a2.post(B+"/portal/login",data={"_csrf":csrf(a2,"/portal/login"),"email":"jordan@client.example","password":"wrong"})
r=a2.post(B+"/portal/login",data={"_csrf":csrf(a2,"/portal/login"),"email":"jordan@client.example","password":"ClientPassword123!"}); ok("Too many" in r.text,"lockout after 5 failures")
q("delete from login_attempts where email like 'portal:%%'")

# ---- two-factor
r=a.post(B+"/portal/account/2fa",data={"_csrf":csrf(a,"/portal/account"),"action":"disable","password":"ClientPassword123!"}); ok("required" in r.text and q("select totp_enabled from portal_users where email='jordan@client.example'")[0]["totp_enabled"]==1,"2FA can't be turned off")
a3=requests.Session()
r=a3.post(B+"/portal/login",data={"_csrf":csrf(a3,"/portal/login"),"email":"jordan@client.example","password":"ClientPassword123!"}); ok("/portal/login/2fa" in r.url,"login asks for code")
ok(a3.get(B+"/portal",allow_redirects=False).status_code==302,"not signed in before code")
r=a3.post(B+"/portal/login/2fa",data={"_csrf":csrf(a3,"/portal/login/2fa"),"code":"000000"}); ok("not valid" in r.text,"bad code refused")
code=totp(sec_a)
r=a3.post(B+"/portal/login/2fa",data={"_csrf":csrf(a3,"/portal/login/2fa"),"code":code}); ok(r.url.endswith("/portal"),"code accepted")
a4=requests.Session(); a4.post(B+"/portal/login",data={"_csrf":csrf(a4,"/portal/login"),"email":"jordan@client.example","password":"ClientPassword123!"})
r=a4.post(B+"/portal/login/2fa",data={"_csrf":csrf(a4,"/portal/login/2fa"),"code":code}); ok("not valid" in r.text,"same code can't be used twice (replay)")
q("delete from login_attempts where email like 'portal:%%'")
mid=q("select id from portal_users where email='jordan@client.example'")[0]["id"]
# reset link on an account with 2FA must still ask for the code
r=staff.post(B+f"/portal-users/{mid}",data={"_csrf":csrf(staff,"/clients/1/portal"),"action":"link"})
lr=H.unescape(re.search(r'id="portal-link" value="([^"]+)"',r.text).group(1)); a5=requests.Session()
r=a5.post(lr,data={"_csrf":csrf(a5,lr.replace(B,"")),"password":"ClientPassword456!","confirm":"ClientPassword456!"})
ok(r.url.endswith("/portal/login/2fa") and a5.get(B+"/portal",allow_redirects=False).status_code==302,"reset link doesn't bypass 2FA")
ok(a3.get(B+"/portal",allow_redirects=False).status_code==302,"password reset signed out existing sessions")
r=a5.post(B+"/portal/login/2fa",data={"_csrf":csrf(a5,"/portal/login/2fa"),"code":totp(sec_a)}); ok(r.url.endswith("/portal"),"reset completes with code")
a=a5
staff.post(B+f"/portal-users/{mid}",data={"_csrf":csrf(staff,"/clients/1/portal"),"action":"reset2fa"}); ok(q("select totp_enabled from portal_users where id=%s",mid)[0]["totp_enabled"]==0,"staff reset 2FA")
ok(a.get(B+"/portal",allow_redirects=False).status_code==302,"2FA reset signs the user out")

# ---- reset link replaces old; expiry
r=staff.post(B+f"/portal-users/{mid}",data={"_csrf":csrf(staff,"/clients/1/portal"),"action":"link"})
l_a=H.unescape(re.search(r'id="portal-link" value="([^"]+)"',r.text).group(1))
r=staff.post(B+f"/portal-users/{mid}",data={"_csrf":csrf(staff,"/clients/1/portal"),"action":"link"})
l_b=H.unescape(re.search(r'id="portal-link" value="([^"]+)"',r.text).group(1))
ok("expired or was already used" in requests.get(l_a).text and "Set a password" in requests.get(l_b).text,"new link voids previous")
q("update portal_users set invite_expires_at=now()-interval 1 minute where id=%s",mid)
ok("expired or was already used" in requests.get(l_b).text,"expired link refused")

# ---- staff pages + roles
for p in ["/portal-users","/clients/1/portal","/clients/2/portal",f"/documents/{d1}","/audit","/"]:
    t=staff.get(B+p).text; ok(not errs(t),"staff "+p)
v=requests.Session(); v.post(B+"/login",data={"_csrf":csrf(v,"/login"),"email":"viewer@example.com","password":"ViewerPassword123!"})
ok(v.get(B+"/clients/1/portal").status_code==403,"viewer can't manage portal")
r=staff.post(B+f"/documents/{h1}/portal",data={"_csrf":csrf(staff,f"/documents/{h1}"),"shared":"1"}); ok(q("select portal_shared from documents where id=%s",h1)[0]["portal_shared"]==1,"staff share toggle")
t=staff.get(B+"/portal/nonexistent").text; ok("Sign in" in t or "portal" in t,"unknown portal url handled")
ok(requests.get(B+"/portal/nothing-here").status_code in (302,404),"404 in portal")
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
