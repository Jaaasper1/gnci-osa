// "Show all / Show less" toggle for card sections (events, Facebook feed).
// Items after the 3rd carry .more-item and stay hidden until the section is expanded.
(function () {
    var state = {};

    function set(name, on) {
        document.querySelectorAll('[data-more-group="' + name + '"]').forEach(function (g) {
            g.classList.toggle('show-all', on);
        });
        document.querySelectorAll('[data-more-toggle="' + name + '"]').forEach(function (b) {
            var txt = b.getAttribute(on ? 'data-label-less' : 'data-label-more');
            var span = b.querySelector('span');
            if (span && span.textContent !== txt) span.textContent = txt;
            var ic = b.querySelector('i');
            if (ic) ic.className = 'ti ' + (on ? 'ti-chevron-up' : 'ti-chevron-down');
            b.setAttribute('aria-expanded', on ? 'true' : 'false');
        });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-more-toggle]');
        if (!b) return;
        var n = b.getAttribute('data-more-toggle');
        state[n] = !state[n];
        set(n, state[n]);
    });

    // The Facebook feed is re-rendered by AJAX; keep whatever the user expanded.
    new MutationObserver(function (muts) {
        var hit = muts.some(function (m) {
            return Array.prototype.some.call(m.addedNodes, function (n) {
                return n.nodeType === 1 && (n.matches('[data-more-group]') || n.querySelector('[data-more-group]'));
            });
        });
        if (hit) Object.keys(state).forEach(function (k) { set(k, !!state[k]); });
    }).observe(document.body, { childList: true, subtree: true });
})();
