"""2.23.1 Inviting a client portal user: typing part of a contact's name (in Name) or address (in Email) lists the
client's matching contacts, already-invited ones left out; a click or the keyboard (arrows, Enter) fills both fields,
Escape closes the list without closing the window; names are shown as text."""
from lib import *
from playwright.sync_api import sync_playwright

C = 1
st = login("admin@example.com", "LongPassword123!")
cid = q("insert into contacts (client_id, name, email) values (%s, %s, %s) returning id", C, "<b>Robin</b> Quill", "robin.quill@cedarridgedental.example")[0]["id"]
p = st.get(B + f"/clients/{C}/portal").text
ok('id="invite-contacts"' in p and 'list="invite-contacts"' not in p and "Start typing a name or email" in p and not errs(p), "the invite window carries the contacts for the picker")
ok("robin.quill@cedarridgedental.example" in p and "<b>Robin</b>" not in p, "contact names go into the page escaped")

with sync_playwright() as pw:
    br = pw.chromium.launch(); ctx = br.new_context()
    a = ctx.new_page(); errors = []; a.on("pageerror", lambda e: errors.append(str(e)))
    a.goto(B + "/login"); a.fill("input[name=email]", "admin@example.com"); a.fill("input[name=password]", "LongPassword123!"); a.click("button")
    __import__('sitecustomize').after_login(a, "admin@example.com"); a.wait_for_load_state()
    a.goto(B + f"/clients/{C}/portal"); a.wait_for_load_state()
    a.click("[data-bs-target='#modal-portal-invite']"); a.wait_for_timeout(500)
    a.type("#invite-name", "sam", delay=30); a.wait_for_timeout(200)
    items = a.locator("#invite-picks .list-group-item")
    ok(items.count() == 1 and "Sam Rivera" in items.first.inner_text() and a.is_visible("#invite-picks"), "typing part of a name lists the matching contact")
    items.first.click(); a.wait_for_timeout(200)
    ok(a.input_value("#invite-name") == "Sam Rivera" and a.input_value("#invite-email") == "sam@cedarridgedental.example" and not a.is_visible("#invite-picks"),
       "clicking it fills the name and email")
    a.fill("#invite-name", ""); a.fill("#invite-email", "")
    a.type("#invite-email", "robin", delay=30); a.wait_for_timeout(200)
    ok(a.locator("#invite-picks .list-group-item").count() == 1 and "<b>Robin</b> Quill" in a.locator("#invite-picks").inner_text(), "typing part of an address works too, and a name is shown as text: %s" % a.locator("#invite-picks").inner_text()[:200])
    a.keyboard.press("ArrowDown"); a.keyboard.press("Enter"); a.wait_for_timeout(200)
    ok(a.input_value("#invite-name") == "<b>Robin</b> Quill" and a.input_value("#invite-email") == "robin.quill@cedarridgedental.example" and a.is_visible("#modal-portal-invite"),
       "arrow and Enter pick it without sending the form")
    a.fill("#invite-name", ""); a.type("#invite-name", "e", delay=30); a.wait_for_timeout(200)
    a.keyboard.press("Escape"); a.wait_for_timeout(300)
    ok(not a.is_visible("#invite-picks") and a.is_visible("#modal-portal-invite"), "Escape closes the list, not the window")
    a.fill("#invite-name", ""); a.type("#invite-name", "zzzz", delay=20); a.wait_for_timeout(200)
    ok(not a.is_visible("#invite-picks"), "nothing matching: no list")
    ok(not errors, "no script errors: " + str(errors[:2]))
    br.close()
q("delete from contacts where id=%s", cid)
done()
