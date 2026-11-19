"""2.38.0 Client mapping one tool at a time: a tab per linking tool that is connected or still has records (with how
many clients are missing a link there), tools that aren't connected only named, ?tool= and ?show= checked against
fixed values, a tool opening on the clients missing a link (or every client when none are), Save going back to the
same tab and list, and the page fitting a phone without scrolling sideways."""
import json, re, html as H
from lib import *
from playwright.sync_api import sync_playwright

admin = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")

# Every linking tool as the app sees it: key => [name, connected, number of records]
tools = json.loads(php('$o = []; foreach (Align\\Providers\\Providers::linkConnectors() as $k => $c) $o[$k] = [$c->linkName(), $c->configured(), count($c->linkRecords())]; echo json_encode($o);').stdout)
shown = [k for k, (n, conf, recs) in tools.items() if conf or recs]
hidden = [k for k in tools if k not in shown]
planned = [r["id"] for r in q("select id from clients where is_archived=0 and planning_excluded=0")]


def missing(key):
    """Planned clients with no link row for this tool (the seed has no 'doesn't need backups' choices)."""
    return [c for c in planned if not q("select 1 from client_links where client_id=%s and provider=%s", c, key)] if tools[key][2] else []


def tabs(t):
    """The tool tabs' keys, in order."""
    return re.findall(r'<a class="nav-link text-nowrap[^"]*" href="/mapping\?tool=([a-z0-9_-]+)"', t)


def save_form(t):
    """Every field of the mapping form on page text t, with each picker's current choice."""
    F = {"_csrf": re.search(r'name="_csrf" value="([^"]+)"', t).group(1)}
    form = t.split('action="/mapping" data-unsaved', 1)[1].split("</form>", 1)[0]
    for name, val in re.findall(r'<input type="hidden" name="(tool|show)" value="([^"]*)"', form):
        F[name] = val
    for name, body in re.findall(r'<select name="(link\[[^\]]+\]\[\d+\])"[^>]*>(.*?)</select>', form, re.S):
        m = re.search(r'<option value="([^"]*)" selected', body)
        F[name] = H.unescape(m.group(1)) if m else ""
    return F


ok("ninjaone" in shown and "veeam" in shown and hidden, f"the seed has connected tools {shown} and tools that aren't connected {hidden}")
before = q("select * from client_links order by client_id, provider")
try:
    # ---- tabs: one per connected tool, none for the others, which are only named
    t = tech.get(B + "/mapping").text
    ok(not errs(t) and tabs(t) == shown, f"a tab for each connected tool, in order: {tabs(t)}")
    ok(all(f'href="/mapping?tool={k}"' not in t for k in hidden) and all(f'name="link[{k}]' not in t for k in hidden), "no tab or pickers for a tool that isn't connected")
    names = t.split("Also links clients when connected:", 1)[1].split("</p>", 1)[0] if "Also links clients when connected:" in t else ""
    ok(all(H.escape(tools[k][0]) in names for k in hidden), "...those are named at the bottom instead")
    ok('href="/integrations"' not in names and 'href="/integrations"' in admin.get(B + "/mapping").text.split("Also links clients when connected:", 1)[1].split("</p>", 1)[0],
       "...with a link to Integrations for admins only")
    for k in shown:
        n = len(missing(k))
        tab = t.split(f'href="/mapping?tool={k}"', 1)[1].split("</a>", 1)[0]
        ok((f'>{n}<span class="visually-hidden">' in tab and "text-bg-warning" in tab) if n else ("text-bg-warning" not in tab), f"{k} tab: badge {n or 'none'} for clients missing a link")

    # ---- which tool and list open
    first = next((k for k in shown if missing(k)), shown[0])
    ok(f'href="/mapping?tool={first}" aria-current="page"' in t, f"/mapping opens on the first tool with a client missing a link ({first})")
    ok('name="link[%s][' % first in t and all('name="link[%s][' % k not in t for k in shown if k != first), "...and shows only that tool's pickers")
    rows = set(int(x) for x in re.findall(r'name="link\[%s\]\[(\d+)\]"' % first, t))
    ok(rows == set(missing(first)), f"...on the clients missing a link there ({len(rows)})")
    # a tool with none missing opens on every client; one with some on those
    q("insert ignore into client_links (client_id, provider, external_id, match_method) select id, 'veeam', NULL, 'manual' from clients where is_archived=0 and planning_excluded=0")
    tv = tech.get(B + "/mapping?tool=veeam").text
    ok(not missing("veeam") and len(re.findall(r'name="link\[veeam\]\[\d+\]"', tv)) == len(planned) and '<a class="nav-link py-1 px-2 small active" href="/mapping?tool=veeam&amp;show=all"' in tv,
       "a tool with nobody missing opens on All clients")
    tm = tech.get(B + "/mapping?tool=veeam&show=missing").text
    ok("Every client is linked in Veeam" in tm and "Show all clients" in tm and not re.search(r'name="link\[veeam\]', tm), "...and its Missing a link says everyone is linked")
    tab = tm.split('href="/mapping?tool=veeam"', 1)[1].split("</a>", 1)[0]
    ok("fa-circle-check" in tab and "text-bg-warning" not in tab, "...its tab shows a check instead of a badge")
    q("delete from client_links where provider='veeam'")
    for r in before:
        if r["provider"] == "veeam":
            q("insert into client_links (client_id, provider, external_id, match_method, created_at) values (%s,%s,%s,%s,%s)", r["client_id"], r["provider"], r["external_id"], r["match_method"], r["created_at"])

    # ---- ?tool= and ?show= only take known values
    for bad in ["nope", "../integrations", '"><script>alert(1)</script>', "NINJAONE"]:
        r = tech.get(B + "/mapping", params={"tool": bad, "show": "x<y"})
        ok(r.status_code == 200 and not errs(r.text) and "<script>alert(1)" not in r.text and f'href="/mapping?tool={first}" aria-current="page"' in r.text,
           f"tool={bad[:20]!r}: opens the default tool")
    r = tech.get(B + "/mapping", params={"tool[]": "veeam", "show[]": "all"})
    ok(r.status_code == 200 and not errs(r.text), "array parameters are ignored")

    # ---- saving goes back to the same tab and list; only that tool's links are posted
    tn = tech.get(B + "/mapping?tool=ninjaone&show=all").text
    F = save_form(tn)
    ok(F.get("tool") == "ninjaone" and F.get("show") == "all" and all(k.startswith("link[ninjaone][") for k in F if k.startswith("link[")), "the form carries its tool and list, and only that tool's pickers")
    cid = planned[0]
    was = (q("select external_id from client_links where client_id=%s and provider='ninjaone'", cid) or [{"external_id": None}])[0]["external_id"]
    vwas = q("select external_id, match_method from client_links where client_id=%s and provider='veeam'", cid)
    F[f"link[ninjaone][{cid}]"] = ""
    r = tech.post(B + "/mapping", data=F, allow_redirects=False)
    ok(r.status_code in (302, 303) and r.headers.get("Location", "").endswith("/mapping?tool=ninjaone&show=all"), f"Save returns to the same tool and list: {r.headers.get('Location')}")
    ok(q("select external_id, match_method from client_links where client_id=%s and provider='ninjaone'", cid) == [{"external_id": None, "match_method": "manual"}]
       and q("select external_id, match_method from client_links where client_id=%s and provider='veeam'", cid) == vwas, "the change is saved, and the client's other tools are untouched")
    for tool, show, want in [("//evil.example", "all", "/mapping?show=all"), ("veeam", "x", "/mapping?tool=veeam"), ("nope", "missing", "/mapping?show=missing"), ("", "", "/mapping")]:
        r = tech.post(B + "/mapping", data={"_csrf": csrf(tech, "/mapping"), "tool": tool, "show": show}, allow_redirects=False)
        ok(r.headers.get("Location", "").endswith(want) and "evil" not in r.headers.get("Location", ""), f"posted tool={tool!r} show={show!r} goes back to {want}")
    if was is not None:
        q("update client_links set external_id=%s where client_id=%s and provider='ninjaone'", was, cid)

    # ---- To do: each tool's "clients not linked" item opens that tool's tab
    q("insert into clients (name, source, is_archived, planning_excluded) values ('M238 Unlinked Client','manual',0,0)")
    td = H.unescape(tech.get(B + "/todo").text)
    ok('href="/mapping?tool=ninjaone&show=missing"' in td and 'href="/mapping?tool=veeam&show=missing"' in td and 'href="/mapping?show=missing"' not in td,
       "To do links each tool's item to its own tab")
    tv = tech.get(B + "/mapping?tool=veeam&show=missing").text
    mid = q("select id from clients where name='M238 Unlinked Client'")[0]["id"]
    ok(f'name="link[veeam][{mid}]"' in tv and "not linked yet" in tv, "...where the client is listed as not linked yet")
    q("delete from clients where name='M238 Unlinked Client'")


    # ---- who may open it
    ok(viewer.get(B + "/mapping?tool=veeam").status_code == 403, "viewers still can't open Client mapping")

    # ---- in a browser: tabs switch tools, the filter works, and a phone doesn't scroll sideways
    jar = [{"name": c.name, "value": c.value, "url": B} for c in tech.cookies]
    with sync_playwright() as p:
        b = p.chromium.launch()
        cx = b.new_context(viewport={"width": 390, "height": 800}); cx.add_cookies(jar); pg = cx.new_page()
        pg.goto(B + "/mapping?tool=ninjaone&show=all")
        wide = pg.evaluate("document.documentElement.scrollWidth - window.innerWidth")
        ok(wide <= 1, f"at phone width the page doesn't scroll sideways ({wide}px over)")
        pg.click('a.nav-link[href="/mapping?tool=veeam"]')
        pg.wait_for_load_state()
        ok("tool=veeam" in pg.url and pg.locator('select[name^="link[veeam]"]').count() > 0 and pg.locator('select[name^="link[ninjaone]"]').count() == 0, "clicking a tab shows that tool's list")
        pg.goto(B + "/mapping?tool=ninjaone&show=all")
        name = q("select name from clients where id=%s", planned[1])[0]["name"]
        pg.fill("[data-filter-table=map-table]", name)
        vis = pg.eval_on_selector_all("#map-table tbody tr", "els => els.filter(e => e.getClientRects().length > 0).length")
        ok(vis >= 1 and vis < len(planned), f"the filter narrows the list ({vis} rows)")
        sel = pg.locator(f'select[name="link[ninjaone][{planned[1]}]"]')
        sel.focus()
        ok(sel.locator("option").count() == tools["ninjaone"][2] + 1, "a picker fills in every organization when used")
        b.close()
finally:
    q("delete from client_links")
    for r in before:
        q("insert into client_links (client_id, provider, external_id, match_method, created_at) values (%s,%s,%s,%s,%s)", r["client_id"], r["provider"], r["external_id"], r["match_method"], r["created_at"])
    php('Align\\Sync\\BackupSync::assign();')
ok(q("select * from client_links order by client_id, provider") == before, "links restored")
done()
