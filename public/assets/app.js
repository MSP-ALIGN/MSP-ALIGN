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
