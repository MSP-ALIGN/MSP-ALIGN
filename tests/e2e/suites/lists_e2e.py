"""1.42: long lists load their edit forms when opened (/licenses, /contacts, /projects), client mapping pickers
carry only their choice until used, the indexed counts match the queries they replace; and the new layout:
menu, To do, all-clients devices, search, client reports, tabbed admin pages, device tabs, portal tabs."""
from lib import *
import html as H
from playwright.sync_api import sync_playwright

admin = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
anon = requests.Session()
lic = q("select id, client_id, name from licenses where retired_at is null order by id limit 1")[0]
con = q("select k.id, k.client_id, k.name from contacts k join clients c on c.id = k.client_id where k.archived_at is null and c.is_archived = 0 and c.planning_excluded = 0 order by k.id limit 1")[0]
prj = q("select r.id, r.title from roadmap_items r join clients c on c.id = r.client_id where r.status in ('proposed','approved','scheduled') and c.is_archived = 0 and c.planning_excluded = 0 order by r.id limit 1")[0]

# ---- the lists carry links, not a form per row
for path, kind, row in [("/licenses", "license", lic), ("/contacts", "contact", con), ("/projects", "roadmap", prj)]:
    t = tech.get(B + path).text
    ok(not errs(t) and f'data-bs-target="#modal-{kind}-{row["id"]}"' in t and f'id="modal-{kind}-{row["id"]}"' not in t and "data-lazy-modal=" in t,
       f"{path}: rows link to a form loaded on open, none in the page")
t = viewer.get(B + "/licenses").text
ok("data-lazy-modal=" not in t and not errs(t), "viewers get no edit links")

# ---- the form routes: role, missing rows, the return path
for path, mid, needle in [(f"/licenses/{lic['id']}/form", f"modal-license-{lic['id']}", f'action="/licenses/{lic["id"]}"'),
                          (f"/contacts/{con['id']}/form", f"modal-contact-{con['id']}", f'action="/contacts/{con["id"]}"'),
                          (f"/projects/{prj['id']}/form", f"modal-roadmap-{prj['id']}", f"/roadmap/{prj['id']}\"")]:
    r = tech.get(B + path + "?back=%2Flicenses%3Fx%3D1")
    ok(r.status_code == 200 and f'id="{mid}"' in r.text and needle in r.text and 'name="_csrf"' in r.text and "<html" not in r.text
       and "no-store" in r.headers.get("cache-control", ""), f"{path}: the form alone, for techs")
    ok('name="back" value="/licenses?x=1"' in r.text, f"{path}: return path kept")
    r = tech.get(B + path + "?back=//evil.example/x")
    ok("evil.example" not in r.text, f"{path}: an outside return path is dropped")
    ok(viewer.get(B + path, allow_redirects=False).status_code == 403, f"{path}: viewers refused")
    r = anon.get(B + path, allow_redirects=False)
    ok(r.status_code in (302, 303) and "/login" in r.headers.get("location", ""), f"{path}: signed out goes to sign in")
ok(tech.get(B + "/licenses/99999999/form").status_code == 404 and tech.get(B + "/contacts/99999999/form").status_code == 404, "unknown rows: 404")
n0 = q("select count(*) n from audit_log where action = 'view.contacts'")[0]["n"]
fresh = login("admin@example.com", "LongPassword123!")  # a new session: nothing seen yet in the last 15 minutes
fresh.get(B + f"/contacts/{con['id']}/form")
ok(q("select count(*) n from audit_log where action = 'view.contacts'")[0]["n"] == n0 + 1, "opening a contact form is access-logged like the list")

# ---- in the browser: open, preview, confirm, save
price0 = q("select unit_price from licenses where id=%s", lic["id"])[0]["unit_price"]
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1440, "height": 900})
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
    pg.goto(B + "/licenses")
    sel = f"#modal-license-{lic['id']}"
    pg.locator(f'[data-bs-target="{sel}"]').first.click(); pg.wait_for_selector(sel + ".show", timeout=5000)
    pg.fill(sel + ' input[name="unit_price"]', "7.25"); pg.select_option(sel + ' select[name="pricing"]', "flat")
    ok("7.25" in pg.locator(sel + ' [data-lic="out"]').inner_text(), "loaded form: the cost preview works")
    ok(pg.locator(sel + " [data-contract]").count() == 1 and pg.locator(sel + ' [data-c="hint"]').count() == 1, "loaded form: contract fields set up")
    pg.locator(sel + " .modal-footer button.btn-primary").click(); pg.wait_for_load_state()
    ok(float(q("select unit_price from licenses where id=%s", lic["id"])[0]["unit_price"]) == 7.25 and pg.url.endswith("/licenses"), "saving a loaded form saves and returns to the list")
    q("update licenses set unit_price=%s, pricing='per_seat' where id=%s", price0, lic["id"])
    # a second open reuses the form already on the page
    pg.goto(B + "/contacts"); csel = f"#modal-contact-{con['id']}"
    pg.locator(f'[data-bs-target="{csel}"]').first.click(); pg.wait_for_selector(csel + ".show")
    btn = pg.locator(csel + " [data-confirm]").first
    if btn.count():
        btn.click(); pg.wait_for_selector("#align-confirm.show")
        asked = pg.locator("#align-confirm-title").inner_text()
        pg.click("#align-confirm button:has-text('Cancel')"); pg.wait_for_selector("#align-confirm", state="hidden"); pg.wait_for_timeout(300)
        ok(asked and q("select archived_at from contacts where id=%s", con["id"])[0]["archived_at"] is None, "confirm asked on a loaded form; cancel keeps the contact")
    pg.keyboard.press("Escape"); pg.wait_for_timeout(400)
    pg.locator(f'[data-bs-target="{csel}"]').first.click(); pg.wait_for_selector(csel + ".show")
    ok(pg.locator(csel).count() == 1, "opening it again reuses the form")
    # client mapping: the picker fills on use and keeps its value
    pg.goto(B + "/mapping")
    s0 = pg.locator("select[data-options]").first
    v0 = s0.input_value(); n_before = s0.locator("option").count()
    s0.focus()
    ok(n_before <= 2 and s0.locator("option").count() > n_before and s0.input_value() == v0, "mapping picker: filled when used, same choice")
    ok(s0.locator("option", has_text="(linked elsewhere)").count() >= 1, "mapping picker: marks records linked to other clients")
    b.close()

# ---- the indexed counts and mail status equal the queries they replaced
old = lambda ex: q("select count(*) n from devices d left join device_overrides o on o.device_id = d.id where d.removed_at is null"
                   " and coalesce(nullif(o.device_type, ''), d.device_type) = 'Unassigned'" + ("" if ex else " and coalesce(o.excluded, 0) = 0"))[0]["n"]
did = q("select id from devices where removed_at is null order by id limit 3")
q("insert into device_overrides (device_id, device_type, excluded) values (%s,'Unassigned',0),(%s,'',0),(%s,'Unassigned',1)"
  " on duplicate key update device_type=values(device_type), excluded=values(excluded)", did[0]["id"], did[1]["id"], did[2]["id"])
got = php('echo Align\\Lifecycle\\Lifecycle::unassignedCount(), ",", Align\\Lifecycle\\Lifecycle::unassignedCount(true);').stdout.strip()
ok(got == f"{old(False)},{old(True)}", f"unassigned counts match the full query ({got})")
t = admin.get(B + "/devices/unassigned").text
ok(not errs(t), "unassigned list still loads")
q("delete from device_overrides where device_id in (%s,%s,%s)", did[0]["id"], did[1]["id"], did[2]["id"])
st = json.loads(php('echo json_encode(Align\\Mail\\Mailer::stats());').stdout)
ref = q("select sum(status='queued') qd, sum(status='sent' and sent_at >= now() - interval 1 day) s24, max(case when status='sent' then sent_at end) ls from mail_queue")[0]
ok(st["queued"] == int(ref["qd"] or 0) and st["sent24"] == int(ref["s24"] or 0), "mail status counts match")
# ---- 1.42 layout: the menu for each role
side = lambda t: t.split('app-sidebar')[1].split('</aside>')[0]
a = side(admin.get(B + "/").text); tt = side(tech.get(B + "/").text); v = side(viewer.get(B + "/").text)
for label, href in [("To do", "/todo"), ("Devices &amp; assets", "/devices"), ("Integrations", "/integrations"), ("People", "/users"), ("Settings", "/settings"), ("Audit log", "/audit")]:
    ok(f'href="{href}"' in a and label in a, f"admin menu: {H.unescape(label)}")
ok("Unassigned hardware" not in a and 'href="/help"' not in a and 'href="/mapping"' not in a and 'href="/sync"' not in a, "admin menu: queues and tools moved into To do and tabs")
ok('href="/mapping"' in tt and "Integrations" in tt and 'href="/portal-users"' in tt and "People" in tt and 'href="/settings"' not in tt, "tech menu: Integrations opens Client mapping, People opens portal users")
ok('href="/sync"' in v and "People" not in v and 'href="/todo"' not in v, "viewer menu: Integrations opens Sync history, no People or To do")
ok(viewer.get(B + "/todo", allow_redirects=False).status_code == 403, "viewers can't open To do")
top = admin.get(B + "/").text
ok('action="/search"' in top and 'href="/help"' in top.split('app-header')[1].split('</nav>')[0], "top bar: search everything, help button")
t = admin.get(B + "/mapping").text
ok('group-tabs' in t and all(h in t for h in ['href="/integrations"', 'href="/mapping/backups"', 'href="/sync"']), "Integrations pages share one tab bar")
t = admin.get(B + "/portal-users").text
ok('group-tabs' in t and 'href="/users"' in t and not errs(t), "People: Staff and Client portal users tabs")
cm = side(admin.get(B + "/clients/1").text)
ok([x for x in re.findall(r'<li class="nav-header">([^<]+)</li>', cm)] == ["THEIR IT", "THE PLAN", "MEETINGS"] and 'href="/clients/1/reports"' in cm, "client menu grouped, with Reports")

# ---- To do
t = H.unescape(admin.get(B + "/todo").text)
n_un = int(php('echo Align\\Lifecycle\\Lifecycle::unassignedCount();').stdout.strip() or 0)
n_lic = q("select count(*) n from licenses l join clients c on c.id=l.client_id where l.retired_at is null and l.unit_price is null and c.planning_excluded=0 and c.is_archived=0")[0]["n"]
ok(not errs(t) and (n_un == 0 or f"{n_un} device" in t) and (n_lic == 0 or f"{n_lic} license" in t), f"To do lists hardware ({n_un}) and licenses ({n_lic}) to fix")
badge = re.search(r'href="/todo"[^>]*>.*?<span class="[^"]*badge[^"]*">(\d+)</span>', side(admin.get(B + "/").text), re.S)
ok(badge and int(badge.group(1)) == t.count('data-todo="'), "menu badge = the number of To do items")
ok("devices need a type" not in H.unescape(admin.get(B + "/todo?show=licensing").text) or n_un == 0, "To do tabs filter by kind")

# ---- all-clients devices: views, search on the server, paging, CSV
t = admin.get(B + "/devices").text
total = q("select count(*) n from devices where removed_at is null")[0]["n"]
ok(not errs(t) and "Devices &amp; assets" in t and "list-toolbar" in t and 'data-columns="device-table"' in t and "<th>Client</th>" in t, "Devices & assets: every client, with a Client column and the Columns menu")
ok(t.count('href="/devices/') >= min(100, total) and ("Show 100 more" in t or total <= 100), f"shows 100 at a time ({total} devices)")
ser = q("select serial, display_name from devices where removed_at is null and serial like 'SN%%' order by id limit 1")[0]
t = admin.get(B + "/devices", params={"q": ser["serial"]}).text
ok(ser["display_name"] in t and t.count('href="/devices/') <= 3, "search finds a device by serial on the server")
t = admin.get(B + "/devices?filter=os&class=laptop").text
ok(not errs(t) and re.search(r'btn-secondary dropdown-toggle"[^>]*>Laptop', t) is not None and re.search(r'nav-link active"[^>]*>OS support', t) is not None, "view tab + Type menu")
r = admin.get(B + "/devices/export?filter=stale")
ok(r.status_code == 200 and r.text.startswith("Client,Device,") and "text/csv" in r.headers.get("content-type", ""), "CSV of the filtered list, with Client")
t = admin.get(B + "/clients/1/devices?limit=100&q=zzzznothing").text
ok("No devices match." in t and not errs(t), "client device list: server search")

# ---- search
t = admin.get(B + "/search", params={"q": ser["serial"]}).text
ok(ser["display_name"] in t and not errs(t), "top search finds a device by serial")
k = q("select name, email from contacts where email is not null and archived_at is null limit 1")[0]
t = H.unescape(admin.get(B + "/search", params={"q": k["email"]}).text)
ok(k["name"] in t and "Contacts" in t, "top search finds a contact by email")
ok("Nothing matches" in admin.get(B + "/search?q=zzqqxx").text, "no results: says so")

# ---- client reports page, device tabs
t = admin.get(B + "/clients/1/reports").text
ok(not errs(t) and "/clients/1/report/qbr" in t and "/clients/1/report/assets" in t and "/reports?client=1" in t, "client Reports page lists its reports and links to all options")
dv = q("select id from devices where removed_at is null and client_id is not null or (removed_at is null and rmm_org_id is not null) order by id limit 1")[0]["id"]
t = admin.get(B + f"/devices/{dv}").text
ok(not errs(t) and "record-crumbs" in t and 'href="#details"' in t and 'href="#lifecycle"' in t and 'id="lifecycle"' in t, "device page: breadcrumb and tabs")

# ---- 1.43: light / dark per person, no inline scripts (the CSP blocks them), portal and reports stay light
q("update users set theme='auto' where email='viewer@example.com'")
vw = login("viewer@example.com", "ViewerPassword123!")
t = vw.get(B + "/account").text
ok('id="appearance"' in t and 'name="theme" value="dark"' in t and 'data-theme-pref="auto"' in t and '/assets/theme.js' in t and "<script>" not in t, "account: light or dark picker; auto mode uses the external theme script")
r = vw.post(B + "/account/appearance", data={"_csrf": csrf(vw, "/account"), "theme": "dark"})
ok(r.url.endswith("/account#appearance") and q("select theme from users where email='viewer@example.com'")[0]["theme"] == "dark" and 'data-bs-theme="dark"' in vw.get(B + "/").text, "choosing Dark saves and draws the app dark")
vw.post(B + "/account/appearance", data={"_csrf": csrf(vw, "/account"), "theme": "sideways"})
ok(q("select theme from users where email='viewer@example.com'")[0]["theme"] == "auto", "an unknown choice falls back to Match my computer")
ok('data-bs-theme="light"' in admin.get(B + "/clients/1/report/assets").text, "reports always draw light")
ok('data-theme-pref="light"' in requests.get(B + "/portal/login").text and 'data-theme-pref="auto"' in requests.get(B + "/login").text, "portal sign-in stays light; staff sign-in follows the computer")
with sync_playwright() as p:
    br = p.chromium.launch(); ctx = br.new_context(color_scheme="dark")
    ctx.add_cookies([{"name": c.name, "value": c.value, "url": B} for c in vw.cookies]); pg = ctx.new_page(); pg.goto(B + "/")
    ok(pg.get_attribute("html", "data-bs-theme") == "dark", "Match my computer: a dark computer gets dark mode")
    ctx2 = br.new_context(); ctx2.add_cookies([{"name": c.name, "value": c.value, "url": B} for c in admin.cookies]); pa = ctx2.new_page()
    fw = q("select framework_id from client_frameworks where client_id=1 limit 1")
    if fw:
        pa.goto(B + f"/clients/1/compliance/{fw[0]['framework_id']}")
        grp = pa.locator("[data-radio-buttons]").first
        lab = grp.locator("label.btn").last; lab.click()
        ok(lab.get_attribute("class").count("active") == 1 and grp.locator("label.btn.active").count() == 1 and lab.locator("input").is_checked() and grp.locator("input[type=radio]").first.evaluate("e => getComputedStyle(e).opacity") == "0",
           "checklist status buttons: the clicked one is active and checked, radios hidden")
    br.close()

# ---- client portal: six tabs, sub-tabs in a group
SEC = "JBSWY3DPEHPK3PXP"
q("delete from portal_users where email='tabs@lists.example'")
php(f'Align\\DB::insert("portal_users", ["client_id"=>1,"email"=>"tabs@lists.example","name"=>"Tab Tester","password_hash"=>password_hash("Lists-Pass-123", PASSWORD_DEFAULT),"is_active"=>1,"can_roadmap"=>1,"can_budget"=>1,"can_devices"=>1,"can_documents"=>1,"can_contacts"=>1,"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
ps = requests.Session()
ps.post(B + "/portal/login", data={"_csrf": csrf(ps, "/portal/login"), "email": "tabs@lists.example", "password": "Lists-Pass-123"})
ps.post(B + "/portal/login/2fa", data={"_csrf": csrf(ps, "/portal/login/2fa"), "code": totp(SEC)})
t = ps.get(B + "/portal/devices").text
bar = t.split('portal-sections')[1].split('</nav>')[0]
ok(len(re.findall(r'class="nav-link', bar)) <= 6 and "Your technology" in bar and "portal-subtabs" in t and 'href="/portal/licensing"' in t.split("portal-subtabs")[1][:600], "portal: six tabs, Devices and Licensing as sub-tabs")
ok('data-bs-theme="light"' in t and "/vendor/jquery" not in t, "portal: always light, no jQuery")
ok(not errs(ps.get(B + "/portal/budget").text) and "stat-tiles" in ps.get(B + "/portal/budget").text, "portal budget: tiles on the shared component")
q("delete from portal_users where email='tabs@lists.example'")
done()
