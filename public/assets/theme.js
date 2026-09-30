// Light / dark (1.43): "Match my computer" follows the operating system, before the page draws, and when it changes.
(function () {
  var root = document.documentElement;
  if (!window.matchMedia || (root.getAttribute('data-theme-pref') || 'auto') !== 'auto') return;
  var mq = window.matchMedia('(prefers-color-scheme: dark)');
  var apply = function () { root.setAttribute('data-bs-theme', mq.matches ? 'dark' : 'light'); };
  apply();
  if (mq.addEventListener) mq.addEventListener('change', apply);
})();
