// MSP Align - page behaviour on top of AdminLTE / Bootstrap 4.
//
// Security assumptions: loaded on every staff page, the client portal and the public welcome/signing pages, with a
// CSP of script-src 'self' (no inline scripts or handlers, no eval). The behaviours below are driven by data-*
// attributes and JSON that the server's views write, escaped (e() / json_encode with the JSON_HEX_* flags). The
// delegated handlers on document (data-confirm, data-lazy-modal, data-fill, data-confirm-rules…) act on any element
// with that attribute, which is safe because user-written HTML (documents, templates) passes Docs\Html::clean,
// which keeps no data-* attribute except data-list on <li>. Text from fetch responses or the page goes in with
// textContent; the HTML put in with innerHTML is either fixed markup here or a same-site page the server rendered
// (lazy edit forms). Every POST made from here carries the page's CSRF token.

/** Whether u is a path on this site: starts with one "/" (not "//" or "/\", which browsers read as another host). */
const sitePath = (u) => typeof u === 'string' && /^\/(?![/\\])/.test(u) && !/[\u0000-\u001f]/.test(u);

// Currency and date style from Settings → General (1.38), put on <body data-fmt> by the layout
const alignFmt = (() => {
  let c = {};
  try { c = JSON.parse((document.body && document.body.dataset.fmt) || '{}'); } catch (e) { c = {}; }
  const f = { symbol: '$', after: false, thousands: ',', decimal: '.', date: 'mdy', hour24: false, weekStart: 0, ...c };
  /** Thousands separators into a string of digits. */
  const group = (s) => s.replace(/\B(?=(\d{3})+(?!\d))/g, f.thousands);
  /** n with dec decimals and the chosen separators. */
  const number = (n, dec) => { const [i, d] = Math.abs(n).toFixed(dec).split('.'); return (n < 0 ? '-' : '') + group(i) + (d ? f.decimal + d : ''); };
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return {
    ...f,
    money: (n) => {
      const r = f.decimals === 0 ? Math.round(n) : Math.round(n * 100) / 100;
      const t = number(r, r % 1 ? 2 : 0);
      return f.after ? t + '\u00A0' + f.symbol : f.symbol + (/\p{L}$/u.test(f.symbol) ? '\u00A0' : '') + t;
    },
    date: (d) => f.date === 'iso' ? d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
      : f.date === 'dmy' ? d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() : months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(),
    time: (d) => f.hour24 ? String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0')
      : ((d.getHours() % 12) || 12) + ':' + String(d.getMinutes()).padStart(2, '0') + ' ' + (d.getHours() < 12 ? 'am' : 'pm'),
  };
})();
// Bootstrap 5 components, without jQuery (1.43): bsModal('#id').show(), bsTab(link).show(), bsCollapse(el).show()
const bsEl = (x) => (typeof x === 'string' ? document.querySelector(x) : x);
const bsModal = (x) => (bsEl(x) && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(bsEl(x)) : { show() {}, hide() {} });
const bsTab = (x) => (bsEl(x) && window.bootstrap ? window.bootstrap.Tab.getOrCreateInstance(bsEl(x)) : { show() {} });
const bsCollapse = (x) => (bsEl(x) && window.bootstrap ? window.bootstrap.Collapse.getOrCreateInstance(bsEl(x), { toggle: false }) : { show() {} });

// Set-up that also has to run on parts of the page loaded later (edit forms opened from long lists):
// alignInit.add((root) => ...) runs now on the page and again on each loaded part.
const alignInit = {
  fns: [],
  add(fn) {
    this.fns.push(fn);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => fn(document));
    else fn(document);
  },
  run(root) { this.fns.forEach((fn) => fn(root)); },
};

// "Are you sure?" (2.0.1): an in-app dialog in place of the browser's confirm box.
// alignConfirm({ title, text: 'line' or ['line', ...], ok: 'Delete', danger: true }) resolves to true or false.
const alignConfirm = (() => {
  let el = null, okBtn, cancelBtn, titleEl, bodyEl, iconEl, answer = false, done = null, paused = [], back = null, danger = false;
  let showing = false, wantHide = false;
  // Bootstrap ignores hide() while the dialog is still fading in: a quick click waits for the fade instead of being lost
  const close = (yes) => { answer = yes; if (showing) { wantHide = true; return; } bsModal(el).hide(); };
  /** Makes the dialog once (fixed markup; every text is set later with textContent). */
  const build = () => {
    el = document.createElement('div');
    el.className = 'modal fade align-confirm';
    el.id = 'align-confirm';
    el.tabIndex = -1;
    el.setAttribute('role', 'alertdialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-labelledby', 'align-confirm-title');
    el.setAttribute('aria-describedby', 'align-confirm-body');
    el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
      + '<div class="modal-body d-flex gap-3 pt-4 px-4"><div class="align-confirm-icon"><i></i></div><div class="flex-grow-1">'
      + '<h5 class="modal-title mb-2" id="align-confirm-title"></h5><div id="align-confirm-body" class="text-body-secondary"></div></div></div>'
      + '<div class="modal-footer border-0"><button type="button" class="btn btn-default" data-align-confirm-cancel>Cancel</button>'
      + '<button type="button" class="btn" data-align-confirm-ok></button></div></div></div>';
    document.body.appendChild(el);
    okBtn = el.querySelector('[data-align-confirm-ok]');
    titleEl = el.querySelector('#align-confirm-title');
    bodyEl = el.querySelector('#align-confirm-body');
    iconEl = el.querySelector('.align-confirm-icon i');
    cancelBtn = el.querySelector('[data-align-confirm-cancel]');
    okBtn.addEventListener('click', () => close(true));
    cancelBtn.addEventListener('click', () => close(false));
    el.addEventListener('keydown', (e) => { if (e.key === 'Escape' && showing) close(false); });
    el.addEventListener('shown.bs.modal', () => {
      showing = false;
      if (wantHide) { wantHide = false; bsModal(el).hide(); return; }
      el.setAttribute('role', 'alertdialog'); // Bootstrap sets role=dialog on show
      // above an edit form that is already open: its backdrop and focus trap step aside meanwhile
      const drops = document.querySelectorAll('.modal-backdrop');
      if (drops.length > 1) drops[drops.length - 1].classList.add('align-confirm-backdrop');
      // a red button isn't the default: Enter twice shouldn't delete
      (danger ? cancelBtn : okBtn).focus();
    });
    el.addEventListener('hidden.bs.modal', () => {
      paused.forEach((t) => { try { t.activate(); } catch (e) { /* closed meanwhile */ } });
      paused = [];
      if (document.querySelector('.modal.show')) document.body.classList.add('modal-open');
      if (back && document.contains(back)) { try { back.focus(); } catch (e) { /* gone */ } }
      back = null;
      const r = done; done = null;
      if (r) r(answer);
    });
  };
  return (o) => new Promise((resolve) => {
    if (!window.bootstrap) { resolve(window.confirm([o.title, ...[].concat(o.text || [])].filter(Boolean).join('\n\n'))); return; }
    if (!el) build();
    if (done) { resolve(false); return; } // one at a time
    answer = false;
    wantHide = false;
    showing = true;
    done = resolve;
    danger = !!o.danger;
    back = document.activeElement;
    titleEl.textContent = o.title || 'Are you sure?';
    bodyEl.replaceChildren(...[].concat(o.text || []).filter(Boolean).map((t) => { const p = document.createElement('p'); p.className = 'mb-1'; p.textContent = t; return p; }));
    okBtn.textContent = o.ok || 'Continue';
    okBtn.className = 'btn ' + (o.danger ? 'btn-danger' : 'btn-primary');
    iconEl.className = 'fas ' + (o.danger ? 'fa-triangle-exclamation text-danger' : 'fa-circle-question text-primary');
    document.querySelectorAll('.modal.show').forEach((m) => {
      const inst = window.bootstrap.Modal.getInstance(m);
      if (inst && inst._focustrap) { try { inst._focustrap.deactivate(); paused.push(inst._focustrap); } catch (e) { /* older Bootstrap */ } }
    });
    bsModal(el).show();
  });
})();

// The question and the rest from one message: "Delete Acme? This can't be undone." -> title + text
const confirmParts = (msg) => {
  const m = String(msg || '').match(/^([\s\S]+?\?)(?:\s+([A-Z\u201C"][\s\S]*))?$/);
  return m ? { title: m[1], text: m[2] ? m[2].trim() : '' } : { title: 'Are you sure?', text: msg };
};
/** Whether a confirm should get a red button: a red button, or a message starting with a destructive verb. */
const looksDangerous = (el, msg) => /btn(-outline)?-danger/.test(el.className || '')
  || /^(delete|remove|revoke|disable|retire|archive|reset|turn off|cancel|disconnect|forget|replace|skip)\b/i.test(String(msg || '').trim());

// <button data-confirm="Delete this project?" [data-confirm-ok="Delete"] [data-confirm-danger="0|1"]>
// The click waits for the dialog; Continue clicks it again for real (so the form's own handlers still run).
// The question is shown as text. It only ever asks before a click the page already allows: it adds no action.
document.addEventListener('click', (ev) => {
  const el = ev.target.closest && ev.target.closest('[data-confirm]');
  if (!el) return;
  if (el.dataset.confirmed === '1') { delete el.dataset.confirmed; return; }
  ev.preventDefault();
  ev.stopPropagation();
  ev.stopImmediatePropagation();
  const msg = el.dataset.confirm;
  const label = el.dataset.confirmOk || (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40)
    || ((String(msg || '').match(/^\s*([A-Za-z]+)/) || [])[1] || 'Continue');
  const danger = el.dataset.confirmDanger ? el.dataset.confirmDanger === '1' : looksDangerous(el, msg);
  alignConfirm({ ...confirmParts(msg), ok: label, danger }).then((ok) => {
    if (!ok || el.disabled || !document.contains(el)) return;
    el.dataset.confirmed = '1';
    try { el.click(); } finally { delete el.dataset.confirmed; }
  });
}, true);

// Field values, to tell what changed since the page opened: name -> [values]
const formState = (form) => {
  const st = {};
  Array.from(form.elements).forEach((f) => {
    if (!f.name || f.type === 'hidden' || f.type === 'submit' || f.type === 'button' || f.type === 'file' || f.disabled) return;
    const v = (f.type === 'checkbox' || f.type === 'radio') ? (f.checked ? '1' : '0') : (f.multiple ? Array.from(f.selectedOptions).map((o) => o.value).join(',') : f.value);
    (st[f.name] = st[f.name] || []).push(v);
  });
  return st;
};
/** Whether two formState() values are the same. */
const sameField = (a, b) => JSON.stringify(a || []) === JSON.stringify(b || []);
alignInit.add((root) => root.querySelectorAll('form[data-confirm-rules], form[data-unsaved]').forEach((f) => {
  if (!f.alignStart) f.alignStart = formState(f);
}));
/** Whether a form's fields differ from when it opened (forms with data-unsaved or data-confirm-rules). */
const formDirty = (f) => {
  if (!f.alignStart) return false;
  const now = formState(f);
  return Object.keys({ ...now, ...f.alignStart }).some((k) => !sameField(now[k], f.alignStart[k]));
};

// Ask before a form makes a wide or outside change, only when it would:
// <form data-confirm-rules='[{"when": "[name=send_invites]:checked", "count": "[name=\"ids[]\"]:checked",
//   "changed": "lifespan_laptop,lifespan_desktop", "lowered": "retention_days", "is": {"field": "value"},
//   "changes": true, "title": "...", "text": "... {n} ...",
//   "danger": true, "ok": "Save"}]'>
// A rule applies when all its conditions hold (when: something in the form matches; count: at least one match, and
// {n} is the number; changed: one of these fields differs from when the page opened; lowered: this number went down;
// is: these fields have these values now; changes: anything changed, {n} = how many fields; min: only from n up;
// button: only when submitted with the button of this value or name; atmost: {field: n}, the number is n or less).
// Every rule that applies adds its text; the first one's title and button are used.
const confirmRules = (form, submitter) => {
  let rules = [];
  try { rules = JSON.parse(form.dataset.confirmRules || '[]'); } catch (e) { rules = []; }
  const start = form.alignStart || {}, now = formState(form);
  /** A field's first value as a number (currency signs and separators dropped). */
  const num = (v) => parseFloat(String((v || [])[0] || '').replace(/[^0-9.\-]/g, ''));
  // fields that belong to the form from elsewhere on the page (form="id") count too
  const matches = (sel) => new Set([...Array.from(form.elements).filter((e) => e.matches(sel)), ...form.querySelectorAll(sel)]).size;
  return rules.filter((r) => {
    if (r.button && !(submitter && (submitter.value === r.button || submitter.name === r.button))) return false;
    if (r.when && ![].concat(r.when).every((w) => matches(w))) return false;
    if (r.count) { r.n = matches(r.count); if (!r.n) return false; }
    if (r.changed && !r.changed.split(',').some((k) => !sameField(now[k.trim()], start[k.trim()]))) return false;
    if (r.lowered && !(num(now[r.lowered]) < num(start[r.lowered]))) return false;
    if (r.is && !Object.keys(r.is).every((k) => sameField(now[k], [].concat(r.is[k]).map(String)))) return false;
    if (r.atmost && !Object.keys(r.atmost).every((k) => num(now[k]) <= r.atmost[k])) return false;
    if (r.changes) { r.n = Object.keys({ ...now, ...start }).filter((k) => !sameField(now[k], start[k])).length; if (!r.n) return false; }
    if (r.min && !(r.n >= r.min)) return false;
    return true;
  }).map((r) => ({ ...r, text: String(r.text || '').replace(/\{n\}/g, r.n), title: r.title ? String(r.title).replace(/\{n\}/g, r.n) : '' }));
};
document.addEventListener('submit', (ev) => {
  const form = ev.target;
  if (!(form instanceof HTMLFormElement)) return;
  if (form.dataset.confirmed === '1') return;
  // a button that asked its own question (Delete framework, Send now) isn't asked about the form's other changes
  const hit = form.dataset.confirmRules && !(ev.submitter && ev.submitter.hasAttribute('data-confirm')) ? confirmRules(form, ev.submitter) : [];
  if (!hit.length) return;
  ev.preventDefault();
  ev.stopPropagation();
  const submitter = ev.submitter || null;
  alignConfirm({
    title: hit.find((r) => r.title)?.title || 'Save these changes?',
    text: hit.map((r) => r.text),
    ok: hit.find((r) => r.ok)?.ok || (submitter && submitter.textContent.trim()) || 'Save',
    danger: hit.some((r) => r.danger),
  }).then((ok) => {
    if (!ok) return;
    form.dataset.confirmed = '1';
    try {
      if (submitter && form.requestSubmit) form.requestSubmit(submitter); else if (form.requestSubmit) form.requestSubmit(); else form.submit();
    } finally { delete form.dataset.confirmed; }
  });
}, true);
// A form really on its way (nothing stopped it): its unsaved changes are being saved
window.addEventListener('submit', (ev) => { if (!ev.defaultPrevented && ev.target instanceof HTMLFormElement) ev.target.alignSubmitting = true; });

// Unsaved changes: <form data-unsaved> warns before leaving the page, or closing its window (modal), with changes
// that weren't saved
window.addEventListener('beforeunload', (ev) => {
  const dirty = Array.from(document.querySelectorAll('form[data-unsaved]')).some((f) => !f.alignSubmitting && formDirty(f));
  if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
});
// A form in a window is filled in when the window opens (a new meeting on the day clicked): start from there
document.addEventListener('show.bs.modal', (ev) => {
  if (!ev.target.classList || !ev.target.classList.contains('modal')) return;
  ev.target.querySelectorAll('form[data-unsaved], form[data-confirm-rules]').forEach((f) => { f.alignStart = formState(f); f.alignSubmitting = false; });
});
document.addEventListener('hide.bs.modal', (ev) => {
  const m = ev.target;
  if (m.id === 'align-confirm' || m.alignDiscard) { m.alignDiscard = false; return; }
  const f = m.querySelector('form[data-unsaved]');
  if (!f || f.alignSubmitting || !formDirty(f)) return;
  ev.preventDefault();
  alignConfirm({ title: 'Discard your changes?', text: 'What you typed in this form hasn’t been saved.', ok: 'Discard changes', danger: true }).then((ok) => {
    if (!ok) return;
    f.reset();
    Array.from(f.elements).forEach((x) => { if (x.name) x.dispatchEvent(new Event('change', { bubbles: true })); });
    f.alignStart = formState(f);
    m.alignDiscard = true;
    bsModal(m).hide();
  });
});

// Enter in a text field submits the form with its first button. When that button is a delete, retire or send
// button (it asks first, or is red), Enter uses the form's Save button instead, or does nothing.
document.addEventListener('keydown', (ev) => {
  if (ev.key !== 'Enter' || ev.isComposing || ev.defaultPrevented) return;
  const t = ev.target;
  if (!(t instanceof HTMLInputElement) || !t.form || ['checkbox', 'radio', 'submit', 'button', 'file', 'reset'].includes(t.type)) return;
  const form = t.form;
  if (t.hasAttribute('data-enter-nosubmit')) { ev.preventDefault(); return; }
  const buttons = Array.from(form.elements).filter((b) => b.type === 'submit');
  /** A button Enter shouldn't press: it asks first, is red, or is marked data-enter-skip. */
  const risky = (b) => b.hasAttribute('data-confirm') || /btn(-outline)?-danger/.test(b.className) || b.hasAttribute('data-enter-skip');
  const first = buttons.find((b) => !b.disabled);
  if (!first || !risky(first)) return;
  ev.preventDefault();
  const safe = form.querySelector('[data-default-submit]') || buttons.find((b) => !b.disabled && !risky(b) && /btn-primary|btn-success/.test(b.className));
  if (safe && form.requestSubmit) form.requestSubmit(safe);
}, true);

// Edit forms on long lists load when opened: <a data-lazy-modal="/licenses/5/form?back=…" data-bs-target="#modal-license-5">
// The form is a page of this site (the CSP's connect-src 'self' refuses any other), rendered and escaped by the
// server, so it is added as HTML; its scripts don't run (innerHTML never runs them, and the CSP has no inline).
document.addEventListener('click', (ev) => {
  const el = ev.target.closest && ev.target.closest('[data-lazy-modal]');
  if (!el) return;
  ev.preventDefault();
  const sel = el.dataset.bsTarget;
  // Opened from inside another window (Ready to start in the project window, 2.2.2): close that one first, so
  // the two never stack (Bootstrap shows one modal at a time)
  const parent = el.closest('.modal.show');
  if (parent && parent.id) {
    const go = () => el.click();
    parent.addEventListener('hidden.bs.modal', go, { once: true });
    // Closing can be stopped (unsaved changes): then don't open it later, when that window closes for another reason
    parent.addEventListener('hide.bs.modal', (e) => setTimeout(() => { if (e.defaultPrevented) parent.removeEventListener('hidden.bs.modal', go); }, 0), { once: true });
    bsModal('#' + parent.id).hide();
    return;
  }
  const show = () => bsModal(sel).show();
  if (document.querySelector(sel)) { show(); return; }
  if (el.dataset.loading) return;
  el.dataset.loading = '1';
  el.classList.add('is-loading');
  fetch(el.dataset.lazyModal, { credentials: 'same-origin', headers: { Accept: 'text/html' } })
    .then((r) => {
      // Signed out meanwhile (the request was sent to the sign-in page): reload, which asks to sign in
      if (r.redirected || r.status === 401 || r.status === 419) { window.location.reload(); return null; }
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    })
    .then((html) => {
      if (html === null) return;
      const box = document.createElement('div');
      box.innerHTML = html;
      const parts = Array.from(box.children);
      parts.forEach((n) => document.body.appendChild(n));
      parts.forEach((n) => alignInit.run(n));
      if (!document.querySelector(sel)) throw new Error('form missing');
      show();
    })
    .catch((e) => window.alert("Couldn't open the form (" + e.message + '). Reload the page and try again.'))
    .finally(() => { delete el.dataset.loading; el.classList.remove('is-loading'); });
});

// Long pickers repeated on every row (client mapping) carry only their current choice; the full list is
// copied in from one <template> the first time the select is used: <select data-options="template-id" data-client="7">
const fillOptions = (ev) => {
  const sel = ev.target.closest && ev.target.closest('select[data-options]');
  if (!sel || sel.dataset.filled) return;
  const tpl = document.getElementById(sel.dataset.options);
  if (!tpl) return;
  sel.dataset.filled = '1';
  const value = sel.value;
  const keep = sel.options[0];
  const frag = document.createDocumentFragment();
  frag.appendChild(keep);
  tpl.content.querySelectorAll('option').forEach((o) => {
    const opt = o.cloneNode(true);
    if (opt.dataset.client && opt.dataset.client !== sel.dataset.client) opt.textContent += ' (linked elsewhere)';
    opt.defaultSelected = opt.value === value; // so a form reset or a restored page keeps the saved choice
    frag.appendChild(opt);
  });
  sel.replaceChildren(frag);
  sel.value = value;
};
['mousedown', 'focusin', 'keydown', 'touchstart'].forEach((t) => document.addEventListener(t, fillOptions, true));

document.addEventListener('DOMContentLoaded', () => {
  // Live table filter: <input data-filter-table="table-id">
  document.querySelectorAll('[data-filter-table]').forEach((input) => {
    const table = document.getElementById(input.dataset.filterTable);
    if (!table) return;
    const apply = () => {
      const q = input.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr, li.list-group-item').forEach((tr) => {
        tr.hidden = q !== '' && !tr.textContent.toLowerCase().includes(q);
      });
    };
    input.addEventListener('input', apply);
    apply();
  });

  // Submit a select's form on change: <select data-autosubmit>. With data-confirm-change="Change the role from
  // {from} to {to}?" it asks first, and puts the old choice back on Cancel.
  document.querySelectorAll('select[data-autosubmit]').forEach((sel) => {
    let was = sel.value, asking = false;
    sel.addEventListener('change', () => {
      if (!sel.dataset.confirmChange) { sel.form.submit(); return; }
      if (asking) return; // arrow keys fire a change each: the dialog already shows the first
      asking = true;
      const name = (v) => (Array.from(sel.options).find((o) => o.value === v) || {}).text || v;
      const msg = sel.dataset.confirmChange.replace(/\{from\}/g, name(was)).replace(/\{to\}/g, name(sel.value));
      const to = sel.value;
      alignConfirm({ ...confirmParts(msg), ok: sel.dataset.confirmOk || 'Change', danger: false }).then((ok) => {
        asking = false;
        if (ok) { sel.value = to; was = to; sel.form.submit(); } else { sel.value = was; }
      });
    });
  });

  // Show/hide a block based on a select: <select data-toggle-target="#id"> (hidden when value = "none")
  document.querySelectorAll('select[data-toggle-target]').forEach((sel) => {
    const target = document.querySelector(sel.dataset.toggleTarget);
    const apply = () => target && target.classList.toggle('d-none', sel.value === 'none');
    sel.addEventListener('change', apply);
    apply();
  });

  // Checklist: "Use suggestion" buttons pick the matching status radio
  // Radio buttons drawn as a button group (1.43: Bootstrap 5 dropped the BS4 "buttons" plugin): the checked one is active
  document.addEventListener('change', (e) => {
    const input = e.target;
    if (!input.matches || !input.matches('[data-radio-buttons] input[type=radio]')) return;
    input.closest('[data-radio-buttons]').querySelectorAll('input[type=radio]').forEach((r) => r.closest('label') && r.closest('label').classList.toggle('active', r.checked));
  });

  document.querySelectorAll('[data-set-status]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const group = document.getElementById(btn.dataset.setStatus);
      if (!group) return;
      group.querySelectorAll('label').forEach((l) => l.classList.remove('active'));
      const input = group.querySelector('input[value="' + btn.dataset.value + '"]');
      if (input) {
        input.checked = true;
        input.closest('label').classList.add('active');
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
      btn.remove();
    });
  });

  // Long forms (a 250-control checklist) post only the rows that changed, so they stay under PHP's
  // max_input_vars limit and saves are fast. Rows are marked [data-row]; fields outside rows always post.
  document.querySelectorAll('form[data-post-changed]').forEach((form) => {
    const mark = (e) => {
      const row = e.target.closest && e.target.closest('[data-row]');
      if (row) row.dataset.dirty = '1';
    };
    form.addEventListener('input', mark);
    form.addEventListener('change', mark);
    form.addEventListener('click', (e) => { if (e.target.closest('label, input, select, button[data-xw-use]')) mark(e); });
    form.addEventListener('submit', () => {
      form.querySelectorAll('[data-row]:not([data-dirty])').forEach((row) => {
        row.querySelectorAll('input, select, textarea').forEach((el) => { el.disabled = true; });
      });
    });
  });

  // Checklist crosswalk: reuse the answer from a matching control in another framework.
  // Status is always set; notes, evidence and the linked document only fill empty fields.
  const xwApply = (btn) => {
    const k = btn.dataset.xwUse;
    const group = document.getElementById('c' + k);
    if (!group) return false;
    const input = group.querySelector('input[value="' + btn.dataset.status + '"]');
    if (!input || input.disabled) return false;
    group.querySelectorAll('label').forEach((l) => l.classList.remove('active'));
    input.checked = true;
    input.closest('label').classList.add('active');
    input.dispatchEvent(new Event('change', { bubbles: true }));
    const form = group.closest('form');
    const fill = (name, value) => {
      const el = form.querySelector('[name="c[' + k + '][' + name + ']"]');
      if (el && value && !el.value) {
        el.value = value;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    };
    fill('notes', btn.dataset.notes);
    fill('evidence', btn.dataset.evidence);
    const doc = form.querySelector('select[name="c[' + k + '][document_id]"]');
    if (doc && !doc.value && btn.dataset.doc !== '0' && doc.querySelector('option[value="' + btn.dataset.doc + '"]')) {
      doc.value = btn.dataset.doc;
      doc.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const row = group.closest('tr');
    row.classList.add('xw-filled');
    const note = row.querySelector('.xw-filled-note');
    if (note) {
      note.textContent = 'Filled from ' + btn.dataset.from + ' — review, then save the checklist.';
      note.classList.remove('d-none');
    }
    return true;
  };
  document.querySelectorAll('button[data-xw-use]:not([data-xw-suggest])').forEach((btn) => {
    btn.addEventListener('click', () => xwApply(btn));
  });
  const xwAll = document.getElementById('xw-fill-all');
  if (xwAll) {
    xwAll.addEventListener('click', () => {
      let n = 0;
      document.querySelectorAll('button[data-xw-suggest]').forEach((btn) => {
        const checked = document.querySelector('#c' + btn.dataset.xwUse + ' input:checked');
        if ((!checked || checked.value === 'not_assessed') && xwApply(btn)) n++;
      });
      xwAll.disabled = true;
      xwAll.textContent = n + ' filled — review the highlighted controls, then save';
      const first = document.querySelector('tr.xw-filled');
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }

  // Click-to-select read-only inputs (feed links)
  document.querySelectorAll('input.select-all').forEach((i) => i.addEventListener('focus', () => i.select()));

  // Open a modal from the URL: ?add=1 opens the element marked data-autoopen="add"
  const params = new URLSearchParams(window.location.search);
  if (params.get('add') === '1') {
    const opener = document.querySelector('[data-autoopen="add"]');
    if (opener) bsModal(opener.dataset.bsTarget).show();
  }

  // Calendar (FullCalendar, loaded only on /calendar)
  const calEl = document.getElementById('calendar');
  if (calEl && window.FullCalendar) {
    const clientSel = document.getElementById('calendar-client');
    const cal = new FullCalendar.Calendar(calEl, {
      initialView: window.innerWidth < 768 ? 'listMonth' : 'dayGridMonth',
      headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listMonth' },
      buttonText: { today: 'Today', month: 'Month', week: 'Week', list: 'List' },
      height: 'auto',
      nowIndicator: true,
      dayMaxEvents: 4,
      eventTimeFormat: alignFmt.hour24 ? { hour: '2-digit', minute: '2-digit', hour12: false } : { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
      ...(alignFmt.hour24 ? { slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false } } : {}),
      firstDay: alignFmt.weekStart,
      // Week view headers day-first when dates are (the built-in English locale writes Tue 9/29)
      ...(alignFmt.date !== 'mdy' ? { views: { timeGridWeek: { dayHeaderContent: (a) => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][a.date.getDay()] + ' ' + a.date.getDate() + '/' + (a.date.getMonth() + 1) } } } : {}),
      events: (info, success, failure) => {
        const q = new URLSearchParams({ start: info.startStr, end: info.endStr });
        if (clientSel && clientSel.value) q.set('client', clientSel.value);
        fetch(calEl.dataset.events + '?' + q.toString(), { credentials: 'same-origin' })
          .then((r) => r.json()).then(success).catch(failure);
      },
      dateClick: (info) => {
        if (calEl.dataset.canCreate !== '1') return;
        const modal = document.getElementById('modal-meeting');
        if (!modal) return;
        const date = modal.querySelector('input[name="date"]');
        if (date) date.value = info.dateStr.slice(0, 10);
        const time = modal.querySelector('input[name="time"]');
        if (time && info.dateStr.length > 10) time.value = info.dateStr.slice(11, 16);
        if (clientSel && clientSel.value) {
          const c = modal.querySelector('select[name="client_id"]');
          if (c) c.value = clientSel.value;
        }
        bsModal(modal).show();
      },
    });
    cal.render();
    if (clientSel) clientSel.addEventListener('change', () => cal.refetchEvents());
  }
});

// ---- 0.3.0 additions ----
document.addEventListener('DOMContentLoaded', () => {
  // Clients list: select all + bulk bar
  const all = document.getElementById('check-all');
  const bar = document.getElementById('bulk-bar');
  const rows = () => Array.from(document.querySelectorAll('.row-check'));
  const refresh = () => {
    const n = rows().filter((c) => c.checked && !c.closest('tr').hidden).length;
    if (bar) {
      bar.classList.toggle('d-none', n === 0);
      bar.classList.toggle('d-flex', n > 0);
      const cnt = document.getElementById('bulk-count');
      if (cnt) cnt.textContent = n;
    }
  };
  if (all) all.addEventListener('change', () => { rows().forEach((c) => { if (!c.closest('tr').hidden) c.checked = all.checked; }); refresh(); });
  rows().forEach((c) => c.addEventListener('change', refresh));

  // Checkbox that submits its form (roadmap lanes, report options)
  document.querySelectorAll('[data-autosubmit-check]').forEach((c) => c.addEventListener('change', () => c.form.submit()));

  // Roadmap: "+" on a quarter pre-selects that quarter in the add-item modal
  const rmModal = document.getElementById('modal-roadmap');
  if (rmModal) {
    rmModal.addEventListener('show.bs.modal', (ev) => {
      const q = ev.relatedTarget ? ev.relatedTarget.getAttribute('data-quarter') : null;
      const sel = ev.target.querySelector('select[name="target_quarter"]');
      if (sel && q !== null) sel.value = q;
    });
  }

  // Reports page: each card builds its URL from the chosen client (and framework / document)
  const rc = document.getElementById('report-client');
  const metaEl = document.getElementById('report-meta');
  if (rc && metaEl) {
    let meta = {};
    try { meta = JSON.parse(metaEl.textContent) || {}; } catch (e) { meta = {}; }
    /** Replaces a select's options (ids and names from the page's JSON; names set as text). */
    const fill = (sel, items) => {
      sel.textContent = '';
      items.forEach((it) => { const o = document.createElement('option'); o.value = it.id; o.textContent = it.name; sel.appendChild(o); });
    };
    const refresh = () => {
      const m = meta[rc.value] || { backup: false, frameworks: [], documents: [] };
      document.querySelectorAll('.report-framework').forEach((s) => fill(s, m.frameworks));
      document.querySelectorAll('.report-document').forEach((s) => fill(s, m.documents));
      document.querySelectorAll('form.report-card').forEach((f) => {
        const need = f.dataset.needs;
        const msg = { backup: 'This client is not linked to a backup company yet (Client mapping).', frameworks: 'No compliance framework is assigned to this client yet.', documents: 'This client has no documents yet.', sla: 'No tickets with SLA data for this client yet.' }[need];
        const ok = !need || (need === 'backup' || need === 'sla' ? m[need] : (m[need] || []).length > 0);
        f.querySelector('button').disabled = !ok;
        const note = f.querySelector('.report-unavailable');
        if (note) { note.textContent = ok ? '' : msg; note.classList.toggle('d-none', ok); }
      });
    };
    document.querySelectorAll('form.report-card').forEach((f) => {
      f.addEventListener('submit', (ev) => {
        // Only numeric ids and plain report paths make it into the address (never text taken from the page as-is)
        const num = (v) => { const x = Number.parseInt(v, 10); return Number.isInteger(x) && x > 0 ? x : 0; };
        const id = num(rc.value);
        const r = f.dataset.report || '';
        let path = '';
        if (r === 'compliance') {
          const fw = num(f.querySelector('.report-framework').value);
          if (fw) path = '/clients/' + id + '/compliance/' + fw + '/export';
        } else if (r === 'document') {
          const doc = num(f.querySelector('.report-document').value);
          if (doc) path = '/documents/' + doc + '/print';
        } else {
          const m = /^\/(report\/[a-z0-9-]+|reports|[a-z0-9-]+)$/.exec(r);
          if (m) path = '/clients/' + id + '/' + m[1];
        }
        if (!id || !path) {
          ev.preventDefault();
          return;
        }
        f.action = path;
      });
    });
    rc.addEventListener('change', refresh);
    refresh();
  }
});

// ---- Branding page live preview (0.5.0; 1.45.2: the 1.43 look, app / sign-in / portal, light or dark) ----
document.addEventListener('DOMContentLoaded', () => {
  const preview = document.getElementById('brand-preview');
  if (!preview) return;
  const color = document.getElementById('brand_primary');
  const picker = document.querySelector('[data-color-for="brand_primary"]');
  /** Whether hex is #rrggbb. */
  const hexOk = (hex) => /^#[0-9a-fA-F]{6}$/.test(hex);
  const rgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.substr(i, 2), 16));
  /** Dark or white text for a background color (WCAG relative luminance). */
  const textFor = (hex) => {
    const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    const [r, g, b] = rgb(hex).map(lin);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.4 ? '#1f2d3d' : '#ffffff';
  };
  const toHex = (a) => '#' + a.map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('');
  // the same link colors Branding::cssVars makes: darker in light mode, lighter in dark mode
  const linkFor = (hex, dark) => dark ? toHex(rgb(hex).map((v) => v + (255 - v) * 0.35)) : toHex(rgb(hex).map((v) => v * 0.8));
  const textLabel = document.getElementById('brand-text-label');
  let current = color.value;
  /** Shows a color in the preview (only #rrggbb, so it can't carry other CSS). */
  const applyColor = (hex) => {
    if (!hexOk(hex)) return;
    current = hex;
    preview.style.setProperty('--bp-color', hex);
    preview.style.setProperty('--bp-text', textFor(hex));
    preview.style.setProperty('--bp-link', linkFor(hex, preview.dataset.bsTheme === 'dark'));
    preview.style.setProperty('--bp-link-light', linkFor(hex, false));   // the client portal is always light (2.2.5)
    if (textLabel) textLabel.textContent = textFor(hex) === '#ffffff' ? 'white' : 'dark';
    document.querySelectorAll('[data-swatch]').forEach((b) => b.classList.toggle('is-active', b.dataset.swatch.toLowerCase() === hex.toLowerCase()));
  };
  applyColor(color.value);
  color.addEventListener('input', () => { applyColor(color.value); if (hexOk(color.value)) picker.value = color.value; });
  picker.addEventListener('input', () => { color.value = picker.value; applyColor(picker.value); });
  document.querySelectorAll('[data-swatch]').forEach((b) => b.addEventListener('click', () => {
    color.value = b.dataset.swatch; picker.value = b.dataset.swatch; applyColor(b.dataset.swatch);
  }));
  /** Text into every preview element matching sel. */
  const setText = (sel, value) => preview.querySelectorAll(sel).forEach((el) => { el.textContent = value; });
  const nameIn = document.querySelector('[data-preview="name"]');
  nameIn.addEventListener('input', () => setText('.bp-name', nameIn.value || preview.dataset.defaultName));
  const companyIn = document.querySelector('[data-preview="company"]');
  companyIn.addEventListener('input', () => setText('.bp-company', companyIn.value || preview.dataset.defaultCompany));
  const msgIn = document.querySelector('[data-preview="message"]');
  msgIn.addEventListener('input', () => setText('#bp-message', msgIn.value || msgIn.placeholder));
  document.querySelector('[data-preview="logo-only"]').addEventListener('change', (e) => preview.querySelectorAll('.bp-name').forEach((el) => el.classList.toggle('d-none', e.target.checked)));
  document.querySelectorAll('[data-preview="sidebar"]').forEach((r) => r.addEventListener('change', () => {
    document.getElementById('bp-side').classList.toggle('is-light', r.value === 'light' && r.checked);
    document.querySelectorAll('.brand-sidebar-option').forEach((o) => o.classList.toggle('is-active', o.querySelector('input').checked));
  }));
  document.querySelectorAll('[data-preview-theme]').forEach((b) => b.addEventListener('click', () => {
    preview.dataset.bsTheme = b.dataset.previewTheme;
    document.querySelectorAll('[data-preview-theme]').forEach((x) => { x.classList.toggle('active', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
    applyColor(current);
  }));
  // Start the preview in the theme the page is shown in
  if (document.documentElement.dataset.bsTheme === 'dark') document.querySelector('[data-preview-theme="dark"]').click();
  // The logo previews (2.2.4: a light mode and a dark mode logo). Each place shows the logo it will use: light
  // backgrounds the light mode logo, dark ones the dark mode logo, either standing in for the other; the menu follows
  // the menu color. Listens on the document, like the image pickers below.
  const logos = {
    dark: preview.dataset.hasDark === '1' ? document.getElementById('logo-img').getAttribute('src') : null,
    light: preview.dataset.hasLight === '1' ? document.getElementById('light-logo-img').getAttribute('src') : null,
  };
  const swap = (old, url) => {
    if (!old) return;
    const img = document.createElement('img');
    ['id', 'alt', 'class'].forEach((a) => { if (old.hasAttribute(a)) img.setAttribute(a, old.getAttribute(a)); });
    if (old.dataset.logo) img.dataset.logo = old.dataset.logo;
    img.classList.remove('opacity-50');
    img.src = url;
    old.replaceWith(img);
  };
  const refresh = () => {
    const builtin = preview.dataset.builtin;
    const light = logos.light || logos.dark || builtin;
    const dark = logos.dark || logos.light || builtin;
    const menuLight = (document.querySelector('[data-preview="sidebar"]:checked') || {}).value === 'light';
    preview.querySelectorAll('[data-logo="light"]').forEach((el) => swap(el, light));
    preview.querySelectorAll('[data-logo="dark"]').forEach((el) => swap(el, dark));
    preview.querySelectorAll('[data-logo="menu"]').forEach((el) => swap(el, menuLight ? light : dark));
  };
  document.addEventListener('change', (e) => {
    const input = e.target;
    if (input.matches && input.matches('[data-preview="sidebar"]')) { refresh(); return; }
    // 2.5.1: the browser icon shows in its own tile only (it isn't in the preview)
    if (input.id === 'favicon' && input.files && input.files[0] && /^image\/(png|jpeg|webp|gif)$/.test(input.files[0].type)) {
      swap(document.getElementById('favicon-img'), URL.createObjectURL(input.files[0]));
      return;
    }
    if ((input.id !== 'logo' && input.id !== 'logo_light') || !input.files || !input.files[0]) return;
    const f = input.files[0];
    if (!/^image\/(png|jpeg|webp|gif)$/.test(f.type)) return;
    const url = URL.createObjectURL(f);   // a blob: address for the chosen file, only ever used as an image
    const kind = input.id === 'logo' ? 'dark' : 'light';
    logos[kind] = url;
    swap(document.getElementById(kind === 'dark' ? 'logo-img' : 'light-logo-img'), url);
    refresh();
  });
});

// Setup wizard: show the chosen logo before saving (2.2.5). blob: address only, used as an image.
document.addEventListener('change', (e) => {
  const input = e.target;
  if (!input.matches || !input.matches('[data-setup-logo]') || !input.files || !input.files[0]) return;
  if (!/^image\/(png|jpeg|webp|gif)$/.test(input.files[0].type)) return;
  const img = document.getElementById('logo-img');
  if (img) img.src = URL.createObjectURL(input.files[0]);
});

// Bulk categorize (Unassigned hardware)
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('bulk-type-form');
  if (!form) return;
  const all = document.getElementById('select-all');
  const apply = document.getElementById('bulk-apply');
  const boxes = () => Array.from(form.querySelectorAll('.row-check'));
  const sync = () => {
    const n = boxes().filter((b) => b.checked).length;
    if (apply) { apply.disabled = n === 0; apply.innerHTML = '<i class="fas fa-check me-1"></i>Apply' + (n ? ' to ' + n : ''); }
    if (all) all.checked = n > 0 && n === boxes().length;
  };
  if (all) all.addEventListener('change', () => { boxes().forEach((b) => { b.checked = all.checked; }); sync(); });
  form.addEventListener('change', (e) => { if (e.target.classList.contains('row-check')) sync(); });
});

// Image pickers (client logo, profile picture): show the file name and a live preview
document.addEventListener('change', (e) => {
  const input = e.target;
  if (!input.matches || !input.matches('[data-logo-input]') || !input.files || !input.files[0]) return;
  const f = input.files[0];
  const scope = input.closest('.modal-body, .card-body, form');
  const box = scope && (scope.querySelector('[data-logo-preview]') || (scope.parentElement && scope.parentElement.querySelector('[data-logo-preview]')));
  if (!box) return;
  const url = URL.createObjectURL(f);
  const img = document.createElement('img');
  img.src = url;
  img.alt = 'Preview';
  const old = box.querySelector('img, span');
  if (old && old.className) img.className = old.className.replace(/\b(user-initials)\b/, '$1') + ' avatar-img';
  box.classList.remove('is-empty');
  box.innerHTML = '';
  box.appendChild(img);
});

// License form: live cost preview (per period, per month, per year)
alignInit.add((root) => {
  const fmt = (n) => alignFmt.money(n);
  const months = { monthly: 1, quarterly: 3, annual: 12, one_time: 0 };
  /** The cost line under the price: per period, and per month and year for recurring ones. */
  const calc = (form) => {
    const q = (k) => form.querySelector('[data-lic="' + k + '"]');
    const out = q('out');
    if (!out) return;
    const price = parseFloat(q('price').value);
    if (isNaN(price)) { out.textContent = 'No price yet'; return; }
    const qty = q('pricing').value === 'per_seat' ? (parseInt(q('seats').value, 10) || 0) : 1;
    const per = price * qty;
    const m = months[q('cycle').value];
    // Built as nodes: the currency symbol (body[data-fmt]) and the option's text are page data, never markup
    const b = document.createElement('b');
    b.textContent = fmt(per);
    out.replaceChildren(b, ' ' + q('cycle').selectedOptions[0].text.toLowerCase());
    if (m) {
      const s = document.createElement('span');
      s.className = 'text-muted';
      s.textContent = fmt(per / m) + '/mo · ' + fmt(per / m * 12) + '/yr';
      out.append(document.createElement('br'), s);
    }
  };
  root.querySelectorAll('[data-lic="out"]').forEach((o) => {
    const form = o.closest('form');
    calc(form);
    form.addEventListener('input', () => calc(form));
    form.addEventListener('change', () => calc(form));
  });
});

// Contract fields: show derived end / renegotiate dates as you type (the server fills them in the same way)
alignInit.add((root) => {
  root.querySelectorAll('[data-contract]').forEach((box) => {
    const form = box.closest('form');
    const q = (k) => box.querySelector('[data-c="' + k + '"]');
    /** The start date field: this box's own, or the form field named in data-start-field. */
    const startIn = () => q('start') && q('start').value ? q('start') : form.querySelector('[name="' + box.dataset.startField + '"]');
    const fmt = (d) => alignFmt.date(d);
    /** The derived end and renegotiate dates under the fields. */
    const update = () => {
      const t = q('term').value;
      q('custom').classList.toggle('d-none', t !== 'custom');
      const months = t === 'custom' ? parseInt(q('custom').value, 10) : parseInt(t, 10);
      const s = startIn();
      let end = q('end').value ? new Date(q('end').value + 'T00:00') : null;
      const bits = [];
      if (!end && s && s.value && months > 0) {
        end = new Date(s.value + 'T00:00'); end.setMonth(end.getMonth() + months); end.setDate(end.getDate() - 1);
        bits.push('Ends ' + fmt(end) + ' (from start + term)');
      }
      const notice = parseInt(q('notice').value, 10);
      if (!q('reneg').value && end && notice >= 0 && !isNaN(notice)) {
        const r = new Date(end); r.setDate(r.getDate() - notice);
        bits.push('renegotiate by ' + fmt(r));
      }
      if (bits.length) q('hint').textContent = bits.join(', ') + '.';
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
  });
});

// Meeting form: add the client's contacts as attendees in one click
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-attendee-picks]').forEach((box) => {
    const form = box.closest('form');
    const input = form.querySelector('[data-attendees]');
    const clientSel = form.querySelector('[name="client_id"]');
    /** Adds a contact as "Name <email>" unless already there. */
    const add = (c) => {
      const entry = c.email ? c.name + ' <' + c.email + '>' : c.name;
      const cur = input.value.trim();
      if (cur.toLowerCase().includes((c.email || c.name).toLowerCase())) return;
      input.value = cur ? cur.replace(/,\s*$/, '') + ', ' + entry : entry;
    };
    /** The client's contacts (same-site JSON), as links that add them; names are set as text. */
    const load = () => {
      box.innerHTML = '';
      const id = clientSel ? clientSel.value : '';
      if (!id) return;
      fetch('/clients/' + encodeURIComponent(id) + '/contacts.json', { credentials: 'same-origin' })
        .then((r) => (r.ok ? r.json() : []))
        .then((list) => {
          if (!list.length) return;
          const key = list.filter((c) => c.key);
          const lbl = document.createElement('span');
          lbl.className = 'text-muted me-1';
          lbl.textContent = 'Add:';
          box.appendChild(lbl);
          if (key.length > 1) {
            const all = document.createElement('a');
            all.href = '#'; all.className = 'me-2 fw-bold'; all.textContent = 'meeting invitees (' + key.length + ')';
            all.addEventListener('click', (e) => { e.preventDefault(); key.forEach(add); });
            box.appendChild(all);
          }
          list.slice(0, 12).forEach((c) => {
            const a = document.createElement('a');
            a.href = '#'; a.className = 'badge text-bg-light border me-1 mb-1' + (c.key ? ' fw-bold' : ' fw-normal');
            a.textContent = '+ ' + c.name; a.title = [c.title, c.email].filter(Boolean).join(' · ');
            a.addEventListener('click', (e) => { e.preventDefault(); add(c); });
            box.appendChild(a);
          });
        })
        .catch(() => {});
    };
    if (clientSel) clientSel.addEventListener('change', load);
    load();
  });
});

// Copy-to-clipboard buttons (data-copy="#input") and the portal invite contact picker
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-copy]').forEach((btn) => btn.addEventListener('click', () => {
    const el = document.querySelector(btn.dataset.copy);
    if (!el) return;
    el.select();
    // The button's own markup is kept once: a second click within 1.5 s used to keep "Copied" as its label for good
    const done = () => {
      if (btn.alignLabel === undefined) btn.alignLabel = btn.innerHTML;
      btn.textContent = 'Copied';
      clearTimeout(btn.alignCopied);
      btn.alignCopied = setTimeout(() => { btn.innerHTML = btn.alignLabel; btn.alignLabel = undefined; }, 1500);
    };
    if (navigator.clipboard) navigator.clipboard.writeText(el.value).then(done, () => { document.execCommand('copy'); done(); });
    else { document.execCommand('copy'); done(); }
  }));
  const email = document.getElementById('invite-email');
  const name = document.getElementById('invite-name');
  if (email && name) {
    email.addEventListener('input', () => {
      const opt = [...document.querySelectorAll('#invite-contacts option')].find((o) => o.value === email.value);
      if (opt && !name.value) name.value = opt.dataset.name;
    });
  }
});

// Automatic logoff: signs out after the configured idle time, with a one-minute warning. Activity (keys, clicks,
// scrolling) keeps the server session alive via a ping. 2.2.6: the idle clock is shared by every tab of the same
// sign-in (staff or portal) through localStorage, so working in one tab keeps a forgotten background tab from
// signing everyone out, and signing out in one tab sends the others to the sign-in page. Without storage (a private
// window that refuses it) each tab keeps its own clock, as before.
document.addEventListener('DOMContentLoaded', () => {
  const meta = document.querySelector('meta[name="align-idle"]');
  if (!meta) return;
  const idle = parseInt(meta.content, 10) * 1000;
  if (!idle) return;
  let lastActive = Date.now();
  let lastPing = Date.now();
  let banner = null;
  let done = false;
  // Keys per sign-in kind (the ping address differs for staff and portal); values are times, never anything secret
  const keyActive = 'align-idle-active:' + meta.dataset.ping;
  const keyOut = 'align-idle-out:' + meta.dataset.ping;
  const store = {
    get: (k) => { try { return parseInt(window.localStorage.getItem(k) || '0', 10) || 0; } catch (e) { return 0; } },
    set: (k, v) => { try { window.localStorage.setItem(k, String(v)); } catch (e) { /* no storage: this tab only */ } },
  };
  let lastWrite = 0;
  /** Takes in activity from another tab: the newest time wins. */
  const sync = () => {
    const t = store.get(keyActive);
    if (t > lastActive) { lastActive = t; if (banner) { banner.remove(); banner = null; } }
  };
  /** Activity: restart the idle clock for every tab (and tell the server, if the warning was showing). */
  const mark = () => {
    lastActive = Date.now();
    if (lastActive - lastWrite > 1000) { lastWrite = lastActive; store.set(keyActive, lastActive); }
    if (banner) { banner.remove(); banner = null; ping(true); }
  };
  window.addEventListener('storage', (ev) => {
    if (ev.key === keyActive) sync();
    // Another tab signed out (by the idle clock or the Sign out button): this one follows
    if (ev.key === keyOut && !done) { done = true; window.location.href = meta.dataset.login; }
  });
  store.set(keyActive, Math.max(store.get(keyActive), lastActive));
  // The Sign out button tells the other tabs too
  document.addEventListener('submit', (ev) => {
    const f = ev.target;
    if (f && f.getAttribute && f.getAttribute('action') === meta.dataset.logout) store.set(keyOut, Date.now());
  }, true);
  ['keydown', 'mousedown', 'wheel', 'touchstart', 'scroll'].forEach((ev) => document.addEventListener(ev, mark, { passive: true, capture: true }));
  let lastMove = 0;
  document.addEventListener('mousemove', () => { const n = Date.now(); if (n - lastMove > 5000) { lastMove = n; mark(); } }, { passive: true });
  /** Keeps the server session in step (active=1: the person did something); 401 means already signed out. */
  const ping = (active) => {
    lastPing = Date.now();
    fetch(meta.dataset.ping + (active ? '?active=1' : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => { if (r.status === 401) signOut(false); })
      .catch(() => {});
  };
  /** Signs out once: POSTs /logout with the CSRF token (post) and goes to the sign-in page from the layout's meta tag. */
  const signOut = (post) => {
    if (done) return;
    done = true;
    store.set(keyOut, Date.now());
    const go = () => { window.location.href = meta.dataset.login; };
    if (!post) { go(); return; }
    const body = new URLSearchParams({ _csrf: meta.dataset.csrf });
    fetch(meta.dataset.logout, { method: 'POST', credentials: 'same-origin', body }).finally(go);
  };
  setInterval(() => {
    const now = Date.now();
    sync();
    const quiet = now - lastActive;
    if (quiet >= idle) { signOut(true); return; }
    if (quiet >= idle - 60000 && !banner) {
      banner = document.createElement('div');
      banner.className = 'idle-warning alert alert-warning shadow';
      banner.setAttribute('role', 'alertdialog');
      const msg = document.createElement('span');
      msg.textContent = 'You will be signed out in a minute because of inactivity. ';
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'btn btn-sm btn-dark ms-2'; btn.textContent = 'Stay signed in';
      btn.addEventListener('click', mark);
      banner.append(msg, btn);
      document.body.appendChild(banner);
    }
    if (lastActive > lastPing && now - lastPing > 60000) ping(true);
  }, 5000);
});

// Fill a modal from the button that opens it: <button data-bs-toggle="modal" data-bs-target="#m" data-fill data-f-kind="device">
// sets [name="kind"] inputs and [data-fill-text="kind"] text inside #m (as values and text, never markup).
document.addEventListener('click', (ev) => {
  const btn = ev.target.closest('[data-fill]');
  if (!btn) return;
  const modal = document.querySelector(btn.dataset.bsTarget || '');
  if (!modal) return;
  Object.keys(btn.dataset).forEach((k) => {
    if (!/^f[A-Z]/.test(k)) return;
    const name = k.charAt(1).toLowerCase() + k.slice(2);
    modal.querySelectorAll('[name="' + name + '"]').forEach((i) => { i.value = btn.dataset[k]; });
    modal.querySelectorAll('[data-fill-text="' + name + '"]').forEach((t) => { t.textContent = btn.dataset[k]; });
  });
});

// Show a block only for some values of a field: <div data-show-when="mail_mode=app,delegated">
(() => {
  const blocks = document.querySelectorAll('[data-show-when]');
  if (!blocks.length) return;
  /** The current value of a radio group or select named name (a name from our own data-show-when). */
  const val = (name) => {
    const el = document.querySelector('[name="' + name + '"]:checked') || document.querySelector('select[name="' + name + '"]');
    return el ? el.value : '';
  };
  // Several conditions separated by ";" must all match: data-show-when="mail_provider=google;mail_mode=app"
  const apply = () => blocks.forEach((b) => {
    const show = b.dataset.showWhen.split(';').every((cond) => {
      const [name, vals] = cond.split('=');
      return vals.split(',').includes(val(name));
    });
    b.classList.toggle('d-none', !show);
  });
  document.addEventListener('change', (ev) => { if (ev.target.name) apply(); });
  apply();
})();

// Updates & restores: a full-screen "please wait" panel with a progress bar
const jobOverlay = (() => {
  const ov = document.getElementById('job-overlay');
  if (!ov) return null;
  const q = (s) => ov.querySelector(s);
  let t0 = Date.now(), timer = null, shown = 0;
  const fmt = (s) => (s >= 60 ? Math.floor(s / 60) + ' min ' + (s % 60) + ' s' : s + ' s');
  /** The elapsed time on the panel. */
  const clock = () => { q('[data-ov-time]').textContent = fmt(Math.round((Date.now() - t0) / 1000)); };
  const api = {
    /** Shows the panel with a title, a step and the time already spent (seconds). */
    show(title, step, elapsed) {
      if (title) q('[data-ov-title]').textContent = title;
      if (step) q('[data-ov-step]').textContent = step + '…';
      if (typeof elapsed === 'number') t0 = Date.now() - elapsed * 1000;
      ov.hidden = false;
      if (!timer) { clock(); timer = setInterval(clock, 1000); }
    },
    /** Progress (never goes backwards, stops at 99 until done), the current step and the time so far. */
    update(pct, step, elapsed) {
      if (ov.hidden) return;
      if (typeof pct === 'number') {
        shown = Math.max(shown, Math.min(99, pct)); // never goes backwards
        q('[data-ov-bar]').style.width = shown + '%';
        q('[data-ov-pct]').textContent = String(shown);
      }
      if (step) q('[data-ov-step]').textContent = step + '…';
      if (typeof elapsed === 'number' && elapsed > 0) t0 = Date.now() - elapsed * 1000;
    },
    /** A line of reassurance under the bar. */
    note(text) { q('[data-ov-note]').textContent = text; },
    /** Finished: full bar, check mark, "Reloading…". */
    done(title) {
      shown = 100;
      q('[data-ov-bar]').style.width = '100%';
      q('[data-ov-bar]').classList.remove('progress-bar-animated');
      q('[data-ov-bar]').classList.add('bg-success');
      q('[data-ov-pct]').textContent = '100';
      q('[data-ov-icon]').className = 'fas fa-circle-check fa-2x text-success mb-3';
      q('[data-ov-title]').textContent = title || 'Finished';
      q('[data-ov-step]').textContent = 'Done';
      q('[data-ov-note]').textContent = 'Reloading…';
      clearInterval(timer);
    },
    /** Failed: the message from the job, as text. */
    fail(message) {
      q('[data-ov-bar]').classList.remove('progress-bar-animated');
      q('[data-ov-bar]').classList.add('bg-danger');
      q('[data-ov-icon]').className = 'fas fa-triangle-exclamation fa-2x text-danger mb-3';
      q('[data-ov-title]').textContent = 'That didn\'t work';
      q('[data-ov-sub]').textContent = message || 'The job stopped with an error.';
      q('[data-ov-note]').textContent = 'Nothing was lost: the details below show what happened.';
      q('[data-ov-actions]').hidden = false;
      clearInterval(timer);
    },
    /** Hides the panel. */
    hide() { ov.hidden = true; clearInterval(timer); timer = null; },
  };
  q('[data-ov-close]').addEventListener('click', () => api.hide());
  // Show it the moment Update or Restore is pressed, before the page changes
  document.querySelectorAll('form[action="/settings/system/update"], form[action="/settings/system/restore"]').forEach((f) => f.addEventListener('submit', () => {
    const confirm = f.querySelector('[name=confirm]');
    if (confirm && !confirm.checked) return; // the server explains what's missing
    const restore = f.action.endsWith('/restore');
    t0 = Date.now();
    api.show(restore ? 'Restoring from the backup' : 'Updating MSP Align', 'Starting', 0);
    api.update(1);
  }));
  if (!ov.hidden) api.show();
  return api;
})();

// Updates & backups: follow a job's progress, download a finished backup, upload with progress
(() => {
  const box = document.querySelector('[data-job-watch]');
  if (box) {
    const id = box.dataset.jobWatch;
    const $ = (s) => box.querySelector(s);
    const badges = { succeeded: ['success', 'Done'], failed: ['danger', 'Failed'], running: ['primary', 'Running'], queued: ['secondary', 'Queued'] };
    const dl = box.querySelector('[data-job-download]');
    let done = false, sawMaintenance = false, started = false, downloaded = false, fails = 0;
    /** The job's badge and card color (a fixed set of classes; an unknown state is shown as its text). */
    const setState = (s) => {
      const [c, t] = badges[s] || ['secondary', s];
      const b = $('[data-job-state]');
      b.className = 'badge px-2 py-1 text-bg-' + c;
      b.textContent = t;
      box.className = box.className.replace(/card-(success|danger|primary|secondary)/, 'card-' + c);
      $('[data-job-spinner]').classList.toggle('d-none', s === 'succeeded' || s === 'failed');
    };
    /** The facts about a backup or restore, as a list (text only). */
    const result = (j) => {
      const r = j.result || {}, el = $('[data-job-result]');
      const b = r.backup;
      const rows = [];
      if (b) {
        if (b.created) rows.push(['Made', alignFmt.date(new Date(b.created)) + ' ' + alignFmt.time(new Date(b.created))]);
        if (b.version) rows.push(['Version', b.version + (b.host ? ' on ' + b.host : '')]);
        rows.push(['Database', b.tables + ' tables']);
        if (b.has_uploads) rows.push(['Uploaded files', String(b.uploads_files)]);
        if (b.app_key_differs) rows.push(['Encryption key', 'From another server: restoring switches this server to it so saved API keys keep working']);
      }
      if (r.public_key) rows.push(['Public key of the pasted key', r.public_key]);
      if (r.safety) rows.push(['Safety copy', 'Kept on the server; see "Safety copies" below']);
      if (r.rolled_back === true) rows.push(['Rollback', 'The previous data was put back automatically']);
      el.innerHTML = '';
      if (!rows.length) return;
      const dlist = document.createElement('dl');
      dlist.className = 'row mb-0';
      rows.forEach(([k, v]) => {
        const dt = document.createElement('dt'); dt.className = 'col-sm-3 fw-normal text-muted'; dt.textContent = k;
        const dd = document.createElement('dd'); dd.className = 'col-sm-9 mb-1'; dd.textContent = v;
        dlist.append(dt, dd);
      });
      el.appendChild(dlist);
    };
    /** Shows the job's state, step, message and log (all text from the server, set with textContent). */
    const render = (j) => {
      if (jobOverlay) {
        jobOverlay.update(j.percent, j.step, j.elapsed);
        if (fails >= 2) jobOverlay.note('Keep this tab open. It refreshes on its own when everything is finished; you don\'t need to do anything.');
      }
      fails = 0;
      setState(j.state);
      if (j.label) $('[data-job-label]').textContent = j.label;
      $('[data-job-step]').textContent = j.step || '';
      $('[data-job-message]').textContent = j.message || '';
      const log = $('[data-job-log]');
      if (log && typeof j.log === 'string') {
        const atEnd = log.scrollTop + log.clientHeight >= log.scrollHeight - 20;
        log.textContent = j.log;
        if (atEnd) log.scrollTop = log.scrollHeight;
      }
      result(j);
      if (j.state === 'failed') box.querySelector('details').open = true;
    };
    /** A job ended: the overlay's result, the backup download (once), and a reload when the page's facts changed. */
    const finish = (j) => {
      done = true;
      if (jobOverlay && ['update', 'restore'].includes(j.action)) {
        if (j.state === 'succeeded') jobOverlay.done(j.action === 'update' ? 'Update complete' : 'Restore complete');
        else jobOverlay.fail(j.message);
      }
      if (j.download && dl) {
        dl.classList.remove('d-none');
        if (started && !downloaded) { downloaded = true; dl.submit(); }
      }
      // Version or backup info changed: refresh the page (not for downloads, which would cancel them)
      if (started && ['update', 'check', 'purge_legacy', 'delete_safety'].includes(j.action)) setTimeout(() => location.replace('/settings/system?job=' + id), 1500);
    };
    /** Polls the job every 1.5 s until it ends. A page that isn't JSON means maintenance mode or, after a restore, signed out. */
    const tick = () => {
      fetch('/settings/system/jobs/' + id, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
        .then(async (r) => {
          const ct = r.headers.get('content-type') || '';
          if (!ct.includes('json')) {
            if (sawMaintenance || r.redirected) {
              // A restore signs everyone out
              done = true;
              setState('succeeded');
              $('[data-job-step]').textContent = 'Finished.';
              $('[data-job-message]').innerHTML = 'Everyone was signed out. <a href="/login">Sign in again</a> to see the result.';
              if (jobOverlay) { jobOverlay.done('Restore complete'); jobOverlay.note('Everyone was signed out. Taking you to sign in…'); setTimeout(() => location.replace('/login'), 2500); }
            }
            return;
          }
          const j = await r.json();
          if (j.maintenance) {
            sawMaintenance = true;
            fails = 0;
            if (jobOverlay) jobOverlay.update(j.percent, j.step, j.elapsed);
            setState('running');
            $('[data-job-step]').textContent = j.step || j.message;
            return;
          }
          if (j.state === 'missing') { done = true; return; }
          render(j);
          if (j.state === 'succeeded' || j.state === 'failed') finish(j);
          else started = true;
        })
        .catch(() => {
          // The web server restarts during an update: keep waiting
          fails++;
          if (jobOverlay && fails >= 2) jobOverlay.note('Restarting the web server… this page reconnects on its own.');
        })
        .finally(() => { if (!done) setTimeout(tick, 1500); });
    };
    const st = $('[data-job-state]').textContent.trim();
    started = st === 'Running' || st === 'Queued';
    if (started) tick();
    else fetch('/settings/system/jobs/' + id, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => r.json()).then(result).catch(() => {});
  }

  document.querySelectorAll('form[data-upload]').forEach((f) => {
    const input = f.querySelector('input[type=file]');
    f.addEventListener('submit', (e) => {
      const err = f.querySelector('[data-upload-error]');
      err.textContent = '';
      if (!input.files.length) { e.preventDefault(); err.textContent = 'Choose a backup file first.'; return; }
      const max = Number(f.dataset.max || 0);
      if (max && input.files[0].size > max) {
        e.preventDefault();
        err.textContent = 'That file is larger than this server accepts through the browser. Copy it to the server and run: sudo msp-align-restore FILE';
        return;
      }
      e.preventDefault();
      const bar = f.querySelector('[data-upload-progress]');
      const btn = f.querySelector('button');
      bar.classList.remove('d-none');
      btn.disabled = true;
      const xhr = new XMLHttpRequest();
      xhr.open('POST', f.action);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.upload.addEventListener('progress', (ev) => { if (ev.lengthComputable) bar.firstElementChild.style.width = Math.round((ev.loaded / ev.total) * 100) + '%'; });
      xhr.addEventListener('load', () => {
        let j = null;
        try { j = JSON.parse(xhr.responseText); } catch (x) { j = null; }
        // Only a path on this site (never //host or a scheme): anything else just reloads the page
        if (j && j.ok) { if (sitePath(j.redirect)) location.href = j.redirect; else location.reload(); return; }
        btn.disabled = false;
        bar.classList.add('d-none');
        err.textContent = j && j.error ? j.error : (xhr.status === 413 || xhr.status === 419 ? 'The file is too large for this server. Copy it to the server and run: sudo msp-align-restore FILE' : 'The upload failed. Try again.');
      });
      xhr.addEventListener('error', () => { btn.disabled = false; err.textContent = 'The upload failed. Check your connection and try again.'; });
      xhr.send(new FormData(f));
    });
  });
})();

// Help: filter the how-to guides, and open one from a link (/help#guide-backup)
(() => {
  const input = document.querySelector('[data-filter-guides]');
  if (!input) return;
  const guides = Array.from(document.querySelectorAll('.help-guide'));
  const empty = document.querySelector('[data-guides-empty]');
  input.addEventListener('input', () => {
    const words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
    let shown = 0;
    guides.forEach((g) => {
      const hit = words.every((w) => g.dataset.search.includes(w));
      g.classList.toggle('d-none', !hit);
      shown += hit ? 1 : 0;
    });
    // Open the guides that match when the search narrows things down
    if (words.length && shown <= 3) guides.filter((g) => !g.classList.contains('d-none')).forEach((g) => bsCollapse(g.querySelector('.collapse')).show());
    if (empty) empty.classList.toggle('d-none', shown > 0);
    const h = document.querySelectorAll('#tab-howto h6');
    h.forEach((el) => { el.classList.toggle('d-none', words.length > 0); });
  });
  // The hash is only looked up as an element id (never a selector or markup); a malformed %-escape is ignored
  const open = () => {
    let id = '';
    try { id = decodeURIComponent(location.hash.slice(1)); } catch (e) { return; }
    if (!id) return;
    const target = document.getElementById(id);
    if (!target) return;
    const pane = target.closest('.tab-pane');
    if (pane) bsTab('a[href="#' + pane.id + '"]').show();
    if (target.classList.contains('help-guide')) bsCollapse(target.querySelector('.collapse')).show();
    setTimeout(() => target.scrollIntoView({ block: 'start' }), 200);
  };
  window.addEventListener('load', open);
  window.addEventListener('hashchange', open);
})();

// Bulk bar for a table: <input data-bulk-all="table-id">, row <input data-bulk-item form="…">, bar [data-bulk-bar="table-id"]
(() => {
  document.querySelectorAll('[data-bulk-bar]').forEach((bar) => {
    const table = document.getElementById(bar.dataset.bulkBar);
    if (!table) return;
    const items = () => Array.from(table.querySelectorAll('[data-bulk-item]'));
    const all = document.querySelector('[data-bulk-all="' + bar.dataset.bulkBar + '"]');
    /** Shows the bar with the count of ticked rows; "all" is ticked when every visible row is. */
    const update = () => {
      const n = items().filter((i) => i.checked).length;
      bar.classList.toggle('d-none', n === 0);
      const c = bar.querySelector('[data-bulk-count]');
      if (c) c.textContent = n;
      if (all) all.checked = n > 0 && n === items().filter((i) => !i.closest('tr').classList.contains('d-none') && i.closest('tr').style.display !== 'none').length;
    };
    table.addEventListener('change', (e) => { if (e.target.matches('[data-bulk-item]')) update(); });
    if (all) all.addEventListener('change', () => {
      items().forEach((i) => { const row = i.closest('tr'); if (row.style.display !== 'none' && !row.classList.contains('d-none')) i.checked = all.checked; });
      update();
    });
    update();
  });
})();

// Roadmap: drag devices (one, or a quarter's whole group) and projects to another quarter
(() => {
  const modalEl = document.getElementById('modal-move');
  if (!modalEl || !document.querySelector('[data-drop-quarter]')) return;
  const cid = modalEl.dataset.client, csrf = modalEl.dataset.csrf;
  let drag = null, target = null;
  // Keep scroll position and open device lists across the reload after a move
  const KEY = 'align-roadmap-' + cid;
  try {
    const saved = JSON.parse(sessionStorage.getItem(KEY) || 'null');
    if (saved) {
      sessionStorage.removeItem(KEY);
      saved.open.forEach((q) => { const d = document.querySelector('[data-hw-group="' + q + '"]'); if (d) d.open = true; });
      window.addEventListener('load', () => window.scrollTo(0, saved.y));
    }
  } catch (e) { /* storage unavailable */ }
  /** Keeps scroll position and open lists for the reload after a move (this tab only). */
  const remember = () => {
    const open = Array.from(document.querySelectorAll('[data-hw-group]')).filter((d) => d.open).map((d) => d.dataset.hwGroup);
    if (target) open.push(target.start); // show the devices where they landed
    try { sessionStorage.setItem(KEY, JSON.stringify({ y: window.scrollY, open })); } catch (e) { /* ignore */ }
  };
  /** POSTs a move with the page's CSRF token; a reply that isn't JSON becomes {ok: false, error}. */
  const post = (url, data) => {
    const body = new URLSearchParams(data);
    body.append('_csrf', csrf);
    return fetch(url, { method: 'POST', headers: { Accept: 'application/json' }, body, credentials: 'same-origin' })
      .then((r) => r.json().catch(() => ({ ok: false, error: 'The server did not answer. Refresh and try again.' })));
  };
  /** After a move: reload, or show the server's error as text. */
  const done = (j, errEl) => {
    if (j && j.ok) { remember(); location.reload(); return; }
    const msg = (j && j.error) || 'Could not move it.';
    if (errEl) errEl.textContent = msg; else alert(msg);
  };

  document.addEventListener('dragstart', (e) => {
    const el = e.target.closest && e.target.closest('[data-drag-devices],[data-drag-project]');
    if (!el) return;
    drag = { el, devices: el.dataset.dragDevices || null, project: el.dataset.dragProject || null, name: el.dataset.dragName || '', planned: el.dataset.dragPlanned === '1', from: el.closest('[data-drop-quarter]') };
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', drag.name);
    el.classList.add('is-dragging');
    document.body.classList.add('rm-dragging');
    e.stopPropagation();
  });
  // Scroll the page while dragging near the top or bottom edge
  document.addEventListener('dragover', (e) => {
    if (!drag) return;
    if (e.clientY < 70) window.scrollBy(0, -25);
    else if (e.clientY > window.innerHeight - 70) window.scrollBy(0, 25);
  });
  document.addEventListener('dragend', () => {
    if (drag) drag.el.classList.remove('is-dragging');
    document.body.classList.remove('rm-dragging');
    document.querySelectorAll('.rm-drop-on').forEach((q) => q.classList.remove('rm-drop-on'));
  });
  document.querySelectorAll('[data-drop-quarter]').forEach((q) => {
    q.addEventListener('dragover', (e) => { if (!drag || drag.from === q) return; e.preventDefault(); e.dataTransfer.dropEffect = 'move'; q.classList.add('rm-drop-on'); });
    q.addEventListener('dragleave', (e) => { if (!q.contains(e.relatedTarget)) q.classList.remove('rm-drop-on'); });
    q.addEventListener('drop', (e) => {
      e.preventDefault();
      q.classList.remove('rm-drop-on');
      if (!drag || drag.from === q) return;
      target = { start: q.dataset.dropQuarter, label: q.dataset.quarterLabel };
      if (drag.project) {
        post('/clients/' + cid + '/roadmap/' + drag.project + '/move', { target_quarter: target.start }).then((j) => done(j));
        return;
      }
      modalEl.querySelector('[data-move-name]').textContent = drag.name;
      modalEl.querySelector('[data-move-quarter]').textContent = target.label;
      modalEl.querySelector('[data-move-error]').textContent = '';
      modalEl.querySelector('[data-move-reset]').classList.toggle('d-none', !drag.planned);
      modalEl.querySelector('#move-note').value = '';
      const move = { ...drag };
      modalEl._move = move;
      bsModal(modalEl).show();
    });
  });
  /** Moves the dragged devices' replacement to quarter ('' puts the planned date back). */
  const send = (quarter) => {
    const m = modalEl._move;
    if (!m) return;
    const data = new URLSearchParams();
    m.devices.split(',').forEach((id) => data.append('ids[]', id));
    data.append('replace_on', quarter);
    data.append('replace_note', quarter ? modalEl.querySelector('#move-note').value : '');
    modalEl.querySelectorAll('.modal-footer button').forEach((b) => { b.disabled = true; });
    post('/clients/' + cid + '/devices/replacement', data).then((j) => {
      modalEl.querySelectorAll('.modal-footer button').forEach((b) => { b.disabled = false; });
      done(j, modalEl.querySelector('[data-move-error]'));
    });
  };
  modalEl.querySelector('[data-move-save]').addEventListener('click', () => send(target.start));
  modalEl.querySelector('[data-move-reset]').addEventListener('click', () => send(''));
  modalEl.querySelector('#move-note').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); send(target.start); } });
})();

// ---- Dashboard (1.21): "Needs attention" filters, and Customize (reorder / hide cards, saved per user)
(() => {
  const list = document.querySelector('.dash-att-list');
  if (list) {
    const limit = parseInt(list.dataset.attLimit || '8', 10);
    let filter = '';
    let all = false;
    const more = document.querySelector('[data-att-more]');
    const apply = () => {
      let shown = 0;
      list.querySelectorAll('li[data-att-cat]').forEach((li) => {
        const match = !filter || li.dataset.attCat === filter;
        const visible = match && (all || filter || shown < limit);
        li.hidden = !visible;
        if (visible) shown++;
      });
      if (more) more.closest('.card-footer').hidden = all || !!filter;
    };
    document.querySelectorAll('[data-att-filter]').forEach((b) => b.addEventListener('click', () => {
      filter = b.dataset.attFilter;
      document.querySelectorAll('[data-att-filter]').forEach((x) => {
        x.classList.toggle('btn-primary', x === b);
        x.classList.toggle('btn-outline-secondary', x !== b);
      });
      apply();
    }));
    if (more) more.addEventListener('click', () => { all = true; apply(); });
    apply();
  }

  const dash = document.getElementById('dash');
  const btn = document.getElementById('dash-customize');
  const bar = document.getElementById('dash-editbar');
  if (!dash || !btn || !bar) return;
  const layout = JSON.parse(dash.dataset.layout || '{}');
  const zones = [...dash.querySelectorAll('.dash-zone')];
  /** The layout as it is on the page now (hidden cards kept at the end of their zone). */
  const collect = () => {
    const order = {};
    zones.forEach((z) => { order[z.dataset.zone] = [...z.querySelectorAll(':scope > .dash-card')].map((c) => c.dataset.card); });
    // Hidden cards aren't on the page: keep them at the end of the zone they were in
    (layout.hidden || []).forEach((k) => {
      const z = Object.keys(layout.order).find((zz) => layout.order[zz].includes(k)) || 'side';
      if (!order[z].includes(k)) order[z].push(k);
    });
    return { order, hidden: layout.hidden || [] };
  };
  /** Saves the layout (null resets it) with the CSRF token. */
  const save = (data, reload) => {
    const body = new URLSearchParams({ _csrf: bar.dataset.csrf });
    if (data === null) body.append('reset', '1'); else body.append('layout', JSON.stringify(data));
    return fetch('/dashboard/layout', { method: 'POST', credentials: 'same-origin', body, headers: { Accept: 'application/json' } })
      .then((r) => r.json()).then((j) => { if (j.ok && reload) location.reload(); return j; });
  };
  /** Turns Customize on or off. */
  const setEditing = (on) => {
    document.body.classList.toggle('dash-editing', on);
    bar.hidden = !on;
    btn.hidden = on;
    dash.querySelectorAll('.dash-grip').forEach((g) => g.setAttribute('draggable', on ? 'true' : 'false'));
  };
  btn.addEventListener('click', () => setEditing(true));
  document.getElementById('dash-done').addEventListener('click', () => { save(collect(), false).then(() => setEditing(false)); });
  document.getElementById('dash-reset').addEventListener('click', () => save(null, true));
  bar.querySelectorAll('[data-dash-show]').forEach((b) => b.addEventListener('click', () => {
    const d = collect();
    d.hidden = d.hidden.filter((k) => k !== b.dataset.dashShow);
    save(d, true);
  }));
  dash.addEventListener('click', (e) => {
    const card = e.target.closest('.dash-card');
    if (!card || !document.body.classList.contains('dash-editing')) return;
    if (e.target.closest('[data-dash-hide]')) {
      layout.hidden = [...(layout.hidden || []), card.dataset.card];
      const d = collect();
      const z = card.closest('.dash-zone').dataset.zone;
      if (!d.order[z].includes(card.dataset.card)) d.order[z].push(card.dataset.card);
      save(d, true);
      return;
    }
    const mv = e.target.closest('[data-dash-move]');
    if (mv) {
      // Up/down walks through all cards in page order, crossing from top to main to side
      const all = zones.flatMap((z) => [...z.querySelectorAll(':scope > .dash-card')]);
      const i = all.indexOf(card);
      const j = i + parseInt(mv.dataset.dashMove, 10);
      if (j < 0 || j >= all.length) return;
      const other = all[j];
      if (mv.dataset.dashMove === '-1') other.before(card); else other.after(card);
      mv.focus();
    }
  });
  let dragging = null;
  dash.addEventListener('dragstart', (e) => {
    const grip = e.target.closest && e.target.closest('.dash-grip');
    if (!grip || !document.body.classList.contains('dash-editing')) return;
    dragging = grip.closest('.dash-card');
    dragging.classList.add('dash-dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragging.dataset.card);
    try { e.dataTransfer.setDragImage(dragging, 20, 20); } catch (err) { /* older browsers */ }
  });
  dash.addEventListener('dragover', (e) => {
    if (!dragging) return;
    const z = e.target.closest('.dash-zone');
    if (!z) return;
    e.preventDefault();
    const after = [...z.querySelectorAll(':scope > .dash-card:not(.dash-dragging)')].find((c) => {
      const r = c.getBoundingClientRect();
      return e.clientY < r.top + r.height / 2;
    });
    if (after) after.before(dragging); else z.appendChild(dragging);
  });
  const end = () => { if (dragging) dragging.classList.remove('dash-dragging'); dragging = null; };
  dash.addEventListener('drop', (e) => { if (dragging) { e.preventDefault(); end(); } });
  dash.addEventListener('dragend', end);
})();

// ---- Onboarding page (1.22): contacts table (add, remove, paste from a spreadsheet) and request forms
(() => {
  const form = document.querySelector('[data-contacts-form]');
  if (form) {
    const body = form.querySelector('[data-contacts-body]');
    const tpl = document.getElementById('contact-row-template');
    /** A new contact row from the <template>, filled with vals (as field values, never markup). */
    const addRow = (vals) => {
      const row = tpl.content.firstElementChild.cloneNode(true);
      Object.entries(vals || {}).forEach(([k, v]) => { const el = row.querySelector('[data-f="' + k + '"]'); if (el) el.value = v; });
      body.appendChild(row);
      return row;
    };
    form.querySelector('[data-contact-add]').addEventListener('click', () => addRow().querySelector('input').focus());
    body.addEventListener('click', (e) => {
      const del = e.target.closest('[data-row-delete]');
      if (del) del.closest('tr').remove();
    });
    body.addEventListener('change', (e) => {
      if (e.target.matches('[data-f="remove"]')) {
        const tr = e.target.closest('tr');
        tr.classList.toggle('welcome-removed', e.target.checked);
        e.target.closest('label').classList.toggle('active', e.target.checked);
        e.target.closest('label').title = e.target.checked ? 'Keep this person' : 'No longer with the company';
      }
    });
    if (!body.querySelector('tr')) addRow();
    const pasteBtn = form.querySelector('[data-paste-add]');
    if (pasteBtn) pasteBtn.addEventListener('click', () => {
      const ta = document.getElementById('paste-text');
      let n = 0;
      ta.value.split(/\r?\n/).forEach((line) => {
        if (!line.trim()) return;
        const cells = (line.includes('\t') ? line.split('\t') : line.split(',')).map((c) => c.trim().replace(/^"|"$/g, ''));
        if (/^first/i.test(cells[0] || '') && /last/i.test(cells[1] || '')) return; // header row
        // An empty row the page started with gets filled first
        const blank = [...body.querySelectorAll('tr.welcome-new')].find((tr) => [...tr.querySelectorAll('input:not([type=checkbox])')].every((i) => !i.value));
        const vals = { first: cells[0] || '', last: cells[1] || '', title: cells[2] || '', phone: cells[3] || '', email: cells[4] || '' };
        if (blank) Object.entries(vals).forEach(([k, v]) => { blank.querySelector('[data-f="' + k + '"]').value = v; }); else addRow(vals);
        n++;
      });
      form.querySelector('[data-paste-result]').textContent = n ? n + ' added below. Check them, then save.' : 'Nothing to add.';
      if (n) ta.value = '';
    });
    form.addEventListener('submit', () => {
      const rows = [...body.querySelectorAll('tr[data-contact-row]')].map((tr) => {
        const r = {};
        tr.querySelectorAll('[data-f]').forEach((el) => { r[el.dataset.f] = el.type === 'checkbox' ? el.checked : el.value; });
        return r;
      });
      form.querySelector('[name="contacts_json"]').value = JSON.stringify(rows);
      // Send the table as one field (keeps long lists under the server's form limit)
      body.querySelectorAll('input[name]').forEach((el) => { el.disabled = true; });
    });
  }
  document.querySelectorAll('[data-access-add]').forEach((btn) => btn.addEventListener('click', () => {
    const list = btn.parentElement.querySelector('[data-access-list]');
    const rows = list.querySelectorAll('[data-access-row]');
    if (rows.length >= 10) return;
    const row = rows[0].cloneNode(true);
    row.querySelectorAll('input, select').forEach((el) => {
      el.name = el.name.replace(/\[\d+\]/, '[' + rows.length + ']');
      if (el.tagName === 'INPUT') el.value = ''; else el.selectedIndex = 0;
    });
    list.appendChild(row);
  }));
})();

// Settings -> API key form: presets, write implies read, client list, custom expiry date
(() => {
  document.querySelectorAll('[data-api-form]').forEach((form) => {
    const boxes = () => Array.from(form.querySelectorAll('input[name="scopes[]"]'));
    form.querySelectorAll('[data-api-preset]').forEach((btn) => btn.addEventListener('click', () => {
      const want = new Set(btn.dataset.apiPreset.split(' ').filter(Boolean));
      boxes().forEach((b) => { b.checked = want.has(b.value); });
    }));
    form.addEventListener('change', (e) => {
      const t = e.target;
      if (t.matches('[data-api-write]') && t.checked) {
        const r = form.querySelector('[data-api-read][data-api-scope="' + t.dataset.apiScope + '"]');
        if (r) r.checked = true;
      }
      if (t.matches('[data-api-read]') && !t.checked) {
        const w = form.querySelector('[data-api-write][data-api-scope="' + t.dataset.apiScope + '"]');
        if (w) w.checked = false;
      }
      if (t.name === 'client_scope') {
        const list = form.querySelector('#api-clients');
        if (list) list.classList.toggle('d-none', t.value !== 'some');
      }
    });
    const exp = form.querySelector('[data-api-expires]');
    const on = form.querySelector('[data-api-expires-on]');
    if (exp && on) {
      const sync = () => { on.classList.toggle('d-none', exp.value !== 'date'); on.required = exp.value === 'date'; };
      exp.addEventListener('change', sync);
      sync();
    }
  });
})();

// Settings → General → Currency & dates: live preview of the choices before saving
document.addEventListener('DOMContentLoaded', () => {
  const out = document.querySelector('[data-locale-preview]');
  if (!out) return;
  const cur = JSON.parse(out.dataset.currencies || '{}');
  const val = (id) => (document.getElementById(id) || {}).value || '';
  const seps = { comma: [',', '.'], dot: ['.', ','], space: [' ', ','], apostrophe: ["'", '.'] };
  /** The sample line: money, a negative amount, a date and a time in the chosen style (text only). */
  const update = () => {
    const [symbol, usual, decimals] = cur[val('locale_currency')] || ['$', 'before', 2];
    const after = (val('locale_currency_position') || usual) === 'after';
    const [th, dec] = seps[val('locale_number')] || seps.comma;
    const num = (n, d) => { const [i, f] = Math.abs(n).toFixed(d).split('.'); return i.replace(/\B(?=(\d{3})+(?!\d))/g, th) + (f ? dec + f : ''); };
    const money = (n, d) => { const t = num(n, decimals ? d : 0); const gap = after || /\p{L}$/u.test(symbol) ? ' ' : ''; return (n < 0 ? '-' : '') + (after ? t + ' ' + symbol : symbol + gap + t); };
    const date = { mdy: 'Tue Sep 29, 2026', dmy: 'Tue 29 Sep 2026', iso: 'Tue 2026-09-29' }[val('locale_date')] || 'Tue Sep 29, 2026';
    out.textContent = money(1234.5, 2) + ' · ' + money(-980, 0) + ' · ' + date + ' · ' + (val('locale_time') === '24' ? '14:30' : '2:30 pm');
  };
  document.querySelectorAll('[data-locale]').forEach((s) => s.addEventListener('change', update));
});

// Portal: /portal/licensing#suggest (from the home page) opens the Suggest form straight away
document.addEventListener('DOMContentLoaded', () => {
  if (location.hash === '#suggest' && document.getElementById('modal-suggest')) bsModal('#modal-suggest').show();
  // On a phone the section bar scrolls sideways: keep the current section in view
  const cur = document.querySelector('.portal-sections .nav-link.active');
  if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'nearest', inline: 'nearest' });
});

// Optional table columns (1.42): <div data-columns="device-table"> holding <input type="checkbox" data-col="serial">.
// The choice is kept in this browser (per table) and turns on class show-col-<key> on the table.
alignInit.add((root) => {
  root.querySelectorAll('[data-columns]').forEach((box) => {
    const table = document.getElementById(box.dataset.columns);
    if (!table) return;
    const key = 'align-cols-' + box.dataset.columns;
    let saved = null;
    try { saved = JSON.parse(window.localStorage.getItem(key) || 'null'); } catch (e) { saved = null; }
    const boxes = Array.from(box.querySelectorAll('input[data-col]'));
    const apply = () => boxes.forEach((b) => table.classList.toggle('show-col-' + b.dataset.col, b.checked));
    if (Array.isArray(saved)) boxes.forEach((b) => { b.checked = saved.includes(b.dataset.col); });
    apply();
    box.addEventListener('click', (ev) => ev.stopPropagation()); // keep the menu open while ticking
    boxes.forEach((b) => b.addEventListener('change', () => {
      apply();
      try { window.localStorage.setItem(key, JSON.stringify(boxes.filter((x) => x.checked).map((x) => x.dataset.col))); } catch (e) { /* private window */ }
    }));
  });
});

// Record-page tabs (1.42): open the tab named in the address (#lifecycle) and keep the address in step
document.addEventListener('DOMContentLoaded', () => {
  const tabs = document.querySelectorAll('.record-tabs [data-bs-toggle="tab"]');
  if (!tabs.length) return;
  const pick = window.location.hash && document.querySelector('.record-tabs [data-bs-toggle="tab"][href="' + CSS.escape(window.location.hash) + '"]');
  if (pick) bsTab(pick).show();
  tabs.forEach((t) => t.addEventListener('shown.bs.tab', (ev) => { try { window.history.replaceState(null, '', ev.target.getAttribute('href')); } catch (e) { /* ignore */ } }));
});

// A link to a project's window on the roadmap (/clients/5/roadmap#modal-roadmap-12) opens it (2.1)
const openFromHash = () => {
  const m = /^#(modal-roadmap-\d+)$/.exec(location.hash);
  const el = m && document.getElementById(m[1]);
  if (el && el.classList.contains('modal')) bsModal(el).show();
};
window.addEventListener('load', openFromHash);
window.addEventListener('hashchange', openFromHash);

// Devices page: "Make projects" carries the ticked devices into its window, with their count and budgeted total (2.1)
document.addEventListener('show.bs.modal', (ev) => {
  const form = ev.target.querySelector && ev.target.querySelector('form[data-project-from]');
  if (!form) return;
  form.querySelectorAll('input[data-copied]').forEach((i) => i.remove());
  // devices already in a project are left out: the server would skip them anyway
  const picked = Array.from(document.querySelectorAll(form.dataset.projectFrom)).filter((i) => i.checked && !i.hasAttribute('data-in-project'));
  let total = 0;
  picked.forEach((i) => {
    const h = document.createElement('input');
    h.type = 'hidden'; h.name = 'ids[]'; h.value = i.value; h.dataset.copied = '1';
    form.appendChild(h);
    total += parseFloat(i.dataset.cost || '0') || 0;
  });
  const n = picked.length;
  form.querySelectorAll('[data-pick-count]').forEach((el) => { el.textContent = n; });
  form.querySelectorAll('[data-pick-n]').forEach((el) => { el.value = String(n); });
  form.querySelectorAll('[data-pick-s]').forEach((el) => { el.textContent = n === 1 ? '' : 's'; });
  form.querySelectorAll('[data-pick-total]').forEach((el) => { el.textContent = total.toLocaleString(undefined, { style: 'currency', currency: form.dataset.currency || 'USD', maximumFractionDigits: 0 }); });
  form.querySelectorAll('[data-pick-many]').forEach((el) => el.classList.toggle('d-none', n < 2));
  const each = form.querySelector('input[name="mode"][value="each"]');
  if (each) each.checked = true;
  form.dispatchEvent(new Event('change'));
});
document.addEventListener('change', (ev) => {
  const form = ev.target.closest ? ev.target.closest('form[data-project-from]') : null;
  if (!form) return;
  const n = form.querySelectorAll('input[data-copied]').length || parseInt(form.dataset.fixedCount || '0', 10);
  const one = n === 1 || (form.querySelector('input[name="mode"]:checked') || {}).value === 'together';
  form.querySelectorAll('[data-pick-one]').forEach((el) => el.classList.toggle('d-none', !one));
  const btn = form.querySelector('[data-pick-submit]');
  if (btn) {
    btn.textContent = one ? 'Make the project' : 'Make ' + n + ' projects';
    btn.disabled = !n; // nothing ticked that can still become a project
  }
});

// 2.2.2 Devices & assets Filters panel: "Any" fields stay out of the address, and a new make drops the old model
// (the model list is for one make; it appears once the make is applied). Without script the form still works.
document.addEventListener('submit', (ev) => {
  const form = ev.target;
  if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-device-filters')) return;
  form.querySelectorAll('select, input[type=hidden]').forEach((el) => { if (el.value === '') el.disabled = true; });
  // re-enable once the page is back from the cache (back button), so the fields still work
  window.addEventListener('pageshow', () => form.querySelectorAll(':disabled').forEach((el) => { el.disabled = false; }), { once: true });
});
document.addEventListener('change', (ev) => {
  const el = ev.target;
  if (!(el instanceof HTMLSelectElement) || el.id !== 'df-make') return;
  const model = el.form && el.form.querySelector('#df-model');
  if (model) { model.value = ''; model.disabled = true; }
});

// 2.3.0 Alignment review: the live score and progress as answers change ([data-w] weights the row by priority),
// "Use" / "Fill" hint buttons pick an answer, and "Fill … from compliance" picks the suggested answer on every
// unanswered row that has one. Only radio values the page drew are ever chosen.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('al-form');
  const live = document.getElementById('al-live');
  if (!form || !live) return;
  const rows = () => Array.from(form.querySelectorAll('.al-q'));
  const band = (s) => (s >= 80 ? ['On track', 'success'] : s >= 60 ? ['Needs attention', 'warning'] : ['At risk', 'danger']);
  const update = () => {
    let ok = 0, all = 0, done = 0;
    const list = rows();
    list.forEach((r) => {
      const c = r.querySelector('input[type=radio]:checked');
      r.classList.toggle('is-open', !c);
      if (!c) return;
      done++;
      const w = Number(r.dataset.w) || 2;
      if (c.value === 'aligned') { ok += w; all += w; } else if (c.value === 'misaligned') { all += w; }
    });
    const s = all ? Math.round((100 * ok) / all) : null;
    live.textContent = s === null ? '–' : s + '%';
    const b = document.getElementById('al-live-band');
    if (b) {
      const [label, tone] = s === null ? ['Not scored yet', 'secondary'] : band(s);
      b.textContent = label;
      b.className = 'badge text-bg-' + tone;
    }
    const a = document.getElementById('al-answered');
    if (a) a.textContent = done + ' / ' + list.length;
    const p = document.getElementById('al-prog');
    if (p) p.style.width = (list.length ? Math.round((100 * done) / list.length) : 0) + '%';
  };
  const choose = (id, value) => {
    const input = form.querySelector('#al-' + id + ' input[type=radio][value="' + value + '"]');
    if (!input || input.disabled) return false;
    input.checked = true;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    const row = input.closest('.al-q');
    if (row) { row.dataset.dirty = '1'; row.classList.add('al-filled'); }
    return true;
  };
  form.addEventListener('change', (e) => { if (e.target.matches('input[type=radio]')) update(); });
  form.addEventListener('click', (e) => {
    const b = e.target.closest('[data-al-set]');
    if (b && choose(b.dataset.alSet, b.dataset.value)) b.remove();
  });
  const all = document.getElementById('al-fill-all');
  if (all) all.addEventListener('click', () => {
    let n = 0;
    rows().forEach((r) => {
      if (!r.dataset.suggest || r.querySelector('input[type=radio]:checked')) return;
      const id = (r.querySelector('[id^="al-"]') || {}).id;
      if (id && choose(id.slice(3), r.dataset.suggest)) n++;
    });
    all.disabled = true;
    all.textContent = n ? 'Filled ' + n + ': review, then save' : 'Nothing to fill';
  });
  update();
});

// 2.4.0 Since last QBR: the date box shows only for "A date…"; picking a review shows it straight away
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.querySelector('[data-ch-since]');
  const date = document.getElementById('ch-date');
  if (!sel || !date) return;
  sel.addEventListener('change', () => {
    date.hidden = sel.value !== 'date';
    if (sel.value === 'date') date.focus();
    else sel.form.submit();
  });
});
