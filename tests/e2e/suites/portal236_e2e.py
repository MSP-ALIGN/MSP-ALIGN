"""2.36.0 Client portal flow and search: every list page has a search box that filters as you type (devices also by
the full user name; heading rows stay while something under them matches; "Nothing matches" shows); the Documents page
is one list with category tabs (client-friendly names, counts) and a search; a compliance control's document is only a
link for people who can open Documents; "Since your last review" has a way back. Staff side: the setup checklist
doesn't link a role to pages it can't open; a one-letter search says to type two; the Staff page is called Staff."""
import atexit
from lib import *
from playwright.sync_api import sync_playwright

PW = "Quartz-Lantern-Field-31"
SEC = "ONSWG4TFORZWK3DPONSWG4TFORZWK3DP"
SEC2 = "OBQXE5DBNRSGK4TPOBQXE5DBNRSGK4TP"
cid = q("select id from clients where psa_id='1'")[0]["id"]


def cleanup():
    q("delete from portal_users where email in ('all236@portalq.example', 'nodocs236@portalq.example')")
    q("delete from documents where title like 'P236 %%'")
    q("delete from login_attempts")


cleanup()
atexit.register(cleanup)


def mkuser(email, secret, **perms):
    p = {"can_roadmap": 1, "can_budget": 1, "can_devices": 1, "can_documents": 1, "can_closet": 1, **perms}
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{cid},"email"=>"{email}","name"=>"P236 Person","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        + ",".join(f'"{k}"=>{v}' for k, v in p.items()) + f',"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{secret}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')


def plogin(email, secret):
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(secret)})
    return s


for title, cat in [("P236 Acceptable use", "policy"), ("P236 Password policy", "policy"), ("P236 Printer how-to", "procedure"), ("P236 Disaster recovery", "plan"), ("P236 Security program", "wisp")]:
    q("insert into documents (client_id, title, category, kind, status, portal_shared, body_html) values (%s, %s, %s, 'page', 'active', 1, '<p>Body</p>')", cid, title, cat)
mkuser("all236@portalq.example", SEC)
mkuser("nodocs236@portalq.example", SEC2, can_documents=0)
s = plogin("all236@portalq.example", SEC)
nd = plogin("nodocs236@portalq.example", SEC2)

# ---- search boxes on the list pages
for path, target in [("/portal/devices", "portal-devices-table"), ("/portal/contacts", "portal-contacts"), ("/portal/licensing", "portal-licenses"),
                     ("/portal/vendors", "portal-vendors"), ("/portal/closet", "portal-closet-all"), ("/portal/documents", "portal-docs")]:
    t = s.get(B + path).text
    ok(f'data-filter-table="{target}"' in t and f'id="{target}"' in t and f'id="{target}-none"' in t and not errs(t), f"{path} has a search box over its list")
t = s.get(B + "/portal/devices").text
user = q("select last_user from devices where client_id=%s and last_user like '%%\\\\\\\\%%' limit 1", cid)
ok(not user or f'data-search="{user[0]["last_user"]}' in t.replace("&#039;", "'").replace("&#92;", "\\"), "devices also match the full user name (data-search)")

# ---- Documents: one list with category tabs and client-friendly names
t = s.get(B + "/portal/documents").text
ok('data-filter-pills="portal-docs"' in t and ">All <" in t.replace("All <span", "All <"), "the Documents page has category tabs")
ok("Policies" in t and "How-to guides" in t and "Emergency &amp; recovery plans" in t and "Security program" in t, "categories have client-friendly names")
ok(">WISP<" not in t and "Procedure / SOP" not in t and "Plan (IR / DR / BCP)" not in t, "...not the staff terms")
ok('data-cat="policy"' in t and t.index("P236 Security program") < t.index("P236 Acceptable use"), "documents carry their category and follow the category order")

# ---- the filter in a browser: typing hides rows and empty headings; tabs narrow the list
jar = [{"name": c.name, "value": c.value, "url": B} for c in s.cookies]
with sync_playwright() as p:
    b = p.chromium.launch(); cx = b.new_context(); cx.add_cookies(jar); pg = cx.new_page()
    pg.goto(B + "/portal/documents")
    vis = lambda: pg.eval_on_selector_all("#portal-docs li", "els => els.filter(e => !e.hidden).map(e => e.textContent.trim().split('\\n')[0].trim())")
    total = len(vis())
    pg.fill("[data-filter-table=portal-docs]", "password")
    ok([x for x in vis() if "P236" in x] == ["P236 Password policy"], "typing narrows the documents: %s" % vis()[:3])
    pg.fill("[data-filter-table=portal-docs]", "zzznothing")
    ok(not vis() and pg.is_visible("#portal-docs-none"), "nothing matches: the list empties and says so")
    pg.fill("[data-filter-table=portal-docs]", "")
    pg.click("[data-filter-pills=portal-docs] [data-value=policy]")
    ok(sorted(x for x in vis() if "P236" in x) == ["P236 Acceptable use", "P236 Password policy"] and len(vis()) < total, "a tab narrows the list to its category")
    pg.click("[data-filter-pills=portal-docs] [data-value='']")
    ok(len(vis()) == total, "All shows everything again")
    pg.goto(B + "/portal/licensing")
    heads = lambda: pg.eval_on_selector_all("#portal-licenses tr.proj-quarter", "els => els.filter(e => !e.hidden).length")
    allh = heads()
    first = pg.eval_on_selector("#portal-licenses tbody tr:not(.proj-quarter) td", "e => e.textContent.trim().split(/\\s+/)[0]") if allh else ""
    if first:
        pg.fill("[data-filter-table=portal-licenses]", first)
        ok(1 <= heads() <= allh, "licensing: a match keeps its category heading (%d of %d headings shown)" % (heads(), allh))
    b.close()

# ---- compliance: a control's document is a link only for people who can open Documents
fw = q("select framework_id as id from client_frameworks where client_id=%s limit 1", cid)
if fw:
    doc = q("select id from documents where title='P236 Acceptable use'")[0]["id"]
    ctl = q("select id from compliance_controls where framework_id=%s order by sort, id limit 1", fw[0]["id"])[0]["id"]
    q("insert into client_control_status (client_id, control_id, status, document_id) values (%s, %s, 'met', %s) on duplicate key update document_id=values(document_id)", cid, ctl, doc)
    t = nd.get(B + f"/portal/compliance/{fw[0]['id']}").text
    ok("P236 Acceptable use" in t and f'/portal/documents/{doc}"' not in t and not errs(t), "without Documents access the control names its document but doesn't link it")
    ok(f'/portal/documents/{doc}"' in s.get(B + f"/portal/compliance/{fw[0]['id']}").text, "with it, the document is a link")
    q("update client_control_status set document_id=NULL where client_id=%s and control_id=%s", cid, ctl)

t = s.get(B + "/portal/changes").text
ok('href="/portal"' in t and "Since your last review" in t, "Since your last review has a way back Home")
t = s.get(B + "/portal/account").text
ok("Documents shared in the PSA" not in t and "their company" not in t, "the account page doesn't show staff wording")

# ---- staff side
viewer = login("viewer@example.com", "ViewerPassword123!")
t = viewer.get(B + "/").text
ok('href="/integrations"' not in t.split("Getting set up")[1][:6000] if "Getting set up" in t else True, "the setup checklist doesn't link a viewer to Integrations")
admin = login("admin@example.com", "LongPassword123!")
t = admin.get(B + "/search?q=a").text
ok("Type at least two letters" in t and "Nothing matches" not in t, "a one-letter search asks for two letters")
ok(">Staff</h1>" in admin.get(B + "/users").text, "the Staff page is called Staff")
t = viewer.get(B + "/help").text
step = t.split('id="tab-workflow"')[1].split('id="tab-new"')[0]
ok('href="/mapping"' not in step and 'href="/portal-users"' not in step, "the workflow help doesn't link a viewer to pages they can't open")
ok('href="/mapping"' in admin.get(B + "/help").text, "...admins still get those links")
done()
