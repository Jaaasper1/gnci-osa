/* Floating labels: toggles .fl-on on a .form-group when its input is focused, filled or autofilled. */
(function () {
  function init(group) {
    var input = group.querySelector('input.form-control');
    if (!input || group.dataset.fl) return;
    group.dataset.fl = '1';
    function filled() {
      var af = false;
      try { af = input.matches(':-webkit-autofill') || input.matches(':autofill'); } catch (e) {}
      return input.value !== '' || af;
    }
    function update() { group.classList.toggle('fl-on', document.activeElement === input || filled()); }
    ['focus', 'blur', 'input', 'change', 'animationstart'].forEach(function (ev) { input.addEventListener(ev, update); });
    update();
    [100, 400, 1000, 2500].forEach(function (ms) { setTimeout(update, ms); });   /* browser autofill lands a moment after load */
    window.addEventListener('pageshow', update);
  }
  function start() {
    var groups = document.querySelectorAll('.fl-form .form-group');
    for (var i = 0; i < groups.length; i++) init(groups[i]);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
