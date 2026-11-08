"""2.34.0 Staff acknowledgments of policies: "Ask staff to acknowledge" on a client document sends the client's contacts
and/or pasted addresses (invalid and repeated ones left out, at most 500) their own private link (hash stored). Each
reads the PDF and ticks "I have read and agree to this policy" with their name; the record keeps the name, address,
time, IP, browser and the version and SHA-256 of what they read. Statuses, remind / add people / send again / cancel,
expiry and automatic reminders, wrong and other-kind links, one person can't acknowledge for another, the client
portal's "Waiting for you to acknowledge" (only their own), the acknowledgment record (print and save as a document),
the compliance evidence button (only the client's own frameworks' controls), "Needs acknowledging again" after a change,
roles, another document's request, escaping, the audit log, and signing (step 1) unaffected.
Everything is made up (Zz234ack, *.example addresses)."""
import hashlib
import html as H
import zlib
from lib import *

C = 1       # Cedar Ridge Family Dental, Inc.
OTHER = 2   # Northfield Hardware & Supply
PW = "Quartz-Lantern-Field-34"
SEC = "KRUGKIDROVUWG2ZAMJZG653OEBTG66BC"
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")


def local(u): return re.sub(r"^https?://[^/]+", B, u)
def mails(): return requests.get(M + "/mock/graph").json()["mail"]
def mails_to(addr, sub):
    return [m["message"] for m in mails() if sub in m["message"]["subject"] and any(r["emailAddress"]["address"] == addr for r in m["message"]["toRecipients"])]
def link_in(msg): return local(re.search(r'href="(https?://[^"]+/portal/docack/[A-Za-z0-9_-]+)"', msg["body"]["content"]).group(1))
def last_link(addr): return link_in([m for m in mails_to(addr, "acknowledge") if not m["subject"].startswith("You acknowledged")][-1])  # not the receipts
def pcsrf(s, url):
    m = re.search(r'name="_csrf" value="([^"]+)"', s.get(url).text) or re.search(r'name="_csrf" value="([^"]+)"', s.get(B + "/portal/login").text)
    return m.group(1)
def audit(action, like): return q("select count(*) n from audit_log where action = %s and detail like %s", action, like)[0]["n"]
def post(path, data=None, s=None, page=None):
    s = s or tech
    return s.post(B + path, data={"_csrf": csrf(s, page or f"/documents/{P}"), **(data or {})})


def pdf_text(b):
    """Text from Align's PDFs (Flate streams, hex strings in Windows-1252)."""
    out = []
    for m in re.finditer(rb"/Length (\d+)[^>]*>>\s*stream\r?\n", b):
        s = b[m.end():m.end() + int(m.group(1))]
        try: s = zlib.decompress(s)
        except Exception: pass
        out += [bytes.fromhex(h.decode()).decode("cp1252", "replace") for h in re.findall(rb"<([0-9a-f]+)> Tj", s)]
    return re.sub(r"\s+", " ", " ".join(out))


def portal_user(email, client=C):
    php(f'Align\\DB::insert("portal_users", ["client_id"=>{client},"email"=>"{email}","name"=>"Pat Portal","password_hash"=>Align\\Security::hashPassword("{PW}"),"is_active"=>1,'
        f'"can_roadmap"=>1,"can_budget"=>0,"can_devices"=>1,"can_documents"=>1,"can_approve"=>0,"can_contacts"=>0,'
        f'"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
    s = requests.Session()
    r = s.post(B + "/portal/login", data={"_csrf": csrf(s, "/portal/login"), "email": email, "password": PW})
    if r.url.endswith("/portal/login/2fa"):
        s.post(B + "/portal/login/2fa", data={"_csrf": csrf(s, "/portal/login/2fa"), "code": totp(SEC)})
    q("delete from login_attempts")
    return s


def contact(cid, name, email):
    q("insert into contacts (client_id, source, name, email, title) values (%s, 'manual', %s, %s, 'Staff')", cid, name, email)
    return q("select id from contacts where email = %s", email)[0]["id"]


def ask(doc, ids=(), pasted="", **opt):
    data = {"people[]": [str(i) for i in ids], "pasted": pasted, "days": str(opt.get("days", 30)), "message": opt.get("message", ""), "control_id": str(opt.get("control_id", ""))}
    if opt.get("resign"):
        data["resign"] = "1"
    return post(f"/documents/{doc}/acknowledgments", data, page=f"/documents/{doc}")


def last_request(doc): return q("select * from doc_sign_requests where document_id = %s and kind = 'acknowledge' order by id desc limit 1", doc)[0]
def people(rid): return q("select * from doc_sign_signers where request_id = %s order by sort, id", rid)
def person(rid, email): return q("select * from doc_sign_signers where request_id = %s and email = %s", rid, email)[0]


def ack(s, link, name="Someone", agree=True, **extra):
    data = {"_csrf": pcsrf(s, link), "name": name, **extra}
    if agree:
        data["agree"] = "1"
    return s.post(link, data=data)


def cleanup():
    q("delete from documents where title like '%%Zz234ack%%'")
    q("delete from portal_users where email like '%%@docack.example'")
    q("delete from contacts where email like '%%@docack.example'")
    q("delete from compliance_frameworks where slug like 'zz234ack-%%'")
    q("delete from mail_queue where kind = 'docack_ask' and status = 'queued'")


cleanup()
try:
    requests.get(M + "/mock/graph-reset")
    MQ0 = q("select coalesce(max(id), 0) m from mail_queue")[0]["m"]
    EV0, UP0 = audit("compliance.evidence", "%Zz234ack Baseline ZA-1%"), audit("document.upload", "%Acknowledgment record: Zz234ack%")  # (a rerun keeps older rows)
    AV = contact(C, "Avery Zz234ack Lane", "avery@docack.example")
    BL = contact(C, "Blake <b>Zz234ack</b> Moss", "blake@docack.example")
    CA = contact(OTHER, "Casey Zz234ack Other", "casey@docack.example")
    q("insert into documents (client_id, title, category, status, body_html, version) values (%s, %s, 'policy', 'active', %s, 2)",
      C, "Zz234ack Acceptable <Use> Policy", "<h1>Acceptable use</h1><p>Zz234ack staff lock their screens.</p>")
    P = q("select id from documents where title = 'Zz234ack Acceptable <Use> Policy'")[0]["id"]
    q("insert into documents (client_id, title, category, status, body_html) values (%s, 'Zz234ack Draft', 'policy', 'draft', '<p>x</p>')", C)
    DRAFT = q("select id from documents where title = 'Zz234ack Draft'")[0]["id"]
    q("insert into documents (client_id, title, category, status, body_html) values (%s, 'Zz234ack Other client policy', 'policy', 'active', '<p>Other</p>')", OTHER)
    ODOC = q("select id from documents where title = 'Zz234ack Other client policy'")[0]["id"]
    q("insert into documents (client_id, title, category, status, body_html) values (%s, 'Zz234ack Big list', 'policy', 'active', '<p>Many people</p>')", C)
    BIG = q("select id from documents where title = 'Zz234ack Big list'")[0]["id"]
    # frameworks: one assigned to this client, one to the other client, one to nobody
    q("insert into compliance_frameworks (slug, name) values ('zz234ack-main', 'Zz234ack Baseline'), ('zz234ack-other', 'Zz234ack Other FW'), ('zz234ack-none', 'Zz234ack Unassigned')")
    FW, FW2, FW3 = [q("select id from compliance_frameworks where slug = %s", s)[0]["id"] for s in ("zz234ack-main", "zz234ack-other", "zz234ack-none")]
    q("insert into client_frameworks (client_id, framework_id) values (%s, %s), (%s, %s)", C, FW, OTHER, FW2)
    q("insert into compliance_controls (framework_id, ref, section, title, sort) values (%s, 'ZA-1', 'People', 'Zz234ack Staff accept the AUP', 1), (%s, 'ZB-1', 'People', 'Zz234ack Other control', 1), (%s, 'ZC-1', 'People', 'Zz234ack Nobody', 1)", FW, FW2, FW3)
    CTL, CTL2, CTL3 = [q("select id from compliance_controls where framework_id = %s", f)[0]["id"] for f in (FW, FW2, FW3)]
    q("insert into client_control_status (client_id, control_id, status, notes) values (%s, %s, 'partial', 'Zz234ack keep this note')", C, CTL)

    # ======== roles and refusals
    t = tech.get(B + f"/documents/{P}").text
    ok(not errs(t) and "Ask staff to acknowledge" in t and 'id="modal-send-ack"' in t and 'id="acknowledgments"' not in t, "techs get Ask staff to acknowledge next to Send for signature; no card yet")
    ok("Zz234ack Staff accept the AUP" in t and "Zz234ack Other control" not in t and "Zz234ack Nobody" not in t, "Counts as evidence for lists only the client's own frameworks' controls")
    ok("Blake &lt;b&gt;Zz234ack&lt;/b&gt; Moss" in t and "<b>Zz234ack</b>" not in t, "contacts in the form are escaped")
    vt = viewer.get(B + f"/documents/{P}").text
    ok("Ask staff to acknowledge" not in vt and "modal-send-ack" not in vt, "viewers don't get the button")
    ok(post(f"/documents/{P}/acknowledgments", {"people[]": [str(AV)]}, s=viewer).status_code == 403, "viewers can't ask (403)")
    ok("Set the document to Active before sending it for acknowledgment" in flash(ask(DRAFT, [AV]).text), "a draft can't be sent")
    ok("Choose at least one person" in flash(ask(P, [CA], "not-an-address").text) and not q("select 1 from doc_sign_requests where document_id = %s", P),
       "another client's contact and an invalid address leave nobody: nothing is sent")
    ok("Choose at least one person" in flash(ask(ODOC, [AV]).text), "this client's contact can't be asked on another client's document")

    # ======== contacts and pasted addresses
    pasted = "Dana Zz234ack <dana@docack.example>\nnot-an-email, avery@docack.example; eve@docack.example\nEVE@docack.example\n<script>alert(1)</script> <sx@docack.example>"
    r = ask(P, [AV, CA, AV], pasted, control_id=CTL, resign=True, message="Please read by Friday <b>thanks</b>")
    f = flash(r.text)
    ok("Asked 4 people to acknowledge it." in f and "1 not a valid email address" in f and "already on the list" in f, "contacts and pasted addresses: 4 people (invalid and repeats left out): " + f)
    R = last_request(P)
    S = people(R["id"])
    ok(R["kind"] == "acknowledge" and R["status"] == "sent" and R["verify_code"] == 0 and R["countersign"] == 0 and R["resign_on_change"] == 1 and R["control_id"] == CTL and R["doc_version"] == 2,
       "the request is an acknowledgment, with the control and 'ask again'")
    ok([x["email"] for x in S] == ["avery@docack.example", "dana@docack.example", "eve@docack.example", "sx@docack.example"]
       and S[0]["contact_id"] == AV and S[1]["contact_id"] is None and S[1]["name"] == "Dana Zz234ack" and S[3]["name"] == "<script>alert(1)</script>",
       "pasted people are kept on the request with their names")
    ok(not q("select 1 from contacts where email in ('dana@docack.example', 'eve@docack.example', 'sx@docack.example')"), "pasted people who aren't contacts aren't made into contacts")
    SRC = open(UPLOADS + "/documents/" + R["source_file"], "rb").read()
    ok(SRC.startswith(b"%PDF-") and hashlib.sha256(SRC).hexdigest() == R["source_hash"] and "lock their screens" in pdf_text(SRC), "the document is frozen as a PDF with its SHA-256")
    ma = mails_to("avery@docack.example", "Please read and acknowledge")
    ok(len(ma) == 1 and "Please read by Friday &lt;b&gt;thanks&lt;/b&gt;" in ma[0]["body"]["content"] and "Read and acknowledge" in ma[0]["body"]["content"]
       and all(len(mails_to(a, "Please read and acknowledge")) == 1 for a in ("dana@docack.example", "eve@docack.example", "sx@docack.example")), "everyone gets their own email (the message escaped)")
    LA, LD, LE, LS = (last_link(a) for a in ("avery@docack.example", "dana@docack.example", "eve@docack.example", "sx@docack.example"))
    ok(len({LA, LD, LE, LS}) == 4 and S[0]["token_hash"] == hashlib.sha256(LA.split("/")[-1].encode()).hexdigest()
       and LA.split("/")[-1] not in json.dumps(q("select * from doc_sign_signers where request_id = %s", R["id"]), default=str), "private links: only their hashes are stored")
    ok(not q("select 1 from mail_queue where kind = 'docack_ask' and purged = 0 and status = 'sent'"), "the sent emails' bodies (with the links) are wiped")
    t = tech.get(B + f"/documents/{P}").text
    ok('id="acknowledgments"' in t and "data-ack-status>Waiting<" in t and "&lt;script&gt;alert(1)&lt;/script&gt;" in t and "<script>alert(1)" not in t
       and "Counts as evidence for Zz234ack Baseline ZA-1" in t, "the card shows the request, names escaped, and its control")

    # ---- the cap: 500 per request
    big = "\n".join(f"p{i}@docack.example" for i in range(520))
    r = ask(BIG, [], big)
    RB = last_request(BIG)
    ok("Asked 500 people" in flash(r.text) and "20 over the limit of 500" in flash(r.text) and len(people(RB["id"])) == 500, "at most 500 people per request")
    r = post(f"/documents/{BIG}/acknowledgments/{RB['id']}/add", {"pasted": "late@docack.example"}, page=f"/documents/{BIG}")
    ok("Nobody new to add" in flash(r.text) and "1 over the limit" in flash(r.text) and len(people(RB["id"])) == 500, "a full request takes nobody more")
    post(f"/documents/{BIG}/acknowledgments/{RB['id']}/cancel", page=f"/documents/{BIG}")
    q("delete from mail_queue where kind = 'docack_ask' and status = 'queued'")

    # ======== each acknowledges with their own link
    sa = requests.Session()
    t = sa.get(LA).text
    ok('id="docack-form"' in t and 'value="Avery Zz234ack Lane"' in t and "I have read and agree to this policy" in t and "Zz234ack Acceptable &lt;Use&gt; Policy" in t
       and f'data-src="/portal/docack/{LA.split("/")[-1]}/file"' in t and "pdfview.js" in t and not errs(t), "the link shows the document and the box, name filled in")
    fl = sa.get(LA + "/file")
    ok(fl.status_code == 200 and fl.content == SRC and "sandbox" in fl.headers.get("Content-Security-Policy", ""), "the PDF that was sent is shown, sandboxed")
    ok("tick the box" in flash(ack(sa, LA, "Avery Lane", agree=False).text) and person(R["id"], "avery@docack.example")["status"] == "waiting", "the box must be ticked")
    r = ack(sa, LA, "Avery Lane", agree=False)
    ok('value="Avery Lane"' in r.text, "the typed name is kept after an error")
    ok("type your name" in flash(ack(sa, LA, "   ").text), "a name is needed")
    r = ack(sa, LA, "Avery Lane", email="dana@docack.example")
    ok("Thank you" in r.text and "it's acknowledged" in r.text and "as Avery Lane" in r.text, "Avery acknowledges")
    pa = person(R["id"], "avery@docack.example")
    rec = json.loads(pa["signature"])
    ok(pa["status"] == "signed" and pa["signed_at"] and rec["name"] == "Avery Lane" and rec["email"] == "avery@docack.example" and rec["ip"] and rec["agent"]
       and rec["source_hash"] == R["source_hash"] and rec["doc_version"] == 2 and rec["via"] == "link", "the record: name, address, time, IP, browser, version and hash")
    ok(person(R["id"], "dana@docack.example")["status"] == "waiting", "an address in the form can't acknowledge for someone else")
    ack(sa, LA, "Again")
    ok(q("select count(*) n from doc_sign_events where request_id = %s and event = 'acknowledged'", R["id"])[0]["n"] == 1 and json.loads(person(R["id"], "avery@docack.example")["signature"])["name"] == "Avery Lane",
       "acknowledging twice does nothing")
    ok(requests.get(LA[:-3] + "AAA").status_code == 404 and "expired or was replaced" in requests.get(B + "/portal/docack/not-a-token").text, "a wrong token is refused")
    ok("Acknowledged by 1 of 4" in tech.get(B + f"/documents/{P}").text, "the card shows Acknowledged by 1 of 4")
    sd = requests.Session()
    ok("Thank you" in ack(sd, LD, "Dana Zz234ack").text and person(R["id"], "dana@docack.example")["status"] == "signed", "Dana acknowledges with her own link")

    # ---- remind and add people
    r = post(f"/documents/{P}/acknowledgments/{R['id']}/remind")
    ok("Reminder sent to 2 people" in flash(r.text) and len(mails_to("eve@docack.example", "Reminder: please acknowledge")) == 1
       and not mails_to("avery@docack.example", "Reminder") and link_in(mails_to("eve@docack.example", "Reminder")[0]) == LE, "a reminder goes to those waiting, with the same links")
    t = tech.get(B + f"/documents/{P}").text
    ok(f'id="modal-add-ack-{R["id"]}"' in t, "an open request has Add people")
    r = post(f"/documents/{P}/acknowledgments/{R['id']}/add", {"people[]": [str(BL), str(CA)], "pasted": "Avery@docack.example, Fay Zz234ack <fay@docack.example>, bad@@x"})
    f = flash(r.text)
    ok("Added 2 people" in f and "1 already on the list" in f and "1 not a valid" in f and len(people(R["id"])) == 6, "add people: Blake and Fay (a repeat, another client's contact and an invalid one left out): " + f)
    LF = last_link("fay@docack.example")
    ok(requests.get(LF).status_code == 200 and audit("document.ack_added", f"#{P} Zz234ack%") == 1, "the added people get their links")

    # ---- the client portal: only their own
    pb = portal_user("blake@docack.example")
    pd = portal_user("gus@docack.example")
    po = portal_user("olly@docack.example", client=OTHER)
    SB = person(R["id"], "blake@docack.example")["id"]
    t = pb.get(B + "/portal").text
    ok("Waiting for you to acknowledge" in t and "Zz234ack Acceptable &lt;Use&gt; Policy" in t and f'href="/portal/acknowledgments/{SB}"' in t and not errs(t), "Blake sees Waiting for you to acknowledge on the portal home")
    ok("Waiting for you to acknowledge" in pb.get(B + "/portal/documents").text, "and on the portal's Documents page")
    ok("Waiting for you to acknowledge" not in pd.get(B + "/portal").text and pd.get(B + f"/portal/acknowledgments/{SB}").status_code == 404, "another portal user of the client doesn't see it or open it")
    ok(po.get(B + f"/portal/acknowledgments/{SB}").status_code == 404, "nor another client's portal user")
    r = pd.get(LE)
    ok(r.status_code == 403 and "for someone else" in r.text and "docack-form" not in r.text, "an emailed link refuses to open for a portal user signed in as someone else")
    ok(pd.post(LE, data={"_csrf": pcsrf(pd, B + "/portal"), "name": "Gus", "agree": "1"}).status_code == 403 and person(R["id"], "eve@docack.example")["status"] == "waiting",
       "and can't be acknowledged by them")
    r = pb.get(B + f"/portal/acknowledgments/{SB}")
    ok(r.url.startswith(B + "/portal/docack/") and "docack-form" in r.text and "opened it from the client portal" in r.text, "Blake opens it from the portal")
    r = ack(pb, r.url, "Blake Moss")
    ok("Thank you" in r.text and json.loads(person(R["id"], "blake@docack.example")["signature"])["via"] == "portal", "Blake acknowledges from the portal (recorded)")
    ok("Waiting for you to acknowledge" not in pb.get(B + "/portal").text, "nothing waits for Blake any more")

    # ---- an expired link
    q("update doc_sign_signers set token_expires_at = now() - interval 1 hour where request_id = %s and email = 'fay@docack.example'", R["id"])
    ok(requests.get(LF).status_code == 404 and last_request(P)["status"] == "sent", "an expired person's link is refused; the request stays open for the others")
    t = tech.get(B + f"/documents/{P}").text
    ok('data-ack-person="acknowledged"' in t and 'data-ack-person="waiting"' in t and 'data-ack-person="expired"' in t and "3 acknowledged · 2 waiting · 1 not acknowledged" in t,
       "the card shows acknowledged, waiting and expired people with counts")

    # ---- kinds don't mix; another document's request is refused
    ok(requests.get(B + "/portal/docsign/" + LE.split("/")[-1]).status_code == 404, "an acknowledgment link doesn't open the signing page")
    ok(post(f"/documents/{P}/signatures/{R['id']}/remind").status_code == 404, "signature actions don't take an acknowledgment request")
    ok(post(f"/documents/{ODOC}/acknowledgments/{R['id']}/remind", page=f"/documents/{ODOC}").status_code == 404, "a request can't be reached through another document")
    ok(tech.get(B + f"/documents/{ODOC}/acknowledgments/{R['id']}/record").status_code == 404, "nor its record")
    for u in ("remind", "resend", "cancel", "again", "evidence", "add", "record"):
        ok(post(f"/documents/{P}/acknowledgments/{R['id']}/{u}", {"control_id": str(CTL)}, s=viewer).status_code == 403, f"viewers can't {u} (403)")

    # ======== compliance evidence: only a control of the client's own frameworks
    for c, why in ((CTL2, "another client's framework"), (CTL3, "a framework the client doesn't have"), (999999, "a made-up control")):
        r = post(f"/documents/{P}/acknowledgments/{R['id']}/evidence", {"control_id": str(c)})
        ok("Choose a control" in flash(r.text), f"refused: {why}")
    ok(q("select evidence from client_control_status where client_id = %s and control_id = %s", C, CTL)[0]["evidence"] is None
       and not q("select 1 from client_control_status where control_id in (%s, %s)", CTL2, CTL3), "nothing was written for them")
    r = post(f"/documents/{P}/acknowledgments/{R['id']}/evidence", {"control_id": str(CTL)})
    ev = q("select * from client_control_status where client_id = %s and control_id = %s", C, CTL)[0]
    ok("Added to the evidence" in flash(r.text) and "This document is linked" in flash(r.text) and ev["evidence"].startswith('3 of 6 staff acknowledged "Zz234ack Acceptable <Use> Policy" (version 2) on ') and ev["document_id"] == P
       and ev["status"] == "partial" and ev["notes"] == "Zz234ack keep this note", "the evidence is set and the document linked; status and notes stay")
    t = tech.get(B + f"/clients/{C}/compliance/{FW}").text
    ok("3 of 6 staff acknowledged &quot;Zz234ack Acceptable &lt;Use&gt; Policy&quot;" in t, "the checklist shows it, escaped")
    ok(audit("compliance.evidence", "%Zz234ack Baseline ZA-1%") == EV0 + 1, "setting the evidence is audited")
    # evidence and a document staff set by hand are kept (2.34.0 review)
    q("insert into compliance_controls (framework_id, ref, section, title, sort) values (%s, 'ZA-2', 'People', 'Zz234ack Policy on file', 2)", FW)
    CTLB = q("select id from compliance_controls where framework_id = %s and ref = 'ZA-2'", FW)[0]["id"]
    q("insert into client_control_status (client_id, control_id, status, evidence, document_id) values (%s, %s, 'met', 'Zz234ack signed policy on file', %s)", C, CTLB, DRAFT)
    for _ in range(2):
        r = post(f"/documents/{P}/acknowledgments/{R['id']}/evidence", {"control_id": str(CTLB)})
    ev = q("select * from client_control_status where client_id = %s and control_id = %s", C, CTLB)[0]
    lines = ev["evidence"].split("\n")
    ok(len(lines) == 2 and lines[0] == "Zz234ack signed policy on file" and lines[1].startswith("3 of 6 staff acknowledged") and ev["document_id"] == DRAFT and ev["status"] == "met"
       and "linked there before is kept" in flash(r.text), "over hand-written evidence: the line is added once (pressing again replaces it), the hand-set document is kept")
    r = post(f"/documents/{P}/acknowledgments/{R['id']}/evidence", {"control_id": str(CTLB), "replace_doc": "1"})
    ok(q("select document_id from client_control_status where client_id = %s and control_id = %s", C, CTLB)[0]["document_id"] == P and "replaces the one linked before" in flash(r.text)
       and audit("compliance.evidence", "%ZA-2%replaced the one linked before%") >= 1, "ticking Replace links this document instead (audited)")
    q("update client_control_status set evidence = %s where client_id = %s and control_id = %s", "é" * 1995, C, CTLB)
    post(f"/documents/{P}/acknowledgments/{R['id']}/evidence", {"control_id": str(CTLB)})
    ev = q("select evidence from client_control_status where client_id = %s and control_id = %s", C, CTLB)[0]["evidence"]
    ok(len(ev) <= 2000 and ev.split("\n")[-1].startswith("3 of 6 staff acknowledged") and ev.split("\n")[-1].endswith(").") and ev.startswith("é"),
       "long evidence is cut from the oldest text (multibyte-safe) and the new line stays whole")

    # ======== the acknowledgment record
    t = tech.get(B + f"/documents/{P}/acknowledgments/{R['id']}/record").text
    ok(not errs(t) and "Acknowledgment record" in t and "Zz234ack Acceptable &lt;Use&gt; Policy" in t and R["source_hash"] in t and "&lt;script&gt;alert(1)&lt;/script&gt;" in t
       and "<script>alert(1)" not in t and "Avery Lane" in t and "data-ack-save" in t and "Expired" in t and "Waiting" in t and "not a contact" in t, "the record lists everyone with status, time and the hash")
    vt = viewer.get(B + f"/documents/{P}/acknowledgments/{R['id']}/record")
    ok(vt.status_code == 200 and "data-ack-save" not in vt.text, "viewers can read the record but not save it")
    r = post(f"/documents/{P}/acknowledgments/{R['id']}/record", page=f"/documents/{P}/acknowledgments/{R['id']}/record")
    rd = q("select * from documents where title like 'Acknowledgment record: Zz234ack%%'")
    ok("saved as a client document" in flash(r.text) and len(rd) == 1 and rd[0]["kind"] == "file" and rd[0]["category"] == "assessment" and rd[0]["portal_shared"] == 0
       and rd[0]["client_id"] == C and r.url.endswith(f"/documents/{rd[0]['id']}"), "save as document: an Assessment PDF, not shared")
    pt = pdf_text(open(UPLOADS + "/documents/" + rd[0]["file_name"], "rb").read())
    ok("Acknowledgment record" in pt and "Avery Lane" in pt and "3 of 6 acknowledged" in pt and R["source_hash"] in pt, "the saved PDF holds the record")

    # ======== "Needs acknowledging again" after a change (nothing emailed by itself)
    before = len(mails())
    v = q("select version from documents where id = %s", P)[0]["version"]
    tech.post(B + f"/documents/{P}/save", data={"_csrf": csrf(tech, f"/documents/{P}"), "base_version": str(v), "body": "<h1>Acceptable use</h1><p>Zz234ack new wording.</p>"})
    t = tech.get(B + f"/documents/{P}").text
    ok("data-needs-ack-again" in t and f"/acknowledgments/{R['id']}/again" in t and f'id="modal-add-ack-{R["id"]}"' not in t and len(mails()) == before,
       "after a change it needs acknowledging again (no Add people); nothing is emailed")
    r = post(f"/documents/{P}/acknowledgments/{R['id']}/add", {"pasted": "hal@docack.example"})
    ok("changed since" in flash(r.text), "people can't be added to the old version")
    r = post(f"/documents/{P}/acknowledgments/{R['id']}/again", {"who": "acked"})
    R3 = last_request(P)
    ok("Asked 3 people again" in flash(r.text) and R3["id"] != R["id"] and sorted(x["email"] for x in people(R3["id"])) == ["avery@docack.example", "blake@docack.example", "dana@docack.example"]
       and R3["control_id"] == CTLB and R3["resign_on_change"] == 1 and "new wording" in pdf_text(open(UPLOADS + "/documents/" + R3["source_file"], "rb").read()),
       "Ask again, only those who acknowledged: the same options, the document as it is now")
    ok(q("select status from doc_sign_requests where id = %s", R["id"])[0]["status"] == "cancelled" and requests.get(LE).status_code == 404, "the old request is closed, so nobody has two links")
    ok("data-needs-ack-again" not in tech.get(B + f"/documents/{P}").text, "the flag goes once asked again")

    # ---- everyone acknowledges: completed, the sender is told; adding people opens it again
    for a, n in (("avery@docack.example", "Avery Lane"), ("blake@docack.example", "Blake Moss"), ("dana@docack.example", "Dana Zz234ack")):
        ack(requests.Session(), last_link(a), n)
    R3 = last_request(P)
    ok(R3["status"] == "completed" and R3["completed_at"] and "Everyone acknowledged" in tech.get(B + f"/documents/{P}").text, "everyone acknowledged: completed")
    ok(q("select count(*) n from mail_queue where id > %s and kind = 'docack_staff' and subject like 'Acknowledged by everyone: Zz234ack%%'", MQ0)[0]["n"] == 1, "the sender is told")
    r = post(f"/documents/{P}/acknowledgments/{R3['id']}/add", {"pasted": "Ivy Zz234ack <ivy@docack.example>"})
    ok("Added 1 person" in flash(r.text) and last_request(P)["status"] == "sent", "adding a new hire opens it again")

    # ---- cancel, and automatic reminders and expiry
    LI = last_link("ivy@docack.example")
    q("update doc_sign_requests set sent_at = now() - interval 5 day, last_reminder_at = null, reminder_count = 0 where id = %s", R3["id"])
    php("Align\\Docs\\Acknowledging::hourly(); Align\\Mail\\Mailer::deliver();")  # automatic reminders wait for the mail timer
    ok(len(mails_to("ivy@docack.example", "Reminder: please acknowledge")) == 1 and last_request(P)["reminder_count"] == 1, "the hourly run sends automatic reminders")
    r = post(f"/documents/{P}/acknowledgments/{R3['id']}/cancel")
    ok("Cancelled" in flash(r.text) and requests.get(LI).status_code == 404 and last_request(P)["status"] == "cancelled"
       and q("select count(*) n from doc_sign_signers where request_id = %s and status = 'signed'", R3["id"])[0]["n"] == 3, "cancel: links stop working, acknowledgments stay on record")
    ask(P, [AV])
    R4 = last_request(P)
    L4 = last_link("avery@docack.example")
    q("update doc_sign_requests set expires_at = now() - interval 1 hour where id = %s", R4["id"])
    php("Align\\Docs\\Acknowledging::hourly();")
    ok(last_request(P)["status"] == "expired" and requests.get(L4).status_code == 404, "the hourly run expires requests past their date")
    r = post(f"/documents/{P}/acknowledgments/{R4['id']}/resend")
    ok("Sent again to 1 person" in flash(r.text) and last_request(P)["status"] == "sent" and requests.get(last_link("avery@docack.example")).status_code == 200, "an expired request can be sent again with new links")

    # ---- audit
    ok(audit("document.ack_sent", f"#{P} Zz234ack%") >= 3 and audit("document.acknowledged", f"#{P} Zz234ack%") == 6 and audit("document.ack_reminder", f"#{P} Zz234ack%") == 2
       and audit("document.ack_cancelled", f"#{P} Zz234ack%") >= 2 and audit("document.upload", "%Acknowledgment record: Zz234ack%") == UP0 + 1, "asking, each acknowledgment, reminders, cancelling and the saved record are audited")
    ok(q("select count(*) n from doc_sign_events where request_id = %s and event in ('asked','opened','acknowledged','reminder','added','evidence','record')", R["id"])[0]["n"] >= 10, "the trail is kept")

    # ---- adding people to an expired request gives the others new links, so it can complete (2.34.0 review)
    ask(P, [AV], "kim@docack.example")
    R5 = last_request(P)
    ack(requests.Session(), last_link("avery@docack.example"), "Avery Lane")
    LK = last_link("kim@docack.example")
    q("update doc_sign_requests set expires_at = now() - interval 1 hour where id = %s", R5["id"])
    q("update doc_sign_signers set token_expires_at = now() - interval 1 hour where request_id = %s and status = 'waiting'", R5["id"])
    php("Align\\Docs\\Acknowledging::hourly();")
    ok(last_request(P)["status"] == "expired", "expired")
    t = tech.get(B + f"/documents/{P}").text
    ok("everyone who hadn&#039;t acknowledged gets a new link too" in t or "everyone who hadn't acknowledged gets a new link too" in t, "the add window says the others get new links")
    r = post(f"/documents/{P}/acknowledgments/{R5['id']}/add", {"pasted": "lee@docack.example"})
    LK2 = last_link("kim@docack.example")
    ok("Added 1 person" in flash(r.text) and "got new links too" in flash(r.text) and LK2 != LK and requests.get(LK).status_code == 404 and last_request(P)["status"] == "sent",
       "adding to an expired request: Kim gets a new link (the old one stays off)")
    ack(requests.Session(), LK2, "Kim Zz234ack")
    ack(requests.Session(), last_link("lee@docack.example"), "Lee Zz234ack")
    ok(last_request(P)["status"] == "completed", "and when everyone has acknowledged it completes")

    # ---- a PDF with comments or fillable fields is refused, for acknowledgments and signatures (2.34.0 review)
    objs = [b"<< /Type /Catalog /Pages 2 0 R >>", b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 4 0 R /Annots [5 0 R] >>",
            b"<< /Length 18 >>\nstream\n0 0 m 100 100 l S\nendstream",
            b"<< /Type /Annot /Subtype /Text /Rect [100 100 120 120] /Contents (Zz234ack note) >>"]
    pdf, offs = b"%PDF-1.4\n", []
    for i, o in enumerate(objs):
        offs.append(len(pdf)); pdf += b"%d 0 obj\n" % (i + 1) + o + b"\nendobj\n"
    x = len(pdf)
    pdf += b"xref\n0 %d\n0000000000 65535 f \n" % (len(objs) + 1) + b"".join(b"%010d 00000 n \n" % o for o in offs)
    pdf += b"trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n" % (len(objs) + 1, x)
    r = tech.post(B + "/documents/upload", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "client_id": str(C), "title": "Zz234ack Commented PDF"}, files={"file": ("c.pdf", pdf, "application/pdf")})
    AN = q("select id from documents where title = 'Zz234ack Commented PDF'")
    ok(bool(AN), "the commented PDF uploads: " + flash(r.text))
    if AN:
        ok("fillable fields or comments" in flash(ask(AN[0]["id"], [AV]).text) and not q("select 1 from doc_sign_requests where document_id = %s", AN[0]["id"]), "asking to acknowledge it is refused")
        r = tech.post(B + f"/documents/{AN[0]['id']}/signatures", data={"_csrf": csrf(tech, f"/documents/{AN[0]['id']}"), "signers[]": [str(AV)], "days": "30"})
        ok("fillable fields or comments" in flash(r.text), "and sending it for signature too")

    # ---- receipts (2.34.0): one per person after acknowledging
    ok(len(mails_to("avery@docack.example", "You acknowledged: Zz234ack")) >= 1 and len(mails_to("kim@docack.example", "You acknowledged: Zz234ack")) == 1
       and "Kim Zz234ack" in mails_to("kim@docack.example", "You acknowledged")[0]["body"]["content"] and "Keep this email for your records" in mails_to("kim@docack.example", "You acknowledged")[0]["body"]["content"],
       "each person gets one receipt with the name they typed")
    ok(not q("select 1 from mail_queue where kind = 'docack_receipt' and purged = 0 and status = 'sent'"), "receipts are wiped from the outbox like the others")

    # ---- "Create link only" (2.34.0): with email off, shown once, nothing emailed, and no receipt
    q("update settings set value = 'off' where name = 'mail_mode'")
    try:
        before = len(mails())
        r = post(f"/documents/{P}/acknowledgments", {"pasted": "Nia Zz234ack <nia@docack.example>", "days": "30", "action": "link"})
        once = re.findall(r'value="(https?://[^"]+/portal/docack/[A-Za-z0-9_-]+)" data-once-link', r.text)
        R6 = last_request(P)
        ok("Link created" in flash(r.text) and len(once) == 1 and people(R6["id"])[0]["link_only"] == 1 and len(mails()) == before, "link only with email off: shown once, nothing emailed")
        ok("data-once-link" not in tech.get(B + f"/documents/{P}").text, "the link shows only once")
    finally:
        q("update settings set value = 'app' where name = 'mail_mode'")
    r = ack(requests.Session(), local(once[0]), "Nia Zz234ack")
    ok("Thank you" in r.text and last_request(P)["status"] == "completed" and not mails_to("nia@docack.example", "You acknowledged") and audit("document.ack_link", f"#{P} Zz234ack%") == 1,
       "acknowledging with that link works; no receipt for a link-only person; audited")

    # ---- the link pages send no Referer (the token stays out of other sites' logs)
    ok(requests.get(last_link("lee@docack.example")).headers.get("Referrer-Policy") == "no-referrer" and requests.get(B + "/portal/docack/not-a-token").headers.get("Referrer-Policy") == "no-referrer",
       "acknowledgment pages send Referrer-Policy: no-referrer")

    # ======== signing (step 1) is unaffected
    r = tech.post(B + f"/documents/{P}/signatures", data={"_csrf": csrf(tech, f"/documents/{P}"), "signers[]": [str(AV)], "days": "30"})
    rs = q("select * from doc_sign_requests where document_id = %s and kind = 'signature'", P)
    t = tech.get(B + f"/documents/{P}").text
    ok("Sent for signature to 1 person" in flash(r.text) and len(rs) == 1 and 'data-sign-request="%d"' % rs[0]["id"] in t and 'data-ack-request="%d"' % rs[0]["id"] not in t
       and 'data-sign-request="%d"' % R["id"] not in t, "sending for signature still works, and each card shows only its own kind")
    sl = local(re.search(r'href="(https?://[^"]+/portal/docsign/[A-Za-z0-9_-]+)"', mails_to("avery@docack.example", "Please review and sign")[-1]["body"]["content"]).group(1))
    ok(requests.get(sl).status_code == 200 and requests.get(B + "/portal/docack/" + sl.split("/")[-1]).status_code == 404, "a signing link opens signing, never the acknowledgment page")
finally:
    cleanup()
done()
