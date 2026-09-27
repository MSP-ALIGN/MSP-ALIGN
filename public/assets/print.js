// Report pages: print button, back button, option checkboxes that reload the report.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
  document.querySelectorAll('[data-back]').forEach((a) => a.addEventListener('click', (ev) => {
    ev.preventDefault();
    if (window.history.length > 1) window.history.back(); else window.close();
  }));
  document.querySelectorAll('[data-autosubmit-check], [data-autosubmit-select]').forEach((c) => c.addEventListener('change', () => c.form.submit()));
});
