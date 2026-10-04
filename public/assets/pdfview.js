// Contracts on the MSP's own PDF (2.2): draws the pages with PDF.js and the boxes over them.
// <div data-pv data-mode="build|preview|sign|view" data-src="/…/source"> with a <script class="pv-data"> of {items, pages}.
// Boxes are placed in points from the page's top-left as shown, and scale with the page.
//
// Security assumptions: used on staff contract pages and on the public signing page. data-src is a same-site URL
// the server wrote (the template's or contract's PDF); the PDF itself is untrusted (an uploaded file), so PDF.js
// only paints it: no eval, no scripts, no forms, no annotation or text layer; the standard fonts come from this
// server (fonts embedded in the PDF are read from its own data, never fetched). The items in
// <script class="pv-data"> are the server's (labels and values typed by staff or the signer), JSON-encoded with
// the JSON_HEX_* flags; every value built into markup here goes through esc() in element text or a quoted
// attribute, and img sources are data: URLs the server made from re-encoded images.
(() => {
  /** HTML-escapes for element text and quoted attributes (not URLs, CSS or JS). */
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let lib = null;
  /** PDF.js, loaded once (as a module, from /vendor on this server) with its worker from the same place. */
  const pdfjs = (v) => lib || (lib = import('/vendor/pdfjs/pdf.min.js?v=' + encodeURIComponent(v || '')).then((m) => {
    m.GlobalWorkerOptions.workerSrc = '/vendor/pdfjs/pdf.worker.min.js?v=' + encodeURIComponent(v || '');
    return m;
  }));
  const docs = new Map();
  // Same-origin only (the PDF and the fonts come from this server); no eval, no scripts, no forms, no annotations
  // layer: the pages are only painted.
  const openDoc = (src, v) => {
    if (!docs.has(src)) {
      const p = pdfjs(v).then((m) => m.getDocument({
        url: src, isEvalSupported: false, disableRange: true, disableStream: true,
        standardFontDataUrl: '/vendor/pdfjs/standard_fonts/',
      }).promise);
      p.catch(() => docs.delete(src)); // a failed load can be tried again
      docs.set(src, p);
    }
    return docs.get(src);
  };

  /** v as a percentage of of, for CSS. */
  const pct = (v, of) => (v / of * 100) + '%';

  // One resize handler for every viewer on the page (a preview that's replaced drops its old viewer)
  const viewers = new Set();
  let resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => viewers.forEach((v) => (v.el.isConnected ? v.resized() : viewers.delete(v))), 200);
  });

  /** Like the PDF: shrink a value until it fits its box (down to 6.5 pt), never cut it; past that it runs over. */
  const fit = (el, size) => {
    if (!el.clientWidth) return;
    let s = +size || 10;
    el.classList.remove('pv-over');
    el.style.setProperty('--fs', s);
    while (el.scrollWidth > el.clientWidth + 1 && s > 6.5) {
      s = Math.max(6.5, s - 0.5);
      el.style.setProperty('--fs', s);
    }
    if (el.scrollWidth > el.clientWidth + 1) el.classList.add('pv-over');
  };

  /** One item's element (not for build mode, where the builder draws its own boxes). */
  const itemEl = (it) => {
    const el = document.createElement('div');
    el.className = 'pv-item pv-' + it.kind + ' pv-a-' + (it.align || 'left') + (it.who ? ' pv-who-' + it.who : '');
    el.dataset.id = it.id;
    el.style.setProperty('--fs', it.size);
    const f = it.field;
    switch (it.kind) {
      case 'text':
        el.textContent = it.text;
        if (it.sig) el.classList.add('pv-script');
        if (it.auto) { el.classList.add('pv-auto'); el.title = 'Filled in automatically: the date you sign'; }
        break;
      case 'label':
        el.textContent = it.label;
        el.title = it.who === 'client' ? 'The client fills this in' : (it.who === 'provider' ? 'You fill this in, or sign here' : 'Filled in by Align');
        break;
      case 'sig':
        if (it.img) el.innerHTML = '<img alt="Signature" src="' + esc(it.img) + '">';
        else { el.textContent = it.text; el.classList.add('pv-script'); }
        break;
      case 'photo':
        el.innerHTML = '<img alt="" src="' + esc(it.img) + '">';
        break;
      // Signing: the steps the guide on the signing page takes the signer through (contracts.js does the rest)
      case 'sighere':
        el.innerHTML = '<button type="button" class="pv-step" data-step="sign"><i class="fas fa-pen-nib me-1" aria-hidden="true"></i>Sign</button>';
        break;
      case 'initials':
        el.innerHTML = '<button type="button" class="pv-step" data-step="initials" data-place="' + esc(it.id) + '"' + (f ? ' data-field="' + esc(f.name) + '"' : '') + '>Initial</button>';
        break;
      case 'input': {
        const req = f.required ? ' required' : '';
        // a client blank posts with the form; the signer's own name and title (role) are copied into it by the guide
        const named = f.role ? ' data-role="' + esc(f.role) + '" autocomplete="' + (f.role === 'sig_name' ? 'name' : 'organization-title') + '"' : ' name="' + esc(f.name) + '" form="sign-form"';
        const common = ' class="pv-in"' + named + ' data-cf' + req + ' aria-label="' + esc(f.label) + '" title="' + esc(f.label + (f.help ? ' — ' + f.help : '')) + '"';
        if (f.type === 'longtext') el.innerHTML = '<textarea' + common + ' placeholder="' + esc(f.label) + '">' + esc(f.value) + '</textarea>';
        else if (f.type === 'choice') el.innerHTML = '<select' + common + '><option value="">' + esc(f.label) + '…</option>' + f.options.map((o) => '<option value="' + esc(o) + '"' + (o === f.value ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>';
        else if (f.type === 'checkbox') el.innerHTML = '<input type="checkbox" value="1"' + common + (f.value === '1' ? ' checked' : '') + '>';
        else {
          const type = { date: 'date', email: 'email', number: 'number', money: 'number', phone: 'tel' }[f.type] || 'text';
          el.innerHTML = '<input type="' + type + '"' + (type === 'number' ? ' step="any" min="0"' : '') + common + ' placeholder="' + esc(f.label) + '" value="' + esc(f.value) + '">';
        }
        break;
      }
      default: // blank
        el.classList.add('pv-empty');
    }
    return el;
  };

  /**
   * One viewer: the pages of the PDF in data-src drawn into canvases as they scroll into view, with a layer for the
   * boxes. Modes: build (the builder draws its own boxes), preview, sign (inputs and steps for the signer), view.
   */
  class Viewer {
    /** Reads the items and page sizes, starts loading (this.ready resolves when the pages are laid out). */
    constructor(el) {
      this.el = el;
      this.mode = el.dataset.mode || 'view';
      const data = JSON.parse((el.querySelector('.pv-data') || { textContent: '{}' }).textContent || '{}');
      this.items = data.items || [];
      this.sizes = data.pages || [];
      this.pagesEl = el.querySelector('.pv-pages');
      this.pages = [];
      this.ready = this.load();
      el.alignPdf = this;
    }

    /** Opens the PDF and lays out one placeholder per page; a PDF that can't be opened shows the reason (escaped). */
    async load() {
      let doc;
      try {
        doc = await openDoc(this.el.dataset.src, this.el.dataset.v);
      } catch (e) {
        this.pagesEl.innerHTML = '<div class="alert alert-warning m-2">The PDF couldn’t be shown here (' + esc(e && e.message ? e.message : 'error') + ').</div>';
        return this; // the message is the result; nothing else to do
      }
      this.doc = doc;
      this.pagesEl.innerHTML = '';
      const n = doc.numPages;
      for (let i = 0; i < n; i++) {
        const [W, H] = this.sizes[i] || [612, 792];
        const wrap = document.createElement('div');
        wrap.className = 'pv-page';
        wrap.style.aspectRatio = W + ' / ' + H;
        wrap.dataset.page = i;
        wrap.innerHTML = '<canvas class="pv-canvas" aria-hidden="true"></canvas><div class="pv-layer"></div><div class="pv-num">Page ' + (i + 1) + ' of ' + n + '</div>';
        this.pagesEl.appendChild(wrap);
        this.pages.push({ el: wrap, layer: wrap.querySelector('.pv-layer'), canvas: wrap.querySelector('canvas'), W, H, drawn: 0, page: null });
      }
      this.scale();
      const io = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => entries.forEach((en) => {
        if (en.isIntersecting) this.draw(+en.target.dataset.page);
      }), { rootMargin: '600px 0px' }) : null;
      this.pages.forEach((p, i) => (io ? io.observe(p.el) : this.draw(i)));
      viewers.add(this);
      this.render();
      return this;
    }

    /** After the window is resized: the new scale, and the pages drawn again at the new size. */
    resized() {
      this.scale();
      this.pages.forEach((p, i) => { if (p.drawn) { p.drawn = 0; this.draw(i); } });
    }

    /** Text size follows the page: --k is screen pixels per point. */
    scale() {
      this.pages.forEach((p) => p.el.style.setProperty('--k', (p.el.clientWidth || 600) / p.W));
    }

    /** Paints page i at the page's current width (sharper on high-density screens, at most 2x). */
    async draw(i) {
      const p = this.pages[i];
      const width = p.el.clientWidth;
      if (!width || p.drawn === width) return;
      p.drawn = width;
      const page = p.page || (p.page = await this.doc.getPage(i + 1));
      const base = page.getViewport({ scale: 1 });
      const ratio = Math.min(window.devicePixelRatio || 1, 2);
      const vp = page.getViewport({ scale: width / base.width * ratio });
      p.canvas.width = Math.floor(vp.width);
      p.canvas.height = Math.floor(vp.height);
      if (p.task) { try { p.task.cancel(); } catch (e) { /* done */ } }
      // Without annotations (form fields, comments, link boxes): signed copies leave them out, so the pages look the
      // same here as in the signed PDF
      const m = await pdfjs(this.el.dataset.v);
      p.task = page.render({ canvasContext: p.canvas.getContext('2d'), viewport: vp, annotationMode: m.AnnotationMode.DISABLE });
      try { await p.task.promise; } catch (e) { /* cancelled by a newer draw */ }
    }

    /** New boxes for the same PDF (the prepare page's live preview), without loading the pages again. */
    setItems(items) {
      this.items = items;
      if (this.pages.length) this.render();
    }

    /** Draws the boxes over the pages and tells listeners (pv:rendered: the guide fills in what was typed before). */
    render() {
      if (this.mode === 'build') return; // the builder draws its boxes
      this.pages.forEach((p) => { p.layer.innerHTML = ''; });
      this.items.forEach((it) => {
        const p = this.pages[it.page];
        if (!p) return;
        const el = itemEl(it);
        Object.assign(el.style, { left: pct(it.x, p.W), top: pct(it.y, p.H), width: pct(it.w, p.W), height: pct(it.h, p.H) });
        p.layer.appendChild(el);
        if (it.kind === 'text') fit(el, it.size);
      });
      this.el.dispatchEvent(new CustomEvent('pv:rendered', { bubbles: true }));
    }

    /** A point on a page, from screen coordinates, in points. */
    toPoints(i, clientX, clientY) {
      const p = this.pages[i];
      const r = p.el.getBoundingClientRect();
      return { x: (clientX - r.left) / r.width * p.W, y: (clientY - r.top) / r.height * p.H };
    }

    /** The page's text with positions in points (top-left origin), for finding blanks. */
    async text(i) {
      const p = this.pages[i];
      const page = p.page || (p.page = await this.doc.getPage(i + 1));
      const vp = page.getViewport({ scale: 1 });
      const m = await pdfjs(this.el.dataset.v);
      const tc = await page.getTextContent();
      return tc.items.filter((t) => t.str !== undefined).map((t) => {
        const tx = m.Util.transform(vp.transform, t.transform);
        const fh = Math.hypot(tx[2], tx[3]) || 10;
        return { str: t.str, x: tx[4], y: tx[5], w: t.width, h: fh };
      });
    }
  }

  // AlignPdf.mount(el): the viewer for el (one per element). Every viewer on the page starts once it's loaded,
  // except the builder's, which contracts.js mounts itself.
  window.AlignPdf = { mount: (el) => el.alignPdf || new Viewer(el), esc };
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-pv]').forEach((el) => { if (el.dataset.mode !== 'build') window.AlignPdf.mount(el); });
  });
})();
