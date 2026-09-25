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
