window.__t = window.__t || function (s) { return s; };
/* Admin OTP confirm dialog, shared by admin/staff.php and admin/students.php.
 * AdminOtp.open({
 *   op:           must match the server allow-list (e.g. 'delete_staff', 'disable_student')
 *   targetId:     id of the record being changed
 *   title, subtitle, body: text shown in the dialog
 *   confirmLabel: text on the final button
 *   danger:       true = red button, false = amber
 *   action:       fields POSTed with the code, e.g. { ajax_action: 'delete_staff', id: 5 }
 *   onDone(data): called when the server reports success
 * })
 */
(function () {
    'use strict';

    var overlay = null;
    var state = null; // config of the request in progress

    function el(id) { return document.getElementById(id); }

    function endpoint() {
        return window.location.pathname.replace(/\?.*$/, '');
    }

    function post(fields, ep) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        var tok = window.CSRF_TOKEN || (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '');
        fd.append('csrf_token', tok);
        return fetch(ep || endpoint(), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                if (r.redirected || r.url.indexOf('index.php') !== -1) {
                    throw new Error(__t('Session expired. Please log in again.'));
                }
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) {
                    throw new Error(__t('Server error. Please try again.'));
                }
                return r.json();
            });
    }

    function showError(msg) {
        var e = el('aotp-error');
        e.textContent = msg;
        e.style.display = 'block';
    }

    function hideError() { el('aotp-error').style.display = 'none'; }

    function setBusy(busy, label) {
        var p = el('aotp-primary');
        p.disabled = busy;
        el('aotp-resend').disabled = busy;
        el('aotp-use-pw').disabled = busy;
        el('aotp-use-email').disabled = busy;
        if (busy) {
            p.textContent = label || 'Please wait...';
        } else if (state) {
            p.textContent = state.stage === 'code' ? state.confirmLabel : __t('Send Code');
        }
    }

    function close() {
        overlay.style.display = 'none';
        state = null;
    }

    function build() {
        overlay = document.createElement('div');
        overlay.id = 'admin-otp-overlay';
        overlay.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9800;align-items:center;justify-content:center;padding:16px;';
        overlay.innerHTML =
            '<div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:420px;box-shadow:0 8px 32px rgba(0,0,0,0.18);">' +
              '<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">' +
                '<div id="aotp-icon-wrap" style="width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
                  '<i id="aotp-icon" class="ti" style="font-size:20px;"></i></div>' +
                '<div><div id="aotp-title" style="font-size:15px;font-weight:600;color:#1F2937;"></div>' +
                '<div id="aotp-subtitle" style="font-size:13px;color:#6B7280;margin-top:2px;"></div></div>' +
              '</div>' +
              '<div id="aotp-body" style="font-size:13px;color:#374151;background:#F9FAFB;border-radius:8px;padding:12px 14px;margin-bottom:16px;"></div>' +
              '<div id="aotp-step-send" style="font-size:13px;color:#374151;">' +
                '<div style="margin-bottom:12px;">Choose how to confirm this action:</div>' +
                '<div style="display:flex;flex-direction:column;gap:8px;">' +
                  '<button id="aotp-use-pw" type="button" class="btn btn-sm" style="width:100%;justify-content:center;background:#6B1A2A;border-color:#6B1A2A;color:#fff;"><i class="ti ti-lock"></i> Confirm with my admin password</button>' +
                  '<button id="aotp-use-email" type="button" class="btn btn-outline btn-sm" style="width:100%;justify-content:center;"><i class="ti ti-mail"></i> Send an email code instead</button>' +
                '</div>' +
              '</div>' +
              '<div id="aotp-step-code" style="display:none;">' +
                '<label id="aotp-code-label" style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Enter the 6-digit code</label>' +
                '<input id="aotp-code" type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000" class="form-control" style="letter-spacing:6px;font-size:18px;text-align:center;">' +
                '<div id="aotp-sent" style="font-size:12px;color:#6B7280;margin-top:6px;"></div>' +
                '<button id="aotp-resend" type="button" class="btn btn-outline btn-sm" style="margin-top:8px;">Resend code</button>' +
              '</div>' +
              '<div id="aotp-error" style="display:none;color:#991B1B;font-size:12px;margin-top:10px;"></div>' +
              '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">' +
                '<button id="aotp-cancel" type="button" class="btn btn-outline btn-sm">Cancel</button>' +
                '<button id="aotp-primary" type="button" class="btn btn-sm"></button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        el('aotp-cancel').onclick = close;
        el('aotp-primary').onclick = onPrimary;
        el('aotp-resend').onclick = function () { requestCode(state && state.mode === 'password' ? 'password' : 'email'); };
        el('aotp-use-pw').onclick = function () { requestCode('password'); };
        el('aotp-use-email').onclick = function () { requestCode('email'); };
        el('aotp-code').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') onPrimary();
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.style.display === 'flex') close();
        });
    }

    // Code box (6 digits) or admin password box, depending on the mode.
    function setCodeBox(mode) {
        var input = el('aotp-code');
        var label = el('aotp-code-label');
        var resend = el('aotp-resend');
        if (mode === 'password') {
            input.type = 'password';
            input.removeAttribute('maxlength');
            input.removeAttribute('inputmode');
            input.autocomplete = 'current-password';
            input.placeholder = __t('Admin password');
            input.style.letterSpacing = 'normal';
            input.style.fontSize = '15px';
            input.style.textAlign = 'left';
            label.textContent = __t('Enter your admin password to confirm');
            resend.style.display = 'none';
        } else {
            input.type = 'text';
            input.setAttribute('maxlength', '6');
            input.setAttribute('inputmode', 'numeric');
            input.autocomplete = 'one-time-code';
            input.placeholder = '000000';
            input.style.letterSpacing = '6px';
            input.style.fontSize = '18px';
            input.style.textAlign = 'center';
            label.textContent = __t('Enter the 6-digit code');
            resend.style.display = '';
        }
    }

    // Step 1: ask the server for the confirmation (emailed code, or password mode).
    function requestCode(method) {
        if (!state) return;
        var cfg = state;
        method = method || 'auto';
        hideError();
        setBusy(true, method === 'password' ? 'Checking...' : 'Sending...');
        post({ ajax_action: 'request_admin_otp', op: cfg.op, id: cfg.targetId, method: method }, cfg.endpoint)
            .then(function (data) {
                if (state !== cfg) return;
                setBusy(false);
                if (!data.success) {
                    showError(data.message || __t('Could not send the code.'));
                    return;
                }
                cfg.stage = 'code';
                cfg.mode = data.mode || 'email';
                el('aotp-step-send').style.display = 'none';
                el('aotp-step-code').style.display = 'block';
                el('aotp-primary').style.display = '';
                setCodeBox(cfg.mode);
                el('aotp-sent').textContent = data.message || (cfg.mode === 'password' ? 'Enter your admin password.' : __t('Code sent.'));
                el('aotp-code').value = '';
                setBusy(false);
                setTimeout(function () { el('aotp-code').focus(); }, 60);
            })
            .catch(function (err) {
                if (state !== cfg) return;
                setBusy(false);
                showError(err.message || __t('Network error. Please try again.'));
            });
    }

    // Step 2: verify the code and run the action.
    function onPrimary() {
        if (!state) return;
        if (state.stage !== 'code') { requestCode('auto'); return; }

        var isPw = state.mode === 'password';
        var code = isPw ? (el('aotp-code').value || '') : (el('aotp-code').value || '').replace(/\D/g, '');
        if (isPw ? code === '' : code.length !== 6) {
            showError(isPw ? 'Enter your admin password.' : __t('Enter the 6-digit code.'));
            return;
        }

        var cfg = state;
        var fields = {};
        Object.keys(cfg.action || {}).forEach(function (k) { fields[k] = cfg.action[k]; });
        fields.admin_otp = code;

        hideError();
        setBusy(true, 'Verifying...');
        post(fields, cfg.endpoint)
            .then(function (data) {
                if (state !== cfg) return;
                if (data.success) {
                    setBusy(false);
                    close();
                    if (cfg.onDone) cfg.onDone(data);
                } else {
                    setBusy(false);
                    showError(data.message || __t('Something went wrong.'));
                }
            })
            .catch(function (err) {
                if (state !== cfg) return;
                setBusy(false);
                showError(err.message || __t('Network error. Please try again.'));
            });
    }

    function open(cfg) {
        if (!overlay) build();
        cfg.stage = 'send';
        cfg.mode = 'email';
        cfg.confirmLabel = cfg.confirmLabel || __t('Confirm');
        state = cfg;

        var danger = cfg.danger !== false;
        var color = danger ? '#991B1B' : '#D97706';
        el('aotp-icon-wrap').style.background = danger ? '#FEE2E2' : '#FEF3C7';
        el('aotp-icon').className = 'ti ' + (danger ? 'ti-trash' : 'ti-user-off');
        el('aotp-icon').style.color = color;
        el('aotp-title').textContent = cfg.title || '';
        el('aotp-subtitle').textContent = cfg.subtitle || '';
        el('aotp-body').textContent = cfg.body || '';

        el('aotp-step-send').style.display = 'block';
        el('aotp-step-code').style.display = 'none';
        el('aotp-primary').style.display = 'none';   // the two choices are the buttons in this step
        hideError();

        var p = el('aotp-primary');
        p.style.background = color;
        p.style.borderColor = color;
        p.style.color = '#fff';
        setBusy(false);

        overlay.style.display = 'flex';
    }

    // Asks for the emailed code, then calls proceed() if it is correct.
    // The page's own save runs inside proceed(); the server lets it through once.
    function confirmThen(cfg, proceed) {
        open({
            op: cfg.op,
            targetId: cfg.targetId,
            title: cfg.title,
            subtitle: cfg.subtitle,
            body: cfg.body,
            confirmLabel: cfg.confirmLabel || __t('Save Changes'),
            danger: false,
            action: { ajax_action: 'otp_verify_only', op: cfg.op, id: cfg.targetId },
            onDone: function () { proceed(); }
        });
    }

    // Gate for exports and imports: confirm first, then run proceed().
    // The server approves one request for this op (see includes/admin-otp.php).
    function gate(opts) {
        open({
            op: opts.op,
            targetId: 0,
            title: opts.title || __t('Confirm'),
            subtitle: opts.subtitle || '',
            body: opts.body || '',
            confirmLabel: opts.confirmLabel || __t('Continue'),
            danger: false,
            endpoint: 'otp-gate.php',
            action: { ajax_action: 'otp_verify_only', op: opts.op, id: 0 },
            onDone: function () { if (opts.proceed) opts.proceed(); }
        });
    }

    // Any link with data-admin-gate="<op>" asks for confirmation before it opens.
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[data-admin-gate]') : null;
        if (!a) return;
        e.preventDefault();
        var href = a.getAttribute('href');
        var target = a.getAttribute('target');
        gate({
            op: a.getAttribute('data-admin-gate'),
            title: __t('Confirm export'),
            subtitle: (a.textContent || '').trim(),
            body: __t('This file contains school records. Confirm before it downloads.'),
            confirmLabel: __t('Export'),
            proceed: function () {
                if (target === '_blank') window.open(href, '_blank');
                else window.location.href = href;
            }
        });
    }, true);

    window.AdminOtp = { open: open, confirmThen: confirmThen, gate: gate };
})();
