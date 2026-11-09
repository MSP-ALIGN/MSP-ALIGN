"""2.35.0 The Administration area: Integrations, Libraries, Staff & portal users, Settings and the Audit log leave the
left menu and open from the user menu's Administration item, on an overview page (/admin) whose pages have their own
sidebar (Back to MSP Align). Each role sees only what it may open: viewers Libraries and Sync history, techs also client
mapping and portal users, admins everything (Settings, Staff, Audit log, Diagnostics, Updates & backups)."""
import re
from lib import *

a = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")


def sidebar(t):
    m = re.search(r'<aside class="app-sidebar.*?</aside>', t, re.S)
    return m.group(0) if m else ""


def usermenu(t):
    m = re.search(r'<li class="nav-item dropdown user-menu">.*?</li>\s*</ul>', t, re.S)
    return m.group(0) if m else ""


# ---- the main menu no longer has the ADMIN group
t = a.get(B + "/").text
sb = sidebar(t)
ok(sb and ">ADMIN<" not in sb and 'href="/audit"' not in sb and 'href="/settings"' not in sb and 'href="/libraries"' not in sb and not errs(t),
   "the left menu has no Admin section any more")
ok(">CLIENTS<" in sb and 'href="/projects"' in sb, "...the rest of it is as before")
um = usermenu(t)
ok('href="/admin"' in um and "Administration" in um, "Administration is in the user menu")

# ---- the overview page, per role
t = a.get(B + "/admin").text
ids = set(re.findall(r'<a [^>]*id="admin-([a-z-]+)"', t))
ok(ids == {"integrations", "libraries", "people", "settings", "audit", "diagnostics", "updates"} and not errs(t), "admins see every section: %s" % sorted(ids))
sb = sidebar(t)
ok("ADMINISTRATION" in sb and "Back to MSP Align" in sb and 'href="/audit"' in sb and 'href="/settings"' in sb and ">CLIENTS<" not in sb,
   "the Administration area has its own sidebar, with Back to MSP Align")
t = tech.get(B + "/admin").text
ids = set(re.findall(r'<a [^>]*id="admin-([a-z-]+)"', t))
ok(ids == {"integrations", "libraries", "people"} and not errs(t), "techs see the sections they can use: %s" % sorted(ids))
href = dict((k, h) for h, k in re.findall(r'href="([^"]+)" id="admin-([a-z-]+)"', t))
ok(href.get("integrations") == "/mapping", "a tech's Integrations opens Client mapping (the first tab they may open): %s" % href.get("integrations"))
ok(href.get("people") == "/portal-users", "...and Staff & portal users opens Client portal users")
t = viewer.get(B + "/admin").text
ids = set(re.findall(r'<a [^>]*id="admin-([a-z-]+)"', t))
ok(ids == {"integrations", "libraries"} and not errs(t), "viewers see Libraries and the sync history: %s" % sorted(ids))
ok(dict((k, h) for h, k in re.findall(r'href="([^"]+)" id="admin-([a-z-]+)"', t)).get("integrations") == "/sync", "a viewer's Integrations opens the sync history")
ok('href="/admin"' in usermenu(viewer.get(B + "/").text), "...from their user menu too")
ok(viewer.get(B + "/settings").status_code == 403 and viewer.get(B + "/audit").status_code == 403, "admin-only pages still refuse others")

# ---- the admin sidebar on the section pages
for path, key in [("/integrations", "integrations"), ("/settings/diagnostics", "settings"), ("/audit", "audit"), ("/users", "people"), ("/libraries", "libraries")]:
    t = a.get(B + path).text
    sb = sidebar(t)
    ok("ADMINISTRATION" in sb and re.search(r'class="nav-link active"[^>]*>\s*<i class="nav-icon fas fa-fw [a-z-]+"></i><p>' , sb) and not errs(t), f"{path}: the Administration sidebar, with its section marked")
t = tech.get(B + "/mapping").text
ok("ADMINISTRATION" in sidebar(t) and 'href="/settings"' not in sidebar(t), "a tech on Client mapping gets the Administration sidebar without admin-only items")
t = a.get(B + "/clients").text
ok("ADMINISTRATION" not in sidebar(t), "other pages keep the main menu")

done()
