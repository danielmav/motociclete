(function () {
  'use strict';
  var NAME = 'dm_consent';
  var VERSION = 'v1';
  var MAX_AGE = 60 * 60 * 24 * 365; // 12 months

  function readCookie() {
    var m = document.cookie.match(/(?:^|;\s*)dm_consent=([^;]*)/);
    if (!m) { return null; }
    var val = decodeURIComponent(m[1]);
    if (val.indexOf(VERSION + ':') !== 0) { return null; } // version bump => re-prompt
    return { analytics: /analytics=1/.test(val) };
  }

  function writeCookie(analytics) {
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = NAME + '=' + VERSION + ':analytics=' + (analytics ? '1' : '0') +
      '; path=/; max-age=' + MAX_AGE + '; SameSite=Lax' + secure;
  }

  function applyConsent(analytics) {
    if (typeof window.gtag === 'function') {
      window.gtag('consent', 'update', { analytics_storage: analytics ? 'granted' : 'denied' });
    }
  }

  var root, banner, modal, analyticsToggle;

  function showBanner() { if (root) { root.hidden = false; if (banner) { banner.hidden = false; } if (modal) { modal.hidden = true; } } }
  function hideAll() { if (root) { root.hidden = true; } }
  function openPrefs() {
    var c = readCookie();
    if (analyticsToggle) { analyticsToggle.checked = !!(c && c.analytics); }
    if (root) { root.hidden = false; }
    if (banner) { banner.hidden = true; }
    if (modal) { modal.hidden = false; }
  }
  window.openCookiePrefs = openPrefs;

  function decide(analytics) {
    writeCookie(analytics);
    applyConsent(analytics);
    hideAll();
  }

  document.addEventListener('DOMContentLoaded', function () {
    root = document.querySelector('[data-cc]');
    if (!root) { return; }
    banner = root.querySelector('[data-cc-banner]');
    modal = root.querySelector('[data-cc-modal]');
    analyticsToggle = root.querySelector('[data-cc-analytics]');

    root.querySelectorAll('[data-cc-accept]').forEach(function (b) { b.addEventListener('click', function () { decide(true); }); });
    root.querySelectorAll('[data-cc-reject]').forEach(function (b) { b.addEventListener('click', function () { decide(false); }); });
    root.querySelectorAll('[data-cc-prefs]').forEach(function (b) { b.addEventListener('click', openPrefs); });
    root.querySelectorAll('[data-cc-modal-close]').forEach(function (b) { b.addEventListener('click', showBanner); });
    var save = root.querySelector('[data-cc-save]');
    if (save) { save.addEventListener('click', function () { decide(!!(analyticsToggle && analyticsToggle.checked)); }); }

    if (!readCookie()) { showBanner(); }
  });
})();
