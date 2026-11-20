"""2.38.1 Update with AI keeps one draft per document instead of adding a new document each time:
- a Draft page is updated in place as a new version (the text before is in its history, Restore puts it back; the
  history note lists what changed; the title stays);
- an Active page gets one Draft copy (documents.ai_source_id), and the next update goes into that copy (AI reads the
  copy) while it is a Draft; once the copy is set Active, the next update makes a new copy; the original never changes;
- an uploaded file works like an Active page: one page copy, then updates of it;
- an earlier "What changed" list isn't sent back to AI, and a title never gets "(AI draft …)" twice;
- a document set Active while AI was writing is not written over (a new copy instead), and another client's document
  that claims the same source is never used.
Techs and admins; the provider mock is tests/mock-ai.php; everything is made up."""
import json
from lib import *

C = 3  # Harbor Point Law Group LLP: no documents in the seed
OTHER = 1
tech = login("tech@example.com", TECH_PASSWORD)
FIX = os.path.join(ROOT, "tests", "e2e", "fixtures", "ai")


def mock(**kw): requests.post(M + "/mock/ai", json=kw)
def calls(): return requests.get(M + "/mock/ai-state").json()["calls"]
def doc(i): return q("select * from documents where id = %s", i)[0]
def docs(cid=C): return q("select * from documents where client_id = %s order by id", cid)
def versions(i): return q("select * from document_versions where document_id = %s order by id", i)
def sent(): return calls()[-1]["body"]["messages"][0]["content"][-1]["text"]
def update(did, mode="refresh", tid=0, cid=C):
    return tech.post(B + f"/clients/{cid}/ai-drafts/{did}", data={"_csrf": csrf(tech, "/"), "mode": mode, "review_id": "0", "template_id": str(tid)})


def answer(title, n):
    """AI's refresh answer: a "What changed" list, then the document (long enough to be accepted)."""
    return (f"<h2>What changed</h2><ul><li>Change {n} one.</li><li>Change {n} two &amp; more.</li></ul>"
            f"<h1>{title}</h1><h2>Access</h2><p>Remote access uses a VPN with MFA, revision {n}. [To confirm: VPN product]</p>"
            f"<h2>Reporting</h2><p><strong>[Added]</strong> Report a suspected compromise to the help desk at once, revision {n}. "
            + "Staff keep their devices locked and up to date. " * 4 + "</p>")


def add(title, status, body):
    q("insert into documents (client_id, title, category, kind, status, body_html, review_due, created_by) values (%s, %s, 'policy', 'page', %s, %s, '2027-01-31', 1)", C, title, status, body)
    return q("select max(id) as id from documents where client_id = %s", C)[0]["id"]


def cleanup():
    q("delete from documents where client_id = %s", C)
    q("delete from documents where client_id = %s and title like 'Zz2381 %%'", OTHER)
    q("delete from ai_usage")
    q("delete from settings where name like 'ai\\_%%'")
    requests.get(M + "/mock/ai-reset")


cleanup()
setting("ai_api_base", M + "/ai"); setting("ai_provider", "claude"); setting("ai_model", "claude-sonnet-5-5")
setting("ai_api_key", "key-claude", secret=True); setting("ai_api_key_for", "claude")
setting("ai_enabled", "1"); setting("ai_feature_drafts", "1")
OLD = "<h1>Remote Access Policy</h1><h2>Access</h2><p>Remote access uses a VPN.</p><h2>Devices</h2><p>" + "Company laptops only. " * 12 + "</p>"
try:
    # ======== a Draft page: updated in place, as a new version
    D = add("Zz2381 Remote Access Policy", "draft", OLD)
    t = tech.get(B + f"/clients/{C}/ai-drafts/{D}").text
    ok(not errs(t) and "AI updates this draft as a new version" in t and "Update the draft" in t, "the form says a Draft is updated in place")
    v0 = doc(D)["version"]
    mock(queue=[answer("Remote Access Policy", 1)])
    r = update(D)
    d = doc(D)
    ok(len(docs()) == 1 and r.url.endswith(f"/documents/{D}") and "AI updated this draft" in flash(r.text), "no new document; the draft opens: " + flash(r.text)[:60])
    ok(d["version"] == v0 + 1 and "revision 1" in d["body_html"] and d["title"] == "Zz2381 Remote Access Policy" and d["status"] == "draft" and d["portal_shared"] == 0,
       "the body is the new version; title and status stay")
    vs = versions(D)
    ok(vs[-2]["kind"] == "manual" and vs[-2]["note"] == "Before AI update" and vs[-2]["body_html"] == OLD, "the text before is in the history")
    ok(vs[-1]["kind"] == "manual" and vs[-1]["note"] == "AI update: Change 1 one.; Change 1 two & more." and vs[-1]["version"] == v0 + 1, "the history note lists what changed: " + str(vs[-1]["note"]))
    ok(q("select 1 from audit_log where action = 'ai.draft' and detail like %s", f"%#{D} Zz2381 Remote Access Policy (AI update: Change 1 one.%"), "audited")
    # again: still one document, and the earlier "What changed" isn't sent back
    mock(queue=[answer("Remote Access Policy", 2)])
    update(D)
    m = re.search(r"<<<DOCUMENT 1 ([0-9a-f]{16}): [^>]*>>>\n(.*?)\n<<<END DOCUMENT 1 \1>>>", sent(), re.S)
    ok(m and "revision 1" in m.group(2) and "What changed" not in m.group(2) and "Change 1 one" not in m.group(2), "AI reads the current text without the old What changed list")
    ok(len(docs()) == 1 and doc(D)["version"] == v0 + 2 and "revision 2" in doc(D)["body_html"] and doc(D)["body_html"].count("What changed") == 1, "a second update: same document, one What changed")
    # Restore puts the original back
    first = versions(D)[0]
    r = tech.post(B + f"/documents/{D}/versions/{first['id']}/restore", data={"_csrf": csrf(tech, f"/documents/{D}")})
    ok(doc(D)["body_html"] == OLD, "Restore puts the text from before AI back")

    # ======== an Active page: one draft copy, then updates of that copy
    A = add("Zz2381 Backup Policy", "active", OLD.replace("Remote Access", "Backup"))
    abefore = doc(A)
    ok("AI writes an updated copy" in tech.get(B + f"/clients/{C}/ai-drafts/{A}").text, "the form says an Active document gets a copy")
    mock(queue=[answer("Backup Policy", 1)])
    r = update(A)
    cp = [x for x in docs() if x["ai_source_id"] == A]
    ok(len(cp) == 1 and cp[0]["status"] == "draft" and cp[0]["title"].startswith("Zz2381 Backup Policy (AI draft ") and r.url.endswith(f"/documents/{cp[0]['id']}")
       and "new draft document" in flash(r.text), "the first update makes one draft copy, linked to the original: " + cp[0]["title"])
    CP = cp[0]["id"]
    t = tech.get(B + f"/clients/{C}/ai-drafts/{A}").text
    ok(f'href="/documents/{CP}"' in t and "AI updates the draft copy" in t, "the form now points at that copy")
    mock(queue=[answer("Backup Policy", 2)])
    r = update(A)
    ok(len([x for x in docs() if x["ai_source_id"] == A]) == 1 and "revision 2" in doc(CP)["body_html"] and r.url.endswith(f"/documents/{CP}")
       and "AI updated the draft copy" in flash(r.text), "the second update goes into the same copy")
    ok("uses a VPN with MFA, revision 1" in sent() and "Company laptops only" not in sent(), "AI read the copy, not the original")
    ok(doc(A) == abefore, "the Active original is unchanged")
    # the move mode uses the copy too, and keeps its title
    MFA = q("select * from document_templates where slug = 'password-mfa-policy'")[0]
    mock(queue=[MFA["body_html"] + "<p>From the old policy: revision 2 carried over.</p>"])
    update(A, "move", MFA["id"])
    c = doc(CP)
    ok(len(docs()) == 3 and c["template_id"] == MFA["id"] and "carried over" in c["body_html"] and c["title"].startswith("Zz2381 Backup Policy (AI draft "), "moving onto a template also updates the copy")
    ok(versions(CP)[-1]["note"].startswith("AI: #%d Zz2381 Backup Policy moved onto template" % A), "with a history note")
    # once the copy is Active, the next update makes a new copy, without a second "(AI draft …)"
    q("update documents set status = 'active', body_html = %s where id = %s", answer("Backup Policy", 2), CP)  # back to a short page
    mock(queue=[answer("Backup Policy", 3)])
    update(A)
    cp = [x for x in docs() if x["ai_source_id"] == A]
    ok(len(cp) == 2 and cp[-1]["status"] == "draft" and doc(CP)["status"] == "active" and "revision 3" not in doc(CP)["body_html"], "a copy set Active is left alone; a new copy is made")
    mock(queue=[answer("Backup Policy", 4)])
    update(CP)
    t2 = [x for x in docs() if x["ai_source_id"] == CP]
    ok(len(t2) == 1 and t2[0]["title"].count("(AI draft") == 1, "updating an Active AI copy gives one '(AI draft …)' in the title: " + t2[0]["title"])

    # ======== an uploaded file: one page copy, then updates of it
    r = tech.post(B + "/documents/upload", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "client_id": str(C), "title": "Zz2381 Incident plan", "category": "plan"},
                  files={"file": ("plan.pdf", open(os.path.join(FIX, "invoice.pdf"), "rb").read(), "application/pdf")})
    F = q("select id from documents where client_id = %s and kind = 'file'", C)[0]["id"]
    fbefore = doc(F)
    mock(queue=[answer("Incident plan", 1)])
    update(F)
    mock(queue=[answer("Incident plan", 2)])
    update(F)
    fc = [x for x in docs() if x["ai_source_id"] == F]
    ok(len(fc) == 1 and fc[0]["kind"] == "page" and fc[0]["category"] == "plan" and "revision 2" in fc[0]["body_html"] and doc(F) == fbefore,
       "a file gets one page copy; the second update goes into it; the file is unchanged")

    # ======== set Active while AI was writing: never written over; another client's copy never used
    S = add("Zz2381 Wifi Policy", "draft", OLD.replace("Remote Access", "Wifi"))
    stale = doc(S)
    q("update documents set status = 'active' where id = %s", S)
    body = answer("Wifi Policy", 1).replace("'", "")
    res = php(f"echo json_encode(\\Align\\Ai\\Drafts\\Draft::put(['id' => {C}, 'name' => 'Harbor'], json_decode('{json.dumps({'id': S, 'client_id': C, 'kind': 'page', 'status': 'draft', 'title': stale['title']})}', true), '{body}', null, 'AI update', 'Zz2381 Wifi Policy', 'policy', {stale['version']}));")
    out = json.loads(res.stdout or "null") if res.stdout.strip().startswith("[") else None
    ok(out and out[1] == "new" and doc(S)["body_html"] == stale["body_html"] and doc(S)["status"] == "active", f"a document set Active meanwhile isn't written over: {out or res.stderr[:200]}")
    q("insert into documents (client_id, title, category, kind, status, body_html, ai_source_id, created_by) values (%s, 'Zz2381 Not yours', 'policy', 'page', 'draft', '<p>Other client</p>', %s, 1)", OTHER, A)
    mock(queue=[answer("Backup Policy", 5)])
    update(A)
    ok(q("select body_html from documents where client_id = %s and title = 'Zz2381 Not yours'", OTHER)[0]["body_html"] == "<p>Other client</p>" and "Other client" not in sent(),
       "another client's document claiming the same source is never read or written")

    # ======== 2.38.1 review fixes
    # an editor who had the draft open gets a conflict, not a silent overwrite of AI's version
    E = add("Zz2381 Email Policy", "draft", OLD.replace("Remote Access", "Email"))
    ev = doc(E)["version"]
    mock(queue=[answer("Email Policy", 1)])
    update(E)
    r = tech.post(B + f"/documents/{E}/save", data={"_csrf": csrf(tech, f"/documents/{E}"), "base_version": str(ev), "body": "<p>stale editor text</p>"})
    ok(r.status_code == 409 and "revision 1" in doc(E)["body_html"], f"an editor still on the old version gets a conflict ({r.status_code})")
    # someone edited the draft while AI was writing: their edits stay, AI's text becomes a new draft
    q("update documents set body_html = '<p>edited meanwhile</p>', version = version + 1 where id = %s", E)
    n = len(docs())
    res = php(f"echo json_encode(\\Align\\Ai\\Drafts\\Draft::put(['id' => {C}, 'name' => 'Harbor'], ['id' => {E}], '<p>ai text</p>', null, 'AI update', 'Zz2381 Email Policy', 'policy', {ev + 1}));")
    ok(res.stdout.strip().endswith(',"changed"]') and doc(E)["body_html"] == "<p>edited meanwhile</p>" and len(docs()) == n + 1, "edits made while AI wrote are kept; AI's version is a new draft: " + (res.stdout or res.stderr)[:120])
    # a refresh copy of an Active document keeps the source's template
    BK = q("select id from document_templates where slug = 'backup-recovery-policy'")[0]["id"]
    T = add("Zz2381 Templated Policy", "active", OLD.replace("Remote Access", "Templated"))
    q("update documents set template_id = %s where id = %s", BK, T)
    mock(queue=[answer("Templated Policy", 1)])
    update(T)
    tc = [x for x in docs() if x["ai_source_id"] == T]
    ok(len(tc) == 1 and tc[0]["template_id"] == BK, "a new copy keeps the source's template")
    # a source deleted while AI was writing: nothing saved
    n = len(docs())
    res = php(f"try {{ \\Align\\Ai\\Drafts\\Draft::put(['id' => {C}, 'name' => 'Harbor'], ['id' => 999999], '<p>x</p>', null, 'AI update', 'Gone', 'policy', null); echo 'saved'; }} catch (\\Align\\Ai\\AiException $e) {{ echo $e->getMessage(); }}")
    ok("deleted or archived while AI was writing" in res.stdout and len(docs()) == n, "a deleted source: refused, nothing saved")
    # a long What changed list: the history note stays within 255 characters
    note = php("echo \\Align\\Ai\\Drafts\\Draft::changeNote('AI update', '<h2>What changed</h2><ul>' . str_repeat('<li>' . str_repeat('long item ', 10) . '</li>', 12) . '</ul><p>x</p>');").stdout
    ok(note.startswith("AI update: long item") and len(note) <= 255, f"a long change list is cut to 255 characters ({len(note)})")
    # a document people wrote that starts with its own "What changed" section keeps it in what AI reads
    W = add("Zz2381 Change log", "draft", "<h2>What changed</h2><ul><li>Our own change log entry.</li></ul>" + OLD)
    mock(queue=[answer("Change log", 1)])
    update(W)
    ok("Our own change log entry" in sent(), "a person's own What changed section is still sent")
finally:
    cleanup()
done()
