"""2.0.1: "are you sure?" before wide, outside or destructive changes, in an in-app dialog; Enter in a form never runs
its delete button; a form with unsaved changes asks before its window closes or the page is left."""
from lib import *
from playwright.sync_api import sync_playwright

prj = q("select r.id, r.title, r.client_id from roadmap_items r join clients c on c.id = r.client_id where r.status in ('proposed','approved','scheduled') and c.is_archived = 0 and c.planning_excluded = 0 order by r.id limit 1")[0]
cid = q("select d.client_id from devices d join clients c on c.id = d.client_id where d.removed_at is null and c.is_archived = 0 and c.planning_excluded = 0 group by d.client_id having count(*) >= 3 order by d.client_id limit 1")[0]["client_id"]
techu = q("select id, role from users where email = 'tech@example.com'")[0]
life0 = q("select value from settings where name = 'lifespan_laptop'")
life0 = life0[0]["value"] if life0 else None

def dialog(pg):
    pg.wait_for_selector("#align-confirm.show")
    return pg.locator("#align-confirm-title").inner_text(), pg.locator("#align-confirm-body").inner_text()
def cancel(pg):
    pg.click("#align-confirm button:has-text('Cancel')"); pg.wait_for_selector("#align-confirm", state="hidden"); pg.wait_for_timeout(300)
def go(pg):
    pg.click("#align-confirm [data-align-confirm-ok]")

with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1440, "height": 900})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
    __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()

    # ---- a delete button asks in the app's own dialog; Cancel keeps everything
    sel = f"#modal-roadmap-{prj['id']}"
    pg.goto(B + "/projects"); pg.locator(f'[data-bs-target="{sel}"]').first.click(); pg.wait_for_selector(sel + ".show"); pg.wait_for_timeout(500)
    pg.locator(sel + " button[value=delete]").click()
    title, body = dialog(pg)
    ok(title == "Delete this project?" and "roadmap" in body and pg.locator("#align-confirm [data-align-confirm-ok]").inner_text().strip() == "Delete"
       and "btn-danger" in pg.locator("#align-confirm [data-align-confirm-ok]").get_attribute("class"), "Delete asks in an in-app dialog, with a red Delete button: " + title)
    ok(pg.evaluate("document.querySelectorAll('.modal.show').length") == 2, "the dialog opens over the project's window")
    cancel(pg)
    ok(q("select id from roadmap_items where id=%s", prj["id"]) and pg.locator(sel).is_visible(), "Cancel: the project stays, and its window stays open")
    # Cancel clicked while the dialog is still fading in isn't lost (Bootstrap ignores hide() mid-fade)
    pg.locator(sel + " button[value=delete]").click()
    pg.evaluate("document.querySelector('#align-confirm [data-align-confirm-cancel]').click()")
    pg.wait_for_selector("#align-confirm", state="hidden", timeout=3000); pg.wait_for_timeout(300)
    ok(q("select id from roadmap_items where id=%s", prj["id"]) and pg.locator(sel).is_visible(), "a quick Cancel, before the dialog has finished opening, still cancels")

    # ---- Enter in a field saves; it never runs the Delete button that comes first in the form
    pg.fill(sel + " input[name=title]", prj["title"] + " (edited)")
    pg.press(sel + " input[name=title]", "Enter"); pg.wait_for_load_state(); pg.wait_for_timeout(500)
    row = q("select title from roadmap_items where id=%s", prj["id"])
    ok(row and row[0]["title"] == prj["title"] + " (edited)" and not pg.locator("#align-confirm.show").count(), "Enter saves the project (not Delete, no question)")

    # ---- unsaved changes: closing the window asks first
    pg.goto(B + "/projects"); pg.locator(f'[data-bs-target="{sel}"]').first.click(); pg.wait_for_selector(sel + ".show"); pg.wait_for_timeout(500)
    pg.fill(sel + " input[name=title]", "Typed but not saved")
    pg.keyboard.press("Escape")
    title, body = dialog(pg)
    ok(title == "Discard your changes?", "closing a window with typing in it asks first")
    cancel(pg)
    ok(pg.locator(sel).is_visible() and pg.input_value(sel + " input[name=title]") == "Typed but not saved", "Cancel: the window and the typing stay")
    pg.keyboard.press("Escape"); dialog(pg); go(pg); pg.wait_for_selector(sel, state="hidden")
    ok(q("select title from roadmap_items where id=%s", prj["id"])[0]["title"] == prj["title"] + " (edited)", "Discard: the window closes and nothing is saved")
    pg.locator(f'[data-bs-target="{sel}"]').first.click(); pg.wait_for_selector(sel + ".show"); pg.wait_for_timeout(500)
    ok(pg.input_value(sel + " input[name=title]") == prj["title"] + " (edited)", "opened again, the form shows what's saved")
    pg.keyboard.press("Escape"); pg.wait_for_selector(sel, state="hidden")
    ok(not pg.locator("#align-confirm.show").count(), "closing without changes doesn't ask")
    q("update roadmap_items set title=%s where id=%s", prj["title"], prj["id"])

    # ---- wide changes say how many: the lifecycle policy recalculates devices
    pg.goto(B + "/settings/planning")
    pg.fill("input[name=lifespan_laptop]", str(int(float(life0 or 4)) + 2))
    pg.click("form[action='/settings'] button.btn-primary")
    title, body = dialog(pg)
    ok(title == "Change the lifecycle policy?" and "Laptops" in body and "device" in body, "changing a lifespan says how many devices are recalculated: " + body[:120])
    cancel(pg)
    now = q("select value from settings where name = 'lifespan_laptop'")
    ok((now[0]["value"] if now else None) == life0, "Cancel: the policy isn't changed")
    pg.goto(B + "/settings/planning")
    pg.fill("input[name=warranty_warn_days]", pg.input_value("input[name=warranty_warn_days]"))
    pg.click("form[action='/settings'] button.btn-primary"); pg.wait_for_load_state(); pg.wait_for_timeout(300)
    ok(not pg.locator("#align-confirm.show").count() and "/settings" in pg.url, "saving without a wide change doesn't ask")

    # ---- bulk: the count of what's ticked
    pg.goto(B + f"/clients/{cid}/devices")
    boxes = pg.locator('input[name="ids[]"][form="bulk-replace"]')
    boxes.nth(0).check(); boxes.nth(1).check()
    pg.click("#bulk-replace button.btn-primary")
    title, body = dialog(pg)
    ok(title == "Change the replacement plan for 2 devices?" and "roadmap" in body, "bulk replacement says how many devices: " + title)
    cancel(pg)

    # ---- a role change asks, and Cancel puts the old role back
    pg.goto(B + "/users")
    rsel = f"form[action='/users/{techu['id']}'] select[name=role]"
    pg.select_option(rsel, "viewer")
    title, body = dialog(pg)
    ok("from Tech to Viewer" in title, "changing a role asks: " + title)
    cancel(pg)
    ok(pg.input_value(rsel) == "tech" and q("select role from users where id=%s", techu["id"])[0]["role"] == techu["role"], "Cancel puts the old role back; nothing saved")

    # ---- writes to the PSA: turning on overwrite of warranty dates in ITFlow asks
    pg.goto(B + "/integrations/itflow")
    if pg.locator("select[name=psa_writeback]").count():
        was = pg.input_value("select[name=psa_writeback]")
        if was != "overwrite":
            pg.select_option("select[name=psa_writeback]", "overwrite")
            pg.click("form[action='/integrations/itflow'] button.btn-primary")
            title, body = dialog(pg)
            ok("ITFlow" in title and "overwrite" in body, "turning on overwrite in ITFlow asks: " + body[:100])
            cancel(pg)
            wb = q("select value from settings where name='psa_writeback'")
            ok((wb[0]["value"] if wb else "off") != "overwrite", "Cancel: not saved")

    # ---- leaving a page with unsaved changes asks (the browser's own question)
    fw = q("select framework_id from client_frameworks where client_id = %s limit 1", prj["client_id"]) or q("select client_id, framework_id from client_frameworks limit 1")
    ccid = prj["client_id"] if q("select 1 from client_frameworks where client_id = %s", prj["client_id"]) else fw[0]["client_id"]
    pg.goto(B + f"/clients/{ccid}/compliance/{fw[0]['framework_id']}")
    first = pg.locator("#checklist-form textarea:visible, #checklist-form input[type=text]:visible").first
    first.click(); pg.keyboard.press("End"); pg.keyboard.type(" note")   # typed by hand: the browser only asks after real input
    ok(pg.evaluate("formDirty(document.querySelector('#checklist-form'))"), "the checklist knows it has unsaved changes")
    kinds = []
    pg.on("dialog", lambda d: (kinds.append(d.type), d.dismiss()))
    pg.close(run_before_unload=True)
    import time; time.sleep(1)
    ok("beforeunload" in kinds, "leaving it asks first: " + str(kinds))
    ok(not errors, "no script errors: " + "; ".join(errors[:3]))
    b.close()

# the server side is unchanged: these are questions in the browser only
ok(q("select count(*) n from roadmap_items where id=%s", prj["id"])[0]["n"] == 1, "nothing was deleted along the way")
done()
