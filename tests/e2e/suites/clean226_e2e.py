"""2.2.6 clean-up: an amount too large for its column is refused with a message instead of saving as empty (projects,
devices, licenses); an empty contact list from the PSA archives nothing; the idle clock and signing out are shared by
the tabs of one sign-in; meetings remember who was invited (the .ics cancellation itself is checked in mail_e2e)."""
from lib import *

st = login("admin@example.com", "LongPassword123!")
BIG = "99999999999"   # more than any amount column holds

# ---- projects: a too-large cost is refused and the old one kept
q("delete from roadmap_items where title like 'CLEAN226%%'")
r = st.post(B + "/clients/1/roadmap", data={"_csrf": csrf(st, "/clients/1/roadmap"), "title": "CLEAN226 big", "category": "hardware", "status": "proposed", "cost": BIG})
ok("too large" in flash(r.text) and "Nothing was saved" in flash(r.text) and not q("select 1 from roadmap_items where title='CLEAN226 big'"), "a new project with a too-large cost is refused: " + flash(r.text))
st.post(B + "/clients/1/roadmap", data={"_csrf": csrf(st, "/clients/1/roadmap"), "title": "CLEAN226 ok", "category": "hardware", "status": "proposed", "cost": "1200"})
pid = q("select id from roadmap_items where title='CLEAN226 ok'")[0]["id"]
r = st.post(B + f"/clients/1/roadmap/{pid}", data={"_csrf": csrf(st, "/clients/1/roadmap"), "title": "CLEAN226 ok", "category": "hardware", "status": "proposed", "cost": "1500", "recurring_monthly": BIG})
ok("monthly cost is too large" in flash(r.text) and float(q("select cost from roadmap_items where id=%s", pid)[0]["cost"]) == 1200.0, "editing it with a too-large monthly cost changes nothing (the old cost stays)")
r = st.post(B + f"/clients/1/roadmap/{pid}", data={"_csrf": csrf(st, "/clients/1/roadmap"), "title": "CLEAN226 ok", "category": "hardware", "status": "proposed", "cost": "1500"})
ok(float(q("select cost from roadmap_items where id=%s", pid)[0]["cost"]) == 1500.0, "a normal amount still saves")
q("delete from roadmap_items where title like 'CLEAN226%%'")

# ---- devices: replacement cost
dev = q("select d.id from devices d where d.removed_at is null order by d.id limit 1")[0]["id"]
q("insert into device_overrides (device_id, replacement_cost) values (%s, 900) on duplicate key update replacement_cost = 900", dev)
r = st.post(B + f"/devices/{dev}", data={"_csrf": csrf(st, f"/devices/{dev}"), "replacement_cost": BIG})
ok("replacement cost is too large" in flash(r.text) and float(q("select replacement_cost from device_overrides where device_id=%s", dev)[0]["replacement_cost"]) == 900.0,
   "a too-large replacement cost is refused and the old one kept: " + flash(r.text))

# ---- licenses: price
lic = q("select id from licenses where client_id=1 order by id limit 1")
if lic:
    lid = lic[0]["id"]
    q("update licenses set unit_price = 12.50 where id=%s", lid)
    r = st.post(B + f"/licenses/{lid}", data={"_csrf": csrf(st, "/clients/1/licenses"), "action": "save", "unit_price": BIG, "category": "other", "pricing": "per_seat", "billing_cycle": "monthly"})
    ok("price is too large" in flash(r.text) and float(q("select unit_price from licenses where id=%s", lid)[0]["unit_price"]) == 12.5, "a too-large license price is refused and the old one kept: " + flash(r.text))

# ---- contacts: an empty answer from the PSA archives nothing for a day
q("delete from settings where name='psa_contacts_empty_since'")
before = q("select count(*) n from contacts where psa_id is not null and archived_at is null")[0]["n"]
out = php('echo Align\\Contacts\\Contacts::syncFromPsa([], [], "ITFlow");').stdout
after = q("select count(*) n from contacts where psa_id is not null and archived_at is null")[0]["n"]
ok(before > 0 and after == before and "returned no contacts" in out and "none were archived" in out, "an empty contact list archives nothing: " + out)
ok(q("select value from settings where name='psa_contacts_empty_since'"), "and the first empty answer is noted")
q("update settings set value = date_format(now() - interval 2 day, '%%Y-%%m-%%d %%H:%%i:%%s') where name='psa_contacts_empty_since'")
was = [r["id"] for r in q("select id from contacts where psa_id is not null and archived_at is null")]
out = php('echo Align\\Contacts\\Contacts::syncFromPsa([], [], "ITFlow");').stdout
ok(q("select count(*) n from contacts where psa_id is not null and archived_at is null")[0]["n"] == 0, "after a day of empty answers they're believed: " + out)
if was:
    q("update contacts set archived_at = null, archived_reason = null where id in (" + ",".join(str(i) for i in was) + ")")
q("delete from settings where name='psa_contacts_empty_since'")

# ---- meetings remember who was invited
ok(q("select 1 from information_schema.columns where table_schema = database() and table_name = 'meetings' and column_name = 'invited_to'"), "meetings have the invited_to column (055)")

# ---- in the browser: tabs share the idle clock, and signing out in one signs out the others
from playwright.sync_api import sync_playwright
with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context()
    a = ctx.new_page(); errors = []; a.on("pageerror", lambda e: errors.append(str(e)))
    a.goto(B + "/login"); a.fill("input[name=email]", "admin@example.com"); a.fill("input[name=password]", "LongPassword123!"); a.click("button")
    __import__('sitecustomize').after_login(a, "admin@example.com"); a.wait_for_load_state()
    b = ctx.new_page(); b.goto(B + "/clients"); b.wait_for_load_state()
    t0 = b.evaluate("localStorage.getItem('align-idle-active:/session/ping')")
    a.wait_for_timeout(1200); a.keyboard.press("Shift"); a.wait_for_timeout(200)
    t1 = b.evaluate("localStorage.getItem('align-idle-active:/session/ping')")
    ok(t0 and t1 and int(t1) > int(t0), "activity in one tab is written to the shared idle clock the other tab reads")
    a.evaluate("document.querySelector('form[action=\"/logout\"]').requestSubmit()")
    a.wait_for_url("**/login**", timeout=10000)
    b.wait_for_url("**/login**", timeout=10000)
    ok("/login" in b.url, "signing out in one tab sends the other tab to the sign-in page")
    ok(not errors, "no script errors: " + str(errors[:2]))
    br.close()
done()
