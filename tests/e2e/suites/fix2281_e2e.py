"""2.28.1 Discounts on any contract row. A price below 0 is kept on a template's own service rows (as a template's
default and when preparing a contract), not only on lines added while preparing; totals and the preview count it off.
Quantities still can't go below 0, and prices stay within +/-10,000,000. The price boxes have no minimum. A period
total below 0 still shows, in the preview and the PDF."""
import re
import json
import html as H2
import base64
import zlib
from lib import *


def pdf_text(b):
    """Text from Align's PDFs (Flate streams, hex strings in Windows-1252), as in contracts_e2e."""
    out = []
    for m in re.finditer(rb"/Length (\d+)[^>]*>>\s*stream\r?\n", b):
        s = b[m.end():m.end() + int(m.group(1))]
        try:
            s = zlib.decompress(s)
        except Exception:
            pass
        out += [bytes.fromhex(h.decode()).decode("cp1252", "replace") for h in re.findall(rb"<([0-9a-f]+)> Tj", s)]
    return re.sub(r"\s+", " ", " ".join(out))


a = login("admin@example.com", "LongPassword123!")
tech = login("tech@example.com", TECH_PASSWORD)

# ---- a template whose own rows include a standing discount (the wording is the test's own)
r = a.post(B + "/contracts/templates", data={"_csrf": csrf(a, "/contracts/templates"), "start": "blank", "name": "F2281 Discount agreement"})
tid = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
d = json.loads(H2.unescape(re.search(r'<script type="application/json" id="ct-def-json">(.*?)</script>', r.text, re.S).group(1)))
d["services"] = {"title": "Services", "rows": [
    {"key": "users", "label": "Users", "price": 160, "period": "month", "qty": 7},
    {"key": "mdm", "label": "Mobile MDM", "price": 30, "period": "month", "qty": 9},
    {"key": "bundle", "label": "Bundle discount", "price": -50, "period": "month", "qty": 1},
    {"key": "low", "label": "Too low", "price": -99_000_000, "period": "month", "qty": -3},
]}
d["blocks"] = [{"type": "text", "html": "<p>F2281 agreement for {{client_name}}.</p>"}, {"type": "services"}, {"type": "signatures"}]
try:
    r = a.post(B + f"/contracts/templates/{tid}", data={"_csrf": csrf(a, f"/contracts/templates/{tid}"), "name": "F2281 Discount agreement", "is_active": "1", "def": json.dumps(d)})
    ok("Template saved" in flash(r.text), "template saved: " + flash(r.text)[:80])
    rows = {x["key"]: x for x in json.loads(q("select def from contract_templates where id=%s", tid)[0]["def"])["services"]["rows"]}
    ok(rows["bundle"]["price"] == -50, "a template row keeps a price below 0 (a standing discount): %s" % rows["bundle"]["price"])
    ok(rows["low"]["price"] == -10_000_000 and rows["low"]["qty"] == 0, "but no lower than -10,000,000, and its quantity can't go below 0: %s" % rows["low"])
    t = a.get(B + f"/contracts/templates/{tid}").text
    ok(not errs(t) and "a price below 0 is a discount" in t, "the template's Services tab says so")
    js = a.get(B + "/assets/contracts.js").text
    ok('data-s="price"' in js and 'min="0" class="form-control" data-s="price"' not in js, "the template editor's price box has no minimum")

    # ---- a contract from it: a discount on one of the template's rows
    cid = q("select id from clients where name like 'Cedar Ridge%%' limit 1")[0]["id"]
    r = tech.post(B + "/contracts", data={"_csrf": csrf(tech, "/contracts"), "template_id": tid, "for": "client", "client_id": cid})
    kid = int(re.search(r"/contracts/(\d+)$", r.url).group(1)); t = r.text
    v = json.loads(q("select vals from contracts where id=%s", kid)[0]["vals"])
    ok(v["svc"]["bundle"]["price"] == -50, "a new contract starts with the template's discount")
    price = re.search(r'<input[^>]*name="svc\[users\]\[price\]"[^>]*>', t).group(0)
    ok(not errs(t) and 'min=' not in price and "below 0 for a discount" in price, "the prepare page's price box on a template row has no minimum: " + price[:160])
    qty = re.search(r'<input[^>]*name="svc\[users\]\[qty\]"[^>]*>', t).group(0)
    ok('min="0"' in qty, "the quantity box still starts at 0")
    ok("A price below 0 is a discount" in t, "and the hint under the table says so")
    F = {"_csrf": csrf(tech, f"/contracts/{kid}"), "then": "save", "title": "F2281 Discount agreement", "client_id": str(cid),
         "signer_name": "Jordan Ellis", "signer_email": "jordan@cedarridgedental.example",
         "svc[users][on]": "1", "svc[users][qty]": "7", "svc[users][price]": "160",
         "svc[mdm][on]": "1", "svc[mdm][qty]": "9", "svc[mdm][price]": "-12.5",
         "svc[bundle][on]": "1", "svc[bundle][qty]": "-2", "svc[bundle][price]": "-75",
         "svc[low][qty]": "1", "svc[low][price]": "-99999999",
         "extra[0][label]": "Loyalty discount", "extra[0][qty]": "1", "extra[0][price]": "-20", "extra[0][period]": "month"}
    r = tech.post(B + f"/contracts/{kid}", data=F)
    ok("Draft saved" in flash(r.text), "draft saved: " + flash(r.text)[:80])
    v = json.loads(q("select vals from contracts where id=%s", kid)[0]["vals"])
    ok(v["svc"]["mdm"]["price"] == -12.5 and v["svc"]["bundle"]["price"] == -75, "prices below 0 on the template's rows are kept: %s, %s" % (v["svc"]["mdm"]["price"], v["svc"]["bundle"]["price"]))
    ok(v["svc"]["bundle"]["qty"] == 0, "a quantity below 0 becomes 0")
    ok(v["svc"]["low"]["price"] == -10_000_000 and v["svc"]["low"]["on"] is False, "a price is capped at -10,000,000 (and an unticked row stays out)")
    ok(v["extra"][0]["price"] == -20, "an added line's discount works as before")
    tot = json.loads(php(f'$c = Align\\Contracts\\Contracts::load({kid}); echo json_encode(Align\\Contracts\\Contracts::totals($c["def"], $c["vals"])["sum"]);').stdout)
    ok(abs(tot["month"] - (7 * 160 - 9 * 12.5 + 0 - 20)) < 0.01, "the monthly total counts the discounts off: 1120 - 112.50 - 20 = %s" % tot["month"])
    pv = r.text.split("data-ct-preview>")[1]
    ok("Mobile MDM" in pv and "$987.50" in pv and "Loyalty discount" in pv and "Too low" not in pv, "the preview shows the discounted total ($987.50)")
    # a one-time line below 0 with nothing else one-time: its total (below 0) still shows
    F["extra[1][label]"] = "Setup fee waived"; F["extra[1][qty]"] = "1"; F["extra[1][price]"] = "-500"; F["extra[1][period]"] = "once"
    pv = tech.post(B + f"/contracts/{kid}", data=F).text.split("data-ct-preview>")[1]
    ok("One-time total" in pv and "-$500" in pv and "Monthly total" in pv, "a total below 0 still shows (One-time total -$500), with the monthly one")
    pdf = pdf_text(base64.b64decode(php(f'$c = Align\\Contracts\\Contracts::load({kid}); echo base64_encode(Align\\Contracts\\PdfRender::build($c));').stdout))
    ok("One-time total" in pdf and "-$500" in pdf and "$987.50" in pdf and "Setup fee waived" in pdf, "and in the PDF: " + pdf[pdf.find("Monthly"):][:90])
finally:
    q("delete from contracts where template_id=%s", tid)
    q("delete from contract_templates where id=%s", tid)
done()
