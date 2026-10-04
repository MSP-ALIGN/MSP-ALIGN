// Report pages: print button, back button, option checkboxes that reload the report.
// The back button goes back in this tab's history, or closes a tab the report opened in; it never navigates to an
// address taken from the page. Option changes submit their own (same-site) form.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));
  document.querySelectorAll('[data-back]').forEach((a) => a.addEventListener('click', (ev) => {
    ev.preventDefault();
    if (window.history.length > 1) window.history.back(); else window.close();
  }));
  document.querySelectorAll('[data-autosubmit-check], [data-autosubmit-select]').forEach((c) => c.addEventListener('change', () => c.form.submit()));
});
