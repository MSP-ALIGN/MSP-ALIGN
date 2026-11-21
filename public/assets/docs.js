// Document editor: Quill + autosave + conflict protection + presence.
//
// Security assumptions: the page is a staff document page or a template editor (an admin's document or onboarding
// template, or the onboarding send form). The HTML handed to the editor (<template id="doc-initial">, /content)
// was cleaned by Docs\Html::clean when saved, and every save is cleaned again by the server, so nothing here is a
// security boundary for the stored HTML: this file only keeps the editor from producing what the server would drop
// (and loses no typing). Server text (names, errors) is only ever put on the page with textContent.

// The formats the server's cleaner keeps (Docs\Html::clean): Quill's own minus image, video and formula. A pasted
// or dropped picture would otherwise become a data: URL in the body, silently dropped when saved, and a large one
// would push the body past the server's 4 MB cut and lose the rest of the document. Shared with contracts.js.
// eslint-disable-next-line no-unused-vars
const alignQuillFormats = ['header', 'bold', 'italic', 'underline', 'strike', 'color', 'background', 'list', 'indent', 'align',
  'blockquote', 'code-block', 'link', 'code', 'script', 'size', 'font', 'direction'];

/**
 * Sets up the one editor on the page (#doc-editor). Template mode: the HTML goes into #template-body when the form
 * is posted. Document mode: autosaves to /documents/{id}/save with the page's CSRF token and the version it
 * started from (409 = someone saved meanwhile: shown, never overwritten without Keep mine), and polls presence.
 * Read-only for viewers (data-can-edit="0"); the server checks the role again on every save.
 */
/**
 * (2.41.0) A marked field value (Docs\Fields): <span class="doc-field" data-field="key" data-value="…">. Quill drops
 * markup it has no format for, so without this the marks would be lost on the first save. The format's value is
 * {key, value}: the field key and the text the value was filled with (the server compares it with the text to tell
 * an untouched value from one someone edited). Typing inside a value keeps the mark, so the edit shows as an edit.
 */
const alignRegisterField = () => {
  if (!window.Quill || window.alignFieldRegistered) return;
  const Inline = Quill.import('blots/inline');
  class FieldBlot extends Inline {
    static create(v) {
      const node = super.create();
      const key = v && typeof v === 'object' ? String(v.key || '') : '';
      if (/^[a-z_]{1,40}$/.test(key)) node.setAttribute('data-field', key);
      node.setAttribute('data-value', v && typeof v === 'object' ? String(v.value || '') : '');
      return node;
    }

    static formats(node) {
      return { key: node.getAttribute('data-field') || '', value: node.getAttribute('data-value') || '' };
    }

    // (2.41.0 review) Parchment merges neighbouring inline blots only when their format values are the same object,
    // never true for {key, value}: bold then un-bold inside a value would leave it in pieces, which the server reads
    // as edited by hand. Join a neighbour that is the same field filled with the same value.
    optimize(context) {
      super.optimize(context);
      const next = this.next;
      if (next instanceof FieldBlot && next.prev === this
        && next.domNode.getAttribute('data-field') === this.domNode.getAttribute('data-field')
        && next.domNode.getAttribute('data-value') === this.domNode.getAttribute('data-value')) {
        next.moveChildren(this);
        next.remove();
      }
    }
  }
  FieldBlot.blotName = 'docfield';
  FieldBlot.tagName = 'SPAN';
  FieldBlot.className = 'doc-field';
  Quill.register(FieldBlot, true);
  window.alignFieldRegistered = true;
};

document.addEventListener('DOMContentLoaded', () => {
  const host = document.getElementById('doc-editor');
  if (!host || !window.Quill) return;
  alignRegisterField();

  const app = document.getElementById('doc-app');
  const isTemplate = host.dataset.mode === 'template';
  const canEdit = isTemplate || (app && app.dataset.canEdit === '1');

  const quill = new Quill(host, {
    theme: 'snow',
    readOnly: !canEdit,
    placeholder: canEdit ? 'Start writing…' : '',
    formats: alignQuillFormats.concat(['docfield']), // (2.41.0) marked field values
    modules: {
      toolbar: canEdit ? [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ color: [] }, { background: [] }],
        [{ list: 'ordered' }, { list: 'bullet' }, { list: 'check' }],
        [{ indent: '-1' }, { indent: '+1' }, { align: [] }],
        ['blockquote', 'code-block', 'link'],
        ['clean'],
      ] : false,
      history: { delay: 1000, maxStack: 200, userOnly: true },
    },
  });

  /** Replaces the editor's content without an undo step or a save (HTML from the server, already cleaned). */
  const setHtml = (html) => {
    const delta = quill.clipboard.convert({ html: html || '' });
    quill.setContents(delta, 'silent');
  };
  // getSemanticHTML gives clean <ul>/<ol> markup but turns every space into a non-breaking
  // space, which stops text wrapping in print, so convert those back.
  const getHtml = () => (quill.getLength() <= 1 ? '' : quill.getSemanticHTML().replace(/\u00a0/g, ' ').replace(/&nbsp;/g, ' '));
  const initial = document.getElementById('doc-initial');
  setHtml(initial ? initial.innerHTML : '');
  quill.history.clear();

  // ---- Template editor: plain form submit ------------------------------------
  if (isTemplate) {
    const form = document.getElementById('template-form');
    form.addEventListener('submit', () => { document.getElementById('template-body').value = getHtml(); });
    document.querySelectorAll('[data-insert]').forEach((b) => b.addEventListener('click', () => {
      const range = quill.getSelection(true);
      quill.insertText(range ? range.index : quill.getLength() - 1, b.dataset.insert, 'user');
    }));
    return;
  }
  if (!app) return;

  // ---- Document editor ---------------------------------------------------------
  const id = app.dataset.id;
  const csrf = app.dataset.csrf;
  let version = parseInt(app.dataset.version, 10);
  let dirty = false;
  let saving = false;
  let queued = false;
  let timer = null;
  let lastTyped = 0;
  let conflictVersion = null;

  const $ = (sel) => document.getElementById(sel);
  const state = $('doc-state');
  const fields = { title: $('doc-title'), category: $('doc-category'), status: $('doc-status'), review_due: $('doc-review') };

  /** The save state next to the title (text only; cls is one of our own text-* classes). */
  const setState = (text, cls) => {
    state.textContent = text;
    state.className = 'doc-save-state small me-3 ' + (cls || '');
  };
  const timeNow = () => new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

  /**
   * POSTs to one of this document's own same-site URLs with the page's CSRF token; resolves to {status, json}.
   * Throws 'signed-out' when the answer is the sign-in page (redirected, or a 200 that isn't JSON) or 419 (the
   * session that issued the token is gone: the server checks the token before it can redirect), and
   * 'HTTP <status>' (with .status) for any other answer that isn't JSON.
   */
  const post = async (url, data) => {
    const body = new URLSearchParams({ _csrf: csrf, ...data });
    const res = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const type = res.headers.get('Content-Type') || '';
    if (!type.includes('application/json')) {
      const err = new Error(res.redirected || res.status === 200 || res.status === 419 ? 'signed-out' : 'HTTP ' + res.status);
      err.status = res.status;
      throw err;
    }
    return { status: res.status, json: await res.json() };
  };

  /** Shows "someone saved a newer version" (info: version, updated_by, updated_ago from the server; text only). */
  const showConflict = (info) => {
    conflictVersion = info.version;
    $('doc-conflict-text').textContent = (info.updated_by || 'Someone') + ' saved a newer version ' + (info.updated_ago || '') +
      '. Your latest changes are not saved yet.';
    $('doc-conflict').classList.remove('d-none');
    $('doc-conflict').classList.add('d-flex');
    setState('Not saved — newer version exists', 'text-danger');
  };
  /** Hides the conflict bar; saves run again. */
  const hideConflict = () => {
    conflictVersion = null;
    $('doc-conflict').classList.add('d-none');
    $('doc-conflict').classList.remove('d-flex');
  };

  /**
   * Saves the title, fields and body. One save at a time: a save asked for meanwhile runs after it. opts.force
   * overwrites a newer version (Keep mine), opts.checkpoint keeps a named version and reloads. Network trouble
   * and server errors (5xx) retry every 5 seconds; an answer that refuses the save (signed out, 4xx such as no
   * longer allowed, document deleted, too large) stops and says so, because retrying can't succeed.
   */
  const save = async (opts = {}) => {
    if (!canEdit) return;
    if (saving) { queued = true; return; }
    if (conflictVersion !== null && !opts.force) return;
    saving = true;
    dirty = false;
    setState('Saving…', 'text-muted');
    const data = {
      base_version: String(version),
      title: fields.title.value,
      category: fields.category.value,
      status: fields.status.value,
      review_due: fields.review_due.value,
      body: getHtml(),
    };
    if (opts.force) data.force = '1';
    if (opts.checkpoint) { data.checkpoint = '1'; data.note = opts.note || ''; }
    try {
      const { status, json } = await post('/documents/' + id + '/save', data);
      if (status === 409) {
        dirty = true;
        showConflict(json);
      } else if (json.ok) {
        version = json.version;
        $('doc-version-label').textContent = version;
        $('doc-updated').textContent = 'just now by you';
        hideConflict();
        $('doc-error').classList.add('d-none');
        setState(opts.checkpoint ? 'Version saved ' + timeNow() : 'Saved ' + timeNow(), 'text-success');
        if (opts.checkpoint) setTimeout(() => window.location.reload(), 600);
      } else {
        const err = new Error(json.error || 'Save failed');
        err.status = status;
        err.fromServer = true;
        throw err;
      }
    } catch (e) {
      dirty = true;
      if (e.message === 'signed-out') {
        $('doc-error').textContent = 'Your session ended, so changes can’t be saved. Copy your text, sign in again in another tab, then paste it back.';
        $('doc-error').classList.remove('d-none');
        setState('Not saved — signed out', 'text-danger');
      } else if (e.status >= 400 && e.status < 500) {
        // Refused, not offline (this used to retry every 5 seconds forever under "Offline — will retry")
        $('doc-error').textContent = 'Not saved: ' + (e.fromServer ? e.message : 'the server refused it (' + e.message + ')')
          + '. Copy your text, then reload the page.';
        $('doc-error').classList.remove('d-none');
        setState('Not saved', 'text-danger');
      } else {
        setState('Offline — will retry', 'text-warning');
        clearTimeout(timer);
        timer = setTimeout(() => save(), 5000);
      }
    } finally {
      saving = false;
      if (queued) { queued = false; if (dirty) schedule(300); }
    }
  };

  /** Marks the editor changed and saves after a pause in typing (ms). */
  const schedule = (ms = 1500) => {
    if (!canEdit) return;
    dirty = true;
    lastTyped = Date.now();
    if (conflictVersion === null) setState('Unsaved changes…', 'text-muted');
    clearTimeout(timer);
    timer = setTimeout(() => save(), ms);
  };

  quill.on('text-change', (delta, old, source) => { if (source === 'user') schedule(); });
  fields.title.addEventListener('input', () => schedule());
  ['category', 'review_due'].forEach((k) => fields[k].addEventListener('change', () => schedule(200)));
  // (2.39.0) Making a document Active with blanks left asks first. While the dialog is open the select shows the
  // old status again, so a save that runs meanwhile (autosave, Ctrl+S) can't send Active before the answer.
  let statusWas = fields.status.value;
  let asking = false;
  fields.status.addEventListener('change', async () => {
    if (asking) { fields.status.value = statusWas; return; } // arrow keys fire a change each: one dialog
    const to = fields.status.value;
    const n = to === 'active' && guide ? scanCount() : 0;
    if (n && typeof alignConfirm === 'function') {
      fields.status.value = statusWas;
      asking = true;
      const ok = await alignConfirm({ title: 'Make it Active with ' + n + (n === 1 ? ' blank' : ' blanks') + ' left to fill?',
        text: 'Active documents show in the client portal when shared, and can be sent for signature. The Blanks list shows what’s left.', ok: 'Make Active', danger: false });
      asking = false;
      if (!ok) return;
      fields.status.value = to;
    }
    statusWas = fields.status.value;
    schedule(200);
  });

  // Ctrl/Cmd+S saves immediately
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); clearTimeout(timer); save(); }
  });

  const cp = $('doc-checkpoint');
  if (cp) cp.addEventListener('click', () => {
    clearTimeout(timer);
    save({ checkpoint: true, note: $('checkpoint-note').value });
    if (window.bootstrap && document.getElementById('modal-checkpoint')) window.bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-checkpoint')).hide();
  });

  /**
   * Replaces the editor with the saved version (/content). auto (the live refresh) never replaces typing: when
   * something was typed, or a save started, while it loaded, the conflict bar is shown instead and false returned.
   * Load theirs (auto false) is the person choosing to drop their changes.
   */
  const loadLatest = async (auto = false) => {
    const res = await fetch('/documents/' + id + '/content', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const j = await res.json();
    // The heartbeat checked "nothing unsaved" before this request; keystrokes since then would be wiped otherwise
    if (auto && (dirty || saving)) {
      if (conflictVersion === null) showConflict(j);
      return false;
    }
    const sel = quill.getSelection();
    setHtml(j.body);
    renderGuide(); // (2.39.0) a silent load fires no text-change
    if (sel) quill.setSelection(Math.min(sel.index, quill.getLength() - 1), 0, 'silent');
    fields.title.value = j.title;
    fields.category.value = j.category;
    fields.status.value = j.status;
    statusWas = j.status; // (2.39.0) the status the Active question goes back to
    fields.review_due.value = j.review_due || '';
    version = j.version;
    $('doc-version-label').textContent = version;
    $('doc-updated').textContent = j.updated_ago + (j.updated_by ? ' by ' + j.updated_by : '');
    dirty = false;
    hideConflict();
    setState('Updated to the latest version', 'text-info');
    return true;
  };
  $('doc-load-theirs').addEventListener('click', () => loadLatest());
  $('doc-keep-mine').addEventListener('click', () => { save({ force: true }); });

  // ---- (2.39.0) Blanks to fill and the section outline -------------------------------
  // A blank is a bracketed prompt the templates leave, like "[name, title]" or "[date]": 1-120 characters with no
  // bracket or line break inside, except a plain number ("[1]" is a citation). Same rule as Docs\Blanks::PATTERN, so
  // the panel and the server's warnings count the same. Everything here is built from the editor's own text and
  // put on the page with textContent; filling a blank is an ordinary edit (undoable, autosaved like typing).
  const guide = $('doc-guide');
  const BLANK = /\[[^[\]\r\n]{1,120}\]/g;
  const INLINE = ['bold', 'italic', 'underline', 'strike', 'color', 'background', 'link', 'code', 'script', 'size', 'font'];
  let blanks = [];
  /** The headings (h1-h3) and blanks in the editor now: {heads: [{index, level, text}], blanks: [{index, text, head, before}]}. */
  const scan = () => {
    const heads = [];
    quill.getLines().forEach((line) => {
      const level = line.formats().header;
      if (level && level <= 3) {
        const index = quill.getIndex(line);
        heads.push({ index, level, text: quill.getText(index, line.length() - 1).trim() || '(untitled section)' });
      }
    });
    const text = quill.getText();
    const found = [];
    let m;
    BLANK.lastIndex = 0;
    while ((m = BLANK.exec(text)) !== null) {
      if (/^\[\d+\]$/.test(m[0])) continue;
      const lineStart = text.lastIndexOf('\n', m.index - 1) + 1;
      let head = -1;
      heads.forEach((h, i) => { if (h.index <= m.index) head = i; });
      found.push({ index: m.index, text: m[0], head, before: text.slice(Math.max(lineStart, m.index - 60), m.index) });
    }
    return { heads, blanks: found };
  };
  /** Selects text in the editor and brings it into view. */
  const goTo = (index, length) => {
    quill.setSelection(index, length, 'user');
    const b = quill.getBounds(index, length);
    if (b) window.scrollTo({ top: quill.root.getBoundingClientRect().top + window.scrollY + b.top - 120, behavior: 'smooth' });
  };
  /**
   * Puts value in place of the blank found at index (only if that blank is still there), keeping its inline
   * formatting (bold and so on), as a user edit so it autosaves and can be undone.
   */
  const fillBlank = (b, value) => {
    if (!canEdit || !value.trim() || quill.getText(b.index, b.text.length) !== b.text) return false;
    const fmt = quill.getFormat(b.index, b.text.length);
    const keep = {};
    INLINE.forEach((k) => { if (fmt[k] !== undefined) keep[k] = fmt[k]; });
    // (2.41.0 review) a form field's blank keeps its mark: the typed text then counts as edited in the document
    if (fmt.docfield && typeof fmt.docfield === 'object') keep.docfield = fmt.docfield;
    quill.deleteText(b.index, b.text.length, 'user');
    quill.insertText(b.index, value.trim(), keep, 'user');
    return true;
  };
  /** Redraws both lists and the count. focusAt: the position in the list whose input should get the focus. */
  const renderGuide = (focusAt = null) => {
    if (!guide) return;
    const { heads, blanks: found } = scan();
    blanks = found;
    $('doc-blank-count').textContent = String(found.length);
    $('doc-blank-count').className = 'badge ms-1 ' + (found.length ? 'text-bg-warning' : 'text-bg-success');
    // The warnings in the Send for signature and acknowledgment dialogs (documents/_blank_warning)
    document.querySelectorAll('[data-blank-warning]').forEach((w) => {
      w.hidden = !found.length;
      w.querySelector('[data-blank-n]').textContent = String(found.length);
      w.querySelector('[data-blank-word]').textContent = found.length === 1 ? 'blank' : 'blanks';
    });
    const list = $('doc-blanks');
    list.replaceChildren();
    if (!found.length) {
      const p = document.createElement('p');
      p.className = 'text-muted px-3 py-2 mb-0';
      p.textContent = 'No blanks left to fill.';
      list.appendChild(p);
    }
    let lastHead = null;
    found.forEach((b, i) => {
      if (b.head !== lastHead) {
        lastHead = b.head;
        const h = document.createElement('div');
        h.className = 'doc-guide-head';
        h.textContent = b.head >= 0 ? heads[b.head].text : 'Start of the document';
        list.appendChild(h);
      }
      const row = document.createElement('div');
      row.className = 'doc-blank';
      row.dataset.blank = b.text;
      const ctx = document.createElement('a');
      ctx.href = '#';
      ctx.className = 'doc-blank-ctx';
      ctx.title = 'Show it in the document';
      ctx.append(document.createTextNode((b.before.length >= 60 ? '…' : '') + b.before));
      const mark = document.createElement('mark');
      mark.textContent = b.text;
      ctx.appendChild(mark);
      ctx.addEventListener('click', (e) => { e.preventDefault(); goTo(b.index, b.text.length); });
      row.appendChild(ctx);
      if (canEdit) {
        const form = document.createElement('form');
        form.className = 'input-group input-group-sm mt-1';
        const input = document.createElement('input');
        input.className = 'form-control';
        input.placeholder = b.text.slice(1, -1);
        input.setAttribute('aria-label', 'Fill ' + b.text);
        input.maxLength = 500;
        const btn = document.createElement('button');
        btn.className = 'btn btn-default';
        btn.textContent = 'Fill';
        form.append(input, btn);
        form.addEventListener('submit', (e) => {
          e.preventDefault();
          const filled = fillBlank(blanks[i] && blanks[i].index === b.index ? blanks[i] : b, input.value);
          clearTimeout(guideTimer); // the fill's text-change would redraw again and take the focus away
          renderGuide(filled ? i : null);
        });
        row.appendChild(form);
        if (focusAt === i) setTimeout(() => input.focus(), 0);
      }
      list.appendChild(row);
    });
    // The outline: each heading, indented by level, with the blanks left under it
    const out = $('doc-outline');
    out.replaceChildren();
    if (!heads.length) {
      const p = document.createElement('p');
      p.className = 'text-muted px-3 py-2 mb-0';
      p.textContent = 'No headings yet. Headings (Heading 1-3 in the toolbar) show here.';
      out.appendChild(p);
    }
    heads.forEach((h, i) => {
      const a = document.createElement('a');
      a.href = '#';
      a.className = 'doc-outline-item level-' + h.level;
      a.textContent = h.text;
      const n = found.filter((b) => b.head === i).length;
      if (n) {
        const badge = document.createElement('span');
        badge.className = 'badge text-bg-warning ms-1';
        badge.textContent = String(n);
        badge.title = n + (n === 1 ? ' blank' : ' blanks') + ' to fill';
        a.appendChild(badge);
      }
      a.addEventListener('click', (e) => { e.preventDefault(); goTo(h.index, 0); });
      out.appendChild(a);
    });
  };
  /** Blanks left in the editor now. */
  const scanCount = () => scan().blanks.length;
  let guideTimer = null;
  if (guide) {
    quill.on('text-change', () => { clearTimeout(guideTimer); guideTimer = setTimeout(() => renderGuide(), 400); });
    renderGuide();
  }

  // Fill from client details: saves what's typed first, then the server fills the blanks the client's details answer
  // on that version and the editor loads the result
  const fillFacts = $('doc-fill-facts');
  if (fillFacts) fillFacts.addEventListener('click', async () => {
    fillFacts.disabled = true;
    try {
      clearTimeout(timer);
      if (dirty || saving) await save();
      for (let i = 0; saving && i < 50; i++) await new Promise((r) => setTimeout(r, 100));
      if (dirty || conflictVersion !== null) { setState('Save your changes first', 'text-warning'); return; }
      const { status, json } = await post('/documents/' + id + '/facts', { base_version: String(version) });
      if (status === 409) { showConflict(json); return; }
      if (!json.ok) throw new Error(json.error || 'Not filled');
      if (json.filled) {
        // loadLatest(true) never replaces typing done while the fill ran: it shows the conflict bar instead
        if (!(await loadLatest(true))) return;
        setState('Filled ' + json.filled + (json.filled === 1 ? ' blank' : ' blanks') + ' from client details', 'text-success');
      } else {
        setState(json.no_facts ? 'No client details yet' : 'No blanks here match the client details', 'text-muted');
      }
    } catch (e) {
      setState('Couldn’t fill from client details', 'text-danger');
    } finally {
      fillFacts.disabled = false;
    }
  });

  // ---- Presence + live refresh ---------------------------------------------------
  const presence = $('doc-presence');
  /**
   * Every 10 s (15 s read-only): says I'm here (and whether I'm typing), shows who else is, and pulls in a newer
   * saved version when I have nothing unsaved. Names come from the server and are set as text; avatar is the
   * server's own avatar_url() path, used only as an image source.
   */
  const heartbeat = async () => {
    try {
      const editing = canEdit && Date.now() - lastTyped < 30000 ? '1' : '0';
      const { json } = await post('/documents/' + id + '/presence', { editing });
      presence.innerHTML = '';
      (json.others || []).forEach((o) => {
        const s = document.createElement(o.avatar ? 'img' : 'span');
        s.className = 'presence-dot' + (o.editing ? ' is-editing' : '') + (o.avatar ? ' avatar-img' : '');
        s.title = o.name + (o.editing ? ' is editing' : ' is viewing');
        if (o.avatar) { s.src = o.avatar; s.alt = o.name; } else { s.textContent = o.initials; }
        presence.appendChild(s);
      });
      if ((json.others || []).length) {
        const t = document.createElement('span');
        t.className = 'small text-muted ms-1';
        const names = json.others.map((o) => o.name.split(' ')[0]);
        t.textContent = names.join(', ') + (json.others.some((o) => o.editing) ? ' editing' : ' here');
        presence.appendChild(t);
      }
      if (json.version > version) {
        if (!dirty && !saving) {
          // someone else saved: pull it in live (unless typing starts while it loads)
          if (await loadLatest(true)) setState('Updated with ' + (json.updated_by || 'another user') + '’s changes', 'text-info');
        } else if (conflictVersion === null) {
          showConflict(json);
        }
      }
    } catch (e) { /* offline: try again next tick */ }
  };
  heartbeat();
  setInterval(heartbeat, canEdit ? 10000 : 15000);

  window.addEventListener('beforeunload', (e) => {
    if (dirty || saving) { e.preventDefault(); e.returnValue = ''; }
  });
});
