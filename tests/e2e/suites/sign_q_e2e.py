"""2.2.1 review: contract signing and the contract model. A new signing link never lands on a contract that isn't
out for signature; the signing trail counts only the PDF's real Initial boxes; a white or invisible drawing isn't a
signature; template defaults are cleaned for their type and dates print only when they are real dates (never one
read relative to today); long wording is never cut silently when a template is saved."""
from lib import *
import os, re, json, hashlib, subprocess, html as H2

PHP = 'require "' + BOOTSTRAP + '"; '
def phpv(code): r = subprocess.run(["php", "-r", PHP + code], env=ENV, capture_output=True, text=True); return (r.stdout + r.stderr).strip()
def local(u): return re.sub(r"^https?://[^/]+", B, u)
def portal_csrf(s, url): return re.search(r'name="_csrf" value="([^"]+)"', s.get(url).text).group(1)

made = []  # contract ids to remove at the end
tpls = []  # [template id, its PDF file]
import atexit
def _tidy():  # also runs when a check raises before the end
    for i in made:
        q("delete from contracts where id=%s", i)
    for tid, f in tpls:
        q("delete from contract_templates where id=%s", tid)
        phpv(f'Align\\Contracts\\PdfStamp::removeIfUnused({json.dumps(f)});')
atexit.register(_tidy)

# ---- template defaults are cleaned for their type (a date default "+1 month" would print a moving date)
d = json.loads(phpv(r'echo json_encode(Align\Contracts\Template::normalize(["fields" => ['
                    r'["label" => "Go live", "type" => "date", "by" => "provider", "default" => "+1 month"],'
                    r'["label" => "Go live two", "type" => "date", "by" => "provider", "default" => "2026-11-01"],'
                    r'["label" => "Go live three", "type" => "date", "by" => "provider", "default" => "01/15/2027"],'
                    r'["label" => "Tier", "type" => "choice", "options" => "Gold\nSilver", "by" => "provider", "default" => "Platinum"],'
                    r'["label" => "Rate", "type" => "money", "by" => "provider", "default" => "$1,500.00"],'
                    r'["label" => "Term", "type" => "number", "by" => "provider", "default" => "12"]],'
                    r'"blocks" => [["type" => "text", "html" => "<p>{{go_live}} {{go_live_two}} {{tier}} {{rate}} {{term}}</p>"]]]));'))
dv = {f["key"]: f["default"] for f in d["fields"]}
ok(dv.get("go_live") == "" and dv.get("go_live_two") == "2026-11-01" and dv.get("go_live_three") == "2027-01-15",
   "a date default must be a fixed date: '+1 month' is dropped, 2026-11-01 kept, 01/15/2027 stored as 2027-01-15: %s" % dv)
ok(dv.get("tier") == "" and dv.get("rate") == "1500" and dv.get("term") == "12", "a choice default must be one of the options; amounts are cleaned like typed ones: %s" % dv)

# ---- a stored date value prints only when it's a real date (contracts made by 2.2.0 can hold a raw default)
v = json.loads(phpv(r'$d = Align\Contracts\Template::normalize(["fields" => [["key" => "go_live", "label" => "Go live", "type" => "date", "by" => "provider"]],'
                    r' "blocks" => [["type" => "text", "html" => "<p>{{go_live}}</p>"]]]);'
                    r' $c = Align\Contracts\Render::sample($d); $c["vals"]["f"]["go_live"] = "+1 month"; $a = Align\Contracts\Contracts::values($c)["go_live"];'
                    r' $c["vals"]["f"]["go_live"] = "next friday"; $b = Align\Contracts\Contracts::values($c)["go_live"];'
                    r' $c["vals"]["f"]["go_live"] = "01/15/2027"; $d = Align\Contracts\Contracts::values($c)["go_live"];'
                    r' $c["vals"]["f"]["go_live"] = "2026-11-01"; echo json_encode([$a, $b, Align\Contracts\Contracts::values($c)["go_live"], $d]);'))
ok(v[0] == "" and v[1] == "" and "2026" in v[2] and "2027" in v[3], "a date value read relative to today prints nothing (it would change each day); a fixed date prints, also one typed as 01/15/2027 in 2.2.0: %s" % v)

# ---- long wording: never cut before cleaning when saving
r = phpv(r'$h = str_repeat("<p class=\"x\" data-a=\"yyyyyyyy\">word</p>", 70000);'
         r' $n = Align\Contracts\Template::normalize(["blocks" => [["type" => "text", "html" => $h]]], true); echo strlen($h), " ", substr_count($n["blocks"][0]["html"], "word");')
ok(r.split()[-1] == "70000" and int(r.split()[0]) > 2 * 1024 * 1024, "wording over 2 MB as typed but under it once cleaned is saved whole, not cut: " + r)
r = phpv(r'try { Align\Contracts\Template::normalize(["blocks" => [["type" => "text", "html" => str_repeat("a", 4 * 1024 * 1024 + 1)]]], true); echo "saved"; }'
         r' catch (InvalidArgumentException $e) { echo "refused: ", $e->getMessage(); }')
ok(r.startswith("refused") and "too long" in r, "wording too big to clean is refused when saving, with the message: " + r[:90])

# ---- a drawn signature needs visible ink (an all-white picture is as blank as an empty one)
PNG = (r'function sq_png($bg, $ink) { $i = imagecreatetruecolor(400, 120); imagesavealpha($i, true); imagealphablending($i, false);'
       r' imagefill($i, 0, 0, imagecolorallocatealpha($i, $bg[0], $bg[1], $bg[2], $bg[3])); imagealphablending($i, true);'
       r' if ($ink) { $k = imagecolorallocate($i, $ink[0], $ink[1], $ink[2]); imagesetthickness($i, 4); imageline($i, 20, 90, 120, 20, $k); imageline($i, 120, 20, 200, 95, $k); imageline($i, 200, 95, 380, 30, $k); }'
       r' ob_start(); imagepng($i); return "data:image/png;base64," . base64_encode(ob_get_clean()); }'
       r' function sq_sig($u) { $r = Align\Contracts\Contracts::signature("draw", "", $u, "Pat Example"); return is_string($r) ? "refused" : "ok"; }')
r = json.loads(phpv(PNG + r' echo json_encode(['
                    r'"white" => sq_sig(sq_png([255, 255, 255, 0], null)),'
                    r'"white_ink" => sq_sig(sq_png([0, 0, 0, 127], [255, 255, 255])),'
                    r'"dark_ink" => sq_sig(sq_png([0, 0, 0, 127], [20, 30, 90])),'
                    r'"dark_on_white" => sq_sig(sq_png([255, 255, 255, 0], [20, 30, 90])),'
                    r'"empty" => sq_sig(sq_png([0, 0, 0, 127], null))]);'))
ok(r["white"] == "refused" and r["white_ink"] == "refused" and r["empty"] == "refused", "an opaque white picture, white strokes and an empty box are all refused: %s" % r)
ok(r["dark_ink"] == "ok" and r["dark_on_white"] == "ok", "a real drawing is accepted, on a transparent or a white background: %s" % r)

# ---- a new signing link only for a contract still out for signature
q("insert into contracts (title, source, status, token_hash, completed_at) values (%s, 'uploaded', 'completed', %s, NOW())", "sign_q done", "a" * 64)
done_id = q("select last_insert_id() id")[0]["id"]; made.append(done_id)
r = phpv(f"var_export(Align\\Contracts\\Contracts::newToken({done_id}, 30));")
ok(r == "NULL" and q("select token_hash from contracts where id=%s", done_id)[0]["token_hash"] == "a" * 64,
   "a signed contract never gets a fresh signing link (its signer's link keeps opening their copy): " + r[:60])
q("insert into contracts (title, status, token_hash, token_expires_at) values (%s, 'sent', %s, NOW() + INTERVAL 5 DAY)", "sign_q sent", "b" * 64)
sent_id = q("select last_insert_id() id")[0]["id"]; made.append(sent_id)
tok = phpv(f"echo Align\\Contracts\\Contracts::newToken({sent_id}, 30);")
row = q("select token_hash, code_sent_count, token_expires_at from contracts where id=%s", sent_id)[0]
ok(re.fullmatch(r"[A-Za-z0-9_-]{43}", tok) and row["token_hash"] == hashlib.sha256(tok.encode()).hexdigest(), "one still out for signature gets a new 256-bit link, stored as its hash")

# ---- the signing trail counts only the PDF's real Initial boxes
a = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)
FIX = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "fixtures")
r = a.post(B + "/contracts/templates", data={"_csrf": csrf(a, "/contracts/templates"), "start": "pdf", "name": "sign_q PDF"},
           files={"file": ("sign-q.pdf", open(FIX + "/contract-sample.pdf", "rb").read(), "application/pdf")})
pid = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
pd = json.loads(q("select def from contract_templates where id=%s", pid)[0]["def"])
tpls.append((pid, pd["pdf"]["file"]))
pd["places"] = [{"page": 0, "x": 72, "y": 72, "w": 200, "h": 14, "key": "client_name"}, {"page": 0, "x": 400, "y": 124, "w": 40, "h": 18, "key": "initials.client"},
                {"page": 0, "x": 72, "y": 600, "w": 170, "h": 34, "key": "sig.client"}]
pd["signing"]["verify_code"] = False; pd["signing"]["countersign"] = "after"
a.post(B + f"/contracts/templates/{pid}", data={"_csrf": csrf(a, f"/contracts/templates/{pid}"), "name": "sign_q PDF", "is_active": "1", "def": json.dumps(pd)})
r = tech.post(B + "/contracts", data={"_csrf": csrf(tech, "/contracts"), "template_id": pid, "for": "lead", "lead_company": "Example Lead Co"})
xid = int(re.search(r"/contracts/(\d+)$", r.url).group(1)); made.append(xid)
tech.post(B + f"/contracts/{xid}", data={"_csrf": csrf(tech, f"/contracts/{xid}"), "then": "save", "client_id": "", "lead_company": "Example Lead Co",
                                         "signer_name": "Robin Example", "signer_email": "robin@lead.example"})
r = tech.post(B + f"/contracts/{xid}/send", data={"_csrf": csrf(tech, f"/contracts/{xid}"), "action": "link"})
xlink = local(re.search(r'id="ct-link" readonly value="([^"]+)"', r.text).group(1))
cx = requests.Session()
t = cx.get(xlink).text
it = {i["key"]: i for i in json.loads(H2.unescape(re.search(r'<script type="application/json" class="pv-data">(.*?)</script>', t, re.S).group(1)))["items"]}
box = it["initials.client"]["id"]
fake = ["zz%d" % i for i in range(9)]
S = {"sig_kind": "type", "sig_typed": "Robin Example", "sig_name": "Robin Example", "initials": "RE", "consent": "1"}
r = cx.post(xlink, data={"_csrf": portal_csrf(cx, xlink), **S, "initialed[]": fake})
ok("initial every Initial box" in flash(r.text) and q("select status from contracts where id=%s", xid)[0]["status"] == "sent", "made-up box ids don't count as the real Initial box")
r = cx.post(xlink, data={"_csrf": portal_csrf(cx, xlink), **S, "initialed[]": [box, box] + fake})
ev = q("select detail from contract_events where contract_id=%s and event='client_signed'", xid)
ok(q("select status from contracts where id=%s", xid)[0]["status"] == "client_signed" and ev and "initialed 1 box one by one" in ev[0]["detail"],
   "the trail says 1 box was initialed (the PDF has one), not the 11 ids the browser sent: " + (ev[0]["detail"] if ev else "no event"))

# ---- lead fixes (2.2.1): the link window while waiting for the countersignature, frozen details kept after you
# signed first, a changed sign-first draft loses your signature, a very long typed email can't break sign-in
tok = "sq" + "x" * 41
q("insert into contracts (title, source, status, token_hash, client_signed_at) values (%s, 'built', 'client_signed', %s, NOW() - INTERVAL 31 DAY)", "sign_q waiting", hashlib.sha256(tok.encode()).hexdigest())
wid = q("select last_insert_id() id")[0]["id"]; made.append(wid)
r = phpv(f'echo Align\\Contracts\\Contracts::byToken("{tok}") === null ? "closed" : "open";')
q("update contracts set client_signed_at = NOW() - INTERVAL 5 DAY where id=%s", wid)
r2 = phpv(f'echo Align\\Contracts\\Contracts::byToken("{tok}") === null ? "closed" : "open";')
ok(r == "closed" and r2 == "open", f"waiting for your countersignature, the link opens for 30 days after the client signed, then closes ({r}, {r2})")

r = phpv(r'$m = new ReflectionMethod(Align\Contracts\Contracts::class, "freeze");'
         r' $v = $m->invoke(null, ["provider_signed_at" => "2026-01-01 10:00:00", "vals" => ["f" => [], "print" => ["client_name" => "Old Name Co"]]] + Align\Contracts\Render::sample(Align\Contracts\Template::blank()));'
         r' echo $v["print"]["client_name"];')
ok(r == "Old Name Co", "sent again after you signed first, the details frozen at the first send stay: " + r)

q("update contracts set provider_user_id=1, provider_signature='{\"kind\":\"typed\"}', provider_signed_at=NOW() where id=%s", xid)
q("update contracts set status='draft' where id=%s", xid)
r = tech.post(B + f"/contracts/{xid}", data={"_csrf": csrf(tech, f"/contracts/{xid}"), "then": "save", "client_id": "", "lead_company": "Example Lead Co",
                                             "signer_name": "Robin Example", "signer_email": "robin@lead.example", "title": "Changed title"})
k = q("select provider_signed_at, provider_signature from contracts where id=%s", xid)[0]
ok(k["provider_signed_at"] is None and k["provider_signature"] is None and "signature was taken off" in r.text,
   "a sign-first draft that changes loses your signature, and the page says so")

long_email = "x" * 240 + "@example.com"
rr = requests.Session(); tk = re.search(r'name="_csrf" value="([^"]+)"', rr.get(B + "/login").text).group(1)
r = rr.post(B + "/login", data={"_csrf": tk, "email": long_email, "password": "Whatever123456!"})
ok(r.status_code == 200 and "/login" in r.url, f"a 252-character email on the staff sign-in is just a failed sign-in, not a server error (got {r.status_code})")
q("delete from login_attempts where email like 'sha256:%%' or email like 'xxxxxxxx%%'")

# ---- tidy up: _tidy() at exit
done()
