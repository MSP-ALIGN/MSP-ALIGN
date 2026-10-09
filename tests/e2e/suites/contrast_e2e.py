"""2.7.3 Buttons readable in light and dark mode: visits one page of each kind an admin can reach (as the crawl
does) in both themes and measures the contrast of every visible button, pill, tab, badge, pagination link, dropdown
item, clickable list item and card header note against what's actually behind it (background colors composited up the
page). Under 3:1 fails, naming the page, theme, element and colors. 3:1 is WCAG's minimum for large text and controls,
so this is the floor that catches invisible text (white on white was 1.05:1), not a full accessibility audit: hover
states, closed menus and dialogs, other roles and the portal aren't covered."""
import re, collections, html
from urllib.parse import urljoin, urlparse
from lib import *
from playwright.sync_api import sync_playwright

SKIP = re.compile(r'^/(logout|portal$|portal/|ics/|vendor/|assets/|branding/logo|settings/email/connect|integrations/email/connect|clients/\d+/logo|users/\d+/avatar|login)|\.(csv|png|jpg|svg|js|css|pdf|zip|json)$|/(export|download|pdf)$')
MIN = 3.0

# Pages: one of each kind (numbers folded), from the same starting points as the crawl
s = login("admin@example.com", "LongPassword123!")
seen, pages, kinds = set(), [], set()
queue = ["/", "/clients/1", "/settings", "/users", "/audit", "/frameworks", "/settings/branding", "/integrations", "/mapping", "/mapping/backups", "/help", "/todo"]
while queue and len(pages) < 500:
    p = queue.pop(0)
    if p in seen:
        continue
    seen.add(p)
    kind = re.sub(r'\d+', 'N', p)
    if kind in kinds:
        continue
    r = s.get(B + p, allow_redirects=False)
    if r.status_code != 200 or "text/html" not in r.headers.get("content-type", ""):
        continue
    kinds.add(kind)
    pages.append(p)
    for href in re.findall(r'href="([^"#]+)"', r.text):
        u = urlparse(urljoin(B + p, html.unescape(href)))
        if u.netloc != urlparse(B).netloc or SKIP.search(u.path):
            continue
        queue.append(u.path + ("?" + u.query if u.query else ""))
ok(len(pages) > 80, f"pages to check: {len(pages)}")

# Measured in the page: text color against the composited background behind the element
JS = r"""(min) => {
  const parse = c => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1]; };
  const over = (top, under) => { const a = top[3]; return [0,1,2].map(i => top[i] * a + under[i] * (1 - a)).concat(1); };
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const bgOf = el => { const stack = []; for (let n = el; n && n.nodeType === 1; n = n.parentElement) { const cs = getComputedStyle(n);
      if (cs.backgroundImage && cs.backgroundImage !== 'none' && !cs.backgroundImage.startsWith('url')) return null; // gradients: can't tell
      const c = parse(cs.backgroundColor); if (c && c[3] > 0) { stack.push(c); if (c[3] >= 1) break; } }
    let base = parse(getComputedStyle(document.body).backgroundColor) || [255,255,255,1]; if (base[3] < 1) base = over(base, [255,255,255,1]);
    for (let i = stack.length - 1; i >= 0; i--) base = over(stack[i], base); return base; };
  const out = [];
  const sel = '.btn, .nav-link, .badge, .page-link, .dropdown-item, .list-group-item-action, .btn-group label, .card-header .card-tools, .card-title small, .modal-title small';
  for (const el of document.querySelectorAll(sel)) {
    const r = el.getBoundingClientRect(); if (r.width < 4 || r.height < 4) continue;
    const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || +cs.opacity === 0 || el.closest('[hidden], .d-none, .modal:not(.show), .dropdown-menu:not(.show), .collapse:not(.show), .tab-pane:not(.active)')) continue;
    if (el.disabled || el.classList.contains('disabled')) continue;
    // the element's own text (not icons only): skip elements without letters or digits
    const text = (el.innerText || '').trim(); if (!/[A-Za-z0-9]/.test(text)) continue;
    const fg = parse(cs.color); const bg = bgOf(el); if (!fg || !bg) continue;
    const c = ratio(over(fg, bg), bg);
    if (c < min) out.push({text: text.slice(0, 40), cls: el.className.toString().slice(0, 90), ratio: Math.round(c * 100) / 100, fg: cs.color, bg: 'rgb(' + bg.slice(0,3).map(Math.round).join(',') + ')'});
  }
  return out;
}"""

bad = collections.defaultdict(list)
failed = []
with sync_playwright() as pw:
    br = pw.chromium.launch()
    pg = br.new_page(viewport={"width": 1400, "height": 1000})
    pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.press("input[name=password]", "Enter")
    __import__("sitecustomize").after_login(pg, "admin@example.com")
    for p in pages:
        try:
            pg.goto(B + p, wait_until="load", timeout=20000)
        except Exception:
            failed.append(p)
            continue
        # no transitions, so colors are read as they settle, not halfway between the themes
        pg.add_style_tag(content="*, *::before, *::after { transition: none !important; animation: none !important; }")
        for theme in ("light", "dark"):
            pg.evaluate("t => document.documentElement.setAttribute('data-bs-theme', t)", theme)
            pg.wait_for_timeout(50)
            for f in pg.evaluate(JS, MIN):
                bad[(theme, f["cls"], f["text"])].append((p, f))
    br.close()
for (theme, cls, text), hits in sorted(bad.items()):
    p, f = hits[0]
    print(f"   {theme:5} {f['ratio']}:1  {text!r}  [{cls}]  {f['fg']} on {f['bg']}  at {p}" + (f" (+{len(hits) - 1} more pages)" if len(hits) > 1 else ""))
ok(len(failed) <= 3, f"pages load for the check ({len(failed)} didn't: {failed[:5]})")
ok(not bad, f"every button, tab, badge and link-button has at least {MIN}:1 contrast in both themes ({len(bad)} failing)")
done()
