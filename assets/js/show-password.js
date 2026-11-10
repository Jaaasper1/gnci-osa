/* GNC-OSA Portal — "show password" eye button.
   Automatically adds an eye icon inside every <input type="password"> on the page (login, register, settings, admin edit forms). */
(function () {
  var css = '.pw-wrap{position:relative;display:block;width:100%}' +
    '.pw-wrap>input{padding-right:46px!important}' +
    'input[type=password]::-ms-reveal,input[type=password]::-ms-clear{display:none}' +
    '.pw-eye{position:absolute;top:50%;right:6px;transform:translateY(-50%);width:34px;height:34px;border:0;border-radius:50%;background:transparent;color:#6b7280;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;opacity:.85}' +
    '.pw-eye.pw-empty{display:none}' +
    '.pw-eye:hover,.pw-eye:focus-visible{opacity:1;background:rgba(0,0,0,.06);outline:none}' +
    'html[data-theme="dark"] .pw-eye{color:#B0B3B8}' +
    'html[data-theme="dark"] .pw-eye:hover,html[data-theme="dark"] .pw-eye:focus-visible{background:rgba(255,255,255,.12)}';
  var EYE = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.9 17.9A10.7 10.7 0 0 1 12 19c-6.4 0-10-7-10-7a18 18 0 0 1 4.1-5M9.9 5.2A10 10 0 0 1 12 5c6.4 0 10 7 10 7a18 18 0 0 1-2.2 3.2M1 1l22 22"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
  var tt = window.__t || function (s) { return s; };

  function enhance(input) {
    if (input.dataset.pwEye) return;
    input.dataset.pwEye = '1';
    var wrap = document.createElement('span');
    wrap.className = 'pw-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'pw-eye';
    function paint() {
      var shown = input.type === 'text';
      b.innerHTML = shown ? EYE_OFF : EYE;
      var label = shown ? tt('Hide password') : tt('Show password');
      b.setAttribute('aria-label', label);
      b.setAttribute('title', label);
      b.setAttribute('aria-pressed', shown ? 'true' : 'false');
    }
    b.addEventListener('click', function () {
      input.type = input.type === 'password' ? 'text' : 'password';
      paint();
      input.focus();
    });
    // Show the eye only while the field has text
    function sync() { b.classList.toggle('pw-empty', input.value === ''); }
    ['input', 'change', 'keyup', 'focus', 'blur'].forEach(function (ev) { input.addEventListener(ev, sync); });
    // catches autofill and values set/cleared from JS (e.g. modals resetting the field)
    setInterval(sync, 300);
    paint();
    sync();
    wrap.appendChild(b);
  }

  function scan(root) {
    var list = (root || document).querySelectorAll('input[type="password"]');
    for (var i = 0; i < list.length; i++) enhance(list[i]);
  }

  function start() {
    var st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);
    scan(document);
    try {
      new MutationObserver(function () { scan(document); }).observe(document.body, { childList: true, subtree: true });
    } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
