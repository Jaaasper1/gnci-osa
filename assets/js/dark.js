window.__t = window.__t || function (s) { return s; };
/* GNC-OSA Portal — dark mode toggle. Loaded synchronously from includes/pwa-head.php.
   Preference is stored in localStorage ('osaTheme' = 'dark' | 'light'); with no choice yet it stays in light mode (the default). */
(function () {
  var KEY = 'osaTheme', root = document.documentElement;

  function stored() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function isDark() { return root.getAttribute('data-theme') === 'dark'; }

  /* Charts are built by inline scripts after chart.umd.js loads; set readable defaults the moment Chart appears. */
  function chartDefaults(C) {
    try {
      if (!C || !C.defaults) return;
      C.defaults.color = isDark() ? '#B0B3B8' : '#666';
      C.defaults.borderColor = isDark() ? 'rgba(255,255,255,0.10)' : 'rgba(0,0,0,0.1)';
    } catch (e) {}
  }
  var _chart = window.Chart;
  try {
    Object.defineProperty(window, 'Chart', {
      configurable: true,
      get: function () { return _chart; },
      set: function (v) { _chart = v; chartDefaults(v); }
    });
  } catch (e) {}

  function apply(dark, save) {
    if (dark) root.setAttribute('data-theme', 'dark'); else root.removeAttribute('data-theme');
    if (save) { try { localStorage.setItem(KEY, dark ? 'dark' : 'light'); } catch (e) {} }
    var m = document.querySelector('meta[name="theme-color"]');
    if (m) m.setAttribute('content', dark ? '#18191A' : '#6B1A2A');
    var btns = document.querySelectorAll('.theme-toggle');
    for (var i = 0; i < btns.length; i++) paint(btns[i]);
  }

  function paint(b) {
    var d = isDark();
    b.setAttribute('aria-pressed', d ? 'true' : 'false');
    b.setAttribute('title', d ? 'Switch to light mode' : __t('Switch to dark mode'));
    b.setAttribute('aria-label', d ? 'Switch to light mode' : __t('Switch to dark mode'));
    /* inline SVG so the button works even if the icon font fails to load */
    b.innerHTML = d
      ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>'
      : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
  }

  function toggle() {
    apply(!isDark(), true);
    /* Charts keep their old colours until redrawn — simplest reliable fix is a reload on pages that have one. */
    if (document.querySelector('canvas')) location.reload();
  }

  function mount() {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'theme-toggle';
    b.addEventListener('click', toggle);
    var nav = document.querySelector('.topnav .nav-right');
    if (nav) { paint(b); nav.insertBefore(b, nav.firstChild); }
    else if (document.querySelector('.auth-split, body.auth-split-page')) { return; }   /* no button on login / register — they still follow the saved or device theme */
    else { b.className += ' theme-toggle-float'; paint(b); document.body.appendChild(b); }
  }

  /* Keep several open tabs in sync. */
  window.addEventListener('storage', function (e) { if (e.key === KEY) apply(e.newValue === 'dark', false); });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { apply(isDark(), false); mount(); });
  else { apply(isDark(), false); mount(); }
})();
