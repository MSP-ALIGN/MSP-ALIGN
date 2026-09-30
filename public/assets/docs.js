// Document editor: Quill + autosave + conflict protection + presence.
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

  const setState = (text, cls) => {
    state.textContent = text;
    state.className = 'doc-save-state small me-3 ' + (cls || '');
  };
  const timeNow = () => new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

  const post = async (url, data) => {
    const body = new URLSearchParams({ _csrf: csrf, ...data });
    const res = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const type = res.headers.get('Content-Type') || '';
    if (!type.includes('application/json')) {
      const err = new Error(res.redirected || res.status === 200 ? 'signed-out' : 'HTTP ' + res.status);
      err.status = res.status;
      throw err;
    }
    return { status: res.status, json: await res.json() };
  };

  const showConflict = (info) => {
    conflictVersion = info.version;
    $('doc-conflict-text').textContent = (info.updated_by || 'Someone') + ' saved a newer version ' + (info.updated_ago || '') +
      '. Your latest changes are not saved yet.';
    $('doc-conflict').classList.remove('d-none');
    $('doc-conflict').classList.add('d-flex');
    setState('Not saved — newer version exists', 'text-danger');
  };
  const hideConflict = () => {
    conflictVersion = null;
    $('doc-conflict').classList.add('d-none');
    $('doc-conflict').classList.remove('d-flex');
  };

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
        throw new Error(json.error || 'Save failed');
      }
    } catch (e) {
      dirty = true;
      if (e.message === 'signed-out') {
        $('doc-error').textContent = 'Your session ended, so changes can’t be saved. Copy your text, sign in again in another tab, then paste it back.';
        $('doc-error').classList.remove('d-none');
        setState('Not saved — signed out', 'text-danger');
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

  const loadLatest = async () => {
    const res = await fetch('/documents/' + id + '/content', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const j = await res.json();
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
  };
  $('doc-load-theirs').addEventListener('click', () => loadLatest());
  $('doc-keep-mine').addEventListener('click', () => { save({ force: true }); });

  // ---- Presence + live refresh ---------------------------------------------------
  const presence = $('doc-presence');
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
          await loadLatest();                                   // someone else saved: pull it in live
          setState('Updated with ' + (json.updated_by || 'another user') + '’s changes', 'text-info');
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
