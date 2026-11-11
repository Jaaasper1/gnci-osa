// Live password checker. Same rules as includes/password-policy.php.
// PwPolicy.attach({input, list, bad, cfg, ctx, names})
//   ctx   = array (or function returning array) of values that must NOT appear: [studentId, email]
//   names = array (or function) of first/last name: allowed, but the rest must still be strong
(function (w) {
  function val(x) { return typeof x === 'function' ? x() : (x || []); }
  function len(s) { return Array.from(s).length; }

  function basic(pw, cfg) {
    return {
      len: len(pw) >= cfg.minLen, up: /[A-Z]/.test(pw), low: /[a-z]/.test(pw),
      num: /\d/.test(pw), sym: /[^A-Za-z0-9]/.test(pw)
    };
  }

  function extra(pw, cfg, ctx, names) {
    var M = cfg.msg;
    if (pw.length > 72) return M.max;
    if (/(.)\1{3,}/u.test(pw)) return M.repeat;
    var leet = pw.toLowerCase().replace(/[@$0345]/g, function (c) { return {'@':'a','$':'s','0':'o','3':'e','4':'a','5':'s'}[c]; });
    var letters = leet.replace(/[^a-z]/g, '');
    for (var i = 0; i < cfg.weak.length; i++) {
      var wd = cfg.weak[i];
      if (letters === wd || (letters.indexOf(wd) === 0 && letters.length - wd.length <= 2)) return M.common;
    }
    var digits = pw.replace(/\D/g, '');
    if (/^(0123456789|1234567890|123456789|12345678|987654321)$/.test(digits) && letters.length < 3) return M.common;

    var low = pw.toLowerCase();
    for (var c = 0; c < ctx.length; c++) {
      var v = String(ctx[c] || '').trim().toLowerCase();
      if (v.indexOf('@') > -1) v = v.slice(0, v.indexOf('@'));
      if (len(v) >= 4 && low.indexOf(v) > -1) return M.personal;
    }
    var rest = low, used = false;
    names.forEach(function (n) {
      String(n || '').trim().toLowerCase().split(/[\s._\-]+/).forEach(function (p) {
        if (len(p) >= 3 && rest.indexOf(p) > -1) { rest = rest.split(p).join(''); used = true; }
      });
    });
    if (used && (len(rest) < 5 || !/\d/.test(rest) || !/[^a-z0-9]/.test(rest))) return M.nameOnly;
    return null;
  }

  w.PwPolicy = {
    attach: function (o) {
      var inp = o.input, cfg = o.cfg;
      if (!inp) return;
      function run() {
        var pw = inp.value, b = basic(pw, cfg), firstBad = null, order = ['len', 'up', 'low', 'num', 'sym'];
        if (o.list) o.list.querySelectorAll('li').forEach(function (li) {
          var ok = pw !== '' && b[li.getAttribute('data-r')];
          li.classList.toggle('ok', ok);
          li.classList.toggle('no', pw !== '' && !ok);
          li.querySelector('i').className = 'ti ' + (ok ? 'ti-circle-check' : 'ti-circle');
        });
        order.forEach(function (k) { if (!b[k] && !firstBad) firstBad = cfg.msg[k]; });
        var ex = pw === '' ? null : extra(pw, cfg, val(o.ctx), val(o.names));
        if (o.bad) {
          // red message only for the "not allowed" cases (common, repeated, personal...)
          o.bad.style.display = ex ? 'block' : 'none';
          o.bad.textContent = ex ? '\u2716 ' + ex : '';
        }
        // browser blocks submit and shows the reason
        inp.setCustomValidity(pw === '' ? '' : (firstBad || ex || ''));
      }
      inp.addEventListener('input', run);
      ['student_id', 'first_name', 'last_name', 'email'].forEach(function (n) {
        var el = document.querySelector('[name="' + n + '"]');
        if (el && el !== inp) el.addEventListener('input', run);
      });
      run();
    }
  };
})(window);
