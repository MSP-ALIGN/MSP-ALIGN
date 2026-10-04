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
document.addEventListener('DOMContentLoaded', () => {
  const host = document.getElementById('doc-editor');
  if (!host || !window.Quill) return;

  const app = document.getElementById('doc-app');
  const isTemplate = host.dataset.mode === 'template';
  const canEdit = isTemplate || (app && app.dataset.canEdit === '1');

  const quill = new Quill(host, {
    theme: 'snow',
    readOnly: !canEdit,
    placeholder: canEdit ? 'Start writing…' : '',
    formats: alignQuillFormats,
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
  ['category', 'status', 'review_due'].forEach((k) => fields[k].addEventListener('change', () => schedule(200)));

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
    if (sel) quill.setSelection(Math.min(sel.index, quill.getLength() - 1), 0, 'silent');
    fields.title.value = j.title;
    fields.category.value = j.category;
    fields.status.value = j.status;
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
