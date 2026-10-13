"""2.16.0 navigation, Connectors and Settings: the main menu (Libraries, Licensing & renewals with a Renewals tab, Staff &
portal users, no Vendors item), the client menu's Setup group (Connectors, Client portal, Onboarding; techs and admins),
search on phones; the Libraries page (each library for the roles that may open it); Standards under Libraries, not a
Settings tab; the client Connectors page (status line, links changed for one client and saved like Client mapping,
training setup, domains, back to the page); the overview's training card (results and Manage, no forms); Settings →
Health & checks (health score and the pass marks, gone from the Huntress, Veeam and PSA pages); the company address on
General (not Contract templates); Branding's company name read-only; demo data on Diagnostics."""
import re, html as H
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
C = 1


def side(t): return t.split('class="app-sidebar')[1].split("</aside>")[0] if 'class="app-sidebar' in t else ""


# ---- the main menu
t = st.get(B + "/").text
m = side(t)
ok('href="/libraries"' in m and ">Libraries<" in m and 'href="/vendors"' not in m, "the main menu has Libraries and no Vendors item")
ok("Licensing &amp; renewals" in m and 'href="/renewals"' not in m and "Staff &amp; portal users" in m, "Licensing & renewals is one item; People is Staff & portal users")
ok('href="/search"' in t and "d-md-none" in t.split('href="/search"')[0][-120:], "phones get a search button")
lt = st.get(B + "/licenses").text
rt = st.get(B + "/renewals").text
ok('href="/renewals"' in lt and 'href="/licenses"' in rt and not errs(lt) and not errs(rt), "Licensing and Renewals are two tabs of one page")
ok('<a href="/renewals" class="nav-link' not in side(rt) and 'href="/licenses" class="nav-link active"' in side(rt), "on Renewals the Licensing item is the active one")

# ---- the client menu: Setup group for techs and admins
ct = tech.get(B + f"/clients/{C}").text
cm = side(ct)
setup = cm.split(">SETUP<")[1] if ">SETUP<" in cm else ""
ok(f'href="/clients/{C}/connectors"' in setup and f'href="/clients/{C}/portal"' in setup and f'href="/clients/{C}/onboarding"' in setup, "the client menu has a Setup group: Connectors, Client portal, Onboarding")
ok(">SETUP<" not in side(viewer.get(B + f"/clients/{C}").text), "viewers don't get the Setup group")
ok("Welcome &amp; onboarding" not in ct, "Welcome & onboarding left the client's … menu")

# ---- Libraries
lib = st.get(B + "/libraries").text
for href in ["/settings/standards", "/frameworks", "/documents/templates", "/vendors", "/contracts/templates", "/settings/onboarding"]:
    ok(f'href="{href}"' in lib, f"Libraries (admin) links to {href}")
vl = viewer.get(B + "/libraries").text
ok(vl and 'href="/vendors"' in vl and 'href="/settings/standards"' not in vl and 'href="/contracts/templates"' not in vl and not errs(vl), "viewers see only the libraries they may open")
sd = st.get(B + "/settings/standards").text
ok('href="/libraries"' in sd and 'href="/settings/standards" class="nav-link' not in sd and not errs(sd), "Alignment standards sit under Libraries, not the Settings tabs")
ok('href="/settings/checks"' in st.get(B + "/settings").text, "Settings has a Health & checks tab")
ok(">Integrations<" in st.get(B + "/integrations").text and ">Connections<" not in st.get(B + "/integrations").text, "the Integrations tab is called Integrations")

# ---- Connectors
cn = tech.get(B + f"/clients/{C}/connectors").text
ok('id="connector-status"' in cn and "NinjaOne" in cn.split('id="connector-status"')[1][:3000] and not errs(cn), "the Connectors page opens with a status line")
for anchor in ['id="links"', 'id="email"', 'id="m365"', 'id="gws"', 'id="email-auth"', 'id="domains"']:
    ok(anchor in cn, f"Connectors has {anchor}")
sel = re.search(r'<select name="link\[ninjaone\]\[%d\]"[^>]*>(.*?)</select>' % C, cn, re.S)
ok(sel and 'value="101" selected' in sel.group(1), "the NinjaOne link can be changed here (the current organization selected)")
r = tech.post(B + "/mapping", data={"_csrf": csrf(tech, f"/clients/{C}/connectors"), "back": f"/clients/{C}/connectors", f"link[ninjaone][{C}]": ""})
ok(f"/clients/{C}/connectors" in r.url and q("select external_id from client_links where client_id=%s and provider='ninjaone'", C)[0]["external_id"] is None,
   "Not linked saves for this client and comes back to Connectors")
r = tech.post(B + "/mapping", data={"_csrf": csrf(tech, f"/clients/{C}/connectors"), "back": f"/clients/{C}/connectors", f"link[ninjaone][{C}]": "102"})
ok(q("select external_id from client_links where client_id=%s and provider='ninjaone'", C)[0]["external_id"] is None and "already linked to" in flash(r.text),
   "another client's organization is refused, as on Client mapping")
tech.post(B + "/mapping", data={"_csrf": csrf(tech, f"/clients/{C}/connectors"), "back": f"/clients/{C}/connectors", f"link[ninjaone][{C}]": "101"})
ok(q("select external_id from client_links where client_id=%s and provider='ninjaone'", C)[0]["external_id"] == "101", "...and linked again")
r = tech.post(B + "/mapping", data={"_csrf": csrf(tech, f"/clients/{C}/connectors"), "back": "https://evil.example/x", f"link[ninjaone][{C}]": "101"})
ok("evil.example" not in r.url and r.url.endswith("/mapping"), "any other back address goes to Client mapping")
ok(viewer.get(B + f"/clients/{C}/connectors").status_code == 403, "viewers can't open Connectors")

# ---- training: setup on Connectors, results on the overview
setting("knowbe4_enabled", "1")  # makes the training card show
ov = st.get(B + f"/clients/{C}").text
card = ov.split('id="sat"')[1].split('id="')[0] if 'id="sat"' in ov else ""
ok(card and f'/clients/{C}/connectors#sat' in ov and f'action="/clients/{C}/sat"' not in ov, "the overview's training card has Manage and no upload form")
cn = st.get(B + f"/clients/{C}/connectors").text
ok('id="sat"' in cn and f'action="/clients/{C}/sat"' in cn, "the upload form is on Connectors")
r = tech.post(B + f"/clients/{C}/sat", data={"_csrf": csrf(tech, f"/clients/{C}/connectors"), "campaign_date": ""}, files={"file": ("x.csv", b"nothing,useful\n1,2\n", "text/csv")})
ok(f"/clients/{C}/connectors" in r.url and "recognise" in flash(r.text), "an upload comes back to Connectors")
q("delete from settings where name='knowbe4_enabled'")

# ---- Settings → Health & checks
ck = st.get(B + "/settings/checks").text
ok(all(f'name="{k}"' in ck for k in ["health_good", "sat_training_pass", "sat_click_max", "sat_campaign_months", "backup_stale_hours", "sla_target"]) and not errs(ck),
   "Health & checks has the health score and every pass mark")
st.post(B + "/settings", data={"_csrf": csrf(st, "/settings/checks"), "_tab": "checks", "sat_training_pass": "85", "sla_target": "40"})
ok(q("select value from settings where name='sat_training_pass'")[0]["value"] == "85" and q("select value from settings where name='sla_target'")[0]["value"] == "50",
   "they save there (clamped to their ranges)")
q("delete from settings where name in ('sat_training_pass','sla_target')")
ok('name="health_good"' not in st.get(B + "/settings/planning").text, "the health score left Planning & lifecycle")

# ---- company details: the address on General; Branding shows the name read-only; demo data on Diagnostics
gen = st.get(B + "/settings").text
ok('name="company_address"' in gen and 'id="demo-data"' not in gen, "General has the company address and no demo data card")
st.post(B + "/settings", data={"_csrf": csrf(st, "/settings"), "_tab": "general", "company_address": "1 Example Way\r\nSpringfield"})
ok(q("select value from settings where name='company_address'")[0]["value"] == "1 Example Way\nSpringfield", "the address saves from General")
ct2 = st.get(B + "/contracts/templates").text
ok('name="company_address"' not in ct2 and 'href="/settings"' in ct2, "Contract templates points to General for the address")
st.post(B + "/contracts/templates/settings", data={"_csrf": csrf(st, "/contracts/templates"), "contract_remind_days": "3", "contract_max_reminders": "2"})
ok(q("select value from settings where name='company_address'")[0]["value"] == "1 Example Way\nSpringfield", "saving the contract reminders keeps the address")
bt = st.get(B + "/settings/branding").text
ok(re.search(r'id="company_name"[^>]*readonly', bt) and 'name="company_name"' not in bt, "Branding shows the company name read-only")
ok('id="demo-data"' in st.get(B + "/settings/diagnostics").text, "demo data is on Diagnostics")
for pth in ["/libraries", "/settings/checks", f"/clients/{C}/connectors", f"/clients/2/connectors", f"/clients/3/connectors", "/settings/diagnostics", f"/clients/{C}/vendors", f"/clients/{C}/backups"]:
    ok(not errs(st.get(B + pth).text), pth + " renders")
done()
