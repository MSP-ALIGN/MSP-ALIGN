"""2.27.0 Feedback: bug reports and feature requests from the top bar. Every staff role sends one (title, details,
priority, kind, the page it came from, an optional PNG/JPEG screenshot) and sees them all on /feedback; admins see
how many are new on the button, mark them planned / done / won't do (or Mark done in the list), set priority and a
note, or delete them. Pages that aren't a path on this site are dropped (and never used to send the person back),
screenshots are checked by content and sent back as images with nosniff and a sandbox CSP, text is escaped, viewers
and techs can't change or delete, each person is limited to 20 an hour, everything is audited. Migration 086 runs
twice; the menu presets the kind and a pasted image becomes the screenshot."""
import base64, os, re, struct, html as H
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
MIG = ROOT + "/db/migrations/086_feedback.php"
DIR = UPLOADS + "/feedback"


def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))


def png(w=40, h=30, fmt="PNG", exif=None):
    """A small image drawn by PHP's GD (as CI has it; no Python imaging library needed); exif: text put into a JPEG's
    EXIF segment (APP1) to check it isn't kept."""
    out = php(f'$i = imagecreatetruecolor({w}, {h}); imagefill($i, 0, 0, imagecolorallocate($i, 30, 120, 200)); ob_start(); '
              + ('imagepng($i);' if fmt == "PNG" else 'imagejpeg($i, null, 90);') + ' echo base64_encode(ob_get_clean());').stdout
    b = base64.b64decode(out)
    if exif:
        seg = b"Exif\0\0MM\0*\0\0\0\x08\0\0" + exif.encode()
        b = b[:2] + b"\xff\xe1" + struct.pack(">H", len(seg) + 2) + seg + b[2:]
    return b


def png_size(path):
    """A PNG's width and height from its IHDR."""
    return struct.unpack(">II", open(path, "rb").read()[16:24])


def files_now():
    return set(os.listdir(DIR)) if os.path.isdir(DIR) else set()


def send(who, files=None, page="/clients", **data):
    d = {"_csrf": csrf(who, "/"), "kind": "bug", "title": "R227 something broke", "body": "", "priority": "normal", "page": page}
    d.update(data)
    return who.post(B + "/feedback", data=d, files=files)


def last():
    r = q("select * from feedback order by id desc limit 1")
    return r[0] if r else None


q("delete from feedback")
try:
    out = php(f'(require "{MIG}")(); (require "{MIG}")(); echo "ok";')
    ok(out.stdout.strip() == "ok", "migration 086 runs twice " + out.stderr[-200:])

    # ======== the button
    for who, name in [(viewer, "viewer"), (tech, "tech"), (st, "admin")]:
        t = who.get(B + "/").text
        ok('id="feedback-menu"' in t and 'data-feedback-kind="bug"' in t and 'data-feedback-kind="feature"' in t and 'id="modal-feedback"' in t and not errs(t),
           f"the top bar has the Feedback menu and form for a {name}")
    ok('id="feedback-badge"' not in st.get(B + "/").text, "no badge while nothing is new")

    # ======== sending
    uid_v = q("select id from users where email='viewer@example.com'")[0]["id"]
    before = files_now()
    r = send(viewer, files={"screenshot": ("shot.png", png() + b"<html><script>alert(1)</script>", "image/png")}, page="/clients/1?tab=devices",
             title="R227 \x07Device list <b>breaks</b>", body="Line one\r\nLine <script>two</script>\x00", priority="high")
    f = last()
    ok(f and f["kind"] == "bug" and f["title"] == "R227 Device list <b>breaks</b>" and f["body"] == "Line one\nLine <script>two</script>"
       and f["priority"] == "high" and f["status"] == "open" and f["page"] == "/clients/1?tab=devices" and f["created_by"] == uid_v,
       f"a viewer sends a bug: control characters removed, text kept as text: {f and {k: f[k] for k in ('title', 'body', 'page', 'priority')}}")
    ok(r.url.endswith("/clients/1?tab=devices") and "Bug report #%d was sent" % f["id"] in flash(r.text), "and goes back to the page it came from: " + flash(r.text)[:80])
    ok(f["file_type"] == "png" and re.fullmatch(r"fb-[a-f0-9]{16}\.png", f["file_name"]) and os.path.isfile(DIR + "/" + f["file_name"]), "the screenshot is stored under a random name")
    ok(b"<script>" not in open(DIR + "/" + f["file_name"], "rb").read() and png_size(DIR + "/" + f["file_name"]) == (40, 30), "re-encoded: bytes hidden after the image are gone")
    bug = f["id"]
    photo = png(fmt="JPEG", exif="R227 secret place")
    ok(b"R227 secret place" in photo and photo[:2] == b"\xff\xd8", "(the test photo carries EXIF text)")
    r = send(tech, files={"screenshot": ("photo.jpeg", photo, "image/jpeg")}, kind="feature", title="R227 Dark mode for reports", priority="nonsense", page="//evil.example/x")
    f = last()
    ok(f["kind"] == "feature" and f["priority"] == "normal" and f["page"] is None and f["file_type"] == "jpg", "a tech requests a feature (JPEG); an unknown priority is normal")
    ok(b"R227 secret place" not in open(DIR + "/" + f["file_name"], "rb").read(), "a photo's metadata isn't kept")
    ok(r.url.rstrip("/").endswith("/feedback") and "evil" not in r.url, "a page that isn't a path on this site is dropped and never used to send them back")
    feat = f["id"]
    for page in ["https://evil.example/", "/\\evil.example", "javascript:alert(1)", "/ok path", "/" + "a" * 600]:
        send(tech, page=page, title="R227 page check")
        ok(last()["page"] is None, f"page {page[:40]!r} is dropped")
    send(tech, page="/integrations/m365/callback?code=SECRET1&state=S2&tab=x&Client_Secret=S3", title="R227 page check", body="0")
    ok(last()["page"] == "/integrations/m365/callback?tab=x" and last()["body"] == "0", f"sign-in codes and secrets are left out of the page: {last()['page']}; a body of 0 is kept")
    q("delete from feedback where title='R227 page check'")

    n = q("select count(*) n from feedback")[0]["n"]
    for files, data, why in [(None, {"title": "   "}, "Give it a short title"),
                             (None, {"kind": "praise"}, "Choose bug or feature"),
                             ({"screenshot": ("shot.png", b"<html><script>alert(1)</script>", "image/png")}, {}, "PNG or JPEG"),
                             ({"screenshot": ("shot.gif", b"GIF89a" + b"\0" * 40, "image/gif")}, {}, "PNG or JPEG"),
                             ({"screenshot": ("big.png", png() + b"\0" * (5 * 1024 * 1024), "image/png")}, {}, "up to 5 MB")]:
        r = send(viewer, files=files, **data)
        ok(why in flash(r.text) and q("select count(*) n from feedback")[0]["n"] == n, f"refused: {why}")
    ok(len(files_now() - before) == 2, f"refused screenshots leave no file behind ({len(files_now() - before)} new)")

    # ======== the list and a report, for everyone
    t = viewer.get(B + "/feedback").text
    ok("R227 Device list" in t and "R227 Dark mode for reports" in t and "&lt;b&gt;breaks&lt;/b&gt;" in t and "<b>breaks</b>" not in t and not errs(t),
       "a viewer sees every report (theirs and others'), escaped")
    ok("Mark done" not in t, "with no Mark done buttons")
    t = viewer.get(B + "/feedback?kind=feature").text
    ok("R227 Dark mode" in t and "R227 Device list" not in t, "filtered to feature requests")
    t = viewer.get(B + f"/feedback/{bug}").text
    ok("&lt;script&gt;two&lt;/script&gt;" in t and "<script>two</script>" not in t and f'/feedback/{bug}/screenshot' in t and "/clients/1?tab=devices" in H.unescape(t)
       and 'name="status"' not in t and not errs(t), "the report page shows details, page and screenshot, escaped; no admin form for a viewer")
    r = viewer.get(B + f"/feedback/{bug}/screenshot")
    ok(r.status_code == 200 and r.headers["Content-Type"] == "image/png" and r.headers.get("X-Content-Type-Options") == "nosniff"
       and "sandbox" in r.headers.get("Content-Security-Policy", "") and r.content[:8] == b"\x89PNG\r\n\x1a\n", "the screenshot is sent as an image with nosniff and a sandbox CSP")
    import requests
    anon = requests.get(B + f"/feedback/{bug}/screenshot", allow_redirects=False)
    ok(anon.status_code in (302, 303) and "/login" in anon.headers.get("Location", ""), "not without signing in")
    ok("/login" in requests.get(B + "/feedback", allow_redirects=False).headers.get("Location", ""), "the list needs a sign-in too")

    # ======== viewers and techs can't change it
    for who, name in [(viewer, "viewer"), (tech, "tech")]:
        who.post(B + f"/feedback/{bug}", data={"_csrf": csrf(who, "/feedback"), "status": "done"})
        who.post(B + f"/feedback/{bug}/delete", data={"_csrf": csrf(who, "/feedback")})
        ok(q("select status from feedback where id=%s", bug)[0]["status"] == "open", f"a {name} can't change or delete a report")
    r = st.post(B + f"/feedback/{bug}", data={"status": "done"}, allow_redirects=False)
    ok(q("select status from feedback where id=%s", bug)[0]["status"] == "open", "not without the CSRF token")

    # ======== admins
    t = st.get(B + "/").text
    ok('id="feedback-badge"' in t and re.search(r'id="feedback-badge">2<', t), "an admin sees 2 new on the button")
    ok('id="feedback-badge"' not in tech.get(B + "/").text, "a tech doesn't")
    t = st.get(B + "/feedback").text
    ok(t.count("Mark done") >= 2 and not errs(t), "the list has Mark done for an admin")
    uid_a = q("select id from users where email='admin@example.com'")[0]["id"]
    st.post(B + f"/feedback/{feat}", data={"_csrf": csrf(st, "/feedback"), "status": "planned", "priority": "high", "admin_note": "Next release\r\nprobably"})
    f = q("select * from feedback where id=%s", feat)[0]
    ok(f["status"] == "planned" and f["priority"] == "high" and f["admin_note"] == "Next release\nprobably" and f["closed_at"] is None, "an admin plans one, with a note")
    r = st.post(B + f"/feedback/{feat}", data={"_csrf": csrf(st, "/feedback"), "status": "done", "back": "list"})
    f = q("select * from feedback where id=%s", feat)[0]
    ok(f["status"] == "done" and f["closed_by"] == uid_a and f["closed_at"] and f["admin_note"] == "Next release\nprobably" and f["priority"] == "high" and r.url.rstrip("/").endswith("/feedback"),
       "Mark done from the list: closed by the admin, note and priority kept")
    closed_at = f["closed_at"]
    st.post(B + f"/feedback/{feat}", data={"_csrf": csrf(st, "/feedback"), "status": "done", "admin_note": "Shipped in 2.27.0"})
    f = q("select * from feedback where id=%s", feat)[0]
    ok(f["closed_at"] == closed_at and f["admin_note"] == "Shipped in 2.27.0", "editing the note of a done one keeps when it was closed")
    r = st.post(B + f"/feedback/{feat}", data={"_csrf": csrf(st, "/feedback"), "status": "done", "admin_note": "Shipped in 2.27.0"})
    ok("No changes" in flash(r.text), "saving nothing new says so")
    t = viewer.get(B + "/feedback?show=closed").text
    ok("R227 Dark mode" in t and "Shipped in 2.27.0" in t and "R227 Device list" not in t, "the Closed tab shows it with the note, for everyone")
    ok("R227 Dark mode" not in viewer.get(B + "/feedback").text, "and it's off the Open tab")
    st.post(B + f"/feedback/{feat}", data={"_csrf": csrf(st, "/feedback"), "status": "open", "admin_note": ""})
    f = q("select * from feedback where id=%s", feat)[0]
    ok(f["status"] == "open" and f["closed_at"] is None and f["closed_by"] is None and f["admin_note"] is None, "reopened: closed date and note cleared")
    st.post(B + f"/feedback/{feat}", data={"_csrf": csrf(st, "/feedback"), "status": "declined"})
    t = st.get(B + f"/feedback/{feat}").text
    ok("Won&#039;t do" in t or "Won't do" in t, "won't do")
    st.post(B + f"/feedback/{feat}", data={"_csrf": csrf(st, "/feedback"), "status": "bogus", "priority": "urgent"})
    f = q("select * from feedback where id=%s", feat)[0]
    ok(f["status"] == "declined" and f["priority"] == "high", "an unknown status or priority changes nothing")
    ok(re.search(r'id="feedback-badge">1<', st.get(B + "/").text), "the badge counts only new (open) ones")

    # ======== delete
    name = q("select file_name from feedback where id=%s", bug)[0]["file_name"]
    r = st.post(B + f"/feedback/{bug}/delete", data={"_csrf": csrf(st, f"/feedback/{bug}")})
    ok(not q("select 1 from feedback where id=%s", bug) and not os.path.exists(DIR + "/" + name), "an admin deletes one; its screenshot goes too")
    ok("no longer exists" in flash(st.get(B + f"/feedback/{bug}").text), "a deleted report says so")
    r = st.get(B + f"/feedback/{bug}/screenshot", allow_redirects=False)
    ok(r.status_code in (302, 303, 404) and not r.headers.get("Content-Type", "").startswith("image"), "and its screenshot is gone")

    acts = [r["action"] for r in q("select action from audit_log where action like 'feedback.%%'")]
    ok({"feedback.create", "feedback.update", "feedback.delete"} <= set(acts), f"audited: {sorted(set(acts))}")

    # ======== 20 an hour
    for i in range(20 - q("select count(*) n from feedback where created_by=%s and created_at > now() - interval 1 hour", uid_v)[0]["n"]):
        q("insert into feedback (kind, title, created_by, created_name) values ('bug', 'R227 flood', %s, 'x')", uid_v)
    n = q("select count(*) n from feedback")[0]["n"]
    r = send(viewer, title="R227 one too many")
    ok("a lot of reports" in flash(r.text) and q("select count(*) n from feedback")[0]["n"] == n, "the 21st report in an hour is refused")
    q("delete from feedback where title='R227 flood'")

    # ======== help
    ok('id="guide-feedback"' in viewer.get(B + "/help").text, "Help has the Feedback guide")

    # ======== in the browser: the menu picks the kind, a pasted image becomes the screenshot
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        br = pw.chromium.launch(); pg = br.new_page(viewport={"width": 1300, "height": 900})
        errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
        pg.goto(B + "/login"); pg.fill("input[name=email]", "tech@example.com"); pg.fill("input[name=password]", TECH_PASSWORD); pg.click("button")
        __import__('sitecustomize').after_login(pg, "tech@example.com"); pg.wait_for_load_state()
        pg.goto(B + "/devices"); pg.wait_for_load_state()
        pg.click("#feedback-menu"); pg.click("[data-feedback-kind=feature]"); pg.wait_for_timeout(400)
        ok(pg.is_checked("#fb-kind-feature"), "Request a feature opens the form with Feature request picked")
        pg.wait_for_timeout(1000)
        ok(pg.evaluate("document.activeElement.id") == "fb-title", "and the title focused: " + str(pg.evaluate("document.activeElement.outerHTML.slice(0, 100)")))
        pg.keyboard.press("Escape"); pg.wait_for_timeout(600)
        ok(not pg.is_visible("#modal-feedback") and not pg.is_visible("#align-confirm"), "closed untouched after Request a feature: no 'discard your changes?'")
        pg.click("#feedback-menu"); pg.click("[data-feedback-kind=feature]"); pg.wait_for_timeout(800)
        pg.set_input_files("#fb-screenshot", {"name": "big.png", "mimeType": "image/png", "buffer": b"\x89PNG" + b"\0" * (5 * 1024 * 1024 + 10)})
        ok(pg.evaluate("document.getElementById('fb-screenshot').files.length") == 0 and "larger than 5 MB" in pg.inner_text("#fb-paste-hint"), "a file over 5 MB is cleared with a message")
        pg.set_input_files("#fb-screenshot", {"name": "small.png", "mimeType": "image/png", "buffer": png()})
        pg.keyboard.press("Escape"); pg.wait_for_timeout(600)
        ok(pg.is_visible("#align-confirm"), "closing with only a screenshot attached asks first")
        pg.click("#align-confirm [data-align-confirm-cancel]"); pg.wait_for_timeout(400)
        pg.evaluate("""() => { const c = document.createElement('canvas'); c.width = 20; c.height = 10; return new Promise((ok) => c.toBlob((b) => {
            const dt = new DataTransfer(); dt.items.add(new File([b], 'image.png', {type: 'image/png'}));
            document.getElementById('fb-body').dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true, cancelable: true})); ok(); }, 'image/png')); }""")
        pg.wait_for_timeout(200)
        ok(pg.evaluate("document.getElementById('fb-screenshot').files.length") == 1 and "Pasted screenshot attached" in pg.inner_text("#fb-paste-hint"), "a pasted image is attached as the screenshot")
        pg.fill("#fb-title", "R227 From the browser")
        pg.click("#modal-feedback button.btn-primary"); pg.wait_for_load_state()
        f = last()
        ok(f["title"] == "R227 From the browser" and f["kind"] == "feature" and f["page"] == "/devices" and f["file_type"] == "png" and "/devices" in pg.url, f"sent from the browser with the page and screenshot: {f['page']} {pg.url}")
        pg.set_viewport_size({"width": 390, "height": 844}); pg.goto(B + "/feedback"); pg.wait_for_timeout(200)
        ok(pg.is_visible("#feedback-menu"), "the button is there on a phone")
        ok(not errors, "no script errors: " + str(errors[:2]))
        br.close()
finally:
    for r in q("select file_name from feedback where file_name is not null"):
        try: os.remove(DIR + "/" + r["file_name"])
        except OSError: pass
    q("delete from feedback")
done()
