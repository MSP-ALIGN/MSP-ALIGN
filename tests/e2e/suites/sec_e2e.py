import re, requests, subprocess, time, pymysql, html as H, hmac, hashlib, base64, struct, os, glob
from lib import *
SESS=WORK+"/sessions"
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a); return c.fetchall()
fails=[]
def ok(c,m):
    print(("PASS " if c else "FAIL ")+m)
    if not c: fails.append(m)
def errs(t): return re.findall(r'(Warning:|Notice:|Deprecated:|Fatal error|Uncaught)[^<]{0,200}',t)
def csrf(sess,p): return re.search(r'name="_csrf" value="([^"]+)"', sess.get(B+p).text).group(1)
_used={}
def totp(secret, reuse=None):
    secret=secret.replace(" ","").upper()
    while True:
        now=int(time.time())//30; step=max(now,_used.get(secret,-1)+1)
        if step<=now+1: break
        time.sleep(2)
    _used[secret]=step
    key=base64.b32decode(secret+"="*((8-len(secret)%8)%8)); h=hmac.new(key,struct.pack(">Q",step),hashlib.sha1).digest(); o=h[-1]&15
    return "%06d"%((struct.unpack(">I",h[o:o+4])[0]&0x7fffffff)%1000000)
def cli(*a): return subprocess.run(["php",ALIGN,*a],env=ENV,capture_output=True,text=True)
def sessfile(sess):
    sid=[c.value for c in sess.cookies if "ALIGN" in c.name][0]; return f"{SESS}/sess_{sid}"
def age_session(sess,key,secs):
    f=sessfile(sess); d=open(f).read()
    d=re.sub(key+r'\|i:(\d+);', lambda m: f"{key}|i:{int(m.group(1))-secs};", d); open(f,"w").write(d)

q("delete from users where email like 'sec-%%'"); q("delete from login_attempts")
q("update settings set value='15' where name='session_idle_minutes'")

admin=requests.Session(); admin.post(B+"/login",data={"_csrf":csrf(admin,"/login"),"email":"chris@example.com","password":"LongPassword123!"})
ok(admin.get(B+"/").url.endswith("/"),"admin signed in with 2FA")

# ---- headers
r=admin.get(B+"/clients/1")
for h in ["Content-Security-Policy","X-Frame-Options","X-Content-Type-Options","Referrer-Policy","Permissions-Policy","Cross-Origin-Opener-Policy"]:
    ok(h in r.headers, "header "+h)
ok("no-store" in r.headers.get("Cache-Control",""),"pages not cached: "+r.headers.get("Cache-Control",""))
ok("object-src 'none'" in r.headers["Content-Security-Policy"] and "base-uri 'self'" in r.headers["Content-Security-Policy"],"CSP hardened")
ok('name="align-idle"' in r.text and 'content="900"' in r.text,"idle timer meta (15 min)")

# ---- new staff user: forced password change, then forced 2FA
r=admin.post(B+"/users",data={"_csrf":csrf(admin,"/users"),"email":"sec-new@example.com","name":"Sec Newbie","role":"tech"})
pw=re.search(r'<code class="h5 select-all">([^<]+)</code>',r.text)
temp=H.unescape(pw.group(1)) if pw else None
ok(temp is not None,"temp password shown")
n=requests.Session(); n.post(B+"/login",data={"_csrf":csrf(n,"/login"),"email":"sec-new@example.com","password":temp})
r=n.get(B+"/clients"); ok(r.url.endswith("/account") and "Set a new password" in H.unescape(r.text),"temp password -> must change first")
for bad,why in [("Password123456","common"),("secnewbie12345!","name"),("aaaaaaaaaaaaaaa","repetitive"),("short","length")]:
    r=n.post(B+"/account/password",data={"_csrf":csrf(n,"/account"),"current":temp,"new":bad,"confirm":bad})
    ok(q("select must_change_password from users where email='sec-new@example.com'")[0]["must_change_password"]==1,f"rejected {why} password")
n2=requests.Session(); n2.post(B+"/login",data={"_csrf":csrf(n2,"/login"),"email":"sec-new@example.com","password":temp})
ok(n2.get(B+"/account").status_code==200,"second session open")
r=n.post(B+"/account/password",data={"_csrf":csrf(n,"/account"),"current":temp,"new":"Correct-Horse-Battery-9","confirm":"Correct-Horse-Battery-9"})
u=q("select * from users where email='sec-new@example.com'")[0]
ok(u["must_change_password"]==0 and u["password_hash"].startswith("$argon2id$"),"password changed, stored as Argon2id")
ok("/login" in n2.get(B+"/account").url,"password change ended the other session")
r=n.get(B+"/clients"); ok(r.url.endswith("/account") and "Two-factor sign-in is required" in H.unescape(r.text),"then 2FA required before any data")
ok(n.get(B+"/session/ping").status_code==200,"ping allowed during setup")
r=n.post(B+"/account/2fa",data={"_csrf":csrf(n,"/account"),"action":"begin"})
sec=re.search(r'<code class="select-all">([A-Z2-7 ]+)</code>',r.text).group(1).replace(" ","")
c1=totp(sec)
r=n.post(B+"/account/2fa",data={"_csrf":csrf(n,"/account"),"action":"confirm","code":c1})
ok(n.get(B+"/clients").url.endswith("/clients"),"after 2FA setup, pages open")
r=n.post(B+"/account/2fa",data={"_csrf":csrf(n,"/account"),"action":"disable","password":"Correct-Horse-Battery-9"})
ok(q("select totp_enabled from users where email='sec-new@example.com'")[0]["totp_enabled"]==1,"staff can't turn 2FA off")
# replay: the code used at enrollment can't sign in
n3=requests.Session(); n3.post(B+"/login",data={"_csrf":csrf(n3,"/login"),"email":"sec-new@example.com","password":"Correct-Horse-Battery-9"})
r=n3.post(B+"/login/2fa",data={"_csrf":csrf(n3,"/login/2fa"),"code":c1}); ok("not valid" in r.text,"TOTP replay refused")
c2=totp(sec); r=n3.post(B+"/login/2fa",data={"_csrf":csrf(n3,"/login/2fa"),"code":c2}); ok(r.url.endswith("/"),"fresh code works")
n4=requests.Session(); n4.post(B+"/login",data={"_csrf":csrf(n4,"/login"),"email":"sec-new@example.com","password":"Correct-Horse-Battery-9"})
r=n4.post(B+"/login/2fa",data={"_csrf":csrf(n4,"/login/2fa"),"code":c2}); ok("not valid" in r.text,"a code can't sign in twice")
q("delete from login_attempts")

# ---- timeouts
age_session(n3,"last_seen",16*60); r=n3.get(B+"/clients"); ok("/login" in r.url and "inactivity" in r.text,"idle 16 min -> signed out")
nid=q("select id from users where email='sec-new@example.com'")[0]["id"]
ok(q("select id from audit_log where action='logout.timeout' and user_id=%s",nid) != (),"timeout logged")
n5=requests.Session(); n5.post(B+"/login",data={"_csrf":csrf(n5,"/login"),"email":"sec-new@example.com","password":"Correct-Horse-Battery-9"})
n5.post(B+"/login/2fa",data={"_csrf":csrf(n5,"/login/2fa"),"code":totp(sec)})
ok(n5.get(B+"/clients").url.endswith("/clients"),"signed in again")
age_session(n5,"login_at",13*3600); ok("/login" in n5.get(B+"/clients").url,"12h absolute limit enforced")
# passive ping doesn't extend the session
n6=requests.Session(); n6.post(B+"/login",data={"_csrf":csrf(n6,"/login"),"email":"sec-new@example.com","password":"Correct-Horse-Battery-9"})
n6.post(B+"/login/2fa",data={"_csrf":csrf(n6,"/login/2fa"),"code":totp(sec)})
age_session(n6,"last_seen",10*60); n6.get(B+"/session/ping"); age_session(n6,"last_seen",6*60)
ok("/login" in n6.get(B+"/clients").url,"background ping without activity doesn't keep session alive")
# admin disable -> sessions end
n7=requests.Session(); n7.post(B+"/login",data={"_csrf":csrf(n7,"/login"),"email":"sec-new@example.com","password":"Correct-Horse-Battery-9"})
n7.post(B+"/login/2fa",data={"_csrf":csrf(n7,"/login/2fa"),"code":totp(sec)})
admin.post(B+f"/users/{nid}",data={"_csrf":csrf(admin,"/users"),"action":"reset_2fa"})
ok("/login" in n7.get(B+"/clients").url,"admin 2FA reset ends user's sessions")

# ---- open redirects
s2=requests.Session()
for nxt in ["/%5Cevil.example","//evil.example","/\tevil.example","https://evil.example"]:
    r=s2.get(B+"/login?next="+nxt); m=re.search(r'name="next" value="([^"]*)"',r.text); ok(m and m.group(1)=="/","next param sanitized: "+repr(nxt))
r=admin.post(B+"/clients/1/licenses",data={"_csrf":csrf(admin,"/clients/1/licenses"),"name":"Redirect test","back":"/\\evil.example"},allow_redirects=False)
ok(r.headers.get("Location","").startswith("/clients/1/licenses"),"back param sanitized: "+r.headers.get("Location",""))
q("delete from licenses where name='Redirect test'")

# ---- failed login audit never stores typed secrets
s3=requests.Session(); s3.post(B+"/login",data={"_csrf":csrf(s3,"/login"),"email":"MySecretP@ss word","password":"x"})
last=q("select detail from audit_log where action='login.failed' order by id desc limit 1")[0]["detail"]
ok("MySecret" not in last,"non-email login input not logged: "+last)
q("delete from login_attempts")

# ---- CSV formula injection
did=q("select id from devices where client_id=1 or id in (select id from devices) limit 1")[0]["id"]
q("insert into device_overrides (device_id,notes) values (%s,'=HYPERLINK(\"http://x\")') on duplicate key update notes=values(notes)",did)
cid=q("select client_id from devices d left join device_overrides o on o.device_id=d.id where d.id=%s",did)[0]["client_id"] or 1
csv=admin.get(B+f"/clients/{cid}/export").text
ok("'=HYPERLINK" in csv and ',=HYPERLINK' not in csv,"CSV formulas neutralized")
q("update device_overrides set notes=NULL where device_id=%s",did)

# ---- calendar feed
v=requests.Session(); v.post(B+"/login",data={"_csrf":csrf(v,"/login"),"email":"viewer@example.com","password":"ViewerPassword123!"})
r=v.post(B+"/calendar/feed",data={"_csrf":csrf(v,"/account")}); ok(r.status_code==403,"viewer can't create a feed")
r=admin.post(B+"/calendar/feed",data={"_csrf":csrf(admin,"/account")})
m=re.search(r'value="(http[^"]+/ics/([a-f0-9]+)\.ics)"',r.text); ok(m is not None,"feed link shown once")
tok=m.group(2); ok(q("select ics_token from users where email='chris@example.com'")[0]["ics_token"]==hashlib.sha256(tok.encode()).hexdigest(),"only token hash stored")
ok("/ics/" not in admin.get(B+"/account").text,"link not shown again")
q("update meetings set agenda='SECRET AGENDA', attendees='bob@secret.example' where client_id=1")
feed=requests.get(B+f"/ics/{tok}.ics").text; ok("BEGIN:VCALENDAR" in feed and "SECRET AGENDA" not in feed and "bob@secret" not in feed,"feed has no agenda/attendees")
ok(requests.get(B+"/ics/"+"0"*64+".ics").status_code==404,"wrong token 404")
ics=admin.get(B+"/meetings").text

# ---- sanitizer: backslash links dropped
from urllib.parse import quote
r=subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; echo Align\\Docs\\Html::clean("<p><a href=\\"/\\\\\\\\evil.example\\">x</a><a href=\\"/ok\\">y</a></p>");'],env=ENV,capture_output=True,text=True).stdout
ok('evil' not in r and 'href="/ok"' in r,"sanitizer drops /\\ links: "+r)

# ---- malformed compliance input doesn't crash
fw=q("select framework_id from client_frameworks where client_id=1 limit 1")
if fw:
    ctl=q("select id from compliance_controls where framework_id=%s limit 1",fw[0]["framework_id"])[0]["id"]
    r=admin.post(B+f"/clients/1/compliance/{fw[0]['framework_id']}",data={"_csrf":csrf(admin,f"/clients/1/compliance/{fw[0]['framework_id']}"),f"c[{ctl}][status][]":"x"})
    ok(r.status_code<500,"array input handled (%s)"%r.status_code)

# ---- audit log chain
ok("Tamper check passed" in admin.get(B+"/audit").text,"audit page: chain intact")
out=cli("audit:verify"); ok(out.returncode==0 and "intact" in out.stdout,"CLI verify: "+out.stdout.strip()[:80])
row=q("select id, detail from audit_log order by id desc limit 1 offset 5")[0]
q("update audit_log set detail='edited' where id=%s",row["id"])
out=cli("audit:verify"); ok(out.returncode==2 and f"#{row['id']}" in out.stdout,"edit detected: "+out.stdout.strip()[:90])
ok("has been altered" in admin.get(B+"/audit").text,"audit page shows alteration")
q("update audit_log set detail=%s where id=%s",row["detail"],row["id"])
mid=q("select id from audit_log order by id desc limit 1 offset 10")[0]["id"]
saved=q("select * from audit_log where id=%s",mid)[0]; q("delete from audit_log where id=%s",mid)
out=cli("audit:verify"); ok(out.returncode==2,"deletion detected: "+out.stdout.strip()[:90])
cols=list(saved.keys()); q("insert into audit_log ("+",".join(cols)+") values ("+",".join(["%s"]*len(cols))+")",*[saved[c] for c in cols])
ok(cli("audit:verify").returncode==0,"restored -> intact again")
# retention prune keeps the chain valid
q("update audit_log set created_at=created_at - interval 7 year where id <= (select * from (select min(id)+2 from audit_log) t)")
# the change above edits hashed fields, so rebuild those three entries' hashes via PHP backfill helper
subprocess.run(["php","-r",'require "'+BOOTSTRAP+'"; $prev=(string)Align\\DB::value("SELECT anchor_hash FROM audit_chain"); foreach (Align\\DB::all("SELECT * FROM audit_log ORDER BY id") as $r) { $h=Align\\AuditChain::hash($r,$prev); Align\\DB::run("UPDATE audit_log SET prev_hash=?, row_hash=? WHERE id=?",[$prev,$h,$r["id"]]); $prev=$h; $last=$r["id"]; } Align\\DB::run("UPDATE audit_chain SET last_id=?, last_hash=?",[$last,$prev]);'],env=ENV)
before=q("select count(*) n from audit_log")[0]["n"]
out=cli("audit:prune"); after=q("select count(*) n from audit_log")[0]["n"]
ok("Pruned 3" in out.stdout and after==before-3+1,"prune removed 3 old entries: "+out.stdout.strip())
ok(cli("audit:verify").returncode==0,"chain still verifies after prune")

# ---- staff pages still fine
for p in ["/","/account","/settings","/audit","/users","/calendar","/portal-users"]:
    r=admin.get(B+p); ok(r.status_code==200 and not errs(r.text),"page "+p)
print("FAILURES:",len(fails)); [print(" -",f) for f in fails]
