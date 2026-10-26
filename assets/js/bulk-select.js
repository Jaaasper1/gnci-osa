window.__t = window.__t || function (s) { return s; };
/*
 * BulkSelect - multi-select for tables and card lists, modelled on the Notifications page:
 *   - checkboxes are hidden until you start selecting: press "Select", or press-and-hold a row
 *     (long-press on touch, click-and-hold with a mouse), then tick / tap rows
 *   - a sticky bar shows "All" (with indeterminate state), "N selected", the actions and Cancel
 *   - selected rows are highlighted; Esc cancels
 *
 * BulkSelect.init({
 *   rows:    'tr[data-bulk-id]',          // selectable rows/cards (each carries data-bulk-id)
 *   before:  '#some-element',             // the bar is inserted before this element
 *   noun:    'candidate',                 // "3 candidates selected"
 *   mount:   function(row){ return el },  // optional: where the checkbox goes (default: start of first <td>)
 *   actions: [{ label, icon, danger,
 *               confirm, reason, run(id, reason) -> Promise<bool>,      // one request per id
 *               batch(ids, done(okCount, failCount, message)) }],       // or: brings its own confirmation (OTP)
 *   onDone:  function(){ location.reload(); }
 * });
 *
 * Nothing is sent to the server except by the actions themselves, so every existing
 * server-side permission / CSRF / OTP check still applies.
 */
(function () {
  var css = '' +
    '.bs-wrap{--bs-acc:var(--maroon,#6B1A2A);--bs-pale:var(--maroon-pale,#F9EEF0);margin:0 0 8px}' +
    '.bs-head{display:flex;justify-content:flex-end;margin-bottom:8px}' +
    '.bs-select{font-size:13px;color:#4B5563;background:#fff;border:1px solid #D1D5DB;border-radius:8px;padding:6px 14px;cursor:pointer;display:inline-flex;align-items:center;gap:5px}' +
    '.bs-select:hover{background:#F3F4F6}' +
    '.bs-bar{display:none;align-items:center;gap:8px;flex-wrap:wrap;position:sticky;top:var(--topnav-height,56px);z-index:20;background:#fff;padding:8px 0 10px;margin-bottom:6px;border-bottom:1px solid #F3F4F6}' +
    '.bs-wrap.bs-on .bs-bar{display:flex}.bs-wrap.bs-on .bs-head{display:none}' +
    '.bs-bar label{display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;cursor:pointer;margin:0}' +
    '.bs-bar input{width:18px;height:18px;accent-color:var(--bs-acc)}' +
    '.bs-count{font-size:13px;font-weight:600;color:#1F2937;margin-right:auto}' +
    '.bs-bar button{font-size:13px;border-radius:8px;padding:6px 12px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;background:#fff;color:#374151;border:1px solid #D1D5DB}' +
    '.bs-bar button:disabled{opacity:.45;cursor:not-allowed}.bs-bar button:not(:disabled):hover{background:#F3F4F6}' +
    '.bs-bar .bs-danger{color:#EF4444;border-color:#FECACA;background:#FEF2F2}.bs-bar .bs-danger:not(:disabled):hover{background:#FEE2E2}' +
    '@media (max-width:560px){.bs-bar .bs-lbl{display:none}.bs-bar button{padding:8px 11px}}' +
    /* row checkbox: hidden until selecting */
    '.bs-rowcb{display:none;flex-shrink:0;width:18px;height:18px;margin:0 10px 0 0;vertical-align:middle;accent-color:var(--bs-acc,#6B1A2A);cursor:pointer}' +
    '.bs-mode .bs-rowcb{display:inline-block}' +
    '.bs-card-cb{position:absolute;top:10px;left:10px;z-index:5;margin:0;width:22px;height:22px;background:#fff;border-radius:4px}' +
    '.bs-sel{background:var(--maroon-pale,#F9EEF0) !important;outline:2px solid var(--maroon,#6B1A2A);outline-offset:-2px}' +
    '.bs-mode{cursor:pointer;-webkit-user-select:none;user-select:none}' +
    '.bs-mode a,.bs-mode button:not(.bs-rowcb){pointer-events:none;opacity:.5}' +
    '.bs-row{-webkit-touch-callout:none}' +
    /* dialog */
    '.bs-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px}' +
    '.bs-modal{background:#fff;border-radius:14px;max-width:420px;width:100%;padding:22px;box-shadow:0 20px 50px rgba(0,0,0,.3)}' +
    '.bs-modal h3{margin:0 0 8px;font-size:17px;color:#111827}.bs-modal p{margin:0 0 12px;color:#4B5563;font-size:14px;line-height:1.5}' +
    '.bs-modal textarea{width:100%;min-height:80px;border:1.5px solid #8A8F98;border-radius:8px;padding:10px;font:inherit;font-size:14px;box-sizing:border-box;margin-bottom:6px}' +
    '.bs-modal .bs-err{color:#DC2626;font-size:12px;min-height:16px;margin-bottom:6px}' +
    '.bs-modal .bs-actions{display:flex;gap:8px;justify-content:flex-end}' +
    '.bs-mbtn{border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid #6B1A2A;background:#6B1A2A;color:#fff}' +
    '.bs-mbtn.bs-danger{background:#DC2626;border-color:#DC2626}.bs-mbtn.bs-ghost{background:#fff;color:#374151;border-color:#D1D5DB}';
  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);

  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

  // Registry of selection instances, so export links can pick up the ticked rows.
  var registry = [];

  function dialog(opts) {
    return new Promise(function (resolve) {
      var bg = document.createElement('div');
      bg.className = 'bs-modal-bg';
      bg.innerHTML = '<div class="bs-modal" role="dialog" aria-modal="true">' +
        '<h3>' + esc(opts.title) + '</h3><p>' + esc(opts.message) + '</p>' +
        (opts.reason ? '<textarea maxlength="500" placeholder="Reason (required)"></textarea><div class="bs-err"></div>' : '') +
        '<div class="bs-actions"><button type="button" class="bs-mbtn bs-ghost" data-no>Cancel</button>' +
        '<button type="button" class="bs-mbtn ' + (opts.danger ? 'bs-danger' : '') + '" data-yes>' + esc(opts.okLabel) + '</button></div></div>';
      document.body.appendChild(bg);
      var ta = bg.querySelector('textarea');
      if (ta) ta.focus();
      function close(v) { bg.remove(); document.removeEventListener('keydown', onKey, true); resolve(v); }
      function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(null); } }
      document.addEventListener('keydown', onKey, true);
      bg.querySelector('[data-no]').onclick = function () { close(null); };
      bg.addEventListener('click', function (e) { if (e.target === bg) close(null); });
      bg.querySelector('[data-yes]').onclick = function () {
        if (ta) {
          var v = ta.value.trim();
          if (!v) { bg.querySelector('.bs-err').textContent = __t('Please enter a reason.'); return; }
          close({ reason: v });
        } else close({});
      };
    });
  }

  function init(cfg) {
    var anchor = typeof cfg.before === 'string' ? document.querySelector(cfg.before) : cfg.before;
    if (!anchor) return;
    var noun = cfg.noun || 'item';
    var sel = {};             // id -> true
    registry.push({ type: cfg.exportType || null, ids: function () { return Object.keys(sel); } });
    var selecting = false, autoExit = false, suppressClick = false, busy = false;

    var wrap = document.createElement('div');
    wrap.className = 'bs-wrap';
    wrap.innerHTML =
      '<div class="bs-head"><button type="button" class="bs-select"><i class="ti ti-checkbox"></i> Select</button></div>' +
      '<div class="bs-bar">' +
        '<label><input type="checkbox" class="bs-all"> <span class="bs-lbl">All</span></label>' +
        '<span class="bs-count">0 selected</span><span class="bs-actions-host"></span>' +
        '<button type="button" class="bs-cancel" title=__t("Cancel")><i class="ti ti-x"></i><span class="bs-lbl">Cancel</span></button>' +
      '</div>';
    anchor.parentNode.insertBefore(wrap, anchor);

    var host = wrap.querySelector('.bs-actions-host');
    var countEl = wrap.querySelector('.bs-count');
    var allCb = wrap.querySelector('.bs-all');
    var btns = [];
    (cfg.actions || []).forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button';
      if (a.danger) b.className = 'bs-danger';
      b.title = a.label;
      b.innerHTML = (a.icon ? '<i class="ti ' + a.icon + '"></i>' : '') + '<span class="bs-lbl">' + esc(a.label) + '</span>';
      b.disabled = true;
      b.onclick = function () { runAction(a); };
      host.appendChild(b);
      btns.push(b);
    });

    function rowsAll() { return Array.prototype.slice.call(document.querySelectorAll(cfg.rows)); }
    function rowsVisible() { return rowsAll().filter(function (r) { return r.getClientRects().length > 0; }); }
    function idOf(r) { return String(r.getAttribute('data-bulk-id')); }

    function mountFor(row) {
      if (cfg.mount) return cfg.mount(row);
      var td = row.querySelector(':scope > td');
      return td || row;
    }

    function ensureCb(row) {
      row.classList.add('bs-row');
      if (!row.querySelector(':scope .bs-rowcb')) {
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'bs-rowcb' + (cfg.mount ? ' bs-card-cb' : '');
        cb.setAttribute('aria-label', 'Select');
        cb.onclick = function (e) { e.stopPropagation(); };
        cb.onchange = function () { toggle(idOf(row), cb.checked); };
        var m = mountFor(row);
        m.insertBefore(cb, m.firstChild);
      }
      // re-applied after the list re-renders (live search / sort / paging)
      var on = !!sel[idOf(row)];
      row.classList.toggle('bs-sel', on);
      row.classList.toggle('bs-mode', selecting);
      var c = row.querySelector('.bs-rowcb'); if (c) c.checked = on;
    }

    function render() {
      var rows = rowsAll();
      rows.forEach(function (r) {
        var on = !!sel[idOf(r)];
        r.classList.toggle('bs-sel', on);
        r.classList.toggle('bs-mode', selecting);
        var cb = r.querySelector('.bs-rowcb'); if (cb) cb.checked = on;
      });
      var vis = rowsVisible();
      var n = Object.keys(sel).length;
      countEl.textContent = n + ' selected';
      var nVis = vis.filter(function (r) { return sel[idOf(r)]; }).length;
      allCb.checked = vis.length > 0 && nVis === vis.length;
      allCb.indeterminate = nVis > 0 && nVis < vis.length;
      btns.forEach(function (b) { b.disabled = busy || n === 0; });
      wrap.classList.toggle('bs-on', selecting);
    }

    function enter(auto) { selecting = true; autoExit = !!auto; render(); }
    function exit() { selecting = false; autoExit = false; sel = {}; render(); }
    function toggle(id, force) {
      id = String(id);
      var on = typeof force === 'boolean' ? force : !sel[id];
      if (on) sel[id] = true; else delete sel[id];
      if (!selecting) enter(false);
      if (selecting && autoExit && !Object.keys(sel).length) { exit(); return; }
      render();
    }

    wrap.querySelector('.bs-select').onclick = function () { enter(false); };
    wrap.querySelector('.bs-cancel').onclick = function () { if (!busy) exit(); };
    allCb.onchange = function () {
      rowsVisible().forEach(function (r) { if (allCb.checked) sel[idOf(r)] = true; else delete sel[idOf(r)]; });
      if (!selecting) enter(false);
      if (autoExit && !Object.keys(sel).length) { exit(); return; }
      render();
    };

    var scope = anchor.parentNode;

    // In select mode a tap anywhere on a row toggles it (links/buttons inside are inert).
    scope.addEventListener('click', function (e) {
      var row = e.target.closest && e.target.closest(cfg.rows);
      if (suppressClick) { suppressClick = false; if (row) { e.preventDefault(); e.stopPropagation(); } return; }
      if (!row || !selecting) return;
      if (e.target.closest('.bs-rowcb')) return;
      e.preventDefault(); e.stopPropagation();
      toggle(idOf(row));
    }, true);

    // Long-press (touch / pen) starts selecting
    (function () {
      var timer = null, sx = 0, sy = 0;
      function cancel() { if (timer) { clearTimeout(timer); timer = null; } }
      scope.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        var row = e.target.closest && e.target.closest(cfg.rows);
        if (!row || e.target.closest('button, input, a, select, textarea')) return;
        sx = e.clientX; sy = e.clientY; cancel();
        timer = setTimeout(function () {
          timer = null; suppressClick = true;
          if (navigator.vibrate) { try { navigator.vibrate(30); } catch (_) {} }
          try { var gs = window.getSelection && window.getSelection(); if (gs) gs.removeAllRanges(); } catch (_) {}
          if (!selecting) enter(true);
          toggle(idOf(row), true);
        }, 450);
      });
      scope.addEventListener('pointermove', function (e) {
        if (timer && (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10)) cancel();
      });
      ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { scope.addEventListener(ev, cancel); });
      scope.addEventListener('contextmenu', function (e) {
        if (e.target.closest && e.target.closest(cfg.rows) && ('ontouchstart' in window || suppressClick)) e.preventDefault();
      });
    })();

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && selecting && !busy) exit(); });

    // Rows are re-rendered by search / filter / paging / live refresh: give new rows a checkbox
    var mo = new MutationObserver(function () {
      var changed = false;
      rowsAll().forEach(function (r) { if (!r.querySelector('.bs-rowcb')) { ensureCb(r); changed = true; } });
      if (changed) render();
    });
    mo.observe(scope, { childList: true, subtree: true });

    rowsAll().forEach(ensureCb);
    render();

    function runAction(a) {
      var ids = Object.keys(sel);
      if (!ids.length || busy) return;
      var n = ids.length;
      function finish(okCount, failCount, message) {
        var text = message || (okCount + ' of ' + n + ' done.');
        if (typeof window.showToast === 'function') window.showToast(text, failCount ? 'error' : 'success');
        else alert(text);
        setTimeout(function () { (cfg.onDone || function () { location.reload(); })(); }, 900);
      }
      if (typeof a.batch === 'function') {
        // The action brings its own confirmation (e.g. an emailed code) and runs once for the whole selection.
        a.batch(ids, finish);
        return;
      }
      var msg = typeof a.confirm === 'function' ? a.confirm(n) : (a.confirm || (__t('Apply to ') + n + ' ' + noun + (n === 1 ? '' : 's') + '?'));
      dialog({ title: a.label + ' (' + n + ')', message: msg, reason: !!a.reason, danger: !!a.danger, okLabel: a.label })
        .then(function (res) {
          if (!res) return;
          busy = true; render();
          var done = 0, failed = 0, chain = Promise.resolve();
          ids.forEach(function (id) {
            chain = chain.then(function () {
              countEl.textContent = 'Working... ' + (done + 1) + ' / ' + n;
              return Promise.resolve(a.run(id, res.reason)).then(function (ok) { if (ok === false) failed++; })
                .catch(function () { failed++; }).then(function () { done++; });
            });
          });
          chain.then(function () {
            busy = false;
            finish(n - failed, failed, (n - failed) + ' of ' + n + ' done.' + (failed ? ' ' + failed + ' failed or were skipped.' : ''));
          });
        });
    }
  }

  var crcTable = null;
  function crc32(str) {
    if (!crcTable) {
      crcTable = [];
      for (var n = 0; n < 256; n++) { var c = n; for (var k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; crcTable[n] = c >>> 0; }
    }
    var crc = 0xFFFFFFFF;
    for (var i = 0; i < str.length; i++) crc = crcTable[(crc ^ str.charCodeAt(i)) & 0xFF] ^ (crc >>> 8);
    return (crc ^ 0xFFFFFFFF) >>> 0;
  }
  // Same fingerprint as adminOtpBatchTarget() in includes/admin-otp.php: crc32(sorted unique ids joined by ",") + 1
  function batchTarget(ids) {
    var list = ids.map(Number).filter(function (v) { return v > 0; });
    list = list.filter(function (v, i) { return list.indexOf(v) === i; }).sort(function (a, b) { return a - b; });
    return crc32(list.join(',')) + 1;
  }

  // Ids ticked in the table that feeds the given export type (e.g. 'students').
  function selectedFor(type) {
    var out = [];
    registry.forEach(function (r) { if (r.type === type) out = out.concat(r.ids()); });
    return out.filter(function (v, i) { return out.indexOf(v) === i; });
  }

  window.BulkSelect = { init: init, batchTarget: batchTarget, selectedFor: selectedFor };
})();
