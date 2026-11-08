"""2.34.0 Send client documents for signature: a written document (printed to PDF) or an uploaded PDF (as it is) to one
or more of the client's contacts, each with their own private link (hash stored), an optional emailed code and an
optional countersignature. The signed copy is the PDF that was sent with the signatures and a certificate added at the
end. Statuses, remind / send again / cancel, expiry, decline, wrong or old links, one signer can't sign for another,
the client portal's "Waiting for your signature" (only the signer's own), the compliance report sent for signature,
"Needs signing again" after the document changes, roles, another document's request, escaping and the audit log.
Everything is made up (Zz234, *.example addresses)."""
import hashlib
import html as H
import zlib
from lib import *
from playwright.sync_api import sync_playwright

C = 1       # Cedar Ridge Family Dental, Inc.
OTHER = 2   # Northfield Hardware & Supply
PW = "Quartz-Lantern-Field-34"
SEC = "KRUGKIDROVUWG2ZAMJZG653OEBTG66BC"
tech = login("tech@example.com", TECH_PASSWORD)
admin = login("admin@example.com", "LongPassword123!")
viewer = login("viewer@example.com", "ViewerPassword123!")


def local(u): return re.sub(r"^https?://[^/]+", B, u)
def mails(): return requests.get(M + "/mock/graph").json()["mail"]
def mails_to(addr, sub):
    return [m["message"] for m in mails() if sub in m["message"]["subject"] and any(r["emailAddress"]["address"] == addr for r in m["message"]["toRecipients"])]
def link_in(msg): return local(re.search(r'href="(https?://[^"]+/portal/docsign/[A-Za-z0-9_-]+)"', msg["body"]["content"]).group(1))
def pcsrf(s, url):
    """The portal session's CSRF token from the page (or from the sign-in page when the page has no form)."""
    m = re.search(r'name="_csrf" value="([^"]+)"', s.get(url).text) or re.search(r'name="_csrf" value="([^"]+)"', s.get(B + "/portal/login").text)
    return m.group(1)
def text(t): return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", " ", t)))
def audit(action, doc): return q("select count(*) n from audit_log where action = %s and detail like %s", action, f"#{doc} Zz234%")[0]["n"]


def pdf_text(b):
    """Text from Align's PDFs (Flate streams, hex strings in Windows-1252)."""
    out = []
    for m in re.finditer(rb"/Length (\d+)[^>]*>>\s*stream\r?\n", b):
        s = b[m.end():m.end() + int(m.group(1))]
        try: s = zlib.decompress(s)
        except Exception: pass
        out += [bytes.fromhex(h.decode()).decode("cp1252", "replace") for h in re.findall(rb"<([0-9a-f]+)> Tj", s)]
    return re.sub(r"\s+", " ", " ".join(out))


def drawn_png():
    r = php('$i = imagecreatetruecolor(400, 120); imagesavealpha($i, true); imagefill($i, 0, 0, imagecolorallocatealpha($i, 0, 0, 0, 127));'
            ' $k = imagecolorallocate($i, 20, 30, 90); imagesetthickness($i, 4); imageline($i, 20, 90, 120, 20, $k); imageline($i, 120, 20, 200, 95, $k); imageline($i, 200, 95, 380, 30, $k);'
            ' ob_start(); imagepng($i); echo base64_encode(ob_get_clean());')
    return "data:image/png;base64," + r.stdout.strip()


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
    q("insert into contacts (client_id, source, name, email, title) values (%s, 'manual', %s, %s, 'Manager')", cid, name, email)
    return q("select id from contacts where email = %s", email)[0]["id"]


def send(doc, ids, **opt):
    data = {"_csrf": csrf(tech, f"/documents/{doc}"), "signers[]": [str(i) for i in ids], "days": str(opt.get("days", 30)), "message": opt.get("message", "")}
    for k in ("verify_code", "countersign", "resign"):
        if opt.get(k):
            data[k] = "1"
    return tech.post(B + f"/documents/{doc}/signatures", data=data)


def last_request(doc): return q("select * from doc_sign_requests where document_id = %s order by id desc limit 1", doc)[0]
def signers(rid): return q("select * from doc_sign_signers where request_id = %s order by sort, id", rid)


def sign(s, link, **extra):
    data = {"_csrf": pcsrf(s, link), "sig_kind": "type", "sig_typed": extra.pop("typed", "Signer"), "sig_name": extra.pop("name", "Signer"), "sig_title": "Manager", "consent": "1"}
    data.update(extra)
    return s.post(link, data=data)


def code_open(s, link, email):
    s.post(link + "/code", data={"_csrf": pcsrf(s, link)})
    code = re.search(r"letter-spacing:6px[^>]*>(\d{6})<", mails_to(email, "Your code to sign")[-1]["body"]["content"]).group(1)
    return s.post(link + "/verify", data={"_csrf": pcsrf(s, link), "code": code})


def cleanup():
    q("delete from documents where title like '%%Zz234%%'")
    q("delete from portal_users where email like '%%@docsign.example'")
    q("delete from contacts where email like '%%@docsign.example'")
    q("delete from compliance_frameworks where slug like 'zz234-%%'")


cleanup()
try:
    requests.get(M + "/mock/graph-reset")
    MQ0 = q("select coalesce(max(id), 0) m from mail_queue")[0]["m"]
    AV = contact(C, "Avery Zz234 Lane", "avery@docsign.example")
    BL = contact(C, "Blake <b>Zz234</b> Moss", "blake@docsign.example")
    CA = contact(OTHER, "Casey Zz234 Other", "casey@docsign.example")
    q("insert into documents (client_id, title, category, status, body_html, version) values (%s, %s, 'policy', 'active', %s, 3)",
      C, "Zz234 Acceptable <Use> Policy", "<h1>Acceptable use</h1><p>Zz234 staff use company devices for work.</p><ul><li>Lock your screen</li></ul>")
    P = q("select id from documents where title = 'Zz234 Acceptable <Use> Policy'")[0]["id"]
    q("insert into documents (client_id, title, category, status, body_html) values (%s, 'Zz234 Draft plan', 'plan', 'draft', '<p>x</p>')", C)
    DRAFT = q("select id from documents where title = 'Zz234 Draft plan'")[0]["id"]

    # ======== roles and refusals
    t = tech.get(B + f"/documents/{P}").text
    ok(not errs(t) and 'id="signatures"' in t and "Send for signature" in t and "Not sent for signature yet." in t, "a client document has a Signatures card with Send for signature")
    ok("Blake &lt;b&gt;Zz234&lt;/b&gt; Moss" in t and "<b>Zz234</b>" not in t and "Casey Zz234" not in t, "the send form lists the client's own contacts, escaped")
    ok("data-doc-requests" in t, "the actions row leaves room for another kind of request")
    vt = viewer.get(B + f"/documents/{P}").text
    ok('id="signatures"' in vt and "Send for signature" not in vt, "viewers see the card but can't send")
    ok(viewer.post(B + f"/documents/{P}/signatures", data={"_csrf": csrf(viewer, f"/documents/{P}"), "signers[]": [str(AV)]}).status_code == 403, "viewers can't send (403)")
    ok("Set the document to Active" in flash(send(DRAFT, [AV]).text), "a draft can't be sent")
    ok("Choose at least one person" in flash(send(P, [CA]).text) and not q("select 1 from doc_sign_requests where document_id = %s", P), "another client's contact is ignored: nobody to send to")
    import io, zipfile
    zb = io.BytesIO()
    with zipfile.ZipFile(zb, "w") as z:
        z.writestr("[Content_Types].xml", "<Types/>")
        z.writestr("word/document.xml", "<w:document/>")
    DOCX = zb.getvalue()
    r = tech.post(B + "/documents/upload", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "client_id": str(C), "title": "Zz234 Word file"}, files={"file": ("w.docx", DOCX, "application/octet-stream")})
    WORD = q("select id from documents where title = 'Zz234 Word file'")
    if WORD:
        ok("Upload it as a PDF to send it for signature" in flash(send(WORD[0]["id"], [AV]).text), "a Word file is refused: upload it as a PDF")
    else:
        ok(False, "the Word file uploaded: " + flash(r.text))

    # ======== a written document to two signers, with the code, a countersignature and "ask again"
    r = send(P, [AV, BL, AV, CA], verify_code=True, countersign=True, resign=True, message="Please sign by Friday <b>thanks</b>")
    ok("Sent for signature to 2 people" in flash(r.text), "sent to two people (a repeat and another client's contact left out): " + flash(r.text))
    R = last_request(P)
    S = signers(R["id"])
    ok(R["status"] == "sent" and R["verify_code"] == 1 and R["countersign"] == 1 and R["resign_on_change"] == 1 and R["doc_version"] == 3 and len(S) == 2
       and [x["email"] for x in S] == ["avery@docsign.example", "blake@docsign.example"], "the request and its two signers are saved")
    srcpath = UPLOADS + "/documents/" + R["source_file"]
    SRC = open(srcpath, "rb").read()
    ok(SRC.startswith(b"%PDF-") and hashlib.sha256(SRC).hexdigest() == R["source_hash"] and "Zz234 Acceptable <Use> Policy" in pdf_text(SRC)
       and "Lock your screen" in pdf_text(SRC) and "Version 3" in pdf_text(SRC), "the written document is printed to a PDF and its SHA-256 kept")
    ma, mb = mails_to("avery@docsign.example", "Please review and sign"), mails_to("blake@docsign.example", "Please review and sign")
    ok(len(ma) == 1 and len(mb) == 1 and "Please sign by Friday &lt;b&gt;thanks&lt;/b&gt;" in ma[0]["body"]["content"] and "Review and sign" in ma[0]["body"]["content"],
       "each signer gets their own email (the message escaped)")
    LA, LB = link_in(ma[0]), link_in(mb[0])
    ok(LA != LB and LA.split("/")[-1] not in json.dumps(q("select * from doc_sign_signers where request_id = %s", R["id"]), default=str)
       and S[0]["token_hash"] == hashlib.sha256(LA.split("/")[-1].encode()).hexdigest(), "private links: only their hashes are stored")
    ok(not q("select 1 from mail_queue where kind = 'docsign_sign' and purged = 0 and status = 'sent'"), "the sent emails' bodies (with the links) are wiped from the outbox")
    t = tech.get(B + f"/documents/{P}").text
    ok('data-sign-status>Sent<' in t and "Avery Zz234 Lane" in t and "Remind" in t and "Cancel" in t, "the document page shows Sent with the signers and actions")

    # ---- Avery: the code first, then signs
    sa = requests.Session()
    t = sa.get(LA).text
    ok("Email me a code" in t and "Lock your screen" not in t and 'docsign-form' not in t and "Zz234 Acceptable &lt;Use&gt; Policy" in t, "the link asks for a code first (the title escaped)")
    ok(sa.get(LA + "/file").status_code == 403, "the PDF isn't sent before the code")
    sa.post(LA + "/code", data={"_csrf": pcsrf(sa, LA)})
    r = sa.post(LA + "/verify", data={"_csrf": pcsrf(sa, LA), "code": "000000"})
    ok("isn't right" in flash(r.text), "a wrong code is refused")
    r = code_open(sa, LA, "avery@docsign.example")
    t = r.text
    ok('id="docsign-form"' in t and "Sign the document" in t and f'data-src="/portal/docsign/{LA.split("/")[-1]}/file"' in t and "sign this document electronically" in t and not errs(t),
       "the right code opens the PDF and the signature form")
    f = sa.get(LA + "/file")
    ok(f.status_code == 200 and f.content == SRC and "sandbox" in f.headers.get("Content-Security-Policy", ""), "the PDF that was sent is shown, sandboxed")
    r = sa.post(LA, data={"_csrf": pcsrf(sa, LA), "sig_kind": "type", "sig_typed": "Avery Lane", "sig_name": "Avery Lane"})
    ok("tick the box" in flash(r.text), "signing needs the consent box")
    r = sign(sa, LA, typed="Avery Lane", name="Avery Lane")
    ok("Thank you" in r.text and "1 of 2 people have signed" in r.text, "Avery signs: 1 of 2")
    ok(signers(R["id"])[0]["status"] == "signed" and json.loads(signers(R["id"])[0]["signature"])["via"] == "code", "the signature records that the code was entered")
    ok("Signed by 1 of 2" in tech.get(B + f"/documents/{P}").text, "the document page shows Signed by 1 of 2")
    ok(sign(sa, LA, typed="Again").status_code == 200 and signers(R["id"])[0]["signed_at"] == q("select signed_at from doc_sign_signers where id=%s", S[0]["id"])[0]["signed_at"]
       and len(q("select 1 from doc_sign_events where request_id=%s and event='signed'", R["id"])) == 1, "signing twice does nothing")
    # one signer can't sign for another: Avery's verified session doesn't open Blake's link
    t = sa.get(LB).text
    ok("Email me a code" in t and "docsign-form" not in t, "Avery's session still needs Blake's own code for Blake's link")
    ok(requests.get(LA[:-3] + "AAA").status_code == 404 and "expired or was replaced" in requests.get(B + "/portal/docsign/not-a-token").text, "a wrong token is refused")

    # ---- remind and send again
    r = tech.post(B + f"/documents/{P}/signatures/{R['id']}/remind", data={"_csrf": csrf(tech, f"/documents/{P}")})
    rem_b, rem_a = mails_to("blake@docsign.example", "Reminder: please sign"), mails_to("avery@docsign.example", "Reminder: please sign")
    ok("Reminder sent to 1 person" in flash(r.text) and len(rem_b) == 1 and not rem_a and link_in(rem_b[0]) == LB, "a reminder goes to Blake only, with the same link")
    r = tech.post(B + f"/documents/{P}/signatures/{R['id']}/resend", data={"_csrf": csrf(tech, f"/documents/{P}")})
    LB2 = link_in(mails_to("blake@docsign.example", "Please review and sign")[-1])
    ok("Sent again to 1 person" in flash(r.text) and LB2 != LB and requests.get(LB).status_code == 404 and requests.get(LB2).status_code == 200, "send again: a new link for Blake; the old one stops working")
    ok(sign(sa, LA).status_code == 200 and signers(R["id"])[0]["status"] == "signed", "Avery's link still shows where it stands")

    # ---- the client portal: only the signer's own
    pb = portal_user("blake@docsign.example")
    pd = portal_user("dana@docsign.example")
    po = portal_user("blake@docsign.example".replace("blake", "olly"), client=OTHER)
    SB = S[1]["id"]
    t = pb.get(B + "/portal").text
    ok("Waiting for your signature" in t and "Zz234 Acceptable &lt;Use&gt; Policy" in t and f'href="/portal/signatures/{SB}"' in t and not errs(t), "the signer sees Waiting for your signature on the portal home")
    ok("Waiting for your signature" in pb.get(B + "/portal/documents").text, "and on the portal's Documents page")
    ok("Waiting for your signature" not in pd.get(B + "/portal").text and pd.get(B + f"/portal/signatures/{SB}").status_code == 404, "another portal user of the same client doesn't see it and can't open it")
    ok(po.get(B + f"/portal/signatures/{SB}").status_code == 404, "nor can another client's portal user")
    r = pd.get(LB2)
    ok(r.status_code == 403 and "for someone else" in r.text and "docsign-form" not in r.text, "the emailed link refuses to open for a portal user signed in as someone else")
    r = pb.get(B + f"/portal/signatures/{SB}")
    ok(r.url.startswith(B + "/portal/docsign/") and "docsign-form" in r.text and "opened it from the client portal" in r.text and "Email me a code" not in r.text,
       "from the portal the signer opens it without the emailed code")
    PL = r.url
    r = sign(pb, PL, sig_kind="draw", sig_png=drawn_png(), name="Blake Moss")
    ok("Thank you" in r.text and "will countersign next" in r.text, "Blake signs from the portal (drawn): waiting for the countersignature")
    sg = json.loads(signers(R["id"])[1]["signature"])
    ok(sg["kind"] == "drawn" and sg["via"] == "portal" and not sg["code_verified"], "the signature records the portal sign-in")
    ok("Waiting for your signature" not in pb.get(B + "/portal").text, "nothing waits for Blake any more")

    # ---- the countersignature and the signed copy
    t = tech.get(B + f"/documents/{P}").text
    ok("Waiting for countersignature" in t and f'id="modal-countersign-{R["id"]}"' in t and "contracts.js" in t, "the document page waits for the countersignature")
    u = f"/documents/{P}/signatures/{R['id']}/countersign"
    r = tech.post(B + u, data={"_csrf": csrf(tech, f"/documents/{P}"), "sig_kind": "type", "sig_typed": "Tess Tech", "sig_name": "Tess Tech"})
    ok("Tick the box" in flash(r.text), "the countersignature needs the consent box")
    ok(viewer.post(B + u, data={"_csrf": csrf(viewer, f"/documents/{P}"), "sig_kind": "type", "sig_typed": "V", "sig_name": "V", "consent": "1"}).status_code == 403, "viewers can't countersign")
    r = tech.post(B + u, data={"_csrf": csrf(tech, f"/documents/{P}"), "sig_kind": "type", "sig_typed": "Tess Tech", "sig_name": "Tess Tech", "sig_title": "vCIO", "consent": "1"})
    R = last_request(P)
    ok("Countersigned" in flash(r.text) and R["status"] == "completed" and R["signed_file"] and R["completed_at"], "countersigned: completed, with the signed copy")
    d = tech.get(B + f"/documents/{P}/signatures/{R['id']}/pdf")
    pt = pdf_text(d.content)
    ok(d.status_code == 200 and d.content.startswith(SRC) and hashlib.sha256(d.content).hexdigest() == R["signed_hash"], "the signed copy is the PDF that was sent with pages added after it")
    ok("Signatures and certificate" in pt and "Avery Lane" in pt and "Blake Moss" in pt and "Countersigned by: Tess Tech" in pt and R["source_hash"] in pt
       and "entered the one-time code emailed" in pt and "client portal" in pt and "IP address" in pt and re.search(r"Page 2 of [23]\b", pt), "the certificate: signers, how each was identified, IPs, the fingerprint")
    ok("Completed" in tech.get(B + f"/documents/{P}").text and "Signed PDF" in tech.get(B + f"/documents/{P}").text, "the document page shows Completed and the signed PDF")
    ok(len(mails_to("avery@docsign.example", "Signed: ")) == 1 and len(mails_to("blake@docsign.example", "Signed: ")) == 1, "each signer is emailed the signed copy")
    t = sa.get(LA).text
    ok("Signed by everyone" in t and "Download the signed PDF" in t and sa.get(LA + "/pdf").content == d.content, "the signer's link offers the signed copy")
    ok(viewer.get(B + f"/documents/{P}/signatures/{R['id']}/pdf").status_code == 200, "any staff role can download the signed copy (like the document)")
    ok(audit("document.sign_sent", P) == 1 and audit("document.signed", P) == 2 and audit("document.countersigned", P) == 1 and audit("document.sign_completed", P) == 1,
       "sending, each signature, the countersignature and completion are audited")
    ok(q("select count(*) n from doc_sign_events where request_id = %s and event in ('code_ok','portal','signed','countersigned','completed')", R["id"])[0]["n"] >= 6, "the signing trail is kept")

    # ---- "Needs signing again" after the document changes (never emailed by itself)
    before = len(mails())
    v = q("select version from documents where id = %s", P)[0]["version"]
    r = tech.post(B + f"/documents/{P}/save", data={"_csrf": csrf(tech, f"/documents/{P}"), "base_version": str(v), "body": "<h1>Acceptable use</h1><p>Zz234 changed wording.</p>"})
    t = tech.get(B + f"/documents/{P}").text
    ok(r.status_code == 200 and "data-needs-resign" in t and f"/signatures/{R['id']}/again" in t and len(mails()) == before, "after a change it needs signing again, and nothing is emailed by itself")
    r = tech.post(B + f"/documents/{P}/signatures/{R['id']}/again", data={"_csrf": csrf(tech, f"/documents/{P}")})
    R2 = last_request(P)
    ok("Sent for signature again" in flash(r.text) and R2["id"] != R["id"] and len(signers(R2["id"])) == 2 and R2["countersign"] == 1 and R2["resign_on_change"] == 1
       and "changed wording" in pdf_text(open(UPLOADS + "/documents/" + R2["source_file"], "rb").read()), "Send again: the same people and options, with the document as it is now")
    ok("data-needs-resign" not in tech.get(B + f"/documents/{P}").text, "the flag goes once it's sent again")
    tech.post(B + f"/documents/{P}/signatures/{R2['id']}/cancel", data={"_csrf": csrf(tech, f"/documents/{P}")})

    # ======== an uploaded PDF, as it is: no code, no countersignature (signed in a browser)
    PDF = base64.b64decode(php('$p = new Align\\Pdf\\Pdf(612, 792); $p->addPage(); $p->text(72, 100, "Zz234 uploaded security policy", "Helvetica", 14); $p->addPage(); $p->text(72, 100, "Page two", "Helvetica", 12); echo base64_encode($p->output());').stdout)
    r = tech.post(B + "/documents/upload", data={"_csrf": csrf(tech, f"/clients/{C}/documents"), "client_id": str(C), "title": "Zz234 Uploaded policy"}, files={"file": ("policy.pdf", PDF, "application/pdf")})
    F = q("select id, file_name from documents where title = 'Zz234 Uploaded policy'")[0]
    ok("Sent for signature to 1 person" in flash(send(F["id"], [AV]).text), "an uploaded PDF is sent")
    RF = last_request(F["id"])
    ok(RF["source_file"] == F["file_name"] and RF["source_hash"] == hashlib.sha256(PDF).hexdigest() and RF["source_pages"] == 2, "the uploaded PDF is used as it is")
    LF = link_in(mails_to("avery@docsign.example", "Please review and sign")[-1])
    with sync_playwright() as p:
        b = p.chromium.launch()
        pg = b.new_page(viewport={"width": 1100, "height": 900})
        pg.goto(LF)
        pg.wait_for_selector(".pv-pages canvas, .pv-pages .pv-page", timeout=15000)
        ok(pg.locator("#docsign-form").count() == 1 and pg.locator("text=Email me a code").count() == 0, "without the code option the link opens the document directly, pages painted")
        pg.click("[data-sig-tab=draw]")
        box = pg.locator("[data-sig-canvas]").bounding_box()
        pg.mouse.move(box["x"] + 30, box["y"] + 100); pg.mouse.down()
        for i in range(1, 12): pg.mouse.move(box["x"] + 30 + i * 35, box["y"] + 100 - (i % 3) * 30)
        pg.mouse.up()
        pg.check("#ds-consent")
        pg.click("#docsign-form button.btn-primary")
        pg.wait_for_load_state()
        ok("Signed by everyone" in pg.content(), "signed in the browser with a drawn signature: complete straight away")
        b.close()
    RF = last_request(F["id"])
    sf = json.loads(signers(RF["id"])[0]["signature"])
    signed = open(UPLOADS + "/documents/" + RF["signed_file"], "rb").read()
    ok(RF["status"] == "completed" and sf["kind"] == "drawn" and sf["via"] == "link" and signed.startswith(PDF) and "Opened the private signing link" in pdf_text(signed)
       and "Page 3 of 3" in pdf_text(signed), "completed: the uploaded PDF byte for byte, then the certificate")

    # ---- decline
    send(F["id"], [AV, BL])
    RD = last_request(F["id"])
    ld_a, ld_b = link_in(mails_to("avery@docsign.example", "Please review and sign")[-1]), link_in(mails_to("blake@docsign.example", "Please review and sign")[-1])
    sb = requests.Session()
    r = sb.post(ld_b + "/decline", data={"_csrf": pcsrf(sb, ld_b), "reason": "<script>alert('no')</script> wrong version"})
    ok("declined this document" in flash(r.text) and last_request(F["id"])["status"] == "declined", "a signer declines: the request is declined")
    t = requests.get(ld_a).text
    ok("This document was declined" in t and "docsign-form" not in t, "the other signer's link can't sign it any more")
    t = tech.get(B + f"/documents/{F['id']}").text
    ok("Declined" in t and "&lt;script&gt;alert(&#039;no&#039;)&lt;/script&gt; wrong version" in t and "<script>alert('no')" not in t, "the reason shows on the document page, escaped")
    ok(q("select count(*) n from mail_queue where id > %s and kind = 'docsign_staff' and subject = 'Declined: Zz234 Uploaded policy'", MQ0)[0]["n"] == 1, "the sender is told")

    # ---- cancel and expiry
    send(F["id"], [AV])
    RC = last_request(F["id"])
    lc = link_in(mails_to("avery@docsign.example", "Please review and sign")[-1])
    r = tech.post(B + f"/documents/{F['id']}/signatures/{RC['id']}/cancel", data={"_csrf": csrf(tech, f"/documents/{F['id']}")})
    ok("Cancelled" in flash(r.text) and requests.get(lc).status_code == 404 and last_request(F["id"])["status"] == "cancelled", "cancel: the link stops working at once")
    send(F["id"], [AV])
    RE = last_request(F["id"])
    le = link_in(mails_to("avery@docsign.example", "Please review and sign")[-1])
    q("update doc_sign_requests set expires_at = now() - interval 1 hour where id = %s", RE["id"])
    q("update doc_sign_signers set token_expires_at = now() - interval 1 hour where request_id = %s", RE["id"])
    ok(requests.get(le).status_code == 404 and last_request(F["id"])["status"] == "expired", "an expired link is refused and the request marked expired")
    ok("Expired" in tech.get(B + f"/documents/{F['id']}").text, "the document page shows Expired")
    r = tech.post(B + f"/documents/{F['id']}/signatures/{RE['id']}/resend", data={"_csrf": csrf(tech, f"/documents/{F['id']}")})
    le2 = link_in(mails_to("avery@docsign.example", "Please review and sign")[-1])
    ok(last_request(F["id"])["status"] == "sent" and requests.get(le2).status_code == 200, "an expired request can be sent again with new links")
    q("update doc_sign_requests set expires_at = now() - interval 1 hour where id = %s", RE["id"])
    php("Align\\Docs\\Signing::hourly();")
    ok(last_request(F["id"])["status"] == "expired", "the hourly run expires requests whose links passed their date")

    # ---- the emailed code locks out (2.34.0 review): CODE_ATTEMPTS wrong tries, CODE_SENDS sends a day
    send(F["id"], [AV], verify_code=True)
    RL = last_request(F["id"])
    ll = link_in(mails_to("avery@docsign.example", "Please review and sign")[-1])
    sl = requests.Session()
    ok(sl.get(ll).headers.get("Referrer-Policy") == "no-referrer" and requests.get(B + "/portal/docsign/not-a-token").headers.get("Referrer-Policy") == "no-referrer",
       "signing pages send Referrer-Policy: no-referrer")
    sl.post(ll + "/code", data={"_csrf": pcsrf(sl, ll)})
    code = re.search(r"letter-spacing:6px[^>]*>(\d{6})<", mails_to("avery@docsign.example", "Your code to sign")[-1]["body"]["content"]).group(1)
    wrong = "000000" if code != "000000" else "111111"
    for _ in range(5):  # Contracts::CODE_ATTEMPTS
        r = sl.post(ll + "/verify", data={"_csrf": pcsrf(sl, ll), "code": wrong})
    ok("Too many tries" in flash(r.text), "after 5 wrong codes: too many tries")
    r = sl.post(ll + "/verify", data={"_csrf": pcsrf(sl, ll), "code": code})
    ok("docsign-form" not in r.text and sl.get(ll + "/file").status_code == 403, "then even the right code doesn't open it")
    q("update doc_sign_signers set code_sent_count = 10, code_sent_at = now() - interval 2 minute where request_id = %s", RL["id"])  # Contracts::CODE_SENDS
    n = len(mails_to("avery@docsign.example", "Your code to sign"))
    r = sl.post(ll + "/code", data={"_csrf": pcsrf(sl, ll)})
    ok("Too many codes" in flash(r.text) and len(mails_to("avery@docsign.example", "Your code to sign")) == n, "after 10 codes in a day no more are sent")
    tech.post(B + f"/documents/{F['id']}/signatures/{RL['id']}/cancel", data={"_csrf": csrf(tech, f"/documents/{F['id']}")})

    # ---- the PDF that was sent changed on the server: no signed copy is stamped onto it (2.34.0 review)
    send(P, [AV])
    RS = last_request(P)
    ls = link_in(mails_to("avery@docsign.example", "Please review and sign")[-1])
    with open(UPLOADS + "/documents/" + RS["source_file"], "ab") as fh:
        fh.write(b"\n% changed\n")
    r = sign(requests.Session(), ls, typed="Avery Lane", name="Avery Lane")
    RS = last_request(P)
    t = tech.get(B + f"/documents/{P}").text
    ok(RS["status"] == "completed" and RS["signed_file"] is None and "data-source-changed" in t and "file changed after it was sent" in t, "the signed copy isn't made; the page says to send it again")
    php("Align\\Docs\\Signing::hourly();")
    ok(last_request(P)["signed_file"] is None and audit("document.sign_source_changed", P) == 1
       and not q("select 1 from doc_sign_events where request_id = %s and event = 'pdf_failed'", RS["id"]), "the hourly run doesn't try again; audited once")

    # ---- another document's request is refused
    ok(tech.get(B + f"/documents/{F['id']}/signatures/{R['id']}/pdf").status_code == 404, "a request can't be reached through another document's address")
    ok(tech.post(B + f"/documents/{F['id']}/signatures/{R2['id']}/remind", data={"_csrf": csrf(tech, f"/documents/{F['id']}")}).status_code == 404, "nor acted on")

    # ======== the compliance report, sent for signature
    q("insert into compliance_frameworks (slug, name, description) values ('zz234-main', 'Zz234 Baseline <FW>', 'Zz234 made-up baseline.')")
    FW = q("select id from compliance_frameworks where slug = 'zz234-main'")[0]["id"]
    q("insert into compliance_frameworks (slug, name) values ('zz234-other', 'Zz234 Other')")
    FW2 = q("select id from compliance_frameworks where slug = 'zz234-other'")[0]["id"]
    q("insert into client_frameworks (client_id, framework_id) values (%s, %s)", C, FW)
    q("insert into client_frameworks (client_id, framework_id) values (%s, %s)", OTHER, FW2)
    q("insert into compliance_controls (framework_id, ref, section, title, sort) values (%s, 'ZZ-1', 'Access', 'Zz234 MFA on email', 1), (%s, 'ZZ-2', 'Access', 'Zz234 Screen lock', 2)", FW, FW)
    ctl = q("select id from compliance_controls where framework_id = %s and ref = 'ZZ-1'", FW)[0]["id"]
    q("insert into client_control_status (client_id, control_id, status, notes, owner) values (%s, %s, 'met', 'Zz234 internal note', 'Office manager')", C, ctl)
    t = tech.get(B + f"/clients/{C}/report/compliance/{FW}").text
    ok(f'href="/clients/{C}/report/compliance/{FW}/sign"' in t, "the staff compliance report has Send for signature")
    ok("/sign\"" not in viewer.get(B + f"/clients/{C}/report/compliance/{FW}").text and viewer.get(B + f"/clients/{C}/report/compliance/{FW}/sign").status_code == 403, "not for viewers")
    t = tech.get(B + f"/clients/{C}/report/compliance/{FW}/sign").text
    ok(not errs(t) and "Zz234 Baseline &lt;FW&gt;" in t and "Avery Zz234 Lane" in t, "the send page lists the client's contacts")
    ok(tech.get(B + f"/clients/{C}/report/compliance/{FW2}/sign").status_code == 404, "a framework the client doesn't have is a 404")
    r = tech.post(B + f"/clients/{C}/report/compliance/{FW}/sign", data={"_csrf": csrf(tech, f"/clients/{C}/report/compliance/{FW}/sign"), "signers[]": [str(CA)]})
    ok("Choose at least one person" in flash(r.text) and not q("select 1 from documents where title like 'Zz234 Baseline%%'"), "nobody chosen: nothing is kept")
    r = tech.post(B + f"/clients/{C}/report/compliance/{FW}/sign", data={"_csrf": csrf(tech, f"/clients/{C}/report/compliance/{FW}/sign"), "signers[]": [str(AV)], "verify_code": "1"})
    rd = q("select * from documents where title like 'Zz234 Baseline%%'")
    ok(len(rd) == 1 and rd[0]["kind"] == "file" and rd[0]["category"] == "assessment" and rd[0]["status"] == "active" and rd[0]["portal_shared"] == 0 and rd[0]["client_id"] == C
       and f"/documents/{rd[0]['id']}" in r.url, "the report is kept as a client document (Assessment, not shared)")
    RR = last_request(rd[0]["id"])
    rt = pdf_text(open(UPLOADS + "/documents/" + RR["source_file"], "rb").read())
    ok(RR["status"] == "sent" and "ZZ-1" in rt and "Zz234 Screen lock" in rt and "Score" in rt and "Office manager" in rt and "internal note" not in rt,
       "and sent for signature: the client's version (no notes)")

    # ======== contracts are untouched
    ok(tech.get(B + "/contracts").status_code == 200 and not errs(tech.get(B + "/contracts").text), "the contracts list still works")
finally:
    cleanup()
done()
