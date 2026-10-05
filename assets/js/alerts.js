// Auto-dismiss alerts after 3 seconds: fade out, then smoothly collapse the
// space they occupied so the page returns to its normal layout (no blank gap).
function dismissAlert(el) {
    setTimeout(function () {
        if (!el || !el.parentNode) return;

        // 1) fade
        el.style.transition = 'opacity 0.4s ease';
        el.style.opacity = '0';
        el.style.pointerEvents = 'none';

        // 2) once faded, collapse height/margin/padding to 0 (smooth, so nothing "jumps")
        setTimeout(function () {
            if (!el.parentNode) return;
            var h = el.offsetHeight;
            el.style.boxSizing = 'border-box';
            el.style.height = h + 'px';
            el.style.overflow = 'hidden';
            void el.offsetHeight; // force reflow so the transition starts from the current height
            el.style.transition = 'height .3s ease, margin .3s ease, padding .3s ease, border-width .3s ease';
            el.style.height = '0';
            el.style.marginTop = '0';
            el.style.marginBottom = '0';
            el.style.paddingTop = '0';
            el.style.paddingBottom = '0';
            el.style.borderTopWidth = '0';
            el.style.borderBottomWidth = '0';

            // 3) take it out of the layout entirely
            setTimeout(function () { el.style.display = 'none'; }, 350);
        }, 400);
    }, 3000);
}

document.addEventListener('DOMContentLoaded', function () {
    var selectors = ['.alert-success', '.alert-error', '.info-banner'];
    selectors.forEach(function (sel) {
        document.querySelectorAll(sel).forEach(function (el) {
            if (el.hasAttribute('data-no-dismiss')) return; // e.g. live countdown alert
            dismissAlert(el);
        });
    });
});

// Global function so AJAX-created alerts can also be dismissed
window.autoDismissAlert = function(el) {
    dismissAlert(el);
};
