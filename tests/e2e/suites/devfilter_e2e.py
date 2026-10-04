"""2.2.2: the Filters panel on Devices & assets (every client's list and each client's): make and model, operating
system, age, replacement, warranty, status, backup, location, project and last user. Filters apply together with the
view tabs, Type, Client and the search, live in the page address, show as removable chips, and the CSV follows them."""
from lib import *
import csv, io, json, re, html as H
from urllib.parse import urlencode

def rows(t): return re.findall(r'<tr[^>]*data-device="(\d+)"', t) or re.findall(r'href="/devices/(\d+)"', t)
def ids(t): return sorted(set(int(x) for x in re.findall(r'<a[^>]+href="/devices/(\d+)"', t.split('id="device-table"', 1)[-1].split('</table>', 1)[0])))
def devs(cid=None):
    out = php('$d = (new Align\\Lifecycle\\Lifecycle())->devices(' + (str(cid) if cid else 'null') + '); echo json_encode(array_map(fn($x) => ["id" => (int) $x["id"], "client_id" => $x["client_id"], "make" => Align\\Lifecycle\\DeviceFilters::make($x),'
              ' "model" => trim((string) $x["model"]), "os" => Align\\Lifecycle\\DeviceFilters::os($x), "age" => Align\\Lifecycle\\DeviceFilters::age($x), "replace" => Align\\Lifecycle\\DeviceFilters::replace($x),'
              ' "warranty" => Align\\Lifecycle\\DeviceFilters::warranty($x), "status" => $x["status"], "project" => !empty($x["project"]), "user" => trim((string) $x["last_user"]) !== "", "location" => trim((string) $x["location"]),'
              ' "inactive" => (int) $x["client_inactive"]], $d));').stdout
    return json.loads(out)
def pv(code): return php(code).stdout.strip()

# ---- the rules
ok(pv('$f = fn($n) => Align\\Lifecycle\\DeviceFilters::os(["os_name" => $n]); echo implode("|", array_map($f, ["Windows 11 Pro", "Microsoft Windows 10 Enterprise", "Windows Server 2019 Standard", "Microsoft Windows Server 2012 R2 Datacenter", "macOS 14.5", "Ubuntu 22.04.4 LTS", "", "FortiOS 7.2.8"]));')
   == "Windows 11|Windows 10|Windows Server 2019|Windows Server 2012 R2|macOS|Linux||FortiOS 7.2.8", "operating systems are grouped by family (Windows 11, Server 2019, macOS, Linux…)")
ok(pv('$f = fn($a, $s) => Align\\Lifecycle\\DeviceFilters::age(["start_date" => $s, "age_years" => $a]); echo implode(",", [$f(1.2, "2025-01-01"), $f(3.0, "2023-01-01"), $f(4.9, "2022-01-01"), $f(5.0, "2021-01-01"), $f(null, null)]);')
   == "0-3,3-5,3-5,5+,unknown", "age bands: under 3, 3 to 5, 5 or more, no in-service date")
ok(pv('$t = date("Y"); $f = fn($w, $hw = true) => Align\\Lifecycle\\DeviceFilters::warranty(["warranty_end" => $w, "is_hardware" => $hw]); echo implode(",", [$f("2001-01-01"), $f(date("Y-m-d", strtotime("+30 days"))), $f(($t + 1) . "-06-30"), $f(($t + 5) . "-01-01"), $f(null), $f(null, false)]);')
   in ("expired,90,next,later,none,", "expired,90,year,later,none,"), "warranty bands: expired, 90 days, next year, later, none (and nothing for virtual)")
ok(pv('echo Align\\Lifecycle\\DeviceFilters::make(["manufacturer" => "Dell Inc."]), "|", Align\\Lifecycle\\DeviceFilters::make(["manufacturer" => "LENOVO"]), "|", Align\\Lifecycle\\DeviceFilters::make(["manufacturer" => "VMware, Inc."]);')
   == "Dell|Lenovo|VMware", "makes are tidied (Dell Inc. → Dell, LENOVO → Lenovo)")

st = login("admin@example.com", "LongPassword123!")
all_d = [d for d in devs() if not d["inactive"]]

# ---- every client's list
base = st.get(B + "/devices?limit=5000").text
ok('data-bs-target="#device-filters"' in base and 'id="device-filters"' in base and 'name="make"' in base and 'name="os"' in base and 'name="warranty"' in base,
   "Devices & assets has a Filters button and panel")
ok('name="model"' not in base and "Pick a make" in base, "the model list waits until a make is picked")
makes = {}
for d in all_d: makes[d["make"]] = makes.get(d["make"], 0) + 1
top = max((m for m in makes if m), key=lambda m: makes[m])
ok(f'>{H.escape(top)} ({makes[top]:,})<' in H.unescape(base).replace("&", "&amp;") or f'{top} ({makes[top]:,})' in H.unescape(base), "each choice shows how many devices it has: %s (%d)" % (top, makes[top]))

t = st.get(B + "/devices?" + urlencode({"make": top, "limit": 5000})).text
got = ids(t)
want = sorted(d["id"] for d in all_d if d["make"] == top)
ok(got == want and len(got) == makes[top], "Make filters the list: %d %s devices" % (len(got), top))
ok('data-filter-chips' in t and f"Make: {top}" in H.unescape(t) and 'name="model"' in t, "an active filter shows as a chip, and the model list appears")
chip = re.search(r'<a class="badge rounded-pill[^"]*" href="([^"]+)"[^>]*aria-label="Remove filter Make', t)
ok(chip and "make=" not in H.unescape(chip.group(1)), "the chip's link removes that filter")

# two filters together, with a view tab and the search box keeping them
oss = {}
for d in all_d:
    if d["make"] == top: oss[d["os"]] = oss.get(d["os"], 0) + 1
os1 = max(oss, key=lambda o: oss[o])
t = st.get(B + "/devices?" + urlencode({"make": top, "os": os1 or "-", "limit": 5000})).text
ok(ids(t) == sorted(d["id"] for d in all_d if d["make"] == top and d["os"] == os1), "filters combine: %s and %s" % (top, os1 or "(none)"))
ok(re.search(r'href="[^"]*filter=attention[^"]*make=', H.unescape(t)) or re.search(r'href="[^"]*make=[^"]*filter=attention', H.unescape(t)), "the view tabs keep the filters")
ok('name="make" value="' + H.escape(top) in t.replace("&#039;", "'") or ('type="hidden" name="make"' in t), "the search box keeps them too")

for key, val in [("warranty", "expired"), ("age", "5+"), ("project", "no"), ("user", "yes"), ("replace", "overdue")]:
    t = st.get(B + "/devices?" + urlencode({key: val, "limit": 5000})).text
    want = sorted(d["id"] for d in all_d if (d[key] == val if key not in ("project", "user") else d[key] == (val == "yes")))
    ok(ids(t) == want, f"{key}={val}: {len(want)} devices")

# the tiles follow the filters
t = st.get(B + "/devices?" + urlencode({"make": top})).text
ok(re.search(r'href="/devices\?[^"]*make=', H.unescape(t)) is not None, "the summary tiles keep the filters")

# CSV follows them
r = st.get(B + "/devices/export?" + urlencode({"make": top}))
lines = list(csv.reader(io.StringIO(r.text)))
ok(r.status_code == 200 and len(lines) - 1 == makes[top] and all(top.lower() in (l[4] or "").lower() for l in lines[1:]), "the CSV holds just the filtered devices (%d)" % (len(lines) - 1))

# an unknown or hostile value matches nothing, and is escaped
t = st.get(B + "/devices?" + urlencode({"make": '<script>x</script>"', "status": "nope"})).text
ok(ids(t) == [] and "<script>x</script>" not in t and "&lt;script&gt;" in t and not errs(t), "an unknown value matches no device and is shown escaped")
t = st.get(B + "/devices?" + urlencode({"model": "Anything"})).text
ok(len(ids(t)) == min(len(all_d), 100) and "data-filter-chips" not in t, "a model without a make is ignored")

# ---- a client's list (with backups)
cdev = devs(1)
t = st.get(B + "/clients/1/devices?limit=5000").text
ok('id="device-filters"' in t, "a client's device list has the Filters panel too")
has_backup = 'name="backup"' in t
ok(has_backup, "with a backup tool linked to the client, it filters by backup")
bmap = json.loads(php('echo json_encode(array_map(fn($d) => Align\\Lifecycle\\DeviceFilters::backup($d, Align\\Backup\\Backup::deviceMap(null, 1)), array_column((new Align\\Lifecycle\\Lifecycle())->devices(1), null, "id")));').stdout)
for val in ["none", "ok"]:
    t = st.get(B + "/clients/1/devices?" + urlencode({"backup": val, "limit": 5000})).text
    ok(ids(t) == sorted(int(i) for i, v in bmap.items() if v == val), f"backup={val}: {sum(1 for v in bmap.values() if v == val)} devices")
r = st.get(B + "/clients/1/export?" + urlencode({"warranty": "expired"}))
lines = list(csv.reader(io.StringIO(r.text)))
ok(len(lines) - 1 == sum(1 for d in cdev if d["warranty"] == "expired"), "the client's CSV follows the filters too (%d)" % (len(lines) - 1))
r = st.get(B + "/clients/1/export")
ok(len(list(csv.reader(io.StringIO(r.text)))) - 1 == len(cdev), "without filters it still has every device")
ok(re.search(r'href="/clients/1/export\?[^"]*warranty=expired', H.unescape(st.get(B + "/clients/1/devices?warranty=expired").text)), "the CSV button carries the filters")

# the bulk replacement form comes back to the same filtered list
t = st.get(B + "/clients/1/devices?" + urlencode({"make": cdev[0]["make"] or "-"})).text
rq = re.search(r'name="return_query" value="([^"]*)"', t)
ok(rq and "make=" in H.unescape(rq.group(1)), "Set replacement returns to the filtered list")
st_back = st.post(B + "/clients/1/devices/replacement", data={"_csrf": csrf(st, "/clients/1/devices"), "ids[]": [], "return_query": H.unescape(rq.group(1))}, allow_redirects=False)
ok("make=" in st_back.headers.get("Location", ""), "and the redirect keeps it: " + st_back.headers.get("Location", "")[:100])

# Make projects comes back with filters that hold a space (Windows 11 → Windows+11)
osp = next((d["os"] for d in cdev if " " in d["os"]), "")
if osp:
    t = st.get(B + "/clients/1/devices?" + urlencode({"os": osp})).text
    back = re.search(r'name="back" value="([^"]*)"', t)
    ok(back and "os=" + osp.replace(" ", "+") in H.unescape(back.group(1)), "Make projects keeps a filter with a space: " + (H.unescape(back.group(1)) if back else ""))
    r = st.post(B + "/clients/1/devices/projects", data={"_csrf": csrf(st, "/clients/1/devices"), "back": H.unescape(back.group(1))}, allow_redirects=False)
    ok("os=" + osp.replace(" ", "+") in r.headers.get("Location", ""), "and its redirect keeps it: " + r.headers.get("Location", "")[:80])

# the counts follow the view tab and the other filters (each count is what choosing it shows)
t = st.get(B + "/devices?" + urlencode({"filter": "attention", "make": top, "limit": 5000})).text
opt = re.findall(r'<option value="([^"]*)"[^>]*>([^<]*) \(([\d,]+)\)</option>', t.split('id="df-os"', 1)[1].split("</select>", 1)[0])
ok(opt and all(len(ids(st.get(B + "/devices?" + urlencode({"filter": "attention", "make": top, "os": H.unescape(v), "limit": 5000})).text)) == int(n.replace(",", "")) for v, _, n in opt[:4]),
   "OS counts follow Needs attention and the make: " + ", ".join(f"{H.unescape(l)} ({n})" for _, l, n in opt[:4]))
mk = re.findall(r'<option value="([^"]*)"[^>]*>[^<]* \(([\d,]+)\)</option>', t.split('id="df-make"', 1)[1].split("</select>", 1)[0])
ok(len(mk) > 1, "the make list still offers the other makes while one is chosen (%d)" % len(mk))
# a stale model is dropped, and the Unassigned badge doesn't follow the filters
t = st.get(B + "/devices?" + urlencode({"make": top, "model": "No Such Model 9000", "limit": 5000})).text
ok(len(ids(t)) == makes[top] and "No Such Model" not in t, "a model the make doesn't have is dropped")
badge = lambda t: re.search(r'Unassigned hardware(?: <span class="badge[^"]*">([\d,]+)</span>)?', t).group(1)
ok(badge(st.get(B + "/devices").text) == badge(st.get(B + "/devices?" + urlencode({"make": top})).text), "the Unassigned hardware badge counts every device")
ok(pv('echo Align\\Lifecycle\\DeviceFilters::replace(["project" => ["id" => 1], "replace_due" => "2001-01-01"]), ",", Align\\Lifecycle\\DeviceFilters::replace(["project" => null, "replace_due" => date("Y-m-d")]), ",", Align\\Lifecycle\\DeviceFilters::replace(["project" => null, "replace_due" => null]);')
   == "project,overdue,none", "replacement: in a project, due today is overdue, nothing planned")
# one client picked on the all-clients list: Backup only when that client has a backup tool
nob = pv('foreach (Align\\DB::all("SELECT id FROM clients WHERE is_archived = 0 AND planning_excluded = 0") as $c) { if (!Align\\Providers\\ClientLinks::backupCompanyUids((int) $c["id"]) && (new Align\\Lifecycle\\Lifecycle())->devices((int) $c["id"])) { echo $c["id"]; break; } }')
if nob:
    ok('name="backup"' not in st.get(B + "/devices?client=" + nob).text, "a client without a backup tool has no Backup filter on the all-clients list either (client #%s)" % nob)
ok('name="backup"' in st.get(B + "/devices?client=1").text, "and client #1 (with one) does")

# viewers can filter
v = login("viewer@example.com", "ViewerPassword123!")
ok(v.get(B + "/devices?" + urlencode({"make": top})).status_code == 200, "viewers can filter too")

# ---- in the browser: open the panel, pick a make, apply
from playwright.sync_api import sync_playwright
with sync_playwright() as pw:
    br = pw.chromium.launch(); pg = br.new_page(viewport={"width": 1360, "height": 900})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
    pg.goto(B + "/devices")
    pg.click("button[data-bs-target='#device-filters']"); pg.wait_for_selector("#device-filters.show"); pg.wait_for_timeout(300)
    pg.select_option("#df-make", top)
    with pg.expect_navigation(): pg.click("#device-filters button.btn-primary")
    ok("make=" in pg.url and pg.locator("[data-filter-chips]").count() == 1, "pick a make in the panel and apply: " + pg.url[-60:])
    ok("warranty=" not in pg.url and "q=" not in pg.url, "fields left on Any stay out of the address: " + pg.url[len(B):])
    pg.click("button[data-bs-target='#device-filters']"); pg.wait_for_selector("#device-filters.show"); pg.wait_for_timeout(300)
    models = [o for o in pg.eval_on_selector_all("#df-model option", "os => os.map(o => o.value)") if o]
    ok(len(models) > 0, "after a make, the model list appears (%d models)" % len(models))
    pg.select_option("#df-model", models[0])
    with pg.expect_navigation(): pg.click("#device-filters button.btn-primary")
    ok("model=" in pg.url and pg.locator("[data-filter-chips] a.badge").count() == 2, "make and model together")
    other = [m for m in makes if m and m != top][0]
    pg.click("button[data-bs-target='#device-filters']"); pg.wait_for_selector("#device-filters.show"); pg.wait_for_timeout(300)
    pg.select_option("#df-make", other)
    with pg.expect_navigation(): pg.click("#device-filters button.btn-primary")
    ok("model=" not in pg.url and pg.locator("[data-filter-chips] a.badge").count() == 1, "a new make drops the old model")
    pg.goto(B + "/devices?" + urlencode({"make": top}))
    with pg.expect_navigation(): pg.locator("[data-filter-chips] a.badge").first.click()
    ok("make=" not in pg.url and pg.locator("[data-filter-chips]").count() == 0, "removing the chip clears it")
    pg.set_viewport_size({"width": 390, "height": 844}); pg.goto(B + "/devices?" + urlencode({"make": top}))
    ok(pg.evaluate("document.documentElement.scrollWidth<=window.innerWidth+1"), "no sideways scroll on a phone")
    ok(not errors, "no script errors: " + str(errors[:2]))
    br.close()
done()
