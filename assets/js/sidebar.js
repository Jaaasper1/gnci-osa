/* GNC-OSA Portal — Mobile Sidebar Toggle */
(function () {
    // Where this script lives -> used to find the logo no matter which page (or folder) we are on.
    var SCRIPT_SRC = (document.currentScript && document.currentScript.src) || '';
    var LOGO_FALLBACK = SCRIPT_SRC ? SCRIPT_SRC.replace(/js\/[^\/?#]*(\?.*)?$/, 'img/osa-logo.jpg') : 'assets/img/osa-logo.jpg';
    // ============================================
    // SAME-PAGE ACTIONS = NO PAGE ANIMATION
    // Simple action buttons/links (Hold, Resume, filters, quick form
    // posts...) reload the page they are already on. Replaying the
    // page fade-in / row stagger / count-up there looks like the whole
    // screen "refreshing", so those are skipped when the page we land
    // on is the same one we just left. Moving to a DIFFERENT page (e.g.
    // sidebar links) still gets the normal transition.
    // ============================================
    var NOANIM_KEY = 'gnc_noanim';
    try {
        if (sessionStorage.getItem(NOANIM_KEY) === window.location.pathname) {
            document.documentElement.classList.add('no-anim');
        }
        sessionStorage.removeItem(NOANIM_KEY);
    } catch (err) {}
    function markSamePage(pathname) {
        try {
            if (pathname === window.location.pathname) sessionStorage.setItem(NOANIM_KEY, pathname);
        } catch (err) {}
    }

    document.addEventListener('DOMContentLoaded', function () {
        var hamburger = document.getElementById('hamburger');
        var sidebar   = document.querySelector('.sidebar');
        var overlay   = document.getElementById('sidebar-overlay');

        if (!hamburger || !sidebar) return;

        function openSidebar() {
            sidebar.classList.add('open');
            hamburger.classList.add('open');
            if (overlay) { overlay.classList.add('active'); }
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            hamburger.classList.remove('open');
            if (overlay) { overlay.classList.remove('active'); }
            document.body.style.overflow = '';
        }

        hamburger.addEventListener('click', function () {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }

        // Close on nav item click (useful on mobile)
        sidebar.querySelectorAll('.sitem').forEach(function (item) {
            item.addEventListener('click', function () {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });

        // Close on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSidebar();
        });
    });

    // ============================================
    // LOGIN / LOGOUT LOADING OVERLAY
    // Shows the OSA logo + spinner during auth transitions
    // instead of an abrupt jump to the next page.
    // ============================================
    document.addEventListener('DOMContentLoaded', function () {
        var overlay = document.createElement('div');
        overlay.className = 'app-loading-overlay';
        overlay.innerHTML =
            '<img src="" alt="" id="app-loading-logo">' +
            '<div class="spinner-ring"></div>' +
            '<div class="loading-text" id="app-loading-text">Loading...</div>';
        document.body.appendChild(overlay);

        // Always use the OSA logo (resolved from this script's own location), so the loading screen
        // has the picture on every page — even ones without a .login-logo / topnav brand image.
        var logoEl = overlay.querySelector('#app-loading-logo');
        logoEl.setAttribute('src', LOGO_FALLBACK);
        logoEl.onerror = function () {
            logoEl.onerror = null;
            var found = document.querySelector('.login-logo img, .topnav .brand img, img[src*="osa-logo"]');
            if (found) logoEl.setAttribute('src', found.getAttribute('src'));
        };
        // warm the cache so it is already loaded when the overlay appears
        (new Image()).src = LOGO_FALLBACK;

        function showOverlay(text) {
            overlay.querySelector('#app-loading-text').textContent = text;
            overlay.classList.add('show');
        }
        // Exposed so other flows (e.g. the confirm-modal below) can show this
        // same full-screen overlay for actions that deserve extra weight,
        // like recording a vote.
        window.appShowLoadingOverlay = showOverlay;

        // Intercept auth forms (login, register) — each can set a custom
        // message via data-loading-text="..."; defaults to "Logging in..."
        document.querySelectorAll('form.auth-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!form.checkValidity()) return; // let native validation messages show first
                e.preventDefault();
                showOverlay(form.dataset.loadingText || 'Logging in...');
                setTimeout(function () { form.submit(); }, 550);
            });
        });

        // Intercept any logout link across the app
        document.querySelectorAll('a[href$="logout.php"]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var dest = link.getAttribute('href');
                showOverlay('Logging out...');
                setTimeout(function () { window.location.href = dest; }, 550);
            });
        });

        // Intercept any other link explicitly opted in via data-loading-text="..."
        // (e.g. the Live Election Results button, Back to Login links)
        document.querySelectorAll('a[data-loading-text]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var dest = link.getAttribute('href');
                showOverlay(link.dataset.loadingText);
                setTimeout(function () { window.location.href = dest; }, 120);
            });
        });
    });

    // ============================================
    // CONFIRM MODAL (shared)
    // 1) Any <form class="confirm-submit-form"> with data-confirm-title/
    //    data-confirm-message/data-confirm-btn shows this before submitting.
    // 2) Any other JS action can call window.appConfirm(options, onConfirm)
    //    directly — e.g. a button that triggers an AJAX call, replacing the
    //    native browser confirm() popup with one that matches the app's style.
    // ============================================
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.createElement('div');
        modal.className = 'confirm-modal-overlay';
        modal.innerHTML =
            '<div class="confirm-modal-box">' +
                '<div class="confirm-modal-icon"><i class="ti ti-help-circle"></i></div>' +
                '<div class="confirm-modal-title"></div>' +
                '<div class="confirm-modal-message"></div>' +
                '<div class="confirm-modal-actions">' +
                    '<button type="button" class="btn btn-outline confirm-modal-cancel">Cancel</button>' +
                    '<button type="button" class="btn btn-primary confirm-modal-ok">Confirm</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);

        var pendingForm = null;
        var pendingCallback = null;

        function closeConfirm() {
            modal.classList.remove('show');
            pendingForm = null;
            pendingCallback = null;
        }

        modal.querySelector('.confirm-modal-cancel').addEventListener('click', closeConfirm);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeConfirm(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeConfirm(); });

        modal.querySelector('.confirm-modal-ok').addEventListener('click', function () {
            var form = pendingForm;
            var callback = pendingCallback;
            closeConfirm();
            if (form) {
                setButtonLoading(form);
                // Opt-in full-screen overlay for actions that deserve extra
                // weight (e.g. data-loading-overlay="Recording your vote...").
                if (form.dataset.loadingOverlay && window.appShowLoadingOverlay) {
                    window.appShowLoadingOverlay(form.dataset.loadingOverlay);
                }
                form.submit();
            }
            if (callback) callback();
        });

        // Global helper for button-triggered actions (not forms).
        // Usage: appConfirm({ title, message, btn, danger }, function () { ...do the action... });
        window.appConfirm = function (opts, onConfirm) {
            opts = opts || {};
            pendingCallback = onConfirm;
            modal.querySelector('.confirm-modal-title').textContent = opts.title || 'Are you sure?';
            modal.querySelector('.confirm-modal-message').textContent = opts.message || 'This action cannot be undone.';
            modal.querySelector('.confirm-modal-ok').textContent = opts.btn || 'Confirm';
            modal.classList.toggle('confirm-modal-danger', !!opts.danger);
            modal.classList.add('show');
        };

        var forms = document.querySelectorAll('form.confirm-submit-form');
        forms.forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!form.checkValidity()) return; // let native validation messages show first
                e.preventDefault();

                // Optional custom validation hook: data-confirm-validate="myFunctionName"
                // The named window function must return { valid: bool, message: string }.
                // If invalid, we stop here entirely — the confirm modal never shows.
                if (form.dataset.confirmValidate && typeof window[form.dataset.confirmValidate] === 'function') {
                    var result = window[form.dataset.confirmValidate]();
                    if (!result || !result.valid) return;
                }

                pendingForm = form;
                modal.querySelector('.confirm-modal-title').textContent = form.dataset.confirmTitle || 'Are you sure?';
                modal.querySelector('.confirm-modal-message').textContent = form.dataset.confirmMessage || 'This action cannot be undone.';
                modal.querySelector('.confirm-modal-ok').textContent = form.dataset.confirmBtn || 'Confirm';
                modal.classList.toggle('confirm-modal-danger', form.dataset.confirmDanger === '1');
                modal.classList.add('show');
            });
        });
    });

    // ============================================
    // TOAST NOTIFICATIONS
    // window.appToast(message, type) shows a small styled snackbar
    // instead of the native browser alert() popup.
    // type: 'success' (default) | 'error' | 'info'
    // ============================================
    document.addEventListener('DOMContentLoaded', function () {
        var container = document.createElement('div');
        container.className = 'app-toast-container';
        document.body.appendChild(container);

        window.appToast = function (message, type) {
            type = type || 'success';
            var icons = { success: 'ti-circle-check', error: 'ti-alert-circle', info: 'ti-info-circle' };
            var toast = document.createElement('div');
            toast.className = 'app-toast app-toast-' + type;
            toast.innerHTML = '<i class="ti ' + (icons[type] || icons.success) + '"></i><span></span>';
            toast.querySelector('span').textContent = message;
            container.appendChild(toast);

            requestAnimationFrame(function () { toast.classList.add('show'); });

            setTimeout(function () {
                toast.classList.remove('show');
                setTimeout(function () { toast.remove(); }, 250);
            }, 2600);
        };
    });

    // ============================================
    // ANIMATED SUCCESS CHECKMARK
    // Swaps any "ti-check" / "ti-circle-check" icon for a small SVG
    // that draws itself in (circle, then check), Gmail/Forms-style.
    // - On initial load: only inside flash-message banners
    //   (.alert-success / .alert.alert-success / .info-banner), so
    //   pre-existing per-row status badges (e.g. "Voted", "Used")
    //   are left alone.
    // - After that: a MutationObserver animates the same icons the
    //   moment they're inserted dynamically (AJAX import results,
    //   approve/reject toasts, "Found!" indicators, etc.) with zero
    //   changes needed anywhere else.
    // ============================================
    function animateCheckIcon(icon) {
        if (!icon || icon.tagName !== 'I') return;
        if (!(icon.classList.contains('ti-check') || icon.classList.contains('ti-circle-check'))) return;
        if (!icon.parentNode) return;
        var color = icon.style.color || window.getComputedStyle(icon).color;
        var size  = icon.style.fontSize || window.getComputedStyle(icon).fontSize || '16px';
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 36 36');
        svg.setAttribute('class', 'success-check');
        svg.style.color = color;
        svg.style.width = size;
        svg.style.height = size;
        svg.innerHTML =
            '<circle class="success-check-circle" cx="18" cy="18" r="15"></circle>' +
            '<path class="success-check-mark" d="M10 19l6 6 12-14"></path>';
        icon.parentNode.replaceChild(svg, icon);
    }

    function scanCheckIcons(root) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('i.ti-check, i.ti-circle-check').forEach(animateCheckIcon);
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Initial pass: flash-message banners only.
        document.querySelectorAll('.alert-success, .alert.alert-success, .info-banner, .vote-success-card').forEach(scanCheckIcons);

        // Any check icon added afterwards (AJAX results, toasts, etc.) gets the same treatment automatically.
        var checkObserver = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes && m.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.tagName === 'I') { animateCheckIcon(node); return; }
                    scanCheckIcons(node);
                });
            });
        });
        checkObserver.observe(document.body, { childList: true, subtree: true });
    });

    // ============================================
    // COUNT-UP STAT NUMBERS
    // Any element with class "stat-num" animates from 0 up to its
    // real value on page load instead of appearing instantly.
    // Keeps any prefix/suffix text intact (e.g. "%").
    // ============================================
    document.addEventListener('DOMContentLoaded', function () {
        if (document.documentElement.classList.contains('no-anim')) return;
        document.querySelectorAll('.stat-num').forEach(function (el) {
            var raw = el.textContent.trim();
            var match = raw.match(/^(\D*)([\d,]+(?:\.\d+)?)(.*)$/);
            if (!match) return;

            var prefix   = match[1];
            var numStr   = match[2].replace(/,/g, '');
            var suffix   = match[3];
            var target   = parseFloat(numStr);
            if (isNaN(target)) return;

            var decimals = numStr.indexOf('.') !== -1 ? numStr.split('.')[1].length : 0;
            var scale    = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--anim-scale'));
            if (isNaN(scale)) scale = 1;
            if (scale <= 0) return;
            var duration = 400 * scale;
            var start    = null;

            function frame(ts) {
                if (!start) start = ts;
                var progress = Math.min((ts - start) / duration, 1);
                var eased    = 1 - Math.pow(1 - progress, 3);
                var current  = target * eased;
                el.textContent = prefix + (decimals ? current.toFixed(decimals) : Math.round(current).toLocaleString()) + suffix;
                if (progress < 1) requestAnimationFrame(frame);
                else el.textContent = raw;
            }
            requestAnimationFrame(frame);
        });
    });

    // ============================================
    // TABLE / LIST ROW STAGGER FADE-IN
    // Rows in any <tbody> fade + slide in one after another instead
    // of all popping in at once. Capped so very long tables don't
    // end up with an excessive total delay.
    // ============================================
    function applyRowStagger(root) {
        (root || document).querySelectorAll('tbody').forEach(function (tbody) {
            var rows = tbody.querySelectorAll(':scope > tr');
            // Only the first rows get the fade-in: animating hundreds/thousands of rows makes big tables lag.
            var n = Math.min(rows.length, 12);
            for (var i = 0; i < n; i++) {
                var row = rows[i];
                row.style.setProperty('--row-i', Math.min(i, 8));
                row.classList.add('stagger-row');
                row.addEventListener('animationend', function () { this.classList.remove('stagger-row'); }, { once: true });
            }
        });
    }
    document.addEventListener('DOMContentLoaded', function () { applyRowStagger(document); });

    // ============================================
    // BUTTON PRESS FEEDBACK + INLINE LOADING SPINNER
    // The ":active" press-down scale is pure CSS (see style.css).
    // This adds a spinner + disabled state to a form's submit button
    // the moment it actually submits, so slow requests (Import,
    // Submit, etc.) get instant visual feedback instead of feeling
    // unresponsive. Skips forms already handled elsewhere
    // (auth-form has its own full-screen overlay; confirm-submit-form
    // is handled right where it calls form.submit(), below).
    // ============================================
    function setButtonLoading(form) {
        if (!form) return;
        try { markSamePage(new URL(form.getAttribute('action') || window.location.href, window.location.href).pathname); } catch (err) {}
        var btn = form.querySelector('button[type="submit"]') || form.querySelector('.btn');
        if (btn && btn.classList && btn.classList.contains('btn')) {
            btn.disabled = true;
            // Only show the spinner if the request is actually slow; quick
            // saves/approvals just reload without a flicker.
            var t = setTimeout(function () { btn.classList.add('btn-loading'); }, 300);
            _loadingBtns.push({ btn: btn, timer: t });
        }
    }
    var _loadingBtns = [];

    // ============================================
    // BACK / FORWARD BUTTON RESET
    // When the person presses the browser Back button, the page can be restored
    // from the back/forward cache exactly as it was left: with the full-screen
    // "Logging in… / Logging out…" overlay still covering everything, and
    // submit buttons still disabled + spinning. Put those back to normal.
    // ============================================
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;   // normal fresh load: nothing to reset
        document.querySelectorAll('.app-loading-overlay.show').forEach(function (o) { o.classList.remove('show'); });
        _loadingBtns.forEach(function (x) {
            clearTimeout(x.timer);
            x.btn.disabled = false;
            x.btn.classList.remove('btn-loading');
        });
        _loadingBtns = [];
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.classList.contains('auth-form') || form.classList.contains('confirm-submit-form')) return;
        if (e.defaultPrevented) return;
        setButtonLoading(form);
    });

    // ============================================
    // PAGE TRANSITION
    // Pages fade in on load (pure CSS, see .content in style.css).
    // For a matching fade OUT, plain same-page internal links get a
    // brief fade before the browser actually navigates. Deliberately
    // conservative: skips anything with a custom onclick, a target,
    // a download, an off-site host, a "#" / javascript: / mailto: /
    // tel: href, or a link already handled elsewhere
    // (logout, data-loading-text) so nothing already-working changes.
    // ============================================
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var link = e.target.closest ? e.target.closest('a[href]') : null;
        if (!link) return;
        if (link.hasAttribute('onclick') || link.hasAttribute('download') || link.dataset.loadingText) return;
        if (link.target && link.target !== '_self') return;

        var href = link.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(href)) return;
        if (href.indexOf('logout.php') !== -1) return; // already has its own overlay

        var url;
        try { url = new URL(href, window.location.href); } catch (err) { return; }
        if (url.origin !== window.location.origin) return;

        // Same page (Hold/Resume, filters, pagination...): no fade-out here,
        // and the next load skips the fade-in too.
        if (url.pathname === window.location.pathname) { markSamePage(url.pathname); return; }

        var content = document.querySelector('.main .content') || document.querySelector('.content');
        if (!content) return; // nothing to fade, let the browser navigate normally

        // Different page: navigate straight away (no fade-out delay);
        // the new page still fades in.
    });

    // ============================================
    // SKELETON LOADER
    // window.appSkeletonHTML(lines) returns shimmering placeholder bars
    // to show inside an AJAX-loaded container while a request is in
    // flight, instead of leaving it blank. The container's own success/
    // error rendering afterwards simply overwrites it as usual.
    // ============================================
    window.appSkeletonHTML = function (lines) {
        lines = lines || 3;
        var html = '<div class="skeleton-wrap">';
        for (var i = 0; i < lines; i++) html += '<div class="skeleton-line"></div>';
        html += '</div>';
        return html;
    };
})();
