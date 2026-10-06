"""2.3.0 Alignment reviews: the standards library (starter set, edit, switch off, categories, export / import), a
client's review (start from the last answers, save only changes, automatic and compliance hints, finish and score),
gaps and "Make project", answers shared with the compliance checklists, the overview card, the client list column,
the QBR section and highlight, quiet QBR snapshots, and who may do what."""
from lib import *
import re, json, html as H

def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))

adm = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
view = login("viewer@example.com", "ViewerPassword123!")
CID = 1
q("delete from alignment_reviews where client_id = %s", CID)
q("delete from roadmap_items where title like 'ZzAL%%'")
q("delete from qbr_snapshots where client_id = %s", CID)

# ---- the library: the starter set came with migration 056
n = q("select count(*) n from alignment_standards where is_active = 1")[0]["n"]
ok(n >= 30 and q("select count(*) n from alignment_categories")[0]["n"] >= 7, "the starter standards are there (%d)" % n)
ok(q("select 1 from alignment_standards where title like 'MFA on Microsoft 365%%' and priority = 'critical' and tags like 'iam_mfa%%'"), "built from the MSP Security Baseline, with its compliance tags")
r = adm.get(B + "/settings/standards"); t = r.text
ok(r.status_code == 200 and not errs(t) and 'href="/settings/standards"' in t and "MFA on Microsoft 365 for every user" in t and "Helps with" in t, "Settings → Standards lists them")
ok(re.search(r"HIPAA[^<]*164\.312", t) is not None or "CIS" in t, "with the compliance controls each one helps with")
for who, s in [("tech", tech), ("viewer", view)]:
    ok(s.get(B + "/settings/standards").status_code == 403 and s.post(B + "/settings/standards", data={"_csrf": csrf(s, "/clients"), "title": "x"}).status_code == 403, f"{who}s can't open or change the library")

# add, edit, too-large cost, remove (unused = deleted)
cat = q("select id from alignment_categories order by sort limit 1")[0]["id"]
r = adm.post(B + "/settings/standards", data={"_csrf": csrf(adm, "/settings/standards"), "title": "ZzAL Printers on their own network", "category_id": cat, "priority": "low",
    "why": "A hacked printer can't reach anything.", "tags": "Net_Segmentation, bogus tag!", "auto_check": "nonsense", "fix_title": "Move printers", "fix_category": "infrastructure", "fix_cost": "$1,250"})
row = q("select * from alignment_standards where title = 'ZzAL Printers on their own network'")
ok(row and row[0]["priority"] == "low" and row[0]["tags"] == "net_segmentation,bogus,tag" and row[0]["auto_check"] is None and float(row[0]["fix_cost"]) == 1250.0, "a standard is added, its input cleaned: " + str(row and row[0]["tags"]))
SID = row[0]["id"]
r = adm.post(B + "/settings/standards", data={"_csrf": csrf(adm, "/settings/standards"), "id": SID, "title": "ZzAL Printers on their own network", "category_id": cat, "priority": "critical", "fix_cost": "99999999999"})
ok("too large" in flash(r.text) and q("select priority from alignment_standards where id=%s", SID)[0]["priority"] == "low", "a too-large typical cost is refused, nothing saved")
adm.post(B + "/settings/standards", data={"_csrf": csrf(adm, "/settings/standards"), "id": SID, "title": "ZzAL Printers on their own network", "category_id": "0", "new_category": "ZzAL Office", "priority": "high"})
ok(q("select c.name from alignment_standards s join alignment_categories c on c.id = s.category_id where s.id=%s", SID)[0]["name"] == "ZzAL Office", "edited into a new category")
r = adm.post(B + "/settings/standards/categories", data={"_csrf": csrf(adm, "/settings/standards"), "action": "add", "name": "ZzAL Office"})
ok("already a category" in flash(r.text), "category names are unique")
# export / import round trip
ex = adm.get(B + "/settings/standards/export")
data = ex.json() if ex.headers.get("Content-Type", "").startswith("application/json") else {}
ok(data.get("format") == "msp-align-standards" and any(s["title"] == "ZzAL Printers on their own network" for c in data["categories"] for s in c["standards"]), "the library exports as JSON")
data["categories"].append({"name": "ZzAL Imported", "standards": [{"title": "ZzAL Imported one", "priority": "nonsense", "why": "<script>x</script>"}, {"title": "MFA on Microsoft 365 for every user"}]})
r = adm.post(B + "/settings/standards/import", data={"_csrf": csrf(adm, "/settings/standards")}, files={"file": ("s.json", json.dumps(data), "application/json")})
imp = q("select priority, why from alignment_standards where title = 'ZzAL Imported one'")
ok("Imported 1 standard" in flash(r.text) and imp and imp[0]["priority"] == "medium", "an import adds only what's new: " + flash(r.text))
r = adm.post(B + "/settings/standards/import", data={"_csrf": csrf(adm, "/settings/standards")}, files={"file": ("s.json", b'{"format":"other"}', "application/json")})
ok("isn't a standards export" in flash(r.text), "a file that isn't an export is refused")
ok("&lt;script&gt;" in adm.get(B + "/settings/standards").text, "imported text is shown escaped")
q("delete from alignment_standards where title = 'ZzAL Imported one'"); q("delete from alignment_categories where name = 'ZzAL Imported'")

# ---- a client's page before any review
r = view.get(B + f"/clients/{CID}/alignment"); t = r.text
ok(r.status_code == 200 and not errs(t) and "No alignment review yet" in t and f'href="/clients/{CID}/alignment"' in t and "/alignment/start" not in t, "the Alignment tab: viewers see it, without Start")
ok(view.post(B + f"/clients/{CID}/alignment/start", data={"_csrf": csrf(view, f"/clients/{CID}/alignment")}).status_code == 403, "viewers can't start a review")

# ---- start, answer, save only what changed
r = tech.post(B + f"/clients/{CID}/alignment/start", data={"_csrf": csrf(tech, f"/clients/{CID}/alignment")})
d = q("select * from alignment_reviews where client_id=%s and status='draft'", CID)
ok(r.url.endswith(f"/clients/{CID}/alignment/review") and d, "Start opens a draft review")
RID = d[0]["id"]
tech.post(B + f"/clients/{CID}/alignment/start", data={"_csrf": csrf(tech, f"/clients/{CID}/alignment")})
ok(q("select count(*) n from alignment_reviews where client_id=%s and status='draft'", CID)[0]["n"] == 1, "pressing Start again reuses the draft")
t = tech.get(B + f"/clients/{CID}/alignment/review").text
ok(not errs(t) and "Review in progress" in t and "Align's data:" in t and 'data-post-changed' in t, "the review form, with Align's own data beside the automatic checks")
std = q("select id, title, priority, auto_check from alignment_standards where is_active = 1 order by id")
ids = [s["id"] for s in std]
crit = [s["id"] for s in std if s["priority"] == "critical"]
form = {"_csrf": csrf(tech, f"/clients/{CID}/alignment/review")}
for sid in ids:
    form[f"c[{sid}][answer]"] = "aligned"
form[f"c[{crit[0]}][answer]"] = "misaligned"; form[f"c[{crit[0]}][note]"] = "ZzAL VPN has no MFA <b>yet</b>"
form[f"c[{SID}][answer]"] = "na"
form[f"c[{crit[1]}][answer]"] = "maybe"   # not an answer: left empty
form["c[999999][answer]"] = "aligned"     # not a standard: ignored
r = tech.post(B + f"/clients/{CID}/alignment/review", data=form)
saved = q("select count(*) n from alignment_answers where review_id=%s and answer is not null", RID)[0]["n"]
ok("Saved" in flash(r.text) and saved == len(ids) - 1 and not q("select 1 from alignment_answers where standard_id = 999999"), f"answers saved, unknown ones ignored ({saved})")
plain = [i for i in ids if i not in crit and i != SID][0]
r = tech.post(B + f"/clients/{CID}/alignment/review", data={"_csrf": csrf(tech, f"/clients/{CID}/alignment/review"), f"c[{plain}][answer]": "aligned", f"c[{plain}][note]": ""})
ok("Nothing changed" in flash(r.text), "saving the same answer again changes nothing")
t = tech.get(B + f"/clients/{CID}/alignment/review").text
ok("ZzAL VPN has no MFA &lt;b&gt;yet&lt;/b&gt;" in t, "notes are shown escaped")

# ---- finish: the score is weighted by priority, N/A and unanswered left out
r = tech.post(B + f"/clients/{CID}/alignment/review", data={"_csrf": csrf(tech, f"/clients/{CID}/alignment/review"), "finish": "1"})
rev = q("select * from alignment_reviews where id=%s", RID)[0]
W = {"critical": 4, "high": 3, "medium": 2, "low": 1}
okw = sum(W[s["priority"]] for s in std if s["id"] not in (crit[0], crit[1], SID))
allw = okw + W["critical"]
want = round(okw / allw * 100)
ok(rev["status"] == "done" and rev["score"] == want and rev["misaligned"] == 1 and rev["na"] == 1 and rev["unanswered"] == 1, f"finished: {rev['score']}% (expected {want}), 1 misaligned, 1 N/A, 1 unanswered")
ok("not answered and left out" in flash(r.text) and q("select title from alignment_answers where review_id=%s and standard_id=%s", RID, crit[0])[0]["title"], "the message says so, and answers keep the standard's title")
t = view.get(B + f"/clients/{CID}/alignment").text
ok(f"{want}%" in t and "On track" in t and "Gaps" in text(t) and "ZzAL VPN has no MFA" in t, "the Alignment tab shows the score, band and the gap")
ok("Make project" not in t, "viewers get no Make project button")
t = tech.get(B + f"/clients/{CID}/alignment").text
ok('data-bs-target="#al-project"' in t and f'data-f-alignment_standard_id="{crit[0]}"' in t, "techs can make a project from the gap")
ok(view.get(B + f"/clients/{CID}/alignment/reviews/{RID}").status_code == 200 and "can't be changed" in view.get(B + f"/clients/{CID}/alignment/reviews/{RID}").text, "a finished review opens read-only")
ok(view.get(B + f"/clients/2/alignment/reviews/{RID}", allow_redirects=False).status_code in (302, 303), "another client's review id isn't shown under this client")

# ---- make a project from the gap (the roadmap's own form, with the standard)
r = tech.post(B + f"/clients/{CID}/roadmap", data={"_csrf": csrf(tech, f"/clients/{CID}/alignment"), "title": "ZzAL Require MFA on the VPN", "category": "security",
    "status": "proposed", "priority": "critical", "cost": "600", "alignment_standard_id": crit[0], "back": f"/clients/{CID}/alignment"})
p = q("select * from roadmap_items where title='ZzAL Require MFA on the VPN'")
ok(r.url.endswith(f"/clients/{CID}/alignment") and p and p[0]["alignment_standard_id"] == crit[0], "the project remembers the standard and comes back to Alignment")
ok("On the roadmap" in tech.get(B + f"/clients/{CID}/alignment").text, "the gap then shows it's on the roadmap")

# ---- a second review starts from the first one's answers
tech.post(B + f"/clients/{CID}/alignment/start", data={"_csrf": csrf(tech, f"/clients/{CID}/alignment")})
R2 = q("select id from alignment_reviews where client_id=%s and status='draft'", CID)[0]["id"]
ok(q("select answer, note from alignment_answers where review_id=%s and standard_id=%s", R2, crit[0])[0]["answer"] == "misaligned"
   and q("select answer from alignment_answers where review_id=%s and standard_id=%s", R2, SID)[0]["answer"] == "na", "a new review starts from the last answers (N/A stays)")
# switching a standard off: it leaves drafts, the finished review keeps it
r = adm.post(B + "/settings/standards", data={"_csrf": csrf(adm, "/settings/standards"), "id": SID, "action": "delete"})
ok("switched off" in flash(r.text) and q("select is_active from alignment_standards where id=%s", SID)[0]["is_active"] == 0, "a standard a review used is switched off, not deleted")
ok("ZzAL Printers" not in tech.get(B + f"/clients/{CID}/alignment/review").text and "ZzAL Printers" in view.get(B + f"/clients/{CID}/alignment/reviews/{RID}").text,
   "it leaves the draft; the finished review still shows it")
form = {"_csrf": csrf(tech, f"/clients/{CID}/alignment/review"), f"c[{crit[0]}][answer]": "aligned", f"c[{crit[1]}][answer]": "aligned", "finish": "1"}
tech.post(B + f"/clients/{CID}/alignment/review", data=form)
r2 = q("select * from alignment_reviews where id=%s", R2)[0]
ok(r2["status"] == "done" and r2["score"] == 100 and not q("select 1 from alignment_answers where review_id=%s and standard_id=%s", R2, SID), "the second review: 100%, without the switched-off standard")
t = view.get(B + f"/clients/{CID}/alignment").text
ok("Up " + str(100 - want) + " points" in t if want < 100 else True, "the change since the review before is shown")
ok(q("select score from alignment_reviews where id=%s", RID)[0]["score"] == want, "the old review keeps its own score")
# discard
tech.post(B + f"/clients/{CID}/alignment/start", data={"_csrf": csrf(tech, f"/clients/{CID}/alignment")})
r = tech.post(B + f"/clients/{CID}/alignment/review/discard", data={"_csrf": csrf(tech, f"/clients/{CID}/alignment/review")})
ok("discarded" in flash(r.text) and not q("select 1 from alignment_reviews where client_id=%s and status='draft'", CID), "a draft can be discarded")
ok(q("select count(*) n from audit_log where action in ('alignment.review_start','alignment.review_finish','alignment.review_discard','standard.create','standard.switch_off','standard.import')")[0]["n"] >= 6, "everything is audited")

# ---- elsewhere: overview card, client list, compliance crosswalk, QBR
t = view.get(B + f"/clients/{CID}").text
ok('id="overview-alignment"' in t and "100%" in t.split('id="overview-alignment"')[1][:3000], "the client overview has an Alignment card")
t = view.get(B + "/clients").text
ok("<th><a" in t and "Alignment" in t and "On track" in t, "the client list has an Alignment column")
t = view.get(B + "/clients?alignment=never").text
ok(not re.search(r'href="/clients/%d"' % CID, t), "the Never reviewed filter leaves reviewed clients out")
fw = q("select id from compliance_frameworks where name like 'MSP Security Baseline%%'")[0]["id"]
had_fw = bool(q("select 1 from client_frameworks where client_id=%s and framework_id=%s", CID, fw))
q("insert ignore into client_frameworks (client_id, framework_id) values (%s, %s)", CID, fw)
t = view.get(B + f"/clients/{CID}/compliance/{fw}").text
ok("Alignment review (" in t and not errs(t), "the compliance checklist offers the matching alignment answers")
t = view.get(B + f"/clients/{CID}/report/qbr").text
ok("Alignment with our standards" in t and "s_alignment" in t and not errs(t), "the QBR pack has the Alignment section and a switch for it")
ok("Alignment with our standards" not in view.get(B + f"/clients/{CID}/report/qbr?s_alignment=0").text.split("Executive summary")[1].split("Highlights")[0], "switched off, it's left out of the summary too")

# ---- quiet snapshot when a QBR is completed
q("insert into meetings (uid, client_id, title, type, starts_at, ends_at, status, owner_id) values (md5(rand()), %s, 'ZzAL QBR', 'qbr', now() - interval 1 hour, now(), 'scheduled', 1)", CID)
mid = q("select id from meetings where title='ZzAL QBR' order by id desc limit 1")[0]["id"]
tech.post(B + f"/meetings/{mid}", data={"_csrf": csrf(tech, f"/meetings/{mid}"), "action": "complete"})
snap = q("select data from qbr_snapshots where meeting_id=%s", mid)
d = json.loads(snap[0]["data"]) if snap else {}
ok(d.get("alignment", {}).get("score") == 100 and "devices" in d and "projects" in d, "completing a QBR saves a quiet snapshot of the client's numbers")
q("delete from meetings where id=%s", mid)

# ---- in the browser: the live score and the hint buttons
from playwright.sync_api import sync_playwright
tech.post(B + f"/clients/{CID}/alignment/start", data={"_csrf": csrf(tech, f"/clients/{CID}/alignment")})
with sync_playwright() as pw:
    br = pw.chromium.launch(); pg = br.new_page(viewport={"width": 1300, "height": 900})
    errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(B + "/login"); pg.fill("input[name=email]", "tech@example.com"); pg.fill("input[name=password]", TECH_PASSWORD); pg.click("button")
    __import__('sitecustomize').after_login(pg, "tech@example.com"); pg.wait_for_load_state()
    pg.goto(B + f"/clients/{CID}/alignment/review"); pg.wait_for_timeout(300)
    before = pg.inner_text("#al-live")
    pg.click(f"#al-{crit[0]} label:has-text('Misaligned')"); pg.wait_for_timeout(150)
    after = pg.inner_text("#al-live")
    ok(before == "100%" and after != before and pg.locator(f"#al-{crit[0]} label.active").inner_text().strip().endswith("Misaligned"), f"answering updates the live score ({before} → {after})")
    btn = pg.locator("[data-al-set]").first
    if btn.count():
        target = btn.get_attribute("data-al-set"); val = btn.get_attribute("data-value"); btn.click(); pg.wait_for_timeout(100)
        ok(pg.locator(f"#al-{target} input[value={val}]").is_checked(), "a hint's Use / Fill button picks that answer")
    pg.click('#al-revbar button[form=al-form]:not([name=finish])'); pg.wait_for_load_state()
    ok(q("select answer from alignment_answers a join alignment_reviews r on r.id=a.review_id where r.client_id=%s and r.status='draft' and a.standard_id=%s", CID, crit[0])[0]["answer"] == "misaligned",
       "saving from the page stores the changed answer")
    pg.set_viewport_size({"width": 390, "height": 844}); pg.goto(B + f"/clients/{CID}/alignment"); pg.wait_for_timeout(200)
    ok(pg.evaluate("document.documentElement.scrollWidth<=window.innerWidth+1"), "no sideways scroll on a phone")
    ok(not errors, "no script errors: " + str(errors[:2]))
    br.close()

# clean up
q("delete from alignment_reviews where client_id = %s", CID)
if not had_fw:
    q("delete from client_frameworks where client_id = %s and framework_id = %s", CID, fw)
q("delete from roadmap_items where title like 'ZzAL%%'")
q("delete from qbr_snapshots where client_id = %s", CID)
q("delete from alignment_standards where title like 'ZzAL%%'")
q("delete from alignment_categories where name like 'ZzAL%%'")
done()
