"""2.1: devices due for replacement become projects (one per device, or one for several), with a QUOTE- ticket in the PSA.
A project's devices leave the automatic replacement plan, so the budget counts the project instead, never both."""
from lib import *
import json, re, html as H
from datetime import date, timedelta
from playwright.sync_api import sync_playwright

def mock(path, body=None): return requests.post(M + path, json=body or {}).json()
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def devs():
    out = php('echo json_encode(array_map(fn($d) => ["id" => (int) $d["id"], "name" => $d["name"], "cost" => (float) $d["replacement_cost"], "by" => $d["replace_by"],'
              ' "due" => $d["replace_due"], "project" => $d["project"]["id"] ?? null], array_values(array_filter((new Align\\Lifecycle\\Lifecycle())->devices(1),'
              ' fn($d) => $d["is_hardware"] && $d["status"] !== "excluded"))));').stdout
    return {d["id"]: d for d in json.loads(out)}
def budget():
    b = json.loads(php('$b = Align\\Budget\\Budget::build(1); echo json_encode(["total" => array_sum(array_map(fn($l) => array_sum($l["q"]), $b["lines"])),'
                       ' "hw" => array_sum(array_map(fn($l) => $l["category"] === "hardware" ? $l["count"] : 0, $b["lines"]))]);').stdout)
    return round(b["total"], 2), b["hw"]

# a clean start: no projects made from devices, quote tickets off, no device on a hand-set quarter for the ones used
q("delete ri from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id")
mock("/mock/ticket-create-fail", {"on": False})
all_d = devs()
inplan = [d for d in all_d.values() if d["by"] and php(f'echo json_encode(Align\\Roadmap\\Plan::indexFor("{d["by"]}"));').stdout.strip() != "null"]
ok(len(inplan) >= 4, "the test client has at least 4 devices in the 3-year plan: %d" % len(inplan))
d1, d2, d3, d4 = inplan[:4]
q("update device_overrides set replace_on = null, replace_note = null where device_id in (%s, %s, %s, %s)", d1["id"], d2["id"], d3["id"], d4["id"])
all_d = devs(); d1, d2, d3, d4 = (all_d[x["id"]] for x in (d1, d2, d3, d4))
total0, hw0 = budget()
before = len(mock("/mock/tickets-created")["created"])

st = login("admin@example.com", "LongPassword123!")
tok = lambda: csrf(st, "/clients/1/devices")

# ---- one project per device, with quote tickets
r = st.post(B + "/clients/1/devices/projects", data={"_csrf": tok(), "ids[]": [d1["id"], d2["id"]], "mode": "each", "status": "approved", "ticket": "1", "back": "/clients/1/devices"})
ok("Made 2 projects" in flash(r.text) and "quote tickets" in flash(r.text), "two projects made, one per device: " + flash(r.text)[:200])
p1 = q("select ri.* from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id where rid.device_id = %s", d1["id"])
p2 = q("select ri.* from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id where rid.device_id = %s", d2["id"])
ok(len(p1) == 1 and len(p2) == 1 and p1[0]["id"] != p2[0]["id"], "each device has its own project")
p1 = p1[0]
cur = php('echo Align\\Roadmap\\Plan::quarters()[Align\\Roadmap\\Plan::currentIndex()]["start"];').stdout.strip()
want_q = max(cur, php(f'echo Align\\Roadmap\\Plan::quarterFor("{d1["due"]}")["start"];').stdout.strip())
ok(p1["title"].startswith("Replace ") and d1["name"] in p1["title"] and float(p1["cost"]) == round(d1["cost"], 2) and str(p1["target_quarter"]) == want_q
   and p1["status"] == "approved" and p1["category"] == "hardware", "the project: its name, budgeted cost, replacement quarter, approved: " + str({k: p1[k] for k in ("title", "cost", "target_quarter", "status")}))
created = mock("/mock/tickets-created")["created"][before:]
ok(len(created) == 2 and all(t["ticket_subject"].startswith("QUOTE- Replace ") for t in created) and all(not t.get("ticket_contact_id") for t in created)
   and any(d1["name"] in t["ticket_subject"] for t in created), "two QUOTE- tickets, with no client contact: " + str([t["ticket_subject"] for t in created]))
t1 = next(t for t in created if d1["name"] in t["ticket_subject"])
ok(str(p1["psa_ticket_id"]) == str(t1["ticket_id"]) and "Budgeted" in t1["ticket_details"] and d1["name"] in t1["ticket_details"] and ("modal-roadmap-%d" % p1["id"] in t1["ticket_details"] or not php('echo Align\\Config::get("base_url", "");').stdout.strip()),
   "the ticket number is kept on the project; the ticket lists the device, its budget and a link to the project")

# ---- nothing counts twice
total1, hw1 = budget()
ok(abs(total1 - total0) < 0.01 and hw1 == hw0 - 2, "the budget total is unchanged (%s → %s) and 2 devices left the hardware lines (%d → %d)" % (total0, total1, hw0, hw1))
now = devs()
ok(now[d1["id"]]["by"] is None and now[d1["id"]]["project"] == p1["id"], "the device is out of the automatic plan and knows its project")
api_k = php('$k=Align\\Api\\Keys::create("devproj", Align\\Api\\Keys::allScopes(), null, null, 600, null, 1); echo $k[1];').stdout.strip()
h = {"Authorization": "Bearer " + api_k}
ad = requests.get(B + f"/api/v1/devices/{d1['id']}", headers=h).json().get("data", {})
ok((ad.get("lifecycle") or {}).get("project", {}).get("id") == p1["id"], "the API shows the device's project: " + str((ad.get("lifecycle") or {}).get("project"))[:120])
ap = requests.get(B + f"/api/v1/projects/{p1['id']}", headers=h).json().get("data", {})
ok(ap.get("device_ids") == [d1["id"]] and ap.get("psa_ticket_id") == str(t1["ticket_id"]), "the API shows the project's devices and quote ticket")

# ---- one project for several, with the quote amount
r = st.post(B + "/clients/1/devices/projects", data={"_csrf": tok(), "ids[]": [d3["id"], d4["id"]], "mode": "together", "status": "scheduled", "cost": "2500",
                                                     "title": "Replace front office PCs", "note": "Quote #Q-1042", "back": "/clients/1/devices"})
ok('Made the project "Replace front office PCs"' in flash(r.text), "one project for two devices: " + flash(r.text)[:120])
pt = q("select ri.*, (select count(*) from roadmap_item_devices x where x.roadmap_item_id = ri.id) n from roadmap_items ri where title = 'Replace front office PCs'")[0]
ok(pt["n"] == 2 and float(pt["cost"]) == 2500 and pt["status"] == "scheduled" and "Quote #Q-1042" in (pt["description"] or "") and pt["psa_ticket_id"] is None,
   "it holds both devices, the quote amount and note; no ticket when not asked for")
total2, hw2 = budget()
ok(abs(total2 - (total1 - d3["cost"] - d4["cost"] + 2500)) < 0.01, "the budget uses the quote amount instead of the two budgeted costs")

# ---- a device already in a project isn't added again
n = q("select count(*) n from roadmap_items")[0]["n"]
r = st.post(B + "/clients/1/devices/projects", data={"_csrf": tok(), "ids[]": [d1["id"]], "mode": "each", "back": "/clients/1/devices"})
ok("already in" in flash(r.text) and q("select count(*) n from roadmap_items")[0]["n"] == n, "a device already in a project is skipped: " + flash(r.text)[:120])

# ---- declined or deleted: the devices go back to their replacement dates
q("update roadmap_items set status = 'declined' where id = %s", p1["id"])
ok(devs()[d1["id"]]["by"] is not None, "declined: the device is back in the automatic plan")
r = st.post(B + f"/clients/1/roadmap/{pt['id']}", data={"_csrf": tok(), "action": "delete"})
ok(not q("select 1 from roadmap_item_devices where roadmap_item_id = %s", pt["id"]) and devs()[d3["id"]]["by"] is not None, "deleted: its devices are back in the plan")

# ---- un-declining a project whose device is in another project now: kept declined
r = st.post(B + "/clients/1/devices/projects", data={"_csrf": tok(), "ids[]": [d1["id"]], "back": "/clients/1/devices"})
pnew = q("select ri.id from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id where rid.device_id = %s and ri.status <> 'declined'", d1["id"])
ok(len(pnew) == 1 and pnew[0]["id"] != p1["id"], "a declined project's device can go into a new project")
r = st.post(B + f"/clients/1/roadmap/{p1['id']}", data={"_csrf": tok(), "action": "save", "title": p1["title"], "status": "approved", "category": "hardware", "priority": "medium"})
ok(q("select status from roadmap_items where id = %s", p1["id"])[0]["status"] == "declined" and "kept as declined" in flash(r.text), "approving the old one again is refused: " + flash(r.text)[:140])
ar = requests.patch(B + f"/api/v1/projects/{p1['id']}", headers=h, json={"status": "approved"})
ok(ar.status_code == 422 and q("select status from roadmap_items where id = %s", p1["id"])[0]["status"] == "declined", "the API refuses it too: %d" % ar.status_code)
# ---- Set replacement skips devices in a project; a quarter outside the choices is ignored; other clients' devices are ignored
r = st.post(B + "/clients/1/devices/replacement", data={"_csrf": tok(), "ids[]": [d1["id"], d4["id"]], "replace_on": list(json.loads(php('echo json_encode(Align\\Roadmap\\Plan::choices(5));').stdout))[2]})
ok("Skipped 1 in a project" in flash(r.text) and not (q("select replace_on from device_overrides where device_id = %s", d1["id"]) or [{"replace_on": None}])[0]["replace_on"],
   "Set replacement skips a device in a project: " + flash(r.text)[:140])
q("update device_overrides set replace_on = null where device_id = %s", d4["id"])
other = q("select d.id from devices d where d.client_id <> 1 and d.removed_at is null limit 1")
if other:
    n = q("select count(*) n from roadmap_items")[0]["n"]
    st.post(B + "/clients/1/devices/projects", data={"_csrf": tok(), "ids[]": [other[0]["id"]], "back": "/clients/1/devices"})
    ok(q("select count(*) n from roadmap_items")[0]["n"] == n, "another client's device can't be added")
r = st.post(B + "/clients/1/devices/projects", data={"_csrf": tok(), "ids[]": [d4["id"]], "quarter": "2026-99-99", "back": "/clients/1/devices"})
p4 = q("select ri.target_quarter from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id where rid.device_id = %s and ri.status <> 'declined'", d4["id"])
ok(p4 and p4[0]["target_quarter"] is not None, "an impossible quarter is ignored: its replacement quarter is used")
ok(not errs(st.get(B + "/").text) and not errs(st.get(B + "/clients").text) and not errs(st.get(B + "/clients/1/report/roadmap").text), "dashboard, client list and roadmap report render with projects from devices")
# ---- done: the devices stay out of the plan only for a while after the project's quarter
pid4 = q("select ri.id from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id where rid.device_id = %s and ri.status <> 'declined'", d4["id"])[0]["id"]
q("update roadmap_items set status = 'done' where id = %s", pid4)
ok(devs()[d4["id"]]["project"] == pid4, "done this quarter: still replaced by the project")
q("update roadmap_items set target_quarter = %s where id = %s", (date.today() - timedelta(days=400)).isoformat(), pid4)
ok(devs()[d4["id"]]["project"] is None and devs()[d4["id"]]["by"] is not None, "done over 9 months ago and the device is still listed: planned again")
r = st.post(B + "/clients/1/devices/projects", data={"_csrf": tok(), "ids[]": [d4["id"]], "back": "/clients/1/devices"})
p4b = q("select ri.id from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id where rid.device_id = %s and ri.status not in ('declined', 'done')", d4["id"])
ok(len(p4b) == 1, "and it can go into a new project: " + flash(r.text)[:120])
q("delete from roadmap_items where id in (%s, %s)", pid4, p4b[0]["id"] if p4b else 0)
# ---- one for several, with one of them taken already: made for the rest
r = st.post(B + "/clients/1/devices/projects", data={"_csrf": tok(), "ids[]": [d1["id"], d4["id"]], "mode": "together", "back": "/clients/1/devices"})
pt2 = q("select ri.id, ri.title, ri.cost from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id where rid.device_id = %s and ri.status <> 'declined'", d4["id"])
ok(pt2 and "already in" in flash(r.text) and q("select count(*) n from roadmap_item_devices where roadmap_item_id = %s", pt2[0]["id"])[0]["n"] == 1 and float(pt2[0]["cost"]) == round(d4["cost"], 2),
   "the device already taken is skipped and the project is made for the rest: " + flash(r.text)[:140])
q("delete from roadmap_items where id = %s", pt2[0]["id"] if pt2 else 0)

# ---- the ticket can fail; the project is still made
mock("/mock/ticket-create-fail", {"on": True})
r = st.post(B + "/clients/1/devices/projects", data={"_csrf": tok(), "ids[]": [d3["id"]], "ticket": "1", "back": f"/devices/{d3['id']}"})
p3 = q("select ri.* from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id where rid.device_id = %s and ri.status <> 'declined'", d3["id"])
ok(p3 and p3[0]["psa_ticket_id"] is None and "wasn't created" in flash(r.text), "the PSA refuses the ticket: the project is made and the message says so: " + flash(r.text)[:160])
mock("/mock/ticket-create-fail", {"on": False})
ok("Make projects" not in "" and q("select 1 from audit_log where action = 'roadmap.quote_ticket' limit 1"), "quote tickets are audited")

# ---- viewers can't
v = login("viewer@example.com", "ViewerPassword123!")
r = v.post(B + "/clients/1/devices/projects", data={"_csrf": csrf(v, "/clients/1"), "ids[]": [d4["id"]]})
ok(r.status_code == 403, "viewers can't make projects")

# ---- in the browser
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1440, "height": 1000})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
    # the device page: its project, and Make a project on one that has none
    pg.goto(B + f"/devices/{d3['id']}"); pg.click("a[href='#lifecycle']") if pg.locator("a[href='#lifecycle']").count() else None
    ok(pg.locator("text=Project").count() and pg.locator(f"a[href='/clients/1/roadmap#modal-roadmap-{p3[0]['id']}']").count(), "the device page shows its project")
    # the devices page: tick two, Make projects…, one for both
    pg.goto(B + "/clients/1/devices?limit=500")
    pg.locator(f"input[name='ids[]'][value='{d2['id']}']").count()
    q("delete ri from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id")
    pg.reload()
    pg.check(f"input[name='ids[]'][value='{d1['id']}']"); pg.check(f"input[name='ids[]'][value='{d2['id']}']")
    pg.click("button[data-bs-target='#modal-make-project']"); pg.wait_for_selector("#modal-make-project.show"); pg.wait_for_timeout(400)
    body = pg.locator("#modal-make-project .modal-body").inner_text()
    ok("2 devices" in body and pg.locator("#modal-make-project [data-pick-many]").is_visible() and not pg.locator("#mp-title").is_visible(),
       "the window counts the ticked devices and their budget, and offers one per device or one for all: " + body[:100])
    pg.check("#mp-together"); pg.wait_for_timeout(100)
    ok(pg.locator("#mp-title").is_visible() and pg.locator("[data-pick-submit]").inner_text().strip() == "Make the project", "one for all: name and cost can be set")
    pg.check("#mp-each"); pg.wait_for_timeout(100)
    ok(pg.locator("[data-pick-submit]").inner_text().strip() == "Make 2 projects", "one per device: Make 2 projects")
    pg.check("#mp-together"); pg.fill("#mp-title", "Replace two PCs together")
    pg.click("[data-pick-submit]")
    pg.wait_for_selector("#align-confirm.show")
    ok(pg.locator("#align-confirm-title").inner_text() == "Make one project for 2 devices?", "it asks first, with the count: " + pg.locator("#align-confirm-title").inner_text())
    with pg.expect_navigation(): pg.click("#align-confirm [data-align-confirm-ok]")
    pg.wait_for_load_state("load"); pg.wait_for_timeout(800)
    pid = q("select id from roadmap_items where title = 'Replace two PCs together'")
    ok(pid and "/clients/1/roadmap" in pg.url and pg.locator(f"#modal-roadmap-{pid[0]['id']}.show").count() == 1, "it opens the new project on the roadmap: " + pg.url)
    ok("Replaces 2 devices" in pg.locator(f"#modal-roadmap-{pid[0]['id']} .modal-body").inner_text(), "the project window lists its devices")
    pg.goto(B + "/clients/1/devices?limit=500")
    ok(pg.locator(f"a[href='/clients/1/roadmap#modal-roadmap-{pid[0]['id']}']").count() == 2, "the device list links both devices to the project")
    # one device from its own page
    pg.goto(B + f"/devices/{d3['id']}")
    if pg.locator("a[href='#lifecycle']").count(): pg.click("a[href='#lifecycle']")
    pg.click("a[data-bs-target='#modal-make-project']"); pg.wait_for_selector("#modal-make-project.show"); pg.wait_for_timeout(400)
    ok(d3["name"] in pg.locator("#modal-make-project .modal-body").inner_text() and pg.locator("#mp-title").is_visible(), "the device page's window is for that device, with name and cost")
    pg.click("[data-pick-submit]"); pg.wait_for_selector("#align-confirm.show")
    ok(pg.locator("#align-confirm-title").inner_text() == f"Make a project to replace {d3['name']}?", "it asks first: " + pg.locator("#align-confirm-title").inner_text())
    with pg.expect_navigation(): pg.click("#align-confirm [data-align-confirm-ok]")
    ok(q("select 1 from roadmap_item_devices rid join roadmap_items ri on ri.id = rid.roadmap_item_id where rid.device_id = %s and ri.status <> 'declined'", d3["id"]), "made from the device page")
    ok(not errors, "no script errors: " + "; ".join(errors[:3]))
    b.close()

q("delete ri from roadmap_items ri join roadmap_item_devices rid on rid.roadmap_item_id = ri.id")
done()
