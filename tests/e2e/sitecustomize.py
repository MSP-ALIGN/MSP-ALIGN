"""Loaded automatically by Python when tests/e2e is on PYTHONPATH (run.sh does that): answers the
2FA step for the seeded test accounts, for requests sessions and for Playwright pages (after_login)."""
import time, hmac, hashlib, base64, struct, re
SECRETS={"admin@example.com":"JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXA","tech@example.com":"KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU","viewer@example.com":"MFRGGZDFMZTWQ2LKMFRGGZDFMZTWQ2LK","new@example.com":"GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"}
_last={}
def totp_at(secret, step):
    key=base64.b32decode(secret); h=hmac.new(key,struct.pack(">Q",step),hashlib.sha1).digest(); o=h[-1]&15
    return "%06d"%((struct.unpack(">I",h[o:o+4])[0]&0x7fffffff)%1000000)
def next_code(email):
    import os, json
    f=os.environ.get("ALIGN_TEST_WORK","/tmp/msp-align-tests")+"/totp_last.json"
    try: last=json.load(open(f))
    except Exception: last={}
    while True:
        now=int(time.time())//30
        step=max(now, last.get(email,-1)+1)
        if step<=now+1: break
        time.sleep(2)
    last[email]=step; json.dump(last,open(f,"w"))
    return totp_at(SECRETS[email], step)
try:
    import requests
    _orig=requests.Session.request
    def request(self, method, url, *a, **kw):
        r=_orig(self, method, url, *a, **kw)
        data=kw.get("data") or {}
        if method.upper()=="POST" and isinstance(data,dict) and url.endswith("/login") and data.get("email") in SECRETS and (r.url.endswith("/login/2fa") or r.headers.get("Location","").endswith("/login/2fa")):
            page=r if r.url.endswith("/login/2fa") else _orig(self,"GET",url+"/2fa")
            tok=re.search(r'name="_csrf" value="([^"]+)"', page.text).group(1)
            r=_orig(self,"POST",url+"/2fa",data={"_csrf":tok,"code":next_code(data["email"])},allow_redirects=kw.get("allow_redirects",True))
        return r
    requests.Session.request=request
except ImportError:
    pass

def after_login(pg, email):
    """Playwright: answer the 2FA page for known test accounts."""
    try:
        pg.wait_for_load_state()
    except Exception:
        pass
    if pg.url.endswith("/login/2fa") and email in SECRETS:
        pg.fill("input[name=code]", next_code(email)); pg.click("button")
