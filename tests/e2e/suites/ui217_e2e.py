"""2.17.0 the new look and the design clean-up: the Inter typeface is served from Align itself (and listed on the
License page); the stylesheet has the new tokens (white page, tinted borderless cards, the blue-grey sidebar whose
current item is filled with the brand color) in light and dark; every field label written next to its field is tied to it (clicking the label
focuses the field) and icon-only buttons have names; every page has a main heading; ended states are grey (one
status-colour map: status_tone()); empty lists use the one empty state; tabs scroll on phones instead of wrapping."""
import re, requests
from lib import *
from playwright.sync_api import sync_playwright

st = login("admin@example.com", "LongPassword123!")
C = q("select id from clients where name like 'Cedar Ridge%%'")[0]["id"]

# ---- the typeface: served here, no outside request, licensed
r = requests.get(B + "/vendor/inter/inter-latin-wght-normal.woff2")
ok(r.status_code == 200 and r.content[:4] == b"wOF2", "Inter is served from Align (woff2)")
ok(requests.get(B + "/vendor/inter/LICENSE").status_code == 200 and "Open Font License" in requests.get(B + "/vendor/inter/LICENSE").text, "...with its license")
css = requests.get(B + "/assets/app.css").text
ok('font-family: "Inter"' in css and "/vendor/inter/inter-latin-wght-normal.woff2" in css and "fonts.googleapis" not in css, "the stylesheet loads Inter from this server only")
lic = st.get(B + "/license").text
ok("Inter" in lic and "SIL OFL 1.1" in lic, "the License page lists Inter")

# ---- the look's tokens, light and dark
ok("--align-card-bg: #f4f6f9" in css and "--align-sidebar-bg: #1a2230" in css and "--align-page-bg: #fff" in css, "light: white page, tinted cards, blue-grey sidebar")
ok("--align-card-bg: #161d27" in css and "--align-sidebar-bg: #0a0e14" in css and "--align-page-bg: #0e131a" in css, "dark: the same parts in dark")

# ---- status colours: ended states are grey everywhere
ok(php('echo status_tone("ended"), ",", status_tone("problem"), ",", status_tone("done"), ",", status_tone("nonsense");').stdout.strip() == "secondary,danger,success,secondary",
   "status_tone(): ended grey, problem red, done green, anything else grey")
tones = php('echo json_encode([Align\\Docs\\Documents::STATUSES["archived"][1], Align\\Contracts\\Contracts::STATUSES["expired"][1], Align\\Contracts\\Contracts::STATUSES["void"][1],'
            ' Align\\Contracts\\Contracts::STATUSES["declined"][1], Align\\Onboarding\\Onboarding::STATUS_LABELS["expired"][1], Align\\Portal\\Submissions::STATUS["withdrawn"][1]]);').stdout
ok(tones.strip() == '["secondary","secondary","secondary","secondary","secondary","secondary"]', "archived, expired, cancelled, declined and withdrawn are all grey " + tones)
q("update clients set is_archived=1 where id=%s", C)
t = st.get(B + f"/clients/{C}").text
q("update clients set is_archived=0 where id=%s", C)
ok("text-bg-secondary align-middle ms-1\">Archived" in t and "text-bg-dark" not in t, "an archived client's badge is grey")

# ---- empty states
q("delete from portal_users where email like '%%@ui217.example'")
t = st.get(B + "/search?q=zzqqxx").text
ok('class="empty-state"' in t and "Nothing matches" in t, "an empty search shows the one empty state")
D = q("select id from clients where id<>%s order by id limit 1", C)[0]["id"]
docs = st.get(B + f"/clients/{D}/documents").text
ok('class="empty-state"' in docs or q("select count(*) n from documents where client_id=%s", D)[0]["n"] > 0, "an empty table row uses the empty state")

# ---- in the browser: headings, labels tied to fields, icon buttons named, tabs scroll on a phone
jar = [{"name": c.name, "value": c.value, "domain": "127.0.0.1", "path": c.path or "/"} for c in st.cookies]
PAGES = ["/", f"/clients/{C}", f"/clients/{C}/contacts", f"/clients/{C}/meetings", f"/clients/{C}/documents", f"/clients/{C}/compliance", f"/clients/{C}/alignment",
         f"/clients/{C}/changes", f"/clients/{C}/devices", f"/clients/{C}/connectors", "/users", "/audit", "/sync", "/frameworks", "/documents/templates", "/settings", "/settings/checks",
         "/libraries", "/integrations", "/licenses", "/clients", "/todo", "/account"]
LABELS = r"""() => { const F = 'input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea'; const bad = [];
  document.querySelectorAll('label:not([for])').forEach(l => { if (l.querySelector(F) || l.classList.contains('btn')) return;
    for (let el = l.nextElementSibling; el; el = el.nextElementSibling) { if (el.tagName === 'LABEL') break;
      const f = el.matches(F) ? el : el.querySelector(F); if (f) { if (!f.labels || !f.labels.length) bad.push(l.textContent.trim().slice(0, 40)); break; } } });
  return bad; }"""
NAMES = r"""() => [...document.querySelectorAll('button, a.btn, a[href]')].filter(b => b.offsetParent !== null && !b.textContent.trim()
  && !b.getAttribute('aria-label') && !b.getAttribute('title') && !b.querySelector('img[alt]:not([alt=""])')).map(b => b.outerHTML.slice(0, 90))"""
with sync_playwright() as p:
    b = p.chromium.launch(); cx = b.new_context(viewport={"width": 1366, "height": 900}); cx.add_cookies(jar); pg = cx.new_page()
    noh1, unlinked, unnamed = [], [], []
    for path in PAGES:
        pg.goto(B + path); pg.wait_for_timeout(250)
        if pg.locator("h1").count() < 1: noh1.append(path)
        bad = pg.evaluate(LABELS)
        if bad: unlinked.append((path, bad[:3]))
        nn = pg.evaluate(NAMES)
        if nn: unnamed.append((path, nn[:2]))
    ok(not noh1, "every page has a main heading " + str(noh1))
    ok(not unlinked, "every label written next to its field is tied to it " + str(unlinked)[:400])
    ok(not unnamed, "every icon-only button or link has a name " + str(unnamed)[:400])
    # clicking a label focuses its field (a modal form that had labels with no for=)
    pg.goto(B + f"/clients/{C}/contacts"); pg.wait_for_timeout(250)
    lab = pg.locator("#modal-contact label:not(.form-check-label):not(.btn)").first
    fid = lab.get_attribute("for")
    ok(bool(fid) and pg.locator(f"#{fid}").count() == 1, "a label gets the id of its field (and ids stay unique)")
    pair = pg.evaluate("""(() => { const l = [...document.querySelectorAll('#modal-contact label')].find(x => x.textContent.trim().startsWith('Title'));
      const f = l && document.getElementById(l.htmlFor); return f ? f.name : null; })()""")
    ok(pair == "title", "...the right field: the contact form's Title label goes to its title field (" + str(pair) + ")")
    # the font is used, the menu is blue-grey and its current item is filled with the brand color
    fam = pg.evaluate("getComputedStyle(document.body).fontFamily")
    ok(fam.startswith("Inter"), "pages use Inter: " + fam)
    pg.evaluate("document.documentElement.setAttribute('data-bs-theme','light')"); pg.wait_for_timeout(700)
    pill = pg.evaluate("getComputedStyle(document.querySelector('.sidebar-menu .nav-link.active')).backgroundColor")
    side = pg.evaluate("getComputedStyle(document.querySelector('.app-sidebar')).backgroundColor")
    ok(pill == "rgb(27, 104, 184)" and side == "rgb(26, 34, 48)", "the menu is blue-grey and the current item is the brand blue: " + pill + " on " + side)
    card = pg.evaluate("(() => { const c = getComputedStyle(document.querySelector('.app-content .card')); return [c.backgroundColor, c.borderTopWidth, c.boxShadow]; })()")
    ok(card[0] == "rgb(244, 246, 249)" and card[2] == "none", "cards are the tint with no shadow: " + str(card))
    pg.evaluate("document.documentElement.setAttribute('data-bs-theme','dark')"); pg.wait_for_timeout(700)
    card = pg.evaluate("getComputedStyle(document.querySelector('.app-content .card')).backgroundColor")
    ok(card == "rgb(22, 29, 39)", "...and in dark mode: " + card)
    cx.close()
    # a phone: the settings tabs and section tabs scroll sideways instead of wrapping
    cx = b.new_context(viewport={"width": 390, "height": 800}); cx.add_cookies(jar); pg = cx.new_page()
    pg.goto(B + "/settings"); pg.wait_for_timeout(250)
    tabs = pg.evaluate("(() => { const t = document.querySelector('.nav-tabs'); const cs = getComputedStyle(t); return [cs.flexWrap, cs.overflowX, t.scrollWidth > t.clientWidth]; })()")
    ok(tabs[0] == "nowrap" and tabs[1] == "auto" and tabs[2], "on a phone the tabs scroll sideways " + str(tabs))
    ok(pg.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"), "...and the page itself doesn't scroll sideways")
    b.close()
done()
