"""2.20.0 Uploaded files as documents: techs upload a PDF, Word, Excel, CSV, PNG or JPEG file (a file whose content
doesn't match its type, another type, an empty one and viewers are refused); it is stored under a random name and sent
back with its fixed type, nosniff and a sandbox CSP (PDFs and images inline, the rest as downloads); the page shows a
PDF in the PDF.js viewer; details, Replace file (the old file kept and restorable in the history), Import a Word file
into an editable document (headings, bold, lists, links, tables as lines; nothing unsafe), the client portal (shared,
active, own client only; current file only), print going to the file, delete removing every copy; migration 078 runs
again."""
import io, os, re, struct, zipfile, zlib, json
from lib import *

st = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
viewer = login("viewer@example.com", "ViewerPassword123!")
C = q("select id from clients where is_archived=0 and planning_excluded=0 order by id limit 1")[0]["id"]
OTHER = q("select id from clients where id<>%s and is_archived=0 order by id limit 1", C)[0]["id"]
DIR = php('echo Align\\Docs\\Files::dir();').stdout.strip()
SEC = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP"


def cleanup():
    for r in q("select file_name from documents where title like 'Zz220%%' union select v.file_name from document_versions v join documents d on d.id=v.document_id where d.title like 'Zz220%%'"):
        if r["file_name"] and os.path.exists(os.path.join(DIR, r["file_name"])):
            os.remove(os.path.join(DIR, r["file_name"]))
    q("delete from documents where title like 'Zz220%%'")
    q("delete from portal_users where email='p220@docfile.example'")


cleanup()
import atexit
atexit.register(cleanup)

# ---- files to upload
PDF = b"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
PDF2 = PDF.replace(b"612 792", b"595 842")


def png():
    raw = b"\x00\xff\x00\x00"
    chunk = lambda t, d: struct.pack(">I", len(d)) + t + d + struct.pack(">I", zlib.crc32(t + d) & 0xffffffff)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", 1, 1, 8, 2, 0, 0, 0)) + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")


W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
BODY = (f'<?xml version="1.0" encoding="UTF-8"?><w:document {W}><w:body>'
        '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Acceptable Use</w:t></w:r></w:p>'
        '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Bold rule</w:t></w:r><w:r><w:t xml:space="preserve"> and plain &lt;script&gt;alert(1)&lt;/script&gt;</w:t></w:r></w:p>'
        '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>First bullet</w:t></w:r></w:p>'
        '<w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Nested bullet</w:t></w:r></w:p>'
        '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>Step one</w:t></w:r></w:p>'
        '<w:p><w:hyperlink r:id="rId9"><w:r><w:t>our site</w:t></w:r></w:hyperlink><w:hyperlink r:id="rId8"><w:r><w:t>bad link</w:t></w:r></w:hyperlink></w:p>'
        '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>System</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Owner</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
        '<w:p><w:del><w:r><w:t>Deleted words</w:t></w:r></w:del></w:p>'
        '</w:body></w:document>')
NUM = (f'<?xml version="1.0"?><w:numbering {W}><w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum>'
       '<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>'
       '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num></w:numbering>')
RELS = ('<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId9" Type="hyperlink" Target="https://example.org/policy" TargetMode="External"/>'
        '<Relationship Id="rId8" Type="hyperlink" Target="javascript:alert(1)" TargetMode="External"/></Relationships>')


def zipped(parts):
    b = io.BytesIO()
    with zipfile.ZipFile(b, "w", zipfile.ZIP_DEFLATED) as z:
        for n, c in parts.items():
            z.writestr(n, c)
    return b.getvalue()


DOCX = zipped({"[Content_Types].xml": "<Types/>", "word/document.xml": BODY, "word/numbering.xml": NUM, "word/_rels/document.xml.rels": RELS})
XLSX = zipped({"[Content_Types].xml": "<Types/>", "xl/workbook.xml": "<workbook/>"})
DOC = b"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" + b"\0" * 504
JPG = bytes.fromhex(php('$i = imagecreatetruecolor(2, 2); ob_start(); imagejpeg($i); echo bin2hex(ob_get_clean());').stdout.strip())


def up(sess, name, data, path="/documents/upload", **form):
    page = f"/clients/{C}/documents" if path == "/documents/upload" else path.rsplit("/file", 1)[0]
    return sess.post(B + path, data={"_csrf": csrf(sess, page), "client_id": str(C), **form}, files={"file": (name, data, "application/octet-stream")})


# ---- refused uploads
for name, data, why in [("evil.pdf", b"<html><script>alert(1)</script>", "isn't a real PDF"), ("x.docx", b"not a zip at all", "isn't a real Word"),
                        ("run.exe", b"MZ\x90\x00", "Upload a PDF, Word"), ("pic.png", b"GIF89a....", "isn't a real PNG"), ("empty.pdf", b"", "empty"),
                        ("book.xlsx", DOCX, "isn't a real Excel")]:
    r = up(tech, name, data, title="Zz220 refused")
    ok(why in flash(r.text) and not q("select 1 from documents where title='Zz220 refused'"), f"{name}: refused ({why})")
ok(up(viewer, "a.pdf", PDF, title="Zz220 viewer").status_code == 403, "viewers can't upload")

# ---- a PDF: stored, shown in the viewer, sent with a fixed type
r = up(tech, "Signed Policy (final).pdf", PDF, title="Zz220 Signed policy", category="policy", portal="1")
d = q("select * from documents where title='Zz220 Signed policy'")[0]
P = d["id"]
ok(r.url.endswith(f"/documents/{P}") and d["kind"] == "file" and d["file_type"] == "pdf" and d["status"] == "active" and d["portal_shared"] == 1 and d["version"] == 1
   and d["file_orig"] == "Signed Policy (final).pdf" and d["file_size"] == len(PDF), "a PDF becomes an active document, shared as asked")
ok(re.fullmatch(r"doc-[0-9a-f]{16}\.pdf", d["file_name"]) and open(os.path.join(DIR, d["file_name"]), "rb").read() == PDF, "stored under a random name, byte for byte")
ok(q("select kind from document_versions where document_id=%s", P)[0]["kind"] == "upload" and q("select detail from audit_log where action='document.upload' order by id desc limit 1")[0]["detail"].endswith("(Signed Policy (final).pdf, 1 KB)"),
   "the history and the audit log have it")
t = tech.get(B + f"/documents/{P}").text
ok('data-pv data-mode="view"' in t and f'data-src="/documents/{P}/file"' in t and "/assets/pdfview.js" in t and "Replace file" in t and 'id="doc-editor"' not in t and not errs(t),
   "its page shows it in the PDF viewer, with no editor")
r = viewer.get(B + f"/documents/{P}/file")
h = r.headers
ok(r.content == PDF and h["content-type"] == "application/pdf" and h["x-content-type-options"] == "nosniff" and "sandbox" in h["content-security-policy"]
   and h["content-disposition"].startswith("inline"), "viewers get the file: PDF, inline, nosniff, sandboxed")
ok(viewer.get(B + f"/documents/{P}/file?download=1").headers["content-disposition"].startswith("attachment"), "...or as a download")
ok(tech.get(B + f"/documents/{P}/print", allow_redirects=False).headers.get("location") == f"/documents/{P}/file", "Print goes to the file")
t = tech.get(B + f"/clients/{C}/documents").text
ok("Upload a file" in t and 'id="modal-upload-doc"' in t and "PDF file · Signed Policy (final).pdf" in t, "the client's Documents list shows it, with Upload a file")
ok("Upload a file" in tech.get(B + "/documents").text and "Upload a file" not in viewer.get(B + "/documents").text, "the all-documents page too (not for viewers)")
ok(any(x["id"] == P for x in json.loads(php(f'echo json_encode(Align\\Docs\\Documents::forClient({C}));').stdout)), "it can be picked as compliance evidence")

# ---- other types
types = {}
for name, data, ctype, inline in [("Plan.docx", DOCX, "wordprocessingml", False), ("Old.doc", DOC, "msword", False), ("Assets.xlsx", XLSX, "spreadsheetml", False),
                                  ("List.csv", b"name,serial\nPC-1,ABC\n", "text/csv", False), ("Rack.png", png(), "image/png", True), ("Photo.jpeg", JPG, "image/jpeg", True)]:
    up(tech, name, data, title="Zz220 " + name)
    row = q("select * from documents where title=%s", "Zz220 " + name)
    if not row:
        ok(False, f"{name} uploaded")
        continue
    types[name] = row[0]["id"]
    r = tech.get(B + f"/documents/{row[0]['id']}/file")
    ok(r.content == data and ctype in r.headers["content-type"] and r.headers["content-disposition"].startswith("inline" if inline else "attachment"),
       f"{name}: kept and sent as {ctype}, {'inline' if inline else 'a download'}")
t = tech.get(B + f"/documents/{types['Rack.png']}").text
ok(f'<img src="/documents/{types["Rack.png"]}/file"' in t, "a picture is shown in the page")

# ---- details
r = tech.post(B + f"/documents/{P}/details", data={"_csrf": csrf(tech, f"/documents/{P}"), "title": "Zz220 Signed policy v2", "category": "plan", "status": "draft", "review_due": "2026-02-30"})
d = q("select * from documents where id=%s", P)[0]
ok(d["title"] == "Zz220 Signed policy v2" and d["category"] == "plan" and d["status"] == "draft" and d["review_due"] is None and "Saved" in flash(r.text), "details are saved (a date that doesn't exist is cleared)")
ok(viewer.post(B + f"/documents/{P}/details", data={"_csrf": csrf(viewer, f"/documents/{P}"), "title": "x"}).status_code == 403, "viewers can't change them")
tech.post(B + f"/documents/{P}/details", data={"_csrf": csrf(tech, f"/documents/{P}"), "title": "Zz220 Signed policy", "category": "policy", "status": "active", "review_due": "2027-01-31"})

# ---- replacing, the history, restoring
old = q("select file_name from documents where id=%s", P)[0]["file_name"]
r = up(tech, "Signed Policy A4.pdf", PDF2, path=f"/documents/{P}/file", note="Signed copy")
d = q("select * from documents where id=%s", P)[0]
vs = q("select * from document_versions where document_id=%s order by id", P)
ok("Replaced" in flash(r.text) and d["version"] == 2 and d["file_orig"] == "Signed Policy A4.pdf" and d["file_name"] != old and os.path.exists(os.path.join(DIR, old))
   and [v["file_name"] for v in vs] == [old, d["file_name"]] and vs[-1]["note"] == "Signed copy", "Replace file keeps the old one in the history")
ok(tech.get(B + f"/documents/{P}/file?v={vs[0]['id']}").content == PDF and tech.get(B + f"/documents/{P}/file").content == PDF2, "an earlier file can be downloaded from the history")
t = tech.get(B + f"/documents/{P}/versions/{vs[0]['id']}").text
ok(f'data-src="/documents/{P}/file?v={vs[0]["id"]}"' in t and "Restore this version" in t and not errs(t), "a version shows its own file, with Restore")
ok("Restore this version" not in tech.get(B + f"/documents/{P}/versions/{vs[-1]['id']}").text, "...not for the current one")
other = q("select id from document_versions where document_id<>%s and file_name is not null limit 1", P)
if other:
    ok(tech.get(B + f"/documents/{P}/file?v={other[0]['id']}").status_code == 404, "another document's version isn't sent")
tech.post(B + f"/documents/{P}/versions/{vs[0]['id']}/restore", data={"_csrf": csrf(tech, f"/documents/{P}")})
ok(q("select file_name from documents where id=%s", P)[0]["file_name"] == old and tech.get(B + f"/documents/{P}/file").content == PDF, "restoring puts the earlier file back")

# ---- importing a Word file
r = tech.post(B + f"/documents/{types['Plan.docx']}/import", data={"_csrf": csrf(tech, f"/documents/{types['Plan.docx']}")})
new = q("select * from documents where title='Zz220 Plan.docx' and kind='page'")
ok(new and "Imported as a new editable document" in flash(r.text) and r.url.endswith(f"/documents/{new[0]['id']}"), "a Word file imports into a new editable document")
if new:
    h = new[0]["body_html"]
    ok("<h1>Acceptable Use</h1>" in h and "<strong>Bold rule</strong>" in h and '<li data-list="bullet">First bullet</li>' in h and 'class="ql-indent-1"' in h
       and '<li data-list="ordered">Step one</li>' in h and "System | Owner" in h, "headings, bold, lists (with levels and numbering) and table rows come across " + h[:400])
    ok('href="https://example.org/policy"' in h and "javascript" not in h and "<script" not in h and "&lt;script&gt;" in h and "Deleted words" not in h,
       "web links stay; a javascript: link, script tags and deleted text don't")
    ok(new[0]["status"] == "draft" and new[0]["portal_shared"] == 0 and new[0]["client_id"] == C and q("select kind from documents where id=%s", types["Plan.docx"])[0]["kind"] == "file",
       "...as a Draft, not shared, and the Word file stays")
    q("update documents set title='Zz220 imported' where id=%s", new[0]["id"])
ok("Only a Word (.docx)" in flash(tech.post(B + f"/documents/{types['Old.doc']}/import", data={"_csrf": csrf(tech, f"/documents/{types['Old.doc']}")}).text), "an old .doc can't be imported")
ok("Import into an editable document" in tech.get(B + f"/documents/{types['Plan.docx']}").text and "Import into an editable document" not in tech.get(B + f"/documents/{P}").text, "the Import button is only on Word files")

# ---- the client portal
php(f'Align\\DB::insert("portal_users", ["client_id"=>{C},"email"=>"p220@docfile.example","name"=>"Pat","password_hash"=>password_hash("Doc220-Pass-123", PASSWORD_DEFAULT),"is_active"=>1,"can_documents"=>1,"totp_enabled"=>1,"totp_secret_enc"=>Align\\Crypto::encrypt("{SEC}"),"password_changed_at"=>date("Y-m-d H:i:s")]);')
import requests
ps = requests.Session()
ps.post(B + "/portal/login", data={"_csrf": csrf(ps, "/portal/login"), "email": "p220@docfile.example", "password": "Doc220-Pass-123"})
ps.post(B + "/portal/login/2fa", data={"_csrf": csrf(ps, "/portal/login/2fa"), "code": totp(SEC)})
t = ps.get(B + "/portal/documents").text
ok("Zz220 Signed policy" in t and f'href="/portal/documents/{P}/file?download=1"' in t and "Zz220 Rack.png" not in t, "the portal lists a shared file with a download button (not unshared ones)")
t = ps.get(B + f"/portal/documents/{P}").text
ok(f'data-src="/portal/documents/{P}/file"' in t and "/assets/pdfview.js" in t and not errs(t), "the portal shows it in the PDF viewer")
r = ps.get(B + f"/portal/documents/{P}/file?v={vs[-1]['id']}")
ok(r.content == PDF and "sandbox" in r.headers["content-security-policy"], "the portal gets the current file only")
ok(ps.get(B + f"/portal/documents/{types['Rack.png']}/file").status_code == 404, "an unshared file is 404")
q("update documents set status='archived' where id=%s", P)
ok(ps.get(B + f"/portal/documents/{P}/file").status_code == 404, "...and so is an archived one")
q("update documents set status='active' where id=%s", P)
q("update portal_users set client_id=%s where email='p220@docfile.example'", OTHER)
ok(ps.get(B + f"/portal/documents/{P}/file").status_code == 404, "...and another client's")
q("update portal_users set client_id=%s where email='p220@docfile.example'", C)
n0 = q("select count(*) n from audit_log where action='portal.document_download' and detail='Zz220 Signed policy'")[0]["n"]
ok(ps.get(B + f"/portal/documents/{P}/file?download=1").content == PDF and q("select count(*) n from audit_log where action='portal.document_download' and detail='Zz220 Signed policy'")[0]["n"] == n0 + 1 and n0 == 0,
   "portal downloads are audited (viewing in the page is logged as a view only)")

# ---- the editor's save and the details form each keep to their own kind; a restore that can't happen says so
r = tech.post(B + f"/documents/{P}/save", data={"_csrf": csrf(tech, f"/documents/{P}"), "base_version": "3", "body": "<p>x</p>", "status": "archived"})
ok(r.status_code == 409 and q("select status from documents where id=%s", P)[0]["status"] == "active", "the editor's autosave refuses an uploaded file")
page = q("select id from documents where title='Zz220 imported'")
if page:
    tech.post(B + f"/documents/{page[0]['id']}/details", data={"_csrf": csrf(tech, f"/documents/{page[0]['id']}"), "title": "Zz220 hijacked"})
    ok(q("select title from documents where id=%s", page[0]["id"])[0]["title"] == "Zz220 imported", "the details form doesn't change a written document")
gone = q("select id, file_name from document_versions where document_id=%s order by id desc limit 1", types["Rack.png"])[0]
q("update document_versions set file_name='doc-0000000000000000.png' where id=%s", gone["id"])
r = tech.post(B + f"/documents/{types['Rack.png']}/versions/{gone['id']}/restore", data={"_csrf": csrf(tech, f"/documents/{types['Rack.png']}")})
ok("no file to put back" in flash(r.text) and not q("select 1 from audit_log where action='document.restore' and detail like %s", f"#{types['Rack.png']} %"), "a restore whose file is missing says so and isn't logged")
q("update document_versions set file_name=%s where id=%s", gone["file_name"], gone["id"])

# ---- deleting a client removes its documents' files
q("insert into clients (name, source) values ('Zz220 Gone Co', 'manual')")
G = q("select id from clients where name='Zz220 Gone Co'")[0]["id"]
tech.post(B + "/documents/upload", data={"_csrf": csrf(tech, f"/clients/{G}/documents"), "client_id": str(G), "title": "Zz220 gone file"}, files={"file": ("g.pdf", PDF, "application/pdf")})
gf = q("select file_name from documents where title='Zz220 gone file'")[0]["file_name"]
st.post(B + f"/clients/{G}/delete", data={"_csrf": csrf(st, f"/clients/{G}"), "confirm_name": "Zz220 Gone Co"})
ok(not q("select 1 from clients where id=%s", G) and not os.path.exists(os.path.join(DIR, gf)), "deleting a client removes its uploaded files too")

# ---- deleting removes every copy; the migration again
files = [r_["file_name"] for r_ in q("select file_name from document_versions where document_id=%s", P)]
ok(tech.post(B + f"/documents/{P}/delete", data={"_csrf": csrf(tech, f"/documents/{P}"), "confirm": "DELETE"}).status_code == 403, "techs can't delete")
st.post(B + f"/documents/{P}/delete", data={"_csrf": csrf(st, f"/documents/{P}"), "confirm": "DELETE"})
ok(not q("select 1 from documents where id=%s", P) and not any(os.path.exists(os.path.join(DIR, f)) for f in files), "deleting removes the document and every file in its history")
r = php('(require "' + ROOT + '/db/migrations/078_document_files.php")(); echo "ok";')
ok(r.stdout.strip() == "ok", "migration 078 can run again " + r.stderr[-200:])
done()
