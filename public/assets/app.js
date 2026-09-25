// Mountaineer Align - small progressive enhancements (the app works without JS).
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

  // Submit a select's form when it changes: <select data-autosubmit>
  document.querySelectorAll('select[data-autosubmit]').forEach((sel) => {
    sel.addEventListener('change', () => sel.form.submit());
  });

  // Confirm before submitting: <button data-confirm="Are you sure?">
  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (ev) => {
      if (!window.confirm(el.dataset.confirm)) ev.preventDefault();
    });
  });
});
