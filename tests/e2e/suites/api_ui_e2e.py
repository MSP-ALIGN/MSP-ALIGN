from lib import *
import re, json, sitecustomize
from playwright.sync_api import sync_playwright
fails=[]
def ok(c,m): print(("PASS " if c else "FAIL ")+m); c or fails.append(m)
def q(sql,*a):
    with db.cursor() as c: c.execute(sql,a or None); return c.fetchall()
q("delete from api_keys where name like 'UI %'")
q("update settings set value='1' where name='api_enabled'")
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_page(viewport={"width":1400,"height":1000})
    errs=[]; pg.on("pageerror", lambda e: errs.append(str(e)))
    pg.goto(B+"/login"); pg.fill("input[name=email]","chris@example.com"); pg.fill("input[name=password]","LongPassword123!"); pg.click("button"); sitecustomize.after_login(pg,"chris@example.com")
    pg.goto(B+"/settings/api"); 
    ok(pg.is_visible("text=REST API") and pg.is_visible("a.nav-link.active:has-text('API')"),"Settings has an API tab")
    pg.is_visible("#api-name") or pg.click("button:has-text('New API key')"); pg.wait_for_timeout(400)  # the form opens by itself when there are no keys
    pg.fill("#api-name","UI n8n workflow")
    pg.click("[data-api-preset]:has-text('Clear')")
    pg.check("input[value='projects:write']")
    ok(pg.is_checked("input[value='projects:read']"),"ticking write ticks read")
    pg.uncheck("input[value='projects:read']")
    ok(not pg.is_checked("input[value='projects:write']"),"unticking read unticks write")
    pg.click("[data-api-preset]:has-text('Automation')")
    ok(pg.is_checked("input[value='budget:write']") and pg.is_checked("input[value='devices:read']") and not pg.is_checked("input[value='devices:write']"),"Automation preset: read everything, write planning")
    pg.click("label[for=cs-some]"); pg.wait_for_timeout(200)
    ok(pg.is_visible("#api-clients"),"client list appears for Only these clients")
    pg.click("label[for=acl-1]")
    pg.select_option("#api-exp","90")
    pg.fill("#api-rate","60")
    pg.click("button:has-text('Create key')"); pg.wait_for_timeout(500)
    tok=pg.input_value("#api-token")
    ok(re.match(r"^msa_[A-Za-z0-9]{8}_[A-Za-z0-9]{32}$",tok) is not None and pg.is_visible("text=won't be shown again"),"token shown once after creating")
    row=q("select * from api_keys where name='UI n8n workflow'")[0]
    ok(json.loads(row["client_ids"])==[1] and row["rate_limit"]==60 and "budget:write" in row["scopes"] and row["expires_at"] and tok not in json.dumps(row,default=str),"stored with scopes, client limit, expiry, rate; token itself not stored")
    r=requests.get(B+"/api/v1/",headers={"Authorization":"Bearer "+tok}); ok(r.status_code==200 and r.json()["data"]["client_ids"]==[1],"new key works")
    pg.reload(); ok(not pg.is_visible("#api-token"),"token not shown again on reload")
    ok(pg.is_visible("text=UI n8n workflow") and pg.is_visible("text=Read everything · Write: Projects & roadmap, Budget, Licensing, Meetings"),"key listed with a readable permission summary")
    pg.click("a:has-text('UI n8n workflow')"); pg.wait_for_timeout(300)
    pg.check("input[value='devices:write']"); pg.click("label[for=cs-all]"); pg.click("button:has-text('Save')"); pg.wait_for_timeout(400)
    row=q("select * from api_keys where name='UI n8n workflow'")[0]
    ok("devices:write" in row["scopes"] and row["client_ids"] is None and row["expires_at"],"edit: scopes and clients changed, expiry kept")
    r=requests.get(B+"/api/v1/",headers={"Authorization":"Bearer "+tok}); ok(r.json()["data"]["client_ids"] is None and "devices:write" in r.json()["data"]["scopes"],"changes apply to the next request")
    ok(pg.is_visible("text=Requests from this key") and pg.is_visible("text=GET /api/v1"),"per-key request history")
    pg.once("dialog", lambda d: d.accept())
    pg.click("button:has-text('Revoke key')"); pg.wait_for_timeout(500)
    ok(requests.get(B+"/api/v1/",headers={"Authorization":"Bearer "+tok}).json()["error"]["code"]=="key_revoked","revoke from the UI stops the key")
    a=q("select action from audit_log where action like 'api.key%%' order by id desc limit 3")
    ok({x["action"] for x in a}>={"api.key_create","api.key_update","api.key_revoke"},"key changes audited")
    pg.goto(B+"/settings/api/docs"); pg.wait_for_timeout(300)
    ok(pg.is_visible("text=Getting started") and pg.is_visible("code:has-text('/api/v1/projects')") and pg.is_visible("text=Permissions (scopes)"),"API reference page")
    with pg.expect_download() as dl: pg.click("a:has-text('OpenAPI (JSON)') >> nth=0")
    ok(json.load(open(dl.value.path()))["openapi"]=="3.1.0","OpenAPI download")
    # expiring key on dashboard
    q("insert into api_keys (name,prefix,token_hash,scopes,rate_limit,expires_at,created_by) values ('UI expiring','ZZZZZZZZ',repeat('0',64),'[]',60,date_add(now(), interval 2 day),1)")
    pg.goto(B+"/"); ok(pg.is_visible("text=API key \"UI expiring\" expires in 2 days"),"dashboard warns before a key expires")
    # toggle off
    pg.goto(B+"/settings/api"); pg.click("button:has-text('Turn off')"); pg.wait_for_timeout(300)
    ok(requests.get(B+"/api/v1/").json()["error"]["code"]=="api_disabled","turn off from the UI")
    pg.click("button:has-text('Turn on')"); pg.wait_for_timeout(300)
    ok(not errs,"no JS errors "+str(errs[:2]))
    b.close()
# tech can't open it
t=requests.Session(); c=re.search(r'name="_csrf" value="([^"]+)"',t.get(B+"/login").text).group(1)
r=t.post(B+"/login",data={"_csrf":c,"email":"tech@example.com","password":"TechPassword123!"})
if "code" in r.text.lower():
    c=re.search(r'name="_csrf" value="([^"]+)"',r.text).group(1); t.post(r.url,data={"_csrf":c,"code":sitecustomize.next_code("tech@example.com")})
ok(t.get(B+"/settings/api").status_code==403,"tech can't manage API keys")
q("delete from api_keys where name like 'UI %'")
print("FAILURES:",len(fails))
