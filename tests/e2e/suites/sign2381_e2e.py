"""2.38.1 A written document sent for signature gets its own approval block filled in: the PDF that is sent prints
the block ("Approved by / Signature / Date approved") with blanks for each signer (one block per signer, then
"Countersigned by"), the next review date filled in; once everyone has signed, the signed copy has each signer's name
and title, signature and date stamped into those blanks, after the bytes that were sent (fingerprint unchanged), and
the certificate says so. A document without a block (or one filled in by hand) gets an "Approval" block at the end.
The document itself never changes; acknowledgments and uploaded PDFs are as before.
Everything is made up (Zz2381, *.example addresses)."""
import hashlib
import json
import zlib
from lib import *

C = 1  # Cedar Ridge Family Dental, Inc.
tech = login("tech@example.com", TECH_PASSWORD)


def local(u): return re.sub(r"^https?://[^/]+", B, u)
def mails(): return requests.get(M + "/mock/graph").json()["mail"]
def link(addr):
    msg = [m["message"] for m in mails() if "Please review and sign" in m["message"]["subject"] and any(r["emailAddress"]["address"] == addr for r in m["message"]["toRecipients"])][-1]
    return local(re.search(r'href="(https?://[^"]+/portal/docsign/[A-Za-z0-9_-]+)"', msg["body"]["content"]).group(1))
def pcsrf(s, url): return re.search(r'name="_csrf" value="([^"]+)"', s.get(url).text).group(1)
def last_request(doc): return q("select * from doc_sign_requests where document_id = %s order by id desc limit 1", doc)[0]


def pdf_text(b):
    """Text from Align's PDFs (Flate streams, hex strings in Windows-1252)."""
    out = []
    for m in re.finditer(rb"/Length (\d+)[^>]*>>\s*stream\r?\n", b):
        s = b[m.end():m.end() + int(m.group(1))]
        try: s = zlib.decompress(s)
        except Exception: pass
        out += [bytes.fromhex(h.decode()).decode("cp1252", "replace") for h in re.findall(rb"<([0-9a-f]+)> Tj", s)]
    return re.sub(r"\s+", " ", " ".join(out))


def add_doc(title, body, review="2027-11-19"):
    q("insert into documents (client_id, title, category, kind, status, body_html, review_due, created_by) values (%s, %s, 'policy', 'page', 'active', %s, %s, 1)", C, title, body, review)
    return q("select id from documents where title = %s", title)[0]["id"]


def contact(name, email):
    q("insert into contacts (client_id, source, name, email, title) values (%s, 'manual', %s, %s, 'Manager')", C, name, email)
    return q("select id from contacts where email = %s", email)[0]["id"]


def send(doc, ids, countersign=False):
    data = {"_csrf": csrf(tech, f"/documents/{doc}"), "signers[]": [str(i) for i in ids], "days": "30", "message": ""}
    if countersign:
        data["countersign"] = "1"
    return tech.post(B + f"/documents/{doc}/signatures", data=data)


def sign(addr, typed, title):
    s = requests.Session()
    u = link(addr)
    return s.post(u, data={"_csrf": pcsrf(s, u), "sig_kind": "type", "sig_typed": typed, "sig_name": typed, "sig_title": title, "consent": "1"})


def source(r): return open(UPLOADS + "/documents/" + r["source_file"], "rb").read()


def cleanup():
    q("delete from documents where title like '%%Zz2381%%'")
    q("delete from contacts where email like '%%@sign2381.example'")


cleanup()
# 2.38.1 every built-in template has an approval block Align fills in, and migration 101 brought each up to its file
res = php("$out = []; foreach (Align\\DB::all('SELECT slug, body_html FROM document_templates WHERE is_builtin = 1') as $t) { $f = APP_ROOT . '/db/templates/' . $t['slug'] . '.html';"
          " $out[$t['slug']] = [Align\\Docs\\SignBlock::find((string) $t['body_html']) !== null, is_file($f) && file_get_contents($f) === $t['body_html']]; } echo json_encode($out);")
tp = json.loads(res.stdout or "{}")
ok(len(tp) >= 30 and all(a for a, _ in tp.values()), f"every built-in template has an approval block: {[k for k, (a, _) in tp.items() if not a]}")
ok(all(b for _, b in tp.values()), f"each is its file's current version after the update: {[k for k, (_, b) in tp.items() if not b]}")
# 2.38.1 review: the approval block is the last one (not a form earlier), labels are whole words, nested line breaks count
sb = php("echo json_encode(array_map(fn($h) => Align\\Docs\\SignBlock::find($h)['before'] ?? null, ["
         "'<p>Manager approval: ____<br>Signature: ____<br>Date: ____</p><h2>Approval</h2><p>Approved by: [name, title]<br>Signature: ____<br>Date approved: [date]</p>',"
         "'<p>Ticket assigned to: ____<br>Date opened: ____</p>', '<p><span>Approved by: [name, title]<br>Signature: ____<br>Date: [date]</span></p>']));")
sbr = json.loads(sb.stdout or "[]")
ok(len(sbr) == 3 and "Manager approval" in (sbr[0] or "") and sbr[1] is None and sbr[2] == "", f"the last approval block is used; 'assigned' isn't 'signed'; a line break inside bold text counts: {sbr}")
try:
    tpl = q("select body_html from document_templates where slug = 'remote-access-policy'")[0]["body_html"]
    D = add_doc("Zz2381 Remote Access Policy", tpl)
    before = q("select body_html, version from documents where id = %s", D)[0]
    A = contact("Avery Zz", "avery@sign2381.example")
    Bb = contact("Blake Zz", "blake@sign2381.example")

    # ---- the PDF that is sent: a blank block per signer, then the countersigner
    r = send(D, [A, Bb], countersign=True)
    R = last_request(D)
    slots = json.loads(R["sign_slots"] or "[]")
    ok(R["status"] == "sent" and [s["who"] for s in slots] == [0, 1, "counter"], f"blanks for each signer and the countersigner: {[s['who'] for s in slots]}")
    SRC = source(R)
    st = pdf_text(SRC)
    ok(st.count("Approved by:") == 2 and "Countersigned by:" in st and st.count("Signature:") >= 3 and st.count("Date approved:") == 3, "the approval block prints once per signer")
    ok("Approved by: [name, title]" not in st and "Next review date: Nov 19, 2027" in st, "its placeholders are blanks, and the next review date is filled in")
    ok(all(0 <= s["page"] < R["source_pages"] and len(s["sig"]) == 4 for s in slots), "each blank is on a page of the PDF that was sent")

    # ---- everyone signs: the signed copy has them in the block
    sign("avery@sign2381.example", "Avery Zz", "Managing Partner")
    sign("blake@sign2381.example", "Blake Zz", "Office Manager")
    r = tech.post(B + f"/documents/{D}/signatures/{R['id']}/countersign", data={"_csrf": csrf(tech, f"/documents/{D}"), "sig_kind": "type", "sig_typed": "Tess Tech", "sig_name": "Tess Tech", "sig_title": "vCIO", "consent": "1"})
    R = last_request(D)
    ok(R["status"] == "completed" and R["signed_file"], "completed, with the signed copy")
    d = tech.get(B + f"/documents/{D}/signatures/{R['id']}/pdf")
    pt = pdf_text(d.content)
    ok(d.content.startswith(SRC) and hashlib.sha256(SRC).hexdigest() == R["source_hash"], "the pages that were sent are unchanged (the fingerprint still matches)")
    stamped = pt[len(st):] if pt.startswith(st) else pt
    ok("Avery Zz, Managing Partner" in pt and "Blake Zz, Office Manager" in pt and "Tess Tech, vCIO" in pt, "each signer's name and title is in the block")
    today = php("echo Align\\Fmt::date(date('Y-m-d'));").stdout.strip()
    ok(pt.count(today) >= 3, f"with the date each signed ({today})")
    ok("added when it was signed" in pt and "Signatures and certificate" in pt, "the certificate says the block was filled in when it was signed")
    after = q("select body_html, version from documents where id = %s", D)[0]
    ok(after == before, "the document itself isn't changed")

    # ---- no approval block: one is added at the end
    E = add_doc("Zz2381 Handwritten policy", "<h1>Handwritten policy</h1><p>" + "Staff lock their screens. " * 20 + "</p>")
    send(E, [A])
    RE = last_request(E)
    et = pdf_text(source(RE))
    ok("Approval" in et and "Signed by:" in et and "Signature:" in et and len(json.loads(RE["sign_slots"])) == 1, "a document without a block gets an Approval block at the end")
    sign("avery@sign2381.example", "Avery Zz", "Managing Partner")
    RE = last_request(E)
    ok(RE["status"] == "completed" and "Avery Zz, Managing Partner" in pdf_text(tech.get(B + f"/documents/{E}/signatures/{RE['id']}/pdf").content), "and it's filled in when signed")

    # ---- a block someone filled in by hand is kept as it is; Align adds its own at the end
    F = add_doc("Zz2381 Filled policy", "<h1>Filled</h1><p>" + "Rules apply. " * 20 + "</p><p>Approved by: Jordan Roe, CEO<br>Signature: ______________________<br>Date approved: Jan 5, 2026</p>")
    send(F, [Bb])
    ft = pdf_text(source(last_request(F)))
    ok("Approved by: Jordan Roe, CEO" in ft and "Signed by:" in ft, "a block filled in by hand stays; a new one is added for the signer")

    # ---- an acknowledgment isn't a signature: no blanks
    data = {"_csrf": csrf(tech, f"/documents/{D}"), "people[]": [str(A)], "pasted": "", "days": "30", "message": "", "control_id": ""}
    tech.post(B + f"/documents/{D}/acknowledgments", data=data)
    ack = q("select * from doc_sign_requests where document_id = %s and kind = 'acknowledge' order by id desc limit 1", D)
    ok(ack and ack[0]["sign_slots"] is None and "Approved by: [name, title]" in pdf_text(source(ack[0])), "an acknowledgment's PDF is printed as before, with no blanks")
finally:
    cleanup()
done()
