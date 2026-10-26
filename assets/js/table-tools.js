window.__t = window.__t || function (s) { return s; };
/* ============================================================
   GNC-OSA PORTAL — Table Tools (sorting + dynamic search)
   ------------------------------------------------------------
   Use on any <table>:

     <table data-tt>                       → click-header sorting
     <table data-tt data-tt-search>        → sorting + live search box
     <table data-tt data-tt-search="Search complaints...">
     <table data-tt data-tt-server>        → server-side sort (paginated)

   <th data-nosort> = no sorting on that column.
   <td data-sort="123"> = custom value used for sorting.
   ============================================================ */
(function () {
    'use strict';

    // ── Styles (injected here, no need to touch style.css) ──
    var css = ''
    + '.tt-th{cursor:pointer;user-select:none;white-space:nowrap;}'
    + '.tt-th:hover{background:rgba(0,0,0,.04);}'
    + '.tt-caret{font-size:12px;color:#9CA3AF;margin-left:3px;}'
    + '.tt-th.tt-sorted .tt-caret{color:#6B1A2A;}'
    + '#tt-menu{display:none;position:fixed;min-width:190px;padding:4px 0;background:#fff;border:1px solid #C8C8C8;border-radius:4px;box-shadow:0 6px 18px rgba(0,0,0,.18);z-index:9999;}'
    + '#tt-menu.open{display:block;}'
    + '#tt-menu .tt-item{display:flex;align-items:center;gap:10px;padding:8px 14px;font-size:13px;color:#1F2937;cursor:pointer;}'
    + '#tt-menu .tt-item i:first-child{font-size:17px;color:#C9943A;}'
    + '#tt-menu .tt-item:hover{background:#E5F1FF;}'
    + '#tt-menu .tt-item.selected{background:#CCE4FF;font-weight:600;}'
    + '#tt-menu .tt-check{margin-left:auto;font-size:15px;color:#6B1A2A;}'
    + '#tt-menu .tt-reset{border-top:1px solid #E5E7EB;margin-top:4px;padding-top:8px;color:#6B7280;}'
    + '.tt-search{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px;}'
    + '.tt-search-box{position:relative;flex:1;min-width:200px;max-width:320px;}'
    + '.tt-search-box>i.ti-search{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:15px;pointer-events:none;}'
    + '.tt-search-box input{width:100%;box-sizing:border-box;padding:8px 30px 8px 32px;border:1px solid #E5E7EB;border-radius:8px;font-size:13px;background:#fff;}'
    + '.tt-search-box input:focus{outline:none;border-color:#8C2438;box-shadow:0 0 0 3px rgba(107,26,42,.12);}'
    + '.tt-clear{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:#9CA3AF;cursor:pointer;font-size:15px;padding:4px;display:none;line-height:1;}'
    + '.tt-clear:hover{color:#6B1A2A;}'
    + '.tt-count{font-size:12px;color:#6B7280;}'
    + '.tt-empty td{text-align:center;color:#9CA3AF;padding:24px !important;font-size:13px;}'
    + '.tt-th.tt-filtered{background:rgba(107,26,42,.06);}'
    + '.tt-badge{display:inline-block;margin-left:6px;padding:1px 7px;font-size:11px;font-weight:600;color:#6B1A2A;background:#F3E3C8;border-radius:10px;vertical-align:middle;}'
    + '#tt-menu.win-menu{padding:6px;border:1px solid #E5E5E5;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.16);font-family:"Segoe UI Variable","Segoe UI",system-ui,sans-serif;}'
    + '#tt-menu.win-menu .tt-item{position:relative;padding:7px 10px;border-radius:4px;}'
    + '#tt-menu.win-menu .tt-item i:first-child{font-size:16px;color:#374151;}'
    + '#tt-menu.win-menu .tt-item:hover,#tt-menu.win-menu .tt-item.selected{background:#F0F0F0;}'
    + '#tt-menu.win-menu .tt-item.disabled{opacity:.4;cursor:default;pointer-events:none;}'
    + '#tt-menu .tt-divider{height:1px;margin:4px 2px;background:#E5E7EB;}'
    + '#tt-menu .tt-arrow{margin-left:auto;font-size:14px;color:#6B7280;}'
    + '#tt-menu .tt-sub{display:none;position:absolute;top:-7px;left:100%;margin-left:2px;min-width:210px;padding:6px;background:#fff;border:1px solid #E5E5E5;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.16);z-index:10000;}'
    + '#tt-menu .tt-sub.flip{left:auto;right:100%;margin-left:0;margin-right:2px;}'
    + '#tt-menu .has-sub:hover>.tt-sub,#tt-menu .has-sub.show>.tt-sub{display:block;}'
    + '#tt-menu .tt-year-row{display:flex;align-items:center;justify-content:space-between;padding:2px 4px 8px;}'
    + '#tt-menu .tt-year-label{font-size:15px;font-weight:700;color:#1F2937;}'
    + '#tt-menu .tt-year-row button{width:28px;height:28px;display:flex;align-items:center;justify-content:center;border:1px solid #D1D5DB;border-radius:6px;background:#fff;color:#374151;cursor:pointer;}'
    + '#tt-menu .tt-year-row button:hover:not(:disabled){background:#E5F1FF;}'
    + '#tt-menu .tt-year-row button:disabled{color:#D1D5DB;cursor:default;}'
    + '#tt-menu .tt-month-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:0 4px 2px;}'
    + '#tt-menu .tt-month{padding:7px 0;font-size:13px;text-align:center;color:#1F2937;background:#fff;border:1px solid #E5E7EB;border-radius:6px;cursor:pointer;}'
    + '#tt-menu .tt-month:hover{background:#E5F1FF;}'
    + '#tt-menu .tt-month.empty{color:#9CA3AF;}'
    + '#tt-menu .tt-month.inrange{background:#F6E6EA;border-color:#E5C9CF;color:#6B1A2A;}'
    + '#tt-menu .tt-month.selected{background:#6B1A2A;border-color:#6B1A2A;color:#fff;font-weight:600;}'
    + '#tt-menu .tt-hint{padding:8px 6px 2px;font-size:11px;color:#6B7280;text-align:center;}';
    var st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);

    // ── Helpers ──────────────────────────────────────────────────────────
    function norm(s) {
        return String(s == null ? '' : s).toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ').trim();
    }
    function cellText(cell) {
        if (!cell) return '';
        var d = cell.getAttribute('data-sort');
        if (d !== null) return d;
        return cell.textContent.replace(/\s+/g, ' ').trim();
    }
    function dataRows(table) {
        var out = [];
        for (var b = 0; b < table.tBodies.length; b++) {
            var trs = table.tBodies[b].rows;
            for (var i = 0; i < trs.length; i++) {
                var tr = trs[i];
                if (tr.classList.contains('tt-empty')) continue;
                if (tr.cells.length < 2) continue; // empty-state row (isang colspan cell)
                out.push(tr);
            }
        }
        return out;
    }

    // Auto-detect ng type ng column: num | date | text
    var DATE_RE = /^(?:[A-Za-z]{3,9}\.? \d{1,2}, \d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{2,4})/;
    var NUM_RE  = /^[#₱$]?\s*-?\d[\d,]*(?:\.\d+)?\s*%?$/;
    function detectType(values) {
        var v = values.filter(function (x) { return x !== ''; });
        if (!v.length) return 'text';
        if (v.every(function (x) { return NUM_RE.test(x); })) return 'num';
        if (v.every(function (x) { return DATE_RE.test(x); })) return 'date';
        return 'text';
    }
    function toDate(s) {
        if (/^\d{9,11}$/.test(s)) return parseInt(s, 10) * 1000;   // data-sort holds a unix timestamp
        var m = s.match(DATE_RE);
        if (!m) return 0;
        var t = Date.parse(m[0]);
        if (isNaN(t)) return 0;
        var tm = s.slice(m[0].length).match(/(\d{1,2}):(\d{2})\s*(am|pm)?/i);
        if (tm) {
            var h = parseInt(tm[1], 10) % 12;
            if (tm[3] && tm[3].toLowerCase() === 'pm') h += 12;
            if (!tm[3]) h = parseInt(tm[1], 10);
            t += h * 3600000 + parseInt(tm[2], 10) * 60000;
        }
        return t;
    }
    function toNum(s) { return parseFloat(s.replace(/[^\d.\-]/g, '')) || 0; }

    var LABELS = {
        num:  [__t('Lowest first'), __t('Highest first')],
        text: ['A to Z', 'Z to A'],
        date: [__t('Oldest first'), __t('Newest first')],
        voting: [__t('Not voted first'), __t('Voted first')]
    };

    var COLLATOR = (window.Intl && Intl.Collator) ? new Intl.Collator(undefined, { numeric: true }) : null;
    function cmpText(a, b) { return COLLATOR ? COLLATOR.compare(a, b) : String(a).localeCompare(String(b)); }

    // Put rows back in a new order in ONE go: detach the tbody, append through a fragment, re-attach.
    // (Appending rows one by one into the live table forces a re-layout every time.)
    function reorderRows(rows) {
        if (!rows.length) return;
        var body = rows[0].parentNode, table = body.parentNode, next = body.nextSibling;
        var frag = document.createDocumentFragment();
        for (var i = 0; i < rows.length; i++) frag.appendChild(rows[i]);
        table.removeChild(body);
        body.appendChild(frag);
        table.insertBefore(body, next);
    }

    var MONTHS = [__t('January'),__t('February'),__t('March'),__t('April'),__t('May'),__t('June'),__t('July'),__t('August'),__t('September'),__t('October'),__t('November'),__t('December')];

    // ── Sorting menu (a single menu for the whole page) ──────────────
    var menu = document.createElement('div');
    menu.id = 'tt-menu';
    menu.setAttribute('role', 'menu');
    var menuTh = null;

    function closeMenu() { menu.classList.remove('open'); menuTh = null; }

    function isServer(table) { return table.hasAttribute('data-tt-server'); }

    function currentSort(th) {
        var table = th.closest('table');
        if (isServer(table)) {
            return {
                key: table.getAttribute('data-sort-current') || '',
                dir: table.getAttribute('data-sort-dir') || ''
            };
        }
        return { key: table._ttKey || null, dir: table._ttDir || null };
    }

    function thKey(th) {
        return th.getAttribute('data-sort-key') || String(th.cellIndex);
    }

    function columnType(th) {
        var t = th.getAttribute('data-type');
        if (t) return t;
        var table = th.closest('table');
        var idx = th.cellIndex;
        var vals = dataRows(table).map(function (r) { return cellText(r.cells[idx]); });
        return detectType(vals);
    }

    function openMenu(th) {
        if (th.hasAttribute('data-tt-filter') || th.hasAttribute('data-tt-cfilter')) { openListMenu(th); return; }
        if (th.hasAttribute('data-tt-date'))   { openDateMenu(th); return; }
        menu.classList.remove('win-menu');
        var key = thKey(th), type = columnType(th), cur = currentSort(th);
        var l = th.getAttribute('data-tt-labels') ? th.getAttribute('data-tt-labels').split('|') : (LABELS[type] || LABELS.text);
        var items = [['asc', l[0], 'ti-arrow-up'], ['desc', l[1], 'ti-arrow-down']];
        menu.innerHTML = '';
        items.forEach(function (it) {
            var d = document.createElement('div');
            var sel = cur.key === key && cur.dir === it[0];
            d.className = 'tt-item' + (sel ? ' selected' : '');
            d.innerHTML = '<i class="ti ' + it[2] + '"></i><span>' + it[1] + '</span>' + (sel ? '<i class="ti ti-check tt-check"></i>' : '');
            d.addEventListener('click', function (e) {
                e.stopPropagation();
                applySort(th, key, type, it[0]);
                closeMenu();
            });
            menu.appendChild(d);
        });
        if (cur.key) { // reset sort
            var rs = document.createElement('div');
            rs.className = 'tt-item tt-reset';
            rs.innerHTML = '<i class="ti ti-x"></i><span>Clear sort</span>';
            rs.addEventListener('click', function (e) {
                e.stopPropagation();
                applySort(th, '', type, '');
                closeMenu();
            });
            menu.appendChild(rs);
        }
        var r = th.getBoundingClientRect();
        menu.style.top = (r.bottom + 2) + 'px';
        menu.style.left = Math.max(4, Math.min(r.left, window.innerWidth - 200)) + 'px';
        menu.classList.add('open');
        menuTh = th;
    }

    function updateCarets(table, key, dir) {
        var ths = table.querySelectorAll('th.tt-th');
        for (var i = 0; i < ths.length; i++) {
            var th = ths[i];
            var on = key !== '' && thKey(th) === key;
            th.classList.toggle('tt-sorted', on);
            th.setAttribute('aria-sort', on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
            var c = th.querySelector('.tt-caret');
            if (c) c.className = 'ti tt-caret ' + (on ? (dir === 'asc' ? 'ti-arrow-up' : 'ti-arrow-down') : 'ti-chevron-down');
        }
    }

    function applySort(th, key, type, dir) {
        var table = th.closest('table');

        // Server-side (paginated tables): reload the list with the new sort/dir
        if (isServer(table)) {
            go(table, { sort: key, dir: key ? dir : '' });
            return;
        }

        // Client-side
        if (!key) { // clear sort → ibalik sa original na order
            var orig = dataRows(table);
            orig.sort(function (a, b) { return (a._ttI || 0) - (b._ttI || 0); });
            reorderRows(orig);
            table._ttKey = null; table._ttDir = null;
            updateCarets(table, '', '');
            return;
        }
        var idx = th.cellIndex;
        var groupAttr = th.getAttribute('data-tt-group');
        var rows = dataRows(table);
        rows.forEach(function (r, i) { if (r._ttI === undefined) r._ttI = i; }); // stable + original order
        var keyed = rows.map(function (r) {
            var raw = cellText(r.cells[idx]);
            var v = raw === '' ? null : (type === 'num' ? toNum(raw) : type === 'date' ? toDate(raw) : norm(raw));
            return { r: r, v: v, g: groupAttr ? (parseFloat(r.getAttribute(groupAttr)) || 0) : 0 };
        });
        keyed.sort(function (a, b) {
            if (a.g !== b.g) return a.g - b.g;   // groups never mix (e.g. President stays above Vice President)
            if (a.v === null && b.v === null) return a.r._ttI - b.r._ttI;
            if (a.v === null) return 1;   // empty value → always at the end
            if (b.v === null) return -1;
            var c = (typeof a.v === 'number')
                ? a.v - b.v
                : cmpText(String(a.v), String(b.v));
            if (c === 0) return a.r._ttI - b.r._ttI;
            return dir === 'asc' ? c : -c;
        });
        reorderRows(keyed.map(function (k) { return k.r; }));
        table._ttKey = key; table._ttDir = dir;
        updateCarets(table, key, dir);
    }

    // ── Server-side helpers (paginated tables) ───────────────────────────
    // <table data-tt-live="container-id"> → the list is refreshed in place (no full reload)
    var SYNC_KEYS = ['category', 'status', 'sort', 'dir', 'dmonth', 'dyear', 'dmonth_to'];
    var goSeq = 0;
    function urlParams() { return new URLSearchParams(window.location.search); }
    function formOf(table) { return document.getElementById(table.getAttribute('data-tt-form') || ''); }
    function syncForm(table, params) {
        var form = formOf(table);
        if (!form) return;
        SYNC_KEYS.forEach(function (k) {
            var v = params.get(k) || '', el = form.elements[k];
            if (el) { el.value = v; }
            else if (v) {
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = k; h.value = v;
                form.appendChild(h);
            }
        });
    }
    function go(table, changes) {
        var form = formOf(table);
        var params = new URLSearchParams(form ? new FormData(form) : window.location.search);
        Object.keys(changes).forEach(function (k) {
            var v = changes[k];
            if (v === '' || v === null || v === undefined) params.delete(k); else params.set(k, v);
        });
        params.set('page', '1');
        params.delete('p');   // pages that paginate with ?p= (Audit Log) go back to page 1 too
        var url = window.location.pathname + '?' + params.toString();
        var live = document.getElementById(table.getAttribute('data-tt-live') || '');
        if (!live || !window.fetch) { window.location.href = url; return; }

        history.replaceState(null, '', url);
        syncForm(table, params);
        var mine = ++goSeq;
        live.style.opacity = '0.55';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                if (mine !== goSeq) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById(live.id);
                if (!fresh) { window.location.href = url; return; }
                live.innerHTML = fresh.innerHTML;
                live.style.opacity = '';
                init(live);
                live.dispatchEvent(new CustomEvent('tt:updated', { bubbles: true }));
            })
            .catch(function () { window.location.href = url; });
    }

    function addItem(parent, icon, label, selected, onClick) {
        var d = document.createElement('div');
        d.className = 'tt-item' + (selected ? ' selected' : '');
        d.innerHTML = '<i class="ti ' + icon + '"></i><span></span>' + (selected ? '<i class="ti ti-check tt-check"></i>' : '');
        d.querySelector('span').textContent = label;
        d.addEventListener('click', function (e) { e.stopPropagation(); onClick(e); });
        parent.appendChild(d);
        return d;
    }
    function placeMenu(th) {
        var r = th.getBoundingClientRect();
        menu.style.top = (r.bottom + 2) + 'px';
        menu.style.left = Math.max(4, Math.min(r.left, window.innerWidth - 200)) + 'px';
        menu.classList.add('open');
        menuTh = th;
    }
    var hoverTimer = null;
    function cancelClose() { clearTimeout(hoverTimer); }
    function scheduleClose() { cancelClose(); hoverTimer = setTimeout(closeMenu, 250); }

    // List menu: "All ..." + every value (a filter, not A–Z). Used for Category / Status / Position / Party / Course.
    //   data-tt-filter="param"       → server tables (value goes in the URL)
    //   data-tt-cfilter="data-attr"  → client tables (rows are matched on that row attribute)
    // data-tt-options = ["A","B"]  or  [["value","Label"],...]
    function listOptions(th) {
        var raw = [];
        try { raw = JSON.parse(th.getAttribute('data-tt-options') || '[]'); } catch (err) {}
        return raw.map(function (o) { return Array.isArray(o) ? { v: String(o[0]), l: String(o[1]) } : { v: String(o), l: String(o) }; });
    }
    function openListMenu(th) {
        var table = th.closest('table');
        var server = th.hasAttribute('data-tt-filter');
        var key = th.getAttribute(server ? 'data-tt-filter' : 'data-tt-cfilter');
        var cur = server ? (urlParams().get(key) || '') : ((table._ttFilters || {})[key] || '');
        var icon = th.getAttribute('data-tt-icon') || 'ti-tag';
        function choose(v, l) {
            closeMenu();
            if (server) { var c = {}; c[key] = v; go(table, c); }
            else setClientFilter(table, key, v, l);
        }
        menu.innerHTML = '';
        menu.classList.remove('win-menu');
        addItem(menu, 'ti-list', th.getAttribute('data-tt-all') || 'All', cur === '', function () { choose('', ''); });
        listOptions(th).forEach(function (o) {
            addItem(menu, icon, o.l, cur === o.v, function () { choose(o.v, o.l); });
        });
        placeMenu(th);
    }

    // Client-side filter state (works together with list-search.php: it exposes table._ttApply)
    function setClientFilter(table, attr, val, label) {
        table._ttFilters = table._ttFilters || {};
        table._ttFilterLabels = table._ttFilterLabels || {};
        if (val) { table._ttFilters[attr] = val; table._ttFilterLabels[attr] = label; }
        else     { delete table._ttFilters[attr]; delete table._ttFilterLabels[attr]; }
        var ths = table.querySelectorAll('th.tt-th[data-tt-cfilter]');
        for (var i = 0; i < ths.length; i++) {
            var old = ths[i].querySelector('.tt-badge');
            if (old) old.parentNode.removeChild(old);
            filterBadge(ths[i]);
        }
        refilter(table);
    }
    // Client-side date range: rows carry data-ym="YYYY-MM"; table._ttDate = {y, m, mt}
    function rowDateOk(table, r) {
        var d = table._ttDate;
        if (!d || !d.y) return true;
        var p = (r.getAttribute('data-ym') || '').split('-');
        if (!p[0] || p[0] !== String(d.y)) return false;
        var m = parseInt(p[1], 10) || 0, lo = d.m || 1, hi = d.m ? (d.mt || d.m) : 12;
        return m >= lo && m <= hi;
    }
    function refilter(table) {
        if (typeof table._ttApply === 'function') { table._ttApply(); return; }
        var f = table._ttFilters || {}, terms = table._ttTerms || [], rows = dataRows(table), shown = 0;
        rows.forEach(function (r) {
            var ok = Object.keys(f).every(function (k) { return (r.getAttribute(k) || '') === f[k]; }) && rowDateOk(table, r);
            if (ok && terms.length) {
                var hay = (r.hasAttribute('data-search') ? r.getAttribute('data-search') : r.textContent).toLowerCase();
                ok = terms.every(function (w) { return hay.indexOf(w) !== -1; });
            }
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        var body = table.tBodies[0], empty = body.querySelector('.tt-empty');
        if (rows.length && !shown) {
            if (!empty) {
                empty = document.createElement('tr');
                empty.className = 'tt-empty';
                var cols = table.tHead ? table.tHead.rows[table.tHead.rows.length - 1].cells.length : 1;
                empty.innerHTML = '<td colspan="' + cols + '"><i class="ti ti-search-off"></i> No matching results</td>';
                body.appendChild(empty);
            }
        } else if (empty) {
            empty.parentNode.removeChild(empty);
        }
        if (typeof table._ttAfterFilter === 'function') table._ttAfterFilter(shown, rows.length);
    }

    // Date menu (Windows-style): Sort by > / Filter by > (month range) / Clear filter
    function isLive(table) { return table.hasAttribute('data-tt-live'); }
    // Live (server) tables keep the range in the URL; tables that are fully loaded keep it on the table.
    function dateGo(table, ch) {
        if (isLive(table)) { go(table, ch); return; }
        var d = table._ttDate = table._ttDate || {};
        if ('dyear' in ch) d.y = ch.dyear ? String(ch.dyear) : '';
        if ('dmonth' in ch) d.m = parseInt(ch.dmonth, 10) || 0;
        if ('dmonth_to' in ch) d.mt = parseInt(ch.dmonth_to, 10) || 0;
        if (!d.y) { d.m = 0; d.mt = 0; }
        var th = table.querySelector('th[data-tt-date]');
        if (th) {
            th.setAttribute('data-tt-year', d.y || ''); th.setAttribute('data-tt-month', d.m || ''); th.setAttribute('data-tt-month-to', d.mt || '');
            var old = th.querySelector('.tt-badge'); if (old) old.parentNode.removeChild(old);
            filterBadge(th);
        }
        refilter(table);
    }
    function dateState(table) {
        if (table && !isLive(table)) { var d = table._ttDate || {}; return { m: d.m || 0, mt: d.mt || 0, y: d.y || '' }; }
        var p = urlParams();
        return { m: parseInt(p.get('dmonth'), 10) || 0, mt: parseInt(p.get('dmonth_to'), 10) || 0, y: p.get('dyear') || '' };
    }
    function openDateMenu(th) {
        var table = th.closest('table');
        var key = thKey(th), cur = currentSort(th);
        var months = {};
        try { months = JSON.parse(th.getAttribute('data-tt-months') || '{}'); } catch (err) {}
        menu.innerHTML = '';
        menu.classList.add('win-menu');

        function addParent(icon, label) {
            var p = document.createElement('div');
            p.className = 'tt-item has-sub';
            p.innerHTML = '<i class="ti ' + icon + '"></i><span>' + label + '</span><i class="ti ti-chevron-right tt-arrow"></i>';
            var sub = document.createElement('div');
            sub.className = 'tt-sub';
            p.appendChild(sub);
            p.addEventListener('mouseenter', function () {
                sub.classList.remove('flip');
                if (sub.getBoundingClientRect().right > window.innerWidth - 8) sub.classList.add('flip');
            });
            p.addEventListener('click', function (e) {
                if (e.target.closest('.tt-sub')) return;
                e.stopPropagation();
                p.classList.toggle('show');
            });
            menu.appendChild(p);
            return sub;
        }

        // Sort by
        var subSort = addParent('ti-arrows-sort', __t('Sort by'));
        [['asc', __t('Oldest first'), 'ti-arrow-up'], ['desc', __t('Newest first'), 'ti-arrow-down']].forEach(function (it) {
            addItem(subSort, it[2], it[1], cur.key === key && cur.dir === it[0], function () {
                closeMenu(); applySort(th, key, 'date', it[0]);
            });
        });

        // Filter by → month range
        var subFilter = addParent('ti-filter', __t('Filter by'));
        var sec = document.createElement('div');
        sec.className = 'tt-hint';
        sec.style.cssText = 'text-align:left;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:4px 4px 6px;';
        sec.textContent = __t('Month range');
        subFilter.appendChild(sec);

        var years = Object.keys(months).sort(function (a, b) { return parseInt(a, 10) - parseInt(b, 10); });
        if (!years.length) years = [String(new Date().getFullYear())];
        var st0 = dateState(table);
        var viewYear = st0.y || years[years.length - 1];
        if (years.indexOf(viewYear) === -1) {
            years.push(viewYear);
            years.sort(function (a, b) { return parseInt(a, 10) - parseInt(b, 10); });
        }

        var yearRow = document.createElement('div');
        yearRow.className = 'tt-year-row';
        var prevBtn = document.createElement('button'); prevBtn.type = 'button'; prevBtn.innerHTML = '<i class="ti ti-chevron-left"></i>';
        var yearLbl = document.createElement('span');   yearLbl.className = 'tt-year-label';
        var nextBtn = document.createElement('button'); nextBtn.type = 'button'; nextBtn.innerHTML = '<i class="ti ti-chevron-right"></i>';
        yearRow.appendChild(prevBtn); yearRow.appendChild(yearLbl); yearRow.appendChild(nextBtn);

        var grid = document.createElement('div');
        grid.className = 'tt-month-grid';
        var rangePending = false;
        var monthBtns = MONTHS.map(function (name, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'tt-month';
            b.textContent = name.slice(0, 3);
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var n = i + 1, st = dateState(table);
                if (rangePending && st.y === viewYear && st.m) {
                    var lo = Math.min(st.m, n), hi = Math.max(st.m, n);
                    dateGo(table, { dyear: viewYear, dmonth: lo, dmonth_to: hi === lo ? '' : hi }); // 2nd click = end of range
                    rangePending = false;
                } else {
                    dateGo(table, { dyear: viewYear, dmonth: n, dmonth_to: '' });                   // 1st click = start month
                    rangePending = true;
                }
                refresh();
            });
            grid.appendChild(b);
            return b;
        });
        var hint = document.createElement('div');
        hint.className = 'tt-hint';
        subFilter.appendChild(yearRow);
        subFilter.appendChild(grid);
        subFilter.appendChild(hint);

        // Clear filter
        var dv = document.createElement('div');
        dv.className = 'tt-divider';
        menu.appendChild(dv);
        var clearItem = addItem(menu, 'ti-filter-off', __t('Clear filter'), false, function () {
            closeMenu(); dateGo(table, { dmonth: '', dyear: '', dmonth_to: '' });
        });

        function refresh() {
            var st = dateState(table), i = years.indexOf(viewYear);
            yearLbl.textContent = viewYear;
            prevBtn.disabled = i <= 0;
            nextBtn.disabled = i >= years.length - 1;
            var have = {};
            (months[viewYear] || []).forEach(function (m) { have[m] = true; });
            var inYear = st.y === viewYear, end = st.mt || st.m;
            monthBtns.forEach(function (b, idx) {
                var n = idx + 1;
                b.classList.toggle('selected', !!(inYear && st.m && (n === st.m || n === end)));
                b.classList.toggle('inrange', !!(inYear && st.m && n > st.m && n < end));
                b.classList.toggle('empty', !have[n]);
            });
            hint.textContent = rangePending ? 'Now pick the end month' : __t('Pick a start month, then an end month');
            clearItem.classList.toggle('disabled', !(st.m || st.y));
        }
        function stepYear(d) {
            var i = years.indexOf(viewYear) + d;
            if (i < 0 || i >= years.length) return;
            viewYear = years[i];
            var st = dateState(table);
            dateGo(table, { dyear: viewYear, dmonth: st.m || '', dmonth_to: st.mt || '' });
            rangePending = false;
            refresh();
        }
        prevBtn.addEventListener('click', function (e) { e.stopPropagation(); stepYear(-1); });
        nextBtn.addEventListener('click', function (e) { e.stopPropagation(); stepYear(1); });
        refresh();
        placeMenu(th);
    }

    // Small badge in the header showing the active filter (e.g. "Academic", "Feb - Nov 2026")
    function filterBadge(th) {
        var txt = '';
        if (th.hasAttribute('data-tt-cfilter')) {
            var tb = th.closest('table');
            txt = ((tb && tb._ttFilterLabels) || {})[th.getAttribute('data-tt-cfilter')] || '';
        } else if (th.hasAttribute('data-tt-filter')) {
            txt = th.getAttribute('data-tt-value') || '';
        } else if (th.hasAttribute('data-tt-date')) {
            var y = th.getAttribute('data-tt-year') || '';
            var m = parseInt(th.getAttribute('data-tt-month'), 10) || 0;
            var mt = parseInt(th.getAttribute('data-tt-month-to'), 10) || 0;
            if (y) {
                var t = m ? MONTHS[m - 1].slice(0, 3) : '';
                if (mt) t += ' - ' + MONTHS[mt - 1].slice(0, 3);
                txt = (t + ' ' + y).trim();
            }
        }
        th.classList.toggle('tt-filtered', !!txt);
        if (txt) {
            var b = document.createElement('span');
            b.className = 'tt-badge';
            b.textContent = txt;
            th.appendChild(b);
        }
    }

    // ── Header setup ─────────────────────────────────────────────────────
    function setupHeaders(table) {
        if (!table.tHead || !table.tHead.rows.length) return;
        var row = table.tHead.rows[table.tHead.rows.length - 1];
        for (var i = 0; i < row.cells.length; i++) {
            var th = row.cells[i];
            if (th.classList.contains('tt-th')) continue;
            if (th.hasAttribute('data-nosort')) continue;
            if (!th.textContent.trim()) continue;
            th.classList.add('tt-th');
            var c = document.createElement('i');
            c.className = 'ti ti-chevron-down tt-caret';
            th.appendChild(document.createTextNode(' '));
            th.appendChild(c);
            filterBadge(th);
        }
        if (isServer(table)) updateCarets(table, table.getAttribute('data-sort-current') || '', table.getAttribute('data-sort-dir') || '');
    }

    // ── Dynamic search ───────────────────────────────────────────────────
    function setupSearch(table) {
        var attr = table.getAttribute('data-tt-search');
        var wrap = document.createElement('div');
        wrap.className = 'tt-search';
        wrap.innerHTML =
            '<div class="tt-search-box"><i class="ti ti-search"></i>' +
            '<input type="text" autocomplete="off" placeholder="' + (attr || 'Search...').replace(/"/g, '&quot;') + '">' +
            '<button type="button" class="tt-clear" aria-label="Clear search"><i class="ti ti-x"></i></button></div>' +
            '<span class="tt-count"></span>';
        var anchor = table.closest('.table-wrap') || table;
        anchor.parentNode.insertBefore(wrap, anchor);

        var input = wrap.querySelector('input');
        var clear = wrap.querySelector('.tt-clear');
        var count = wrap.querySelector('.tt-count');
        var timer = null;

        function run() {
            var terms = norm(input.value).split(' ').filter(Boolean);
            var rows = dataRows(table), shown = 0;
            rows.forEach(function (r) {
                var hay = norm(r.textContent);
                var ok = terms.every(function (t) { return hay.indexOf(t) !== -1; });
                r.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            clear.style.display = input.value ? 'block' : 'none';
            count.textContent = terms.length ? (__t('Showing') + ' ' + shown + ' ' + __t('of') + ' ' + rows.length) : '';
            var body = table.tBodies[0];
            var empty = body.querySelector('.tt-empty');
            if (terms.length && rows.length && shown === 0) {
                if (!empty) {
                    empty = document.createElement('tr');
                    empty.className = 'tt-empty';
                    var cols = table.tHead ? table.tHead.rows[table.tHead.rows.length - 1].cells.length : 1;
                    empty.innerHTML = '<td colspan="' + cols + '"><i class="ti ti-search-off"></i> No matching results</td>';
                    body.appendChild(empty);
                }
            } else if (empty) {
                empty.parentNode.removeChild(empty);
            }
        }
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 150); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { input.value = ''; run(); }
        });
        clear.addEventListener('click', function () { input.value = ''; run(); input.focus(); });
    }

    // ── Init ─────────────────────────────────────────────────────────────
    function init(root) {
        var tables = (root || document).querySelectorAll('table[data-tt]');
        for (var i = 0; i < tables.length; i++) {
            var t = tables[i];
            if (t._ttReady) continue;
            t._ttReady = true;
            setupHeaders(t);
            if (t.hasAttribute('data-tt-search') && !isServer(t)) setupSearch(t);
        }
    }

    // Delegation — still works even if the table is replaced via AJAX (live search)
    document.addEventListener('click', function (e) {
        var th = e.target.closest ? e.target.closest('th.tt-th') : null;
        if (th) {
            e.stopPropagation();
            if (menu.classList.contains('open') && menuTh === th) { closeMenu(); return; }
            openMenu(th);
            return;
        }
        closeMenu();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
        // "/" to focus the search box
        if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '')) {
            var s = document.querySelector('.tt-search input');
            if (s) { e.preventDefault(); s.focus(); }
        }
    });
    // Date column menu now opens only on click (no hover-open / hover-close)
    menu.addEventListener('click', function (e) { e.stopPropagation(); });
    window.addEventListener('scroll', function () { if (menu.classList.contains('open')) closeMenu(); }, true);
    window.addEventListener('resize', closeMenu);

    document.body ? document.body.appendChild(menu) : document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(menu); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
    else init();

    window.TableTools = { init: init, refilter: refilter };
})();
