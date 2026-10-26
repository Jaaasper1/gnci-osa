window.__t = window.__t || function (s) { return s; };
/* GNC-OSA Portal — accessibility panel. Loaded synchronously from includes/pwa-head.php.
   Settings are saved per browser in localStorage ('osaA11y'); classes go on <html> before first paint. */
(function () {
  var KEY = 'osaA11y', root = document.documentElement;
  var D = { size: 0, sp: 0, dys: 0, links: 0, focus: 0, lines: 0, cb: 'none', mag: 0 };
  var S = {};
  try { S = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}
  for (var k in D) if (!(k in S)) S[k] = D[k];

  function save() { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {} }
  function apply() {
    var c = root.className.replace(/\ba11y-\S+/g, '').replace(/\s+/g, ' ').trim();
    var add = [];
    if (S.size) add.push('a11y-size-' + S.size);
    if (S.sp) add.push('a11y-sp-' + S.sp);
    if (S.dys) add.push('a11y-dys');
    if (S.links) add.push('a11y-links');
    if (S.focus) add.push('a11y-focus');
    if (S.lines) add.push('a11y-lines');
    if (S.cb && S.cb !== 'none') add.push('a11y-cb-' + S.cb);
    root.className = (c + ' ' + add.join(' ')).trim();
  }
  apply();

  /* ---------- colour-blind SVG filters (daltonisation matrices: shift hard-to-see colours into visible ones) ---------- */
  function addFilters() {
    if (document.getElementById('a11y-svg')) return;
    function f(id, m) {
      return '<filter id="a11y-f-' + id + '" color-interpolation-filters="sRGB"><feColorMatrix type="matrix" values="' + m + '"/></filter>';
    }
    var s = document.createElement('div');
    s.id = 'a11y-svg';
    s.setAttribute('aria-hidden', 'true');
    s.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden';
    s.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0"><defs>' +
      f('protan', '1 0 0 0 0  0.7 0.3 0 0 0  0.7 0 0.3 0 0  0 0 0 1 0') +
      f('deutan', '0.3 0.7 0 0 0  0 1 0 0 0  0 0.7 0.3 0 0  0 0 0 1 0') +
      f('tritan', '1 0 0 0 0  0 0.3 0.7 0 0  0 0.3 0.7 0 0  0 0 0 1 0') +
      '</defs></svg>';
    root.appendChild(s);
  }

  /* ---------- panel ---------- */
  var panel, btn;
  function opt(group, val, label, extra) {
    return '<button type="button" class="opt" data-g="' + group + '" data-v="' + val + '"' + (extra || '') + '>' + label + '</button>';
  }
  function sw(key, label) {
    return '<label class="sw"><span>' + label + '</span><input type="checkbox" data-sw="' + key + '"></label>';
  }
  function build() {
    addFilters();
    btn = document.createElement('button');
    btn.id = 'a11y-btn'; btn.type = 'button';
    btn.setAttribute('aria-label', __t('Accessibility options')); btn.title = __t('Accessibility');
    btn.setAttribute('aria-expanded', 'false');
    btn.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="4.5" r="2.2"/><path d="M5 8.2l7 1 7-1 .4 1.8-5.4 1v3.2l2 6.3-1.9.7L12 16l-2.1 5 -1.9-.7 2-6.3V11L4.6 10z"/></svg>';
    panel = document.createElement('div');
    panel.id = 'a11y-panel'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', __t('Accessibility options'));
    panel.innerHTML =
      '<h2>Accessibility <button type="button" class="x" aria-label="Close">&times;</button></h2>' +
      '<h3>Text size</h3><div class="row">' +
        opt('size', 0, __t('Normal')) + opt('size', 1, __t('Large')) + opt('size', 2, __t('Larger')) + opt('size', 3, __t('Largest')) + '</div>' +
      '<h3>Spacing</h3><div class="row">' +
        opt('sp', 0, __t('Normal')) + opt('sp', 1, 'Wide') + opt('sp', 2, 'Wider') + '</div>' +
      '<h3>Reading</h3>' +
        sw('dys', 'Dyslexia-friendly font') + sw('lines', __t('Space between paragraphs')) + sw('links', __t('Underline links')) + sw('focus', __t('Strong focus outline')) +
      '<h3>Text magnifier</h3>' +
        sw('mag', __t('Enlarge text when I hover / tap it')) +
      '<h3>Colour-blind mode</h3><div class="row">' +
        opt('cb', 'none', __t('Off')) + opt('cb', 'protan', 'Protanopia (red)') + opt('cb', 'deutan', 'Deuteranopia (green)') +
        opt('cb', 'tritan', 'Tritanopia (blue)') + opt('cb', 'mono', __t('Grayscale')) + '</div>' +
      '<button type="button" class="reset">Reset all</button>';
    root.appendChild(btn);
    root.appendChild(panel);

    btn.addEventListener('click', function () {
      var o = panel.classList.toggle('open');
      btn.setAttribute('aria-expanded', o ? 'true' : 'false');
    });
    panel.querySelector('.x').addEventListener('click', function () { panel.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); btn.focus(); });
    /* Click / tap anywhere outside the panel (and its button) closes it */
    document.addEventListener('pointerdown', function (e) {
      if (!panel.classList.contains('open')) return;
      if (panel.contains(e.target) || btn.contains(e.target)) return;
      panel.classList.remove('open'); btn.setAttribute('aria-expanded', 'false');
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel.classList.contains('open')) panel.querySelector('.x').click(); });
    panel.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('button.opt') : null;
      if (b) { var g = b.getAttribute('data-g'), v = b.getAttribute('data-v'); S[g] = (g === 'cb') ? v : parseInt(v, 10); save(); apply(); paint(); }
    });
    panel.addEventListener('change', function (e) {
      var k = e.target.getAttribute && e.target.getAttribute('data-sw');
      if (k) { S[k] = e.target.checked ? 1 : 0; save(); apply(); paint(); }
    });
    panel.querySelector('.reset').addEventListener('click', function () { for (var k in D) S[k] = D[k]; save(); apply(); paint(); });
    paint();
  }
  function paint() {
    if (!panel) return;
    var bs = panel.querySelectorAll('button.opt');
    for (var i = 0; i < bs.length; i++) {
      var g = bs[i].getAttribute('data-g'), v = bs[i].getAttribute('data-v');
      bs[i].setAttribute('aria-pressed', String(S[g]) === v ? 'true' : 'false');
    }
    var cs = panel.querySelectorAll('input[data-sw]');
    for (var j = 0; j < cs.length; j++) cs[j].checked = !!S[cs[j].getAttribute('data-sw')];
  }

  /* ---------- text magnifier ---------- */
  var mag, last = null;
  function textOf(el) {
    if (!el || el.nodeType !== 1) return '';
    if (el.closest('#a11y-panel, #a11y-btn, #a11y-mag, script, style, canvas')) return '';
    var t = '';
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') t = el.value || el.placeholder || '';
    else if (el.tagName === 'IMG') t = el.alt || '';
    else {
      for (var n = el.firstChild; n; n = n.nextSibling) if (n.nodeType === 3) t += n.nodeValue + ' ';
      t = t.replace(/\s+/g, ' ').trim();
      if (!t && el.children.length === 0) t = (el.textContent || '').trim();
    }
    return t.length > 400 ? t.slice(0, 400) + '…' : t;
  }
  function show(el, x, y) {
    if (!S.mag) return hide();
    var t = textOf(el);
    if (!t) return hide();
    if (!mag) { mag = document.createElement('div'); mag.id = 'a11y-mag'; mag.setAttribute('aria-hidden', 'true'); root.appendChild(mag); }
    if (last !== t) { mag.textContent = t; last = t; }
    mag.style.display = 'block';
    var w = mag.offsetWidth, h = mag.offsetHeight, vw = window.innerWidth, vh = window.innerHeight;
    var left = Math.min(Math.max(8, x - w / 2), vw - w - 8);
    var top = y - h - 18; if (top < 8) top = Math.min(y + 24, vh - h - 8);
    mag.style.left = left + 'px'; mag.style.top = top + 'px';
  }
  function hide() { if (mag) mag.style.display = 'none'; last = null; }
  function initMag() {
    document.addEventListener('mousemove', function (e) { if (S.mag) show(e.target, e.clientX, e.clientY); });
    document.addEventListener('mouseleave', hide);
    document.addEventListener('touchstart', function (e) { if (!S.mag) return; var t = e.touches[0]; show(document.elementFromPoint(t.clientX, t.clientY), t.clientX, t.clientY); }, { passive: true });
    document.addEventListener('touchend', function () { setTimeout(hide, 2500); }, { passive: true });
    document.addEventListener('focusin', function (e) { if (!S.mag) return; var r = e.target.getBoundingClientRect(); show(e.target, r.left + r.width / 2, r.top); });
    document.addEventListener('focusout', hide);
    panel && panel.addEventListener('change', function () { if (!S.mag) hide(); });
  }

  function mount() {
    build(); initMag();
    /* Lift the button only where another floating button already sits at the bottom-right */
    if (document.querySelector('#osaChatWrap, .liveresults-fab')) root.classList.add('a11y-raised');
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
