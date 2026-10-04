// Light / dark (1.43): "Match my computer" follows the operating system, before the page draws, and when it changes.
// Loaded without defer in <head> so the page never flashes the wrong theme. Reads only the server's
// data-theme-pref on <html> (the person's saved choice; nothing is kept in the browser) and sets data-bs-theme to
// one of two fixed values.
(function () {
  var root = document.documentElement;
  if (!window.matchMedia || (root.getAttribute('data-theme-pref') || 'auto') !== 'auto') return;
  var mq = window.matchMedia('(prefers-color-scheme: dark)');
  /** The theme the operating system asks for now. */
  var apply = function () { root.setAttribute('data-bs-theme', mq.matches ? 'dark' : 'light'); };
  apply();
  if (mq.addEventListener) mq.addEventListener('change', apply);
})();
