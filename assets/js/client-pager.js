window.__t = window.__t || function (s) { return s; };
/* ============================================================
   GNC-OSA PORTAL - Client Pager
   Paginates an already-rendered table or list WITHOUT breaking
   table-tools.js / list-search.php (they hide rows with inline
   style.display = 'none'; this pager only counts the rest).

     <table data-pager="10">                      -> rows of tbody
     <div data-pager="8" data-pager-item=":scope > [data-search]">  -> custom items
   Optional: data-pager-sizes="10,25,50"
   ============================================================ */
(function () {
    'use strict';
    var css = '.pg-off{display:none!important}';
    var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

    function init(box) {
        var per = parseInt(box.getAttribute('data-pager'), 10) || 10;
        var sizes = (box.getAttribute('data-pager-sizes') || '10,25,50').split(',').map(Number);
        if (sizes.indexOf(per) < 0) { sizes.push(per); sizes.sort(function (a, b) { return a - b; }); }
        var itemSel = box.getAttribute('data-pager-item');
        var isTable = box.tagName === 'TABLE';
        var page = 1, sig = '', busy = false;
        var always = box.hasAttribute('data-pager-always'), unit = box.getAttribute('data-pager-unit') || '';

        var bar = document.createElement('div'); bar.className = 'gp-bar';
        var anchor = isTable ? (box.closest('.table-wrap') || box) : box;
        if (isTable) anchor.insertAdjacentElement('afterend', bar); else box.appendChild(bar);

        function items() {
            if (itemSel) return Array.prototype.slice.call(box.querySelectorAll(itemSel));
            return Array.prototype.slice.call(box.querySelectorAll('tbody > tr')).filter(function (r) { return !r.classList.contains('tt-empty'); });
        }

        function render() {
            busy = true;
            var all = items();
            var vis = all.filter(function (el) { return el.style.display !== 'none'; });
            var s = vis.length + ':' + (vis[0] ? (vis[0].getAttribute('data-search') || vis[0].textContent.slice(0, 30)) : '');
            if (s !== sig) { sig = s; page = 1; }
            var pages = Math.max(1, Math.ceil(vis.length / per));
            if (page > pages) page = pages;
            var a = (page - 1) * per, b = a + per;
            all.forEach(function (el) { el.classList.remove('pg-off'); });
            vis.forEach(function (el, i) { if (i < a || i >= b) el.classList.add('pg-off'); });

            if (!vis.length || (!always && vis.length <= Math.min.apply(null, sizes))) { bar.style.display = 'none'; busy = false; return; }
            bar.style.display = '';
            var nav = '<button class="' + (page > 1 ? '' : 'dis') + '" data-go="' + (page - 1) + '">‹ ' + __t('Prev') + '</button>';
            var from = Math.max(1, page - 2), to = Math.min(pages, page + 2);
            if (from > 1) { nav += '<button data-go="1">1</button>'; if (from > 2) nav += '<span class="dis gap">…</span>'; }
            for (var i = from; i <= to; i++) nav += i === page ? '<span class="cur">' + i + '</span>' : '<button data-go="' + i + '">' + i + '</button>';
            if (to < pages) { if (to < pages - 1) nav += '<span class="dis gap">…</span>'; nav += '<button data-go="' + pages + '">' + pages + '</button>'; }
            nav += '<button class="' + (page < pages ? '' : 'dis') + '" data-go="' + (page + 1) + '">' + __t('Next') + ' ›</button>';
            var opts = sizes.map(function (n) { return '<option' + (n === per ? ' selected' : '') + '>' + n + '</option>'; }).join('');
            bar.innerHTML = '<span>' + __t('Showing') + ' ' + (a + 1) + '–' + Math.min(b, vis.length) + ' ' + __t('of') + ' ' + vis.length + (unit ? ' ' + __t(unit) + (__t(unit) === unit && vis.length !== 1 ? 's' : '') : '') + '</span>'
                + ((pages > 1 || !always) ? '<nav class="gp-nav">' + nav + '</nav>' : '')
                + '<label class="gp-size">' + __t('Rows') + ' <select>' + opts + '</select></label>';
            setTimeout(function () { busy = false; }, 0);
        }

        bar.addEventListener('click', function (e) {
            var go = e.target.getAttribute && e.target.getAttribute('data-go');
            if (go && !e.target.classList.contains('dis')) { page = parseInt(go, 10); render(); }
        });
        bar.addEventListener('change', function (e) {
            if (e.target.tagName === 'SELECT') { per = parseInt(e.target.value, 10); page = 1; sig = ''; render(); }
        });

        var t = null;
        new MutationObserver(function () {
            if (busy) return;
            clearTimeout(t); t = setTimeout(render, 30);
        }).observe(box, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] });
        render();
    }

    function boot() { Array.prototype.forEach.call(document.querySelectorAll('[data-pager]'), init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
