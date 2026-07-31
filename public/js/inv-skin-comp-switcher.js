/* Invoicing — dev skin comp switcher (UI Skin Lab). Persists to user pref when logged in. */
(function () {
  var html = document.documentElement;
  var STORAGE_KEY = 'inv-skin-comp';
  var cfg = window.__INV_SKIN_LAB__ || {};
  var slugs = cfg.slugs || ['hey', 'ledger', 'brutalist', 'obsidian'];
  var authored = html.getAttribute('data-skin-comp') || cfg.defaultSlug || 'obsidian';

  function readLocal() {
    try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
  }
  function writeLocal(v) {
    try { localStorage.setItem(STORAGE_KEY, v); } catch (e) {}
  }

  var initial = readLocal() || authored;
  if (slugs.indexOf(initial) === -1) initial = slugs[0] || 'obsidian';
  html.setAttribute('data-skin-comp', initial);
  html.setAttribute('data-bs-theme', initial === 'obsidian' ? 'dark' : 'light');

  function syncBarHeight() {
    var bar = document.getElementById('inv-skin-comp-bar');
    if (!bar) return;
    html.style.setProperty('--comp-h', bar.offsetHeight + 'px');
  }

  function persistUser(slug) {
    if (!cfg.saveUrl || !cfg.csrfToken) return;
    try {
      var body = new URLSearchParams();
      body.set('skin_slug', slug);
      body.set('csrf_token', cfg.csrfToken);
      fetch(cfg.saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
      }).catch(function () {});
    } catch (e) {}
  }

  function paint(active) {
    var btns = document.querySelectorAll('#inv-skin-comp-bar [data-skin-set]');
    Array.prototype.forEach.call(btns, function (b) {
      var on = b.getAttribute('data-skin-set') === active;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  function apply(slug) {
    if (slugs.indexOf(slug) === -1) return;
    html.setAttribute('data-skin-comp', slug);
    html.setAttribute('data-bs-theme', slug === 'obsidian' ? 'dark' : 'light');
    writeLocal(slug);
    paint(slug);
    persistUser(slug);
    syncBarHeight();
  }

  function setup() {
    var bar = document.getElementById('inv-skin-comp-bar');
    if (!bar) return;
    Array.prototype.forEach.call(bar.querySelectorAll('[data-skin-set]'), function (b) {
      b.addEventListener('click', function () {
        apply(b.getAttribute('data-skin-set'));
      });
    });
    paint(html.getAttribute('data-skin-comp') || initial);
    syncBarHeight();
    window.addEventListener('resize', syncBarHeight);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setup);
  } else {
    setup();
  }
})();
