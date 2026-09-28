"""Follows every link a role can reach and flags server errors, PHP warnings and login bounces."""
import re, requests, time, sys, collections
import lib
from urllib.parse import urljoin, urlparse
B=lib.B_FRESH
USERS={"admin":("new@example.com","FreshAdminPass123!")}
SKIP=re.compile(r'^/(logout|portal$|portal/|ics/|vendor/|assets/|branding/logo|settings/email/connect|integrations/email/connect|clients/\d+/logo|users/\d+/avatar)|\.(csv|png|jpg|svg|js|css)$|/export$')
def pattern(p): return re.sub(r'\d+','N',p.split('?')[0])
for role,(email,pw) in USERS.items():
    s=requests.Session()
    tok=re.search(r'name="_csrf" value="([^"]+)"',s.get(B+"/login").text).group(1)
    r=s.post(B+"/login",data={"_csrf":tok,"email":email,"password":pw})
    if "/login" in r.url: lib.ok(False,role+" signs in"); continue
    seen=set(); perpat=collections.Counter(); queue=["/","/clients","/budget","/licenses","/contacts","/renewals","/projects","/devices/unassigned","/calendar","/meetings","/compliance","/documents","/reports","/mapping","/sync","/account","/settings","/users","/audit","/frameworks","/settings/branding","/settings/os"]
    bad=[]; slow=[]; n=0; forbidden=0
    while queue and n<900:
        p=queue.pop(0)
        if p in seen: continue
        seen.add(p)
        pat=pattern(p)
        if perpat[pat]>=3 and re.search(r'\d',p): continue
        perpat[pat]+=1
        t=time.time(); r=s.get(B+p,allow_redirects=False); dt=time.time()-t; n+=1
        if dt>1.5: slow.append((round(dt,2),p))
        if r.status_code in (301,302):
            loc=r.headers.get("location","")
            if "/login" in loc: bad.append((p,"redirect to login"))
            continue
        if r.status_code==403: forbidden+=1; continue
        errs=re.findall(r'(Warning:|Notice:|Deprecated:|Fatal error|Uncaught|Something went wrong)[^<]{0,160}',r.text)
        if r.status_code>=400 or errs: bad.append((p,r.status_code,errs[:1])); continue
        if "text/html" not in r.headers.get("content-type",""): continue
        for href in re.findall(r'href="([^"#]+)"',r.text):
            u=urlparse(urljoin(B+p,href))
            if u.netloc!=urlparse(B).netloc: continue
            path=u.path+("?"+u.query if u.query else "")
            if SKIP.search(u.path) or path in seen: continue
            queue.append(path)
    print(f"{role}: {n} pages, {len(perpat)} page types, {forbidden} forbidden (expected for role), bad={len(bad)}, slow={sorted(slow,reverse=True)[:5]}")
    for b in bad[:15]: print("   BAD",b)
    lib.ok(not bad and n>50,f"{role}: {n} pages crawled, none broken")
lib.done()
