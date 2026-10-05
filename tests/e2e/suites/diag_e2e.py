"""2.2.3 Settings → Diagnostics: server, app, database, storage, data and background-job health for admins, recent
problems, and a text report to share (without the site address or error text)."""
from lib import *
import re, html as H

def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def row(t, label):
    m = re.search(r'<li class="list-group-item d-flex gap-3" data-status="(\w+)"><span class="diag-label text-muted">' + re.escape(H.escape(label)) + r'</span>(.*?)</li>', t, re.S)
    return (m.group(1), text(m.group(2)).strip()) if m else (None, "")

st = login("admin@example.com", "LongPassword123!")
q("delete from sync_runs where summary like 'DIAGTEST%%'")
q("delete from mail_queue where subject like 'DIAGTEST%%'")

# ---- the page
r = st.get(B + "/settings/diagnostics")
t = r.text
ok(r.status_code == 200 and not errs(t) and 'href="/settings/diagnostics"' in t and "Diagnostics" in t, "admins see the Diagnostics tab")
tiles = dict(re.findall(r'data-tile="(\w+)" data-status="(\w+)"', t))
ok(set(tiles) == {"server", "app", "database", "storage", "jobs"} and all(v in ("ok", "warn", "bad") for v in tiles.values()), "five summary tiles, each with a status: " + str(tiles))
for card in ["server", "app", "database", "storage", "jobs", "data", "tables", "problems"]:
    ok(f'id="diag-{card}"' in t, f"the {card} card is there")
s, v = row(t, "Connection"); ok(s == "ok" and "Connected" in v, "database: connected")
s, v = row(t, "Database updates"); ok(s == "ok" and "All applied" in v, "every database update is applied: " + v)
s, v = row(t, "Size"); ok(re.search(r"\d+(\.\d)? (KB|MB|GB) \(data", v) is not None and "tables" in v, "database size: " + v)
s, v = row(t, "Version"); ok(open(ROOT + "/VERSION").read().strip() in v, "app version: " + v)
s, v = row(t, "PHP extensions"); ok(s == "ok", "the PHP extensions Align needs are loaded: " + v)
s, v = row(t, "Debug mode"); ok(v.startswith("Off") or v.startswith("On"), "debug mode is reported: " + v)
s, v = row(t, "Data disk"); ok(" free of " in v and "% used" in v, "free disk space: " + v)
s, v = row(t, "Memory"); ok(" used of " in v or not v, "memory use (where /proc is readable): " + v)
s, v = row(t, "Audit log check (nightly)"); ok(s in ("ok", "warn", "bad") and v, "the nightly audit check is reported: " + v)
n_clients = q("select count(*) n from clients where is_archived = 0")[0]["n"]
ok(re.search(r"<td>Clients</td><td class=\"text-end font-monospace\">" + format(n_clients, ",") + "<", t) is not None, "exact client count (%d)" % n_clients)
big = q("select table_name t from information_schema.tables where table_schema = database() order by data_length + index_length desc limit 1")[0]["t"]
ok(f'<td class="font-monospace small">{big}</td>' in t, "largest tables listed, biggest first: " + big)
ok("app_key" not in t.split('id="diag-app"')[1].split("</div></div>")[0].replace("Encryption key (app_key)", ""), "the encryption key itself is never shown")

# ---- problems show up, and the jobs card notices
q("insert into sync_runs (started_at, finished_at, status, triggered_by, summary) values (now() - interval 5 minute, now() - interval 4 minute, 'failed', 'schedule', 'DIAGTEST Zz Secret Client: ITFlow said no')")
q("insert into mail_queue (kind, recipients, subject, body_html, status, attempts, send_after, created_at) values ('test', '[]', 'DIAGTEST', '', 'queued', 0, now() - interval 1 hour, now() - interval 1 hour)")
was_mail = q("select value from settings where name = 'mail_mode'")
t = st.get(B + "/settings/diagnostics").text
ok("Sync failed" in t and "Zz Secret Client: ITFlow said no" in text(t), "a failed sync shows under problems, with its error")
s, v = row(t, "Sync (hourly)"); ok(s == "bad" and v.startswith("Failed"), "and the sync row turns red: " + v)
s, v = row(t, "Email (every minute)")
ok((s == "bad" and "waiting" in v) or "isn't set up" in v, "an email waiting an hour means the sender isn't running: " + v)
rep = st.get(B + "/settings/diagnostics/report")
ok(rep.status_code == 200 and rep.headers.get("Content-Type", "").startswith("text/plain") and "attachment" in rep.headers.get("Content-Disposition", ""), "the report downloads as a text file")
ok("MSP Align diagnostics" in rep.text and "== Database" in rep.text and "== Background jobs" in rep.text and "Sync failed" in rep.text, "it has every section and lists the problem")
ok("Zz Secret Client" not in rep.text and B.split("//")[1] not in rep.text and "example.com" not in rep.text, "but not the error text or the site address")
ok("Zz Secret Client" not in H.unescape(t.split('id="diag-report"')[1]), "the copy box holds the same report")
q("delete from sync_runs where summary like 'DIAGTEST%%'")
q("delete from mail_queue where subject like 'DIAGTEST%%'")

# ---- who and audit
ok(q("select count(*) n from audit_log where action = 'view.diagnostics'")[0]["n"] >= 1, "viewing it is audited")
ok(q("select count(*) n from audit_log where action = 'diagnostics.report'")[0]["n"] >= 1, "downloading the report is audited")
for who, pw in [("viewer@example.com", "ViewerPassword123!"), ("tech@example.com", TECH_PASSWORD)]:
    u = login(who, pw)
    ok(u.get(B + "/settings/diagnostics").status_code == 403 and u.get(B + "/settings/diagnostics/report").status_code == 403, f"{who.split('@')[0]}s can't open it")

# ---- in the browser: phone width, the copy box
from playwright.sync_api import sync_playwright
with sync_playwright() as pw:
    br = pw.chromium.launch(); pg = br.new_page(viewport={"width": 390, "height": 844})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
    pg.goto(B + "/settings/diagnostics"); pg.wait_for_timeout(300)
    ok(pg.evaluate("document.documentElement.scrollWidth<=window.innerWidth+1"), "no sideways scroll on a phone")
    pg.click("#diag-report-box summary"); pg.wait_for_timeout(200)
    ok(pg.locator("#diag-report").is_visible() and "MSP Align diagnostics" in pg.locator("#diag-report").input_value(), "opening the report box shows the report")
    ok(not errors, "no script errors: " + str(errors[:2]))
    br.close()
done()
