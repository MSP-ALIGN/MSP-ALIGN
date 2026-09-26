// Mountaineer Align - page behaviour on top of AdminLTE / Bootstrap 4.
document.addEventListener('DOMContentLoaded', () => {
  // Live table filter: <input data-filter-table="table-id">
  document.querySelectorAll('[data-filter-table]').forEach((input) => {
    const table = document.getElementById(input.dataset.filterTable);
    if (!table) return;
    const apply = () => {
      const q = input.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr').forEach((tr) => {
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
      }
      btn.remove();
    });
  });

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

  // Reports page: build the report URL from the chosen client + type
  const rf = document.getElementById('report-form');
  if (rf) {
    const setAction = () => {
      rf.action = '/clients/' + document.getElementById('report-client').value + '/report/' + document.getElementById('report-type').value;
    };
    rf.addEventListener('submit', setAction);
    setAction();
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
