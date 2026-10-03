"""2.2.1 review (batch 5): the app's own browser JavaScript in public/assets.

Node checks (no browser, no server): each loads the real file into a fresh vm with a small fake DOM, fake timers and
scripted fetch answers, and checks the fixes: docs.js autosave stops (and says why) when the save is refused (419,
403, 404) instead of retrying "offline" forever, the live refresh never wipes typing, the editor keeps no pictures;
app.js follows a restore upload's redirect only to a path on this site, puts the license cost line in as text, copes
with a malformed /help#hash, and a copy button gets its label back; contracts.js shows the newest preview.
Playwright (the app server): the real Quill drops pasted pictures in the document editor and the template builder,
and a 419 on autosave says the session ended.
"""
from lib import *
import tempfile
from playwright.sync_api import sync_playwright

ASSETS = ROOT + "/public/assets"
HARNESS = r'''
// Node checks for the browser JS (no server, no browser): each check loads the real file into a fresh vm context
// with a small fake DOM, fake timers and a scripted fetch, then prints PASS/FAIL lines.
'use strict';
const vm = require('vm');
const fs = require('fs');
const path = require('path');
const DIR = process.argv[2];
const src = (f) => fs.readFileSync(path.join(DIR, f), 'utf8');
const out = [];
const ok = (c, m) => out.push((c ? 'PASS ' : 'FAIL ') + m);
const flush = async (n = 8) => { for (let i = 0; i < n; i++) await new Promise((r) => setImmediate(r)); };
const escH = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

class ClassList {
  constructor() { this.s = new Set(); }
  add(...c) { c.forEach((x) => this.s.add(x)); }
  remove(...c) { c.forEach((x) => this.s.delete(x)); }
  toggle(c, on) { if (on === undefined) on = !this.s.has(c); if (on) this.s.add(c); else this.s.delete(c); return on; }
  contains(c) { return this.s.has(c); }
}
class El {
  constructor(tag, o = {}) {
    this.tagName = String(tag).toUpperCase();
    this.dataset = o.dataset || {};
    this.value = o.value !== undefined ? o.value : '';
    this._html = ''; this._text = '';
    this.htmlSets = [];
    this.children = [];
    this.listeners = {};
    this.attrs = {};
    this.style = { setProperty() {} };
    this.classList = new ClassList();
    this.className = '';
    this.q = o.q || {}; this.qa = o.qa || {}; this.cl = o.cl || {};
    Object.assign(this, o.props || {});
    if (o.innerHTML !== undefined) this._html = o.innerHTML;
  }
  get innerHTML() { return this._html; }
  set innerHTML(v) { this._html = String(v); this._text = this._html.replace(/<[^>]*>/g, ''); this.htmlSets.push(this._html); this.children = []; }
  get textContent() { return this._text || this.children.map((c) => (typeof c === 'string' ? c : c.textContent)).join(''); }
  set textContent(v) { this._text = String(v); this._html = escH(v); this.children = []; }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  fire(t, ev = {}) {
    ev.type = t; ev.target = ev.target || this;
    ev.preventDefault = ev.preventDefault || (() => { ev.defaultPrevented = true; });
    ev.stopPropagation = ev.stopPropagation || (() => {});
    (this.listeners[t] || []).forEach((f) => f(ev));
    return ev;
  }
  appendChild(c) { this.children.push(c); return c; }
  append(...c) { this.children.push(...c); }
  replaceChildren(...c) { this._html = ''; this._text = ''; this.children = [...c]; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  hasAttribute(k) { return k in this.attrs; }
  removeAttribute(k) { delete this.attrs[k]; }
  querySelector(s) { return this.q[s] || null; }
  querySelectorAll(s) { return this.qa[s] || []; }
  closest(s) { return this.cl[s] || null; }
  matches() { return false; }
  focus() {} select() {} scrollIntoView() {} remove() {}
}

/** A fresh context: document, window, timers, fetch queue. */
function makeEnv(opts = {}) {
  const timers = [];
  let tid = 0;
  const ids = opts.ids || {};
  const qs = opts.qs || {};
  const qsa = opts.qsa || {};
  const docListeners = {};
  const winListeners = {};
  const body = new El('body', { dataset: { fmt: '{}' } });
  const document = {
    readyState: 'complete', body, documentElement: new El('html'),
    getElementById: (id) => ids[id] || null,
    querySelector: (s) => qs[s] || null,
    querySelectorAll: (s) => qsa[s] || [],
    createElement: (t) => new El(t),
    createDocumentFragment: () => new El('#fragment'),
    addEventListener: (t, f) => { (docListeners[t] = docListeners[t] || []).push(f); },
    contains: () => true,
    execCommand: () => true,
  };
  const location = {
    href: 'https://align.example/page', hash: opts.hash || '', search: '', pathname: '/page', reloaded: false,
    reload() { this.reloaded = true; }, replace(u) { this.replaced = u; },
  };
  const fetches = [];
  const fetch = (url, init) => new Promise((resolve, reject) => {
    const f = { url, init, resolve, reject };
    fetches.push(f);
    if (opts.onFetch) opts.onFetch(f);
  });
  const ctx = {
    document, location, console, JSON, Math, Date, Promise, URLSearchParams, Object, Array, String, Number, Set, Map, RegExp, Error,
    setTimeout: (fn, ms) => { const t = { id: ++tid, fn, ms }; timers.push(t); return t.id; },
    clearTimeout: (id) => { const i = timers.findIndex((t) => t.id === id); if (i >= 0) timers.splice(i, 1); },
    setInterval: () => 0, clearInterval: () => {},
    fetch, navigator: {}, alert: () => {}, confirm: () => true, CSS: { escape: (s) => String(s) },
    Event: class { constructor(t) { this.type = t; } }, CustomEvent: class { constructor(t) { this.type = t; } },
    HTMLFormElement: class {}, HTMLInputElement: class {},
    FormData: class { constructor() { this.v = []; } append(k, v) { this.v.push([k, v]); } },
    addEventListener: (t, f) => { (winListeners[t] = winListeners[t] || []).push(f); },
  };
  if (opts.extra) Object.assign(ctx, opts.extra);
  ctx.window = ctx;
  vm.createContext(ctx);
  const env = {
    ctx, timers, fetches, document, location, docListeners, winListeners,
    load: (...files) => files.forEach((f) => vm.runInContext(src(f), ctx, { filename: f })),
    fireDoc: (t, ev = {}) => (docListeners[t] || []).forEach((f) => f(ev)),
    fireWin: (t, ev = {}) => { const errors = []; (winListeners[t] || []).forEach((f) => { try { f(ev); } catch (e) { errors.push(e); } }); return errors; },
    runTimers: () => { const due = timers.splice(0); due.forEach((t) => t.fn()); return due; },
  };
  return env;
}
const res = (status, type, body, extra = {}) => ({
  status, ok: status >= 200 && status < 300, redirected: false, headers: { get: (k) => (k.toLowerCase() === 'content-type' ? type : null) },
  json: async () => (typeof body === 'string' ? JSON.parse(body) : body), text: async () => (typeof body === 'string' ? body : JSON.stringify(body)), ...extra,
});

// ---- docs.js -------------------------------------------------------------------------------------------------
function docsEnv(o = {}) {
  const mk = (id, extra) => new El('div', extra);
  const ids = {};
  ['doc-state', 'doc-conflict', 'doc-conflict-text', 'doc-error', 'doc-version-label', 'doc-updated', 'doc-presence', 'doc-load-theirs', 'doc-keep-mine']
    .forEach((id) => { ids[id] = mk(id); });
  ids['doc-error'].classList.add('d-none');
  ids['doc-conflict'].classList.add('d-none');
  ids['doc-editor'] = mk('doc-editor', { dataset: {} });
  ids['doc-app'] = mk('doc-app', { dataset: { canEdit: '1', id: '7', version: '3', csrf: 'tok' } });
  ids['doc-initial'] = mk('doc-initial', { innerHTML: '<p>Start</p>' });
  ids['doc-title'] = mk('doc-title', { value: 'Policy' });
  ids['doc-category'] = mk('doc-category', { value: 'policy' });
  ids['doc-status'] = mk('doc-status', { value: 'draft' });
  ids['doc-review'] = mk('doc-review', { value: '' });
  const quills = [];
  class Quill {
    constructor(host, options) {
      this.host = host; this.options = options; this.handlers = {}; this.sets = []; this.html = '<p>Start</p>';
      this.clipboard = { convert: ({ html }) => ({ html }) };
      this.history = { clear() {} };
      quills.push(this);
    }
    setContents(d) { this.sets.push(d.html); this.html = d.html; }
    getLength() { return 5; }
    getSemanticHTML() { return this.html; }
    getSelection() { return null; }
    setSelection() {}
    on(t, f) { this.handlers[t] = f; }
    type() { this.html += '<p>typed</p>'; this.handlers['text-change']({}, {}, 'user'); }
  }
  const env = makeEnv({ ids, onFetch: o.onFetch, extra: { Quill } });
  env.ids = ids; env.quills = quills;
  env.load('docs.js');
  env.fireDoc('DOMContentLoaded');
  return env;
}
const presenceOk = (f) => { if (/\/presence$/.test(f.url)) f.resolve(res(200, 'application/json', { version: 3, others: [] })); };

async function docsChecks() {
  // Formats: the editor never keeps pictures (the server drops them; a big one pushes the body past its 4 MB cut)
  {
    const env = docsEnv({ onFetch: presenceOk });
    const f = env.quills[0].options.formats;
    ok(Array.isArray(f) && !f.includes('image') && !f.includes('video') && !f.includes('formula')
      && ['header', 'bold', 'italic', 'underline', 'strike', 'color', 'background', 'list', 'indent', 'align', 'blockquote', 'code-block', 'link'].every((x) => f.includes(x)),
    'docs.js: Quill keeps what the server keeps (no image, video or formula): ' + JSON.stringify(f));
  }
  // 419 (session gone; the token is checked before any redirect): says signed out, no endless "offline" retry
  {
    const env = docsEnv({ onFetch: (f) => { presenceOk(f); if (/\/save$/.test(f.url)) f.resolve(res(419, 'text/html; charset=UTF-8', 'expired')); } });
    await flush();
    env.quills[0].type();
    env.runTimers(); await flush();
    const err = env.ids['doc-error'];
    ok(!err.classList.contains('d-none') && /session ended/.test(err.textContent) && !env.timers.some((t) => t.ms === 5000) && /signed out/.test(env.ids['doc-state'].textContent),
      'docs.js: a 419 on autosave says the session ended and stops (no "Offline — will retry" loop): ' + env.ids['doc-state'].textContent);
  }
  // A JSON refusal (document deleted: 404) shows the server's text and stops
  {
    const env = docsEnv({ onFetch: (f) => { presenceOk(f); if (/\/save$/.test(f.url)) f.resolve(res(404, 'application/json', { error: 'Document not found <b>' })); } });
    await flush();
    env.quills[0].type();
    env.runTimers(); await flush();
    const err = env.ids['doc-error'];
    ok(!err.classList.contains('d-none') && err.textContent.includes('Document not found <b>') && err.htmlSets.length === 0 && !env.timers.some((t) => t.ms === 5000),
      'docs.js: a refused save (404) shows the server\'s message as text and doesn\'t retry: ' + err.textContent);
  }
  // A 403 page (no longer allowed) stops too
  {
    const env = docsEnv({ onFetch: (f) => { presenceOk(f); if (/\/save$/.test(f.url)) f.resolve(res(403, 'text/html', '<p>Not allowed</p>')); } });
    await flush();
    env.quills[0].type();
    env.runTimers(); await flush();
    ok(!env.ids['doc-error'].classList.contains('d-none') && /HTTP 403/.test(env.ids['doc-error'].textContent) && !env.timers.some((t) => t.ms === 5000),
      'docs.js: a 403 on autosave says it was refused and doesn\'t retry');
  }
  // Network trouble still retries (unchanged)
  {
    const env = docsEnv({ onFetch: (f) => { presenceOk(f); if (/\/save$/.test(f.url)) f.reject(new TypeError('Failed to fetch')); } });
    await flush();
    env.quills[0].type();
    env.runTimers(); await flush();
    ok(env.timers.some((t) => t.ms === 5000) && /Offline/.test(env.ids['doc-state'].textContent) && env.ids['doc-error'].classList.contains('d-none'),
      'docs.js: a network error still retries every 5 seconds');
  }
  // 500 still retries
  {
    const env = docsEnv({ onFetch: (f) => { presenceOk(f); if (/\/save$/.test(f.url)) f.resolve(res(500, 'text/html', 'oops')); } });
    await flush();
    env.quills[0].type();
    env.runTimers(); await flush();
    ok(env.timers.some((t) => t.ms === 5000), 'docs.js: a server error (500) still retries');
  }
  // Live refresh: typing while the newer version loads isn't wiped; the conflict bar shows instead
  {
    let content = null;
    const env = docsEnv({ onFetch: (f) => {
      if (/\/presence$/.test(f.url)) f.resolve(res(200, 'application/json', { version: 4, updated_by: 'Example Tech', others: [] }));
      if (/\/content$/.test(f.url)) content = f;
    } });
    await flush();
    ok(content !== null, 'docs.js: a newer version on the server is loaded (live refresh)');
    env.quills[0].type(); // typed while /content is on its way
    content.resolve(res(200, 'application/json', { version: 4, title: 'Policy', body: '<p>theirs</p>', category: 'policy', status: 'draft', review_due: null, updated_by: 'Example Tech', updated_ago: 'just now' }));
    await flush();
    const q0 = env.quills[0];
    ok(!q0.sets.includes('<p>theirs</p>') && q0.html.includes('typed') && env.ids['doc-conflict'].classList.contains('d-flex'),
      'docs.js: text typed while the newer version loads is kept, and the conflict bar shows');
  }
  // Without typing, the live refresh still pulls the newer version in
  {
    const env = docsEnv({ onFetch: (f) => {
      if (/\/presence$/.test(f.url)) f.resolve(res(200, 'application/json', { version: 4, updated_by: 'Example Tech', others: [] }));
      if (/\/content$/.test(f.url)) f.resolve(res(200, 'application/json', { version: 4, title: 'Policy', body: '<p>theirs</p>', category: 'policy', status: 'draft', review_due: null, updated_by: 'Example Tech', updated_ago: 'just now' }));
    } });
    await flush();
    ok(env.quills[0].sets.includes('<p>theirs</p>') && /Example Tech/.test(env.ids['doc-state'].textContent), 'docs.js: with nothing typed, the newer version is pulled in live');
  }
}

// ---- app.js --------------------------------------------------------------------------------------------------
async function appChecks() {
  // Help: a malformed %-escape in the address doesn't throw
  {
    const input = new El('input');
    const env = makeEnv({ hash: '#%E0%A4%A', qs: { '[data-filter-guides]': input } });
    env.load('app.js');
    const errors = env.fireWin('load');
    ok(errors.length === 0, 'app.js: /help#%E0%A4%A (malformed escape) doesn\'t throw: ' + errors.map(String).join('; '));
  }
  // Restore upload: the redirect from the answer is followed only when it's a path on this site
  for (const [redir, follow] of [['//evil.example/x', false], ['https://evil.example/', false], ['/\\evil.example', false], ['/\t/evil.example', false], ['/settings/system?job=5', true]]) {
    const xhrs = [];
    class XMLHttpRequest {
      constructor() { this.l = {}; this.upload = { addEventListener() {} }; xhrs.push(this); }
      open() {} setRequestHeader() {} send() {}
      addEventListener(t, f) { this.l[t] = f; }
    }
    const err = new El('div');
    const fileIn = new El('input', { props: { files: [{ size: 10 }] } });
    const form = new El('form', { dataset: { max: '0' }, props: { action: '/settings/system/restore/upload' },
      q: { 'input[type=file]': fileIn, '[data-upload-error]': err, '[data-upload-progress]': new El('div', { props: { firstElementChild: { style: {} } } }), button: new El('button') } });
    const env = makeEnv({ qsa: { 'form[data-upload]': [form] }, extra: { XMLHttpRequest } });
    env.load('app.js');
    form.fire('submit');
    const x = xhrs[0];
    x.responseText = JSON.stringify({ ok: true, redirect: redir });
    x.status = 200;
    x.l.load();
    const went = env.location.href === redir;
    ok(follow ? went : (!went && env.location.reloaded), 'app.js: restore upload redirect ' + JSON.stringify(redir) + (follow ? ' is followed' : ' is not followed (page reloads)'));
  }
  // License cost line: the option's text and the currency symbol are text, never markup
  {
    const out = new El('div');
    const cycle = new El('select', { value: 'monthly', props: { selectedOptions: [{ text: 'Monthly<img src=x>' }] } });
    const form = new El('form', { q: {
      '[data-lic="out"]': out, '[data-lic="price"]': new El('input', { value: '10' }), '[data-lic="pricing"]': new El('select', { value: 'flat' }),
      '[data-lic="seats"]': new El('input', { value: '' }), '[data-lic="cycle"]': cycle,
    } });
    out.cl.form = form;
    const env = makeEnv({ qsa: { '[data-lic="out"]': [out] } });
    env.load('app.js');
    const text = out.textContent;
    ok(!out.htmlSets.some((h) => h.includes('<img')) && text.includes('$10') && text.includes('monthly<img src=x>') && text.includes('/mo'),
      'app.js: the license cost line puts page text in as text (no markup from an option or the currency): ' + JSON.stringify(text));
  }
  // Copy button: two quick clicks still put its label back
  {
    const btn = new El('button', { dataset: { copy: '#feed' }, innerHTML: '<i class="fas fa-copy"></i> Copy' });
    const feed = new El('input', { value: 'https://align.example/feed' });
    const env = makeEnv({ qsa: { '[data-copy]': [btn] }, qs: { '#feed': feed } });
    env.load('app.js');
    env.fireDoc('DOMContentLoaded');
    btn.fire('click'); btn.fire('click');
    const shown = btn.textContent;
    env.runTimers();
    ok(shown === 'Copied' && btn.innerHTML === '<i class="fas fa-copy"></i> Copy', 'app.js: a copy button clicked twice gets its label back: ' + btn.innerHTML);
  }
  // sitePath
  {
    const env = makeEnv({});
    env.load('app.js');
    const sp = vm.runInContext('typeof sitePath === "function" ? sitePath : null', env.ctx);
    ok(sp && sp('/a') && sp('/settings/system?job=1') && !sp('//x.example') && !sp('/\\x.example') && !sp('https://x.example/') && !sp('javascript:alert(1)') && !sp('/\n/x.example') && !sp(null) && !sp(''),
      'app.js: sitePath() accepts only paths on this site');
  }
}

// ---- contracts.js ---------------------------------------------------------------------------------------------
async function contractsChecks() {
  // The prepare page's live preview shows the newest answer even when an older one arrives later
  {
    const outEl = new El('div');
    const state = new El('span');
    const form = new El('form', { dataset: { preview: '/contracts/preview' } });
    const env = makeEnv({ qs: { 'form[data-ct-prepare]': form, '[data-ct-preview]': outEl, '[data-ct-preview-state]': state } });
    env.load('contracts.js');
    env.fireDoc('DOMContentLoaded');
    form.fire('input'); env.runTimers();
    form.fire('input'); env.runTimers();
    ok(env.fetches.length === 2 && env.fetches[0].init.method === 'POST', 'contracts.js: two edits send two preview requests');
    env.fetches[1].resolve(res(200, 'text/html; charset=UTF-8', '<p>newer</p>'));
    await flush();
    env.fetches[0].resolve(res(200, 'text/html; charset=UTF-8', '<p>older</p>'));
    await flush();
    ok(outEl.innerHTML === '<p>newer</p>' && state.textContent === '', 'contracts.js: an older, slower preview answer doesn\'t replace the newer one: ' + outEl.innerHTML);
  }
}

(async () => {
  for (const [name, fn] of [['docs', docsChecks], ['app', appChecks], ['contracts', contractsChecks]]) {
    try { await fn(); } catch (e) { ok(false, name + ' checks crashed: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e)); }
  }
  console.log(out.join('\n'));
})();
'''

# ---- node checks
with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False) as f:
    f.write(HARNESS)
    hp = f.name
r = subprocess.run(["node", hp, ASSETS], capture_output=True, text=True, timeout=120)
os.unlink(hp)
lines = [x for x in r.stdout.splitlines() if x.startswith(("PASS ", "FAIL "))]
ok(r.returncode == 0 and len(lines) >= 20, "the node checks ran: %d results%s" % (len(lines), (" | " + r.stderr[-300:]) if r.stderr else ""))
for x in lines:
    ok(x.startswith("PASS "), x[5:])

# ---- static checks (CSP is script-src 'self': eval, new Function, string timers and inline handlers can't run)
for name in ["app.js", "contracts.js", "docs.js", "pdfview.js", "theme.js", "print.js"]:
    code = open(ASSETS + "/" + name).read()
    ok(not re.search(r"\beval\(|new Function\(|set(Timeout|Interval)\(\s*['\"`]|<[a-z][^>]*\son[a-z]+\s*=|javascript:", code, re.I),
       name + ": no eval, new Function, string timers, inline handlers or javascript: URLs")
pv = open(ASSETS + "/pdfview.js").read()
ok("isEvalSupported: false" in pv and "AnnotationMode.DISABLE" in pv and "standardFontDataUrl: '/vendor/pdfjs/standard_fonts/'" in pv
   and "workerSrc = '/vendor/pdfjs/" in pv, "pdfview.js: PDF.js without eval, without annotations, fonts and worker from this server")

# ---- in the browser, with the real Quill
did = None; tid = None
try:
    q("insert into documents (client_id, title, category, status, body_html, version) values (NULL, 'JS check (example)', 'policy', 'draft', '<p>Start</p>', 1)")
    did = q("select last_insert_id() as id")[0]["id"]
    a = login("admin@example.com", "LongPassword123!")
    r = a.post(B + "/contracts/templates", data={"_csrf": csrf(a, "/contracts/templates"), "start": "blank", "name": "JS check template (example)"})
    m = re.search(r"/contracts/templates/(\d+)$", r.url)
    tid = int(m.group(1)) if m else None
    ok(tid is not None, "a written contract template to try the builder on")
    PASTE = '<p><strong>Bold</strong> <img src="data:image/png;base64,iVBORw0KGgo="> <a href="https://www.example.com/">a link</a></p><ul><li>one</li></ul>'
    probe = '''(sel) => { const q = Quill.find(document.querySelector(sel)); if (!q) return null;
        q.clipboard.dangerouslyPasteHTML(0, %s, 'silent');
        return { img: !!q.root.querySelector('img'), b: !!q.root.querySelector('strong'), a: !!q.root.querySelector('a[href="https://www.example.com/"]'), li: !!q.root.querySelector('li') }; }''' % json.dumps(PASTE)
    with sync_playwright() as p:
        b = p.chromium.launch(); pg = b.new_page(viewport={"width": 1440, "height": 900})
        errors = []; pg.on("pageerror", lambda e: errors.append(str(e)))
        pg.goto(B + "/login"); pg.fill("input[name=email]", "admin@example.com"); pg.fill("input[name=password]", "LongPassword123!"); pg.click("button")
        __import__('sitecustomize').after_login(pg, "admin@example.com"); pg.wait_for_load_state()

        # document editor: a pasted picture isn't kept (the server would drop it; a big one could cut the document)
        pg.goto(B + f"/documents/{did}"); pg.wait_for_selector("#doc-editor .ql-editor"); pg.wait_for_timeout(500)
        got = pg.evaluate(probe, "#doc-editor")
        ok(got and not got["img"] and got["b"] and got["a"] and got["li"], "document editor: a pasted picture is dropped; bold, links and lists stay: %s" % got)

        # contract template builder: the same
        if tid:
            pg.goto(B + f"/contracts/templates/{tid}"); pg.wait_for_selector(".ct-block .ql-editor"); pg.wait_for_timeout(800)
            got = pg.evaluate(probe, ".ct-block .ql-container")
            ok(got and not got["img"] and got["b"] and got["a"] and got["li"], "template builder: a pasted picture is dropped; bold, links and lists stay: %s" % got)

        # autosave answered 419 (the session that issued the token is gone): says so, no endless "offline" retries
        pg2 = pg  # the same signed-in page; pg2.on("pageerror", lambda e: errors.append(str(e)))
        saves = []
        def refuse(route):
            saves.append(route.request.url)
            route.fulfill(status=419, content_type="text/html; charset=UTF-8", body="Your session expired or the form was tampered with.")
        pg2.route(f"**/documents/{did}/save", refuse)
        pg2.goto(B + f"/documents/{did}"); pg2.wait_for_selector("#doc-editor .ql-editor"); pg2.wait_for_timeout(500)
        pg2.click("#doc-editor .ql-editor"); pg2.keyboard.type(" more")
        pg2.wait_for_timeout(2500)
        st = pg2.locator("#doc-state").inner_text()
        ok(saves and pg2.locator("#doc-error").is_visible() and "session ended" in pg2.locator("#doc-error").inner_text() and "signed out" in st,
           "a 419 on autosave says the session ended (not \"Offline — will retry\"): " + st)
        n = len(saves); pg2.wait_for_timeout(6000)
        ok(len(saves) == n, "and it doesn't keep retrying (%d saves, then %d)" % (n, len(saves)))
        ok(q("select body_html from documents where id=%s", did)[0]["body_html"] == "<p>Start</p>", "nothing was saved meanwhile")
        pg2.close()
        ok(not errors, "no script errors on these pages: " + "; ".join(errors[:3]))
        b.close()
finally:
    if did:
        q("delete from document_presence where document_id=%s", did)
        q("delete from document_versions where document_id=%s", did)
        q("delete from documents where id=%s", did)
    if tid:
        q("delete from contract_templates where id=%s", tid)

done()
