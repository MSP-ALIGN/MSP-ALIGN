"""2.24.2 Contracts on your own PDF: a long value in a tall box (special terms) wraps onto several lines in the browser
(preparing, previewing and signing), the way the signed PDF prints it, instead of shrinking to a single tiny line that
runs off the page. A value in a one-line box still stays on one line (shrinking if it must), and a short value in a tall
box stays centred on one line, as in the PDF."""
import os, re, json
from lib import *
from playwright.sync_api import sync_playwright

a = login("admin@example.com", "LongPassword123!")
FIX = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "fixtures")
pdf = open(FIX + "/contract-sample-objstm.pdf", "rb").read()
LONG = ("Billing will not begin until Client provides written confirmation of full release from any prior technology or "
        "managed services agreement (including notice periods and early-termination fees).")


def cleanup():
    """Removes the template and contract this suite adds."""
    for t in q("select id from contract_templates where name='Fix2242 wrap test'"):
        q("delete from contracts where template_id=%s", t["id"])
        q("delete from contract_templates where id=%s", t["id"])


cleanup()
try:
    r = a.post(B + "/contracts/templates", data={"_csrf": csrf(a, "/contracts/templates"), "start": "pdf", "name": "Fix2242 wrap test"}, files={"file": ("wrap.pdf", pdf, "application/pdf")})
    pid = int(re.search(r"/contracts/templates/(\d+)$", r.url).group(1))
    pd = json.loads(q("select def from contract_templates where id=%s", pid)[0]["def"])
    a.post(B + f"/contracts/templates/{pid}", data={"_csrf": csrf(a, f"/contracts/templates/{pid}"), "name": "Fix2242 wrap test", "is_active": "1", "def": json.dumps(pd)})
    r = a.post(B + "/contracts", data={"_csrf": csrf(a, "/contracts"), "template_id": pid, "for": "lead", "lead_company": "Wrap Test Co"})
    xid = int(re.search(r"/contracts/(\d+)$", r.url).group(1))

    with sync_playwright() as p:
        b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1300, "height": 1000})
        errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
        pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
        __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()
        pg.goto(B + f"/contracts/{xid}"); pg.wait_for_selector(".pv-page"); pg.wait_for_timeout(1200)
        ITEMS = """(long) => [
              {id: 'w1', page: 0, x: 72, y: 300, w: 470, h: 80, kind: 'text', size: 10, text: long, key: 'w1'},
              {id: 'w2', page: 0, x: 72, y: 400, w: 470, h: 14, kind: 'text', size: 10, text: long, key: 'w2'},
              {id: 'w3', page: 0, x: 72, y: 430, w: 470, h: 60, kind: 'text', size: 10, text: 'Special Terms:', key: 'w3'},
              {id: 'w4', page: 0, x: 72, y: 500, w: 300, h: 80, kind: 'text', size: 10, text: long, align: 'center', key: 'w4'},
              {id: 'w5', page: 0, x: 72, y: 590, w: 120, h: 60, kind: 'text', size: 10, text: 'https://www.example.com/a/very/long/path/without/spaces', key: 'w5'},
              {id: 'w6', page: 0, x: 400, y: 590, w: 40, h: 40, kind: 'text', size: 10, text: 'RV and more initials', sig: true, key: 'w6'}]"""
        MEASURE = """() => {
            const one = (id) => {
              const el = document.querySelector('.pv-item[data-id="' + id + '"]');
              if (!el) return null;
              const r = document.createRange(); r.selectNodeContents(el);
              const lines = new Set([...r.getClientRects()].map((x) => Math.round(x.top))).size;
              return {wrap: el.classList.contains('pv-wrap'), over: el.classList.contains('pv-over'), fs: parseFloat(el.style.getPropertyValue('--fs')),
                      fits: el.scrollHeight <= el.clientHeight + 1 && el.scrollWidth <= el.clientWidth + 1, lines,
                      ws: getComputedStyle(el).whiteSpace, ta: getComputedStyle(el).textAlign};
            };
            return Object.fromEntries(['w1', 'w2', 'w3', 'w4', 'w5', 'w6'].map((id) => [id, one(id)]));
        }"""
        pg.evaluate("(long) => { const v = window.AlignPdf.mount(document.querySelector('[data-pv]')); window.__keep = v.items; v.setItems([...v.items, ...(" + ITEMS + ")(long)]); }", LONG)
        res = pg.evaluate(MEASURE)
        w1, w2, w3, w4, w5, w6 = (res[k] for k in ["w1", "w2", "w3", "w4", "w5", "w6"])
        ok(w1 and w1["wrap"] and not w1["over"] and w1["fits"] and w1["lines"] >= 2 and w1["fs"] >= 9 and w1["ws"] == "pre-line",
           f"long text in a tall box wraps onto several lines inside it, at a readable size: {w1}")
        ok(w2 and not w2["wrap"] and w2["ws"] == "nowrap" and w2["lines"] == 1, f"in a one-line box it stays on one line, as in the PDF: {w2}")
        ok(w3 and not w3["wrap"] and not w3["over"] and w3["fs"] == 10 and w3["lines"] == 1, f"a short value in a tall box stays on one line at its size: {w3}")
        ok(w4 and w4["wrap"] and w4["fits"] and w4["lines"] >= 3 and w4["ta"] == "center", f"centred text wraps centred: {w4}")
        ok(w5 and not w5["wrap"] and w5["lines"] == 1, f"a single long word isn't broken, and stays on one centred line: {w5}")
        ok(w6 and not w6["wrap"], f"initials never wrap (the PDF draws them larger, on one line): {w6}")
        # after the window changes size the values are fitted again
        pg.set_viewport_size({"width": 800, "height": 1000}); pg.wait_for_timeout(900)
        res2 = pg.evaluate(MEASURE)
        ok(res2["w1"]["wrap"] and res2["w1"]["fits"] and res2["w4"]["fits"], f"after a resize the wrapped text still fits its box: {res2['w1']}")
        pg.evaluate("() => window.AlignPdf.mount(document.querySelector('[data-pv]')).setItems(window.__keep)")
        ok(not errors, "no script errors: " + str(errors[:2]))
        b.close()

    # smoke check: the server side (PdfStamp, unchanged) still builds the sample PDF with a tall box on it
    pd["places"].append({"page": 0, "x": 72, "y": 300, "w": 470, "h": 80, "key": "client_name"})
    a.post(B + f"/contracts/templates/{pid}", data={"_csrf": csrf(a, f"/contracts/templates/{pid}"), "name": "Fix2242 wrap test", "is_active": "1", "def": json.dumps(pd)})
    sp = a.get(B + f"/contracts/templates/{pid}/pdf")
    ok(sp.status_code == 200 and sp.content.startswith(b"%PDF"), "the template's sample PDF still builds with a tall box")
finally:
    cleanup()
done()
