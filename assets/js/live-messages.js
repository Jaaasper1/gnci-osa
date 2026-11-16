// Live messages: quietly polls for new replies (no page refresh). Pauses while the tab is hidden.
(function () {
  var C = window.LIVE_MSG; if (!C) return;
  var thread = document.getElementById('msgThread');
  var delay = C.interval || 5000, errs = 0, timer = null, busy = false, sig = null, after = C.after || 0;
  var MAX = 60000;

  function nearBottom() { return !thread || (thread.scrollHeight - thread.scrollTop - thread.clientHeight) < 100; }

  function setBadges(n) {
    document.querySelectorAll('.sitem[href$="messages.php"]').forEach(function (a) {
      var b = a.querySelector('.badge-count');
      if (n > 0) { if (!b) { b = document.createElement('span'); b.className = 'badge-count'; a.appendChild(b); } b.textContent = n; }
      else if (b) b.remove();
    });
    var tab = document.querySelector('a[href*="filter=unread"]');
    if (tab) tab.textContent = tab.textContent.replace(/\(\s*\d+\s*\)/, '(' + n + ')');
  }

  function refreshList() {
    var box = document.getElementById('msgList'), q = document.getElementById('msgSearch');
    if (!box || (q && q.value.trim())) return;               // don't disturb an active search
    if (box.querySelector('.msgm-check:checked')) return;    // don't disturb bulk selection
    fetch('messages.php?ajax=1&filter=' + encodeURIComponent(C.filter || 'open') + '&q=', { credentials: 'same-origin' })
      .then(function (r) { return r.headers.get('X-Msg-List') === '1' ? r.text() : null; })
      .then(function (h) { if (h !== null) box.innerHTML = h; })
      .catch(function () {});
  }

  function tick() {
    if (busy || document.hidden) return schedule();
    busy = true;
    fetch('messages.php?poll=1&id=' + (C.id || 0) + '&after=' + after, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw 0; return r.json(); })
      .then(function (d) {
        errs = 0; delay = C.interval || 5000;
        if (!d.ok) return;
        if (d.n > 0 && thread) {
          var stick = nearBottom(), tmp = document.createElement('div'); tmp.innerHTML = d.html;
          while (tmp.firstChild) {
            var el = tmp.firstChild;
            if (el.nodeType === 1 && el.dataset.rid && thread.querySelector('[data-rid="' + el.dataset.rid + '"]')) { tmp.removeChild(el); continue; }
            thread.appendChild(el);
          }
          after = d.last;
          if (stick) thread.scrollTop = thread.scrollHeight;
        }
        if (C.role === 'staff') {
          setBadges(d.unread);
          if (sig !== null && d.sig !== sig) refreshList();
          sig = d.sig;
        }
      })
      .catch(function () { errs++; delay = Math.min(MAX, (C.interval || 5000) * Math.pow(2, errs)); })
      .then(function () { busy = false; schedule(); });
  }

  function schedule() { clearTimeout(timer); timer = setTimeout(tick, delay); }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { clearTimeout(timer); tick(); } });
  schedule();
})();
