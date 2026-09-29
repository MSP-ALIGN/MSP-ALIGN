// MSP-ALIGN - page behaviour on top of AdminLTE / Bootstrap 4.
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

  // Submit a select's form on change: <select data-autosubmit>
  document.querySelectorAll('select[data-autosubmit]').forEach((sel) => {
    sel.addEventListener('change', () => sel.form.submit());
  });

  // Confirm before submitting: <button data-confirm="Are you sure?">
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (ev) => {
      if (!window.confirm(el.dataset.confirm)) ev.preventDefault();
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
  if (params.get('add') === '1' && window.jQuery) {
    const opener = document.querySelector('[data-autoopen="add"]');
    if (opener) window.jQuery(opener.dataset.target).modal('show');
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
      eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
      events: (info, success, failure) => {
        const q = new URLSearchParams({ start: info.startStr, end: info.endStr });
        if (clientSel && clientSel.value) q.set('client', clientSel.value);
        fetch(calEl.dataset.events + '?' + q.toString(), { credentials: 'same-origin' })
          .then((r) => r.json()).then(success).catch(failure);
      },
      dateClick: (info) => {
        if (calEl.dataset.canCreate !== '1' || !window.jQuery) return;
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
        window.jQuery(modal).modal('show');
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
  if (window.jQuery) {
    window.jQuery('#modal-roadmap').on('show.bs.modal', (ev) => {
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
        const id = rc.value;
        const r = f.dataset.report;
        if (r === 'compliance') {
          f.action = '/clients/' + id + '/compliance/' + f.querySelector('.report-framework').value + '/export';
        } else if (r === 'document') {
          f.action = '/documents/' + f.querySelector('.report-document').value + '/print';
        } else {
          f.action = '/clients/' + id + r;
        }
        if (!id) ev.preventDefault();
      });
    });
    rc.addEventListener('change', refresh);
    refresh();
  }
});

// ---- 0.5.0: branding page live preview ----
document.addEventListener('DOMContentLoaded', () => {
  const preview = document.getElementById('brand-preview');
  if (!preview) return;
  const color = document.getElementById('brand_primary');
  const picker = document.querySelector('[data-color-for="brand_primary"]');
  const textFor = (hex) => {
    const c = hex.replace('#', '');
    const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    const [r, g, b] = [0, 2, 4].map((i) => lin(parseInt(c.substr(i, 2), 16)));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.4 ? '#1f2d3d' : '#ffffff';
  };
  const applyColor = (hex) => {
    if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return;
    preview.style.setProperty('--bp-color', hex);
    preview.style.setProperty('--bp-text', textFor(hex));
  };
  applyColor(color.value);
  color.addEventListener('input', () => { applyColor(color.value); if (/^#[0-9a-fA-F]{6}$/.test(color.value)) picker.value = color.value; });
  picker.addEventListener('input', () => { color.value = picker.value; applyColor(picker.value); });
  document.querySelectorAll('[data-swatch]').forEach((b) => b.addEventListener('click', () => {
    color.value = b.dataset.swatch; picker.value = b.dataset.swatch; applyColor(b.dataset.swatch);
  }));
  const nameIn = document.querySelector('[data-preview="name"]');
  const nameOut = document.getElementById('bp-name');
  nameIn.addEventListener('input', () => { nameOut.textContent = nameIn.value || preview.dataset.defaultName; });
  document.querySelector('[data-preview="logo-only"]').addEventListener('change', (e) => nameOut.classList.toggle('d-none', e.target.checked));
  document.querySelector('[data-preview="sidebar"]').addEventListener('change', (e) => document.getElementById('bp-side').classList.toggle('is-light', e.target.value === 'light'));
  const file = document.getElementById('logo');
  file.addEventListener('change', () => {
    const f = file.files[0];
    if (!f) return;
    file.nextElementSibling.textContent = f.name;
    const url = URL.createObjectURL(f);
    document.getElementById('bp-logo').src = url;
    document.getElementById('logo-img').src = url;
  });
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
    if (apply) { apply.disabled = n === 0; apply.innerHTML = '<i class="fas fa-check mr-1"></i>Apply' + (n ? ' to ' + n : ''); }
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
  const label = input.nextElementSibling;
  if (label && label.classList.contains('custom-file-label')) label.textContent = f.name;
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
document.addEventListener('DOMContentLoaded', () => {
  const fmt = (n) => '$' + n.toLocaleString(undefined, { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
  const months = { monthly: 1, quarterly: 3, annual: 12, one_time: 0 };
  const calc = (form) => {
    const q = (k) => form.querySelector('[data-lic="' + k + '"]');
    const out = q('out');
    if (!out) return;
    const price = parseFloat(q('price').value);
    if (isNaN(price)) { out.textContent = 'No price yet'; return; }
    const qty = q('pricing').value === 'per_seat' ? (parseInt(q('seats').value, 10) || 0) : 1;
    const per = price * qty;
    const m = months[q('cycle').value];
    out.innerHTML = '<b>' + fmt(per) + '</b> ' + q('cycle').selectedOptions[0].text.toLowerCase()
      + (m ? '<br><span class="text-muted">' + fmt(per / m) + '/mo · ' + fmt(per / m * 12) + '/yr</span>' : '');
  };
  document.querySelectorAll('[data-lic="out"]').forEach((o) => {
    const form = o.closest('form');
    calc(form);
    form.addEventListener('input', () => calc(form));
    form.addEventListener('change', () => calc(form));
  });
});

// Contract fields: show derived end / renegotiate dates as you type (the server fills them in the same way)
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-contract]').forEach((box) => {
    const form = box.closest('form');
    const q = (k) => box.querySelector('[data-c="' + k + '"]');
    const startIn = () => q('start') && q('start').value ? q('start') : form.querySelector('[name="' + box.dataset.startField + '"]');
    const fmt = (d) => d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
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
    const add = (c) => {
      const entry = c.email ? c.name + ' <' + c.email + '>' : c.name;
      const cur = input.value.trim();
      if (cur.toLowerCase().includes((c.email || c.name).toLowerCase())) return;
      input.value = cur ? cur.replace(/,\s*$/, '') + ', ' + entry : entry;
    };
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
          lbl.className = 'text-muted mr-1';
          lbl.textContent = 'Add:';
          box.appendChild(lbl);
          if (key.length > 1) {
            const all = document.createElement('a');
            all.href = '#'; all.className = 'mr-2 font-weight-bold'; all.textContent = 'meeting invitees (' + key.length + ')';
            all.addEventListener('click', (e) => { e.preventDefault(); key.forEach(add); });
            box.appendChild(all);
          }
          list.slice(0, 12).forEach((c) => {
            const a = document.createElement('a');
            a.href = '#'; a.className = 'badge badge-light border mr-1 mb-1' + (c.key ? ' font-weight-bold' : ' font-weight-normal');
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
    const done = () => { const t = btn.innerHTML; btn.textContent = 'Copied'; setTimeout(() => { btn.innerHTML = t; }, 1500); };
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

// Automatic logoff (HIPAA 164.312(a)(2)(iii)): signs out after the configured idle time, with a
// one-minute warning. Activity (keys, clicks, scrolling) keeps the server session alive via a ping.
document.addEventListener('DOMContentLoaded', () => {
  const meta = document.querySelector('meta[name="align-idle"]');
  if (!meta) return;
  const idle = parseInt(meta.content, 10) * 1000;
  if (!idle) return;
  let lastActive = Date.now();
  let lastPing = Date.now();
  let banner = null;
  let done = false;
  const mark = () => { lastActive = Date.now(); if (banner) { banner.remove(); banner = null; ping(true); } };
  ['keydown', 'mousedown', 'wheel', 'touchstart', 'scroll'].forEach((ev) => document.addEventListener(ev, mark, { passive: true, capture: true }));
  let lastMove = 0;
  document.addEventListener('mousemove', () => { const n = Date.now(); if (n - lastMove > 5000) { lastMove = n; mark(); } }, { passive: true });
  const ping = (active) => {
    lastPing = Date.now();
    fetch(meta.dataset.ping + (active ? '?active=1' : ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => { if (r.status === 401) signOut(false); })
      .catch(() => {});
  };
  const signOut = (post) => {
    if (done) return;
    done = true;
    const go = () => { window.location.href = meta.dataset.login; };
    if (!post) { go(); return; }
    const body = new URLSearchParams({ _csrf: meta.dataset.csrf });
    fetch(meta.dataset.logout, { method: 'POST', credentials: 'same-origin', body }).finally(go);
  };
  setInterval(() => {
    const now = Date.now();
    const quiet = now - lastActive;
    if (quiet >= idle) { signOut(true); return; }
    if (quiet >= idle - 60000 && !banner) {
      banner = document.createElement('div');
      banner.className = 'idle-warning alert alert-warning shadow';
      banner.setAttribute('role', 'alertdialog');
      const msg = document.createElement('span');
      msg.textContent = 'You will be signed out in a minute because of inactivity. ';
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'btn btn-sm btn-dark ml-2'; btn.textContent = 'Stay signed in';
      btn.addEventListener('click', mark);
      banner.append(msg, btn);
      document.body.appendChild(banner);
    }
    if (lastActive > lastPing && now - lastPing > 60000) ping(true);
  }, 5000);
});

// Fill a modal from the button that opens it: <button data-toggle="modal" data-target="#m" data-fill data-f-kind="device">
// sets [name="kind"] inputs and [data-fill-text="kind"] text inside #m.
document.addEventListener('click', (ev) => {
  const btn = ev.target.closest('[data-fill]');
  if (!btn) return;
  const modal = document.querySelector(btn.dataset.target || '');
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
  const clock = () => { q('[data-ov-time]').textContent = fmt(Math.round((Date.now() - t0) / 1000)); };
  const api = {
    show(title, step, elapsed) {
      if (title) q('[data-ov-title]').textContent = title;
      if (step) q('[data-ov-step]').textContent = step + '…';
      if (typeof elapsed === 'number') t0 = Date.now() - elapsed * 1000;
      ov.hidden = false;
      if (!timer) { clock(); timer = setInterval(clock, 1000); }
    },
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
    note(text) { q('[data-ov-note]').textContent = text; },
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
    hide() { ov.hidden = true; clearInterval(timer); timer = null; },
  };
  q('[data-ov-close]').addEventListener('click', () => api.hide());
  // Show it the moment Update or Restore is pressed, before the page changes
  document.querySelectorAll('form[action="/settings/system/update"], form[action="/settings/system/restore"]').forEach((f) => f.addEventListener('submit', () => {
    const confirm = f.querySelector('[name=confirm]');
    if (confirm && !confirm.checked) return; // the server explains what's missing
    const restore = f.action.endsWith('/restore');
    t0 = Date.now();
    api.show(restore ? 'Restoring from the backup' : 'Updating MSP-ALIGN', 'Starting', 0);
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
    const setState = (s) => {
      const [c, t] = badges[s] || ['secondary', s];
      const b = $('[data-job-state]');
      b.className = 'badge px-2 py-1 badge-' + c;
      b.textContent = t;
      box.className = box.className.replace(/card-(success|danger|primary|secondary)/, 'card-' + c);
      $('[data-job-spinner]').classList.toggle('d-none', s === 'succeeded' || s === 'failed');
    };
    const result = (j) => {
      const r = j.result || {}, el = $('[data-job-result]');
      const b = r.backup;
      const rows = [];
      if (b) {
        if (b.created) rows.push(['Made', new Date(b.created).toLocaleString()]);
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
        const dt = document.createElement('dt'); dt.className = 'col-sm-3 font-weight-normal text-muted'; dt.textContent = k;
        const dd = document.createElement('dd'); dd.className = 'col-sm-9 mb-1'; dd.textContent = v;
        dlist.append(dt, dd);
      });
      el.appendChild(dlist);
    };
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
    input.addEventListener('change', () => {
      const label = input.nextElementSibling;
      if (label && input.files[0]) label.textContent = input.files[0].name;
    });
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
        if (j && j.ok) { location.href = j.redirect; return; }
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
    if (words.length && shown <= 3) guides.filter((g) => !g.classList.contains('d-none')).forEach((g) => window.jQuery && window.jQuery(g.querySelector('.collapse')).collapse('show'));
    if (empty) empty.classList.toggle('d-none', shown > 0);
    const h = document.querySelectorAll('#tab-howto h6');
    h.forEach((el) => { el.classList.toggle('d-none', words.length > 0); });
  });
  const open = () => {
    const id = decodeURIComponent(location.hash.slice(1));
    if (!id) return;
    const target = document.getElementById(id);
    if (!target || !window.jQuery) return;
    const pane = target.closest('.tab-pane');
    if (pane) window.jQuery('a[href="#' + pane.id + '"]').tab('show');
    if (target.classList.contains('help-guide')) window.jQuery(target.querySelector('.collapse')).collapse('show');
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
  const remember = () => {
    const open = Array.from(document.querySelectorAll('[data-hw-group]')).filter((d) => d.open).map((d) => d.dataset.hwGroup);
    if (target) open.push(target.start); // show the devices where they landed
    try { sessionStorage.setItem(KEY, JSON.stringify({ y: window.scrollY, open })); } catch (e) { /* ignore */ }
  };
  const post = (url, data) => {
    const body = new URLSearchParams(data);
    body.append('_csrf', csrf);
    return fetch(url, { method: 'POST', headers: { Accept: 'application/json' }, body, credentials: 'same-origin' })
      .then((r) => r.json().catch(() => ({ ok: false, error: 'The server did not answer. Refresh and try again.' })));
  };
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
      if (window.jQuery) window.jQuery(modalEl).modal('show');
    });
  });
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
  const save = (data, reload) => {
    const body = new URLSearchParams({ _csrf: bar.dataset.csrf });
    if (data === null) body.append('reset', '1'); else body.append('layout', JSON.stringify(data));
    return fetch('/dashboard/layout', { method: 'POST', credentials: 'same-origin', body, headers: { Accept: 'application/json' } })
      .then((r) => r.json()).then((j) => { if (j.ok && reload) location.reload(); return j; });
  };
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
