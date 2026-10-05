<?php
/**
 * Shared live-search bar for admin list tables (candidates.php, party-lists.php).
 *
 * Usage:
 *   renderListSearch('cand-table', 'Search name, position, party...', [
 *       ['label' => 'All positions', 'attr' => 'data-position', 'values' => ['President', ...]],
 *   ]);
 *
 * The <table id="..."> rows need a data-search="lowercase searchable text" attribute
 * (plus any data-xxx attribute used by a filter). Typing filters instantly;
 * every word typed must match the START of a word (e.g. "pres bsit" finds BSIT presidents).
 */
function renderListSearch(string $tableId, string $placeholder, array $filters = []): void {
    $id = preg_replace('/[^a-z0-9_-]/i', '', $tableId);
    ?>
    <div class="ls-bar" id="ls-bar-<?= $id ?>">
        <div class="ls-box">
            <i class="ti ti-search"></i>
            <input type="text" id="ls-q-<?= $id ?>" placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES) ?>" autocomplete="off">
            <button type="button" class="ls-clear" id="ls-clear-<?= $id ?>" title="Clear" style="display:none;"><i class="ti ti-x"></i></button>
        </div>
        <?php foreach ($filters as $i => $f): ?>
        <select class="ls-filter" id="ls-f<?= $i ?>-<?= $id ?>" data-attr="<?= htmlspecialchars($f['attr'], ENT_QUOTES) ?>">
            <option value=""><?= htmlspecialchars($f['label'], ENT_QUOTES) ?></option>
            <?php foreach ($f['values'] as $v): ?>
            <option value="<?= htmlspecialchars(strtolower($v), ENT_QUOTES) ?>"><?= htmlspecialchars($v, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endforeach; ?>
        <span class="ls-count" id="ls-count-<?= $id ?>"></span>
    </div>

    <style>
    .ls-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 12px; }
    .ls-box { position:relative; flex:1; min-width:220px; max-width:340px; }
    .ls-box > i.ti-search { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#9CA3AF; font-size:15px; pointer-events:none; }
    .ls-box input { width:100%; box-sizing:border-box; padding:8px 30px 8px 32px; border:1px solid #E5E7EB; border-radius:8px; font-size:13px; background:#fff; }
    .ls-box input:focus, .ls-filter:focus { outline:none; border-color:#8C2438; box-shadow:0 0 0 3px rgba(107,26,42,.12); }
    .ls-clear { position:absolute; right:6px; top:50%; transform:translateY(-50%); border:0; background:none; color:#9CA3AF; cursor:pointer; font-size:15px; padding:4px; line-height:1; }
    .ls-clear:hover { color:#6B1A2A; }
    .ls-filter { padding:8px 10px; border:1px solid #E5E7EB; border-radius:8px; font-size:13px; background:#fff; max-width:200px; }
    .ls-count { font-size:12px; color:#6B7280; }
    .ls-none td { text-align:center; color:#9CA3AF; padding:24px !important; font-size:13px; }
    </style>
    <script>
    // The table is rendered AFTER this bar, so wait until the page is parsed
    // before looking for it.
    (function () {
      function initListSearch() {
        var tid = <?= json_encode($id) ?>;
        var table = document.getElementById(tid);
        if (!table) return;
        var q = document.getElementById('ls-q-' + tid),
            clear = document.getElementById('ls-clear-' + tid),
            count = document.getElementById('ls-count-' + tid),
            filters = document.querySelectorAll('#ls-bar-' + tid + ' .ls-filter'),
            tbody = table.tBodies[0],
            rows = Array.prototype.slice.call(tbody.rows),
            cols = rows.length ? rows[0].cells.length : 1,
            noneRow = null;

        // Lowercase, strip accents, turn punctuation (- . , ') into spaces so
        // "Vice-President" becomes the words "vice" and "president".
        function norm(s) {
            return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, ' ').trim();
        }
        // Padded with spaces so a search word must match the START of a word:
        // "ian" finds "Ian Reyes" but not "Alliance".
        var haystack = rows.map(function (r) { return ' ' + norm(r.getAttribute('data-search')) + ' '; });

        function apply() {
            var tokens = norm(q.value).split(' ').filter(Boolean);
            var shown = 0;
            rows.forEach(function (r, i) {
                var ok = tokens.every(function (t) { return haystack[i].indexOf(' ' + t) !== -1; });
                if (ok && table._ttFilters) {           // header filters (table-tools.js)
                    for (var k in table._ttFilters) {
                        if ((r.getAttribute(k) || '') !== table._ttFilters[k]) { ok = false; break; }
                    }
                }
                if (ok) {
                    for (var f = 0; f < filters.length; f++) {
                        var want = filters[f].value;
                        if (want && (r.getAttribute(filters[f].getAttribute('data-attr')) || '') !== want) { ok = false; break; }
                    }
                }
                r.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            var filtering = tokens.length > 0 || Array.prototype.some.call(filters, function (f) { return f.value; })
                || Object.keys(table._ttFilters || {}).length > 0;
            count.textContent = filtering ? 'Showing ' + shown + ' of ' + rows.length : rows.length + ' total';
            clear.style.display = q.value ? '' : 'none';
            if (!shown) {
                if (!noneRow) {
                    noneRow = document.createElement('tr');
                    noneRow.className = 'ls-none';
                    noneRow.innerHTML = '<td colspan="' + cols + '">No results match your search.</td>';
                    tbody.appendChild(noneRow);
                }
                noneRow.style.display = '';
            } else if (noneRow) {
                noneRow.style.display = 'none';
            }
        }

        table._ttApply = apply;   // lets header filters re-run the search/filter pass
        var typingTimer;
        q.addEventListener('input', function () {   // wait for a short pause in typing instead of filtering on every key
            clearTimeout(typingTimer);
            typingTimer = setTimeout(apply, rows.length > 300 ? 180 : 60);
        });
        filters.forEach(function (f) { f.addEventListener('change', apply); });
        clear.addEventListener('click', function () { q.value = ''; q.focus(); apply(); });
        q.addEventListener('keydown', function (e) { if (e.key === 'Escape') { q.value = ''; apply(); } });
        apply();
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initListSearch);
      else initListSearch();
    })();
    </script>
    <?php
}
