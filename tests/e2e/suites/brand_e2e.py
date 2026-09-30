"""1.45.2: the Branding page in the 1.43 look, with a live preview of the app, the sign-in page and the client portal."""
from lib import *

admin = login("admin@example.com", "LongPassword123!")
before = {r["name"]: r["value"] for r in q("select name, value from settings where name in ('brand_name','brand_primary','brand_sidebar','brand_logo_only','brand_login_message')")}
t = admin.get(B + "/settings/branding").text
ok(not errs(t) and 'id="brand-preview"' in t and all(x in t for x in ['id="bp-tab-app"', 'id="bp-tab-login"', 'id="bp-tab-portal"', 'data-preview-theme="dark"']), "the page has the preview: app, sign-in page and client portal, light and dark")
ok('class="bp-header"' in t and 'bp-search' in t and "Top bar, buttons" not in t, "the preview is the current look (white header with search, not the old colored top bar)")
ok(t.count('name="brand_sidebar"') == 2 and 'type="radio"' in t, "the menu color is two picture choices")
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "save", "brand_name": "Acme vCIO", "company_name": "Example MSP Group",
    "brand_primary": "#2F7A55", "brand_sidebar": "light", "brand_login_message": "Welcome back, team"})
t = admin.get(B + "/settings/branding").text
ok("Branding saved" in flash(r.text) or "saved" in flash(r.text).lower(), "saved: " + flash(r.text))
ok('value="light" checked' in t and "--bp-color: #2f7a55" in t and 'data-swatch="#2f7a55"' in t and 'class="swatch is-active" style="background: #2f7a55"' in t, "the saved color and light menu show as chosen, in the preview too")
ok("Acme vCIO" in t and "Welcome back, team" in t, "the preview shows the name and the sign-in message")
d = admin.get(B + "/").text
ok('app-sidebar shadow sidebar-light' in d and "--align-brand:#2f7a55" in d, "the app uses them")
ok("Welcome back, team" in requests.get(B + "/login").text, "the sign-in page shows the message")
r = admin.post(B + "/settings/branding", data={"_csrf": csrf(admin, "/settings/branding"), "action": "reset"})
t = admin.get(B + "/settings/branding").text
ok('value="dark" checked' in t and "--bp-color: #007bff" in t, "reset: back to the default blue and dark menu")
viewer = login("viewer@example.com", "ViewerPassword123!")
ok(viewer.get(B + "/settings/branding").status_code == 403, "admins only")
q("delete from settings where name in ('brand_name','brand_primary','brand_sidebar','brand_logo_only','brand_login_message')")
for k, v in before.items():
    q("insert into settings (name, value, is_secret) values (%s, %s, 0)", k, v)
done()
