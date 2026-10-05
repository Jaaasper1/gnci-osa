/* ============================================
   OTP per-digit box input
   Wires a row of .otp-box inputs to a single
   hidden <input> holding the combined code.
   ============================================ */
(function () {
    function initOtpBoxes(wrapper) {
        var hidden = document.getElementById(wrapper.dataset.target);
        var boxes = Array.prototype.slice.call(wrapper.querySelectorAll('.otp-box'));
        if (!hidden || !boxes.length) return;

        function sync() {
            hidden.value = boxes.map(function (b) { return b.value; }).join('');
        }

        boxes.forEach(function (box, i) {
            box.addEventListener('input', function () {
                box.value = box.value.replace(/[^0-9]/g, '').slice(-1);
                box.classList.toggle('filled', box.value !== '');
                if (box.value && i < boxes.length - 1) {
                    boxes[i + 1].focus();
                    boxes[i + 1].select();
                }
                sync();
            });

            box.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace') {
                    if (!box.value && i > 0) {
                        boxes[i - 1].focus();
                        boxes[i - 1].select();
                        boxes[i - 1].value = '';
                        boxes[i - 1].classList.remove('filled');
                        sync();
                        e.preventDefault();
                    }
                } else if (e.key === 'ArrowLeft' && i > 0) {
                    boxes[i - 1].focus();
                    e.preventDefault();
                } else if (e.key === 'ArrowRight' && i < boxes.length - 1) {
                    boxes[i + 1].focus();
                    e.preventDefault();
                }
            });

            box.addEventListener('focus', function () {
                box.select();
            });

            box.addEventListener('paste', function (e) {
                var text = (e.clipboardData || window.clipboardData).getData('text');
                var digits = text.replace(/[^0-9]/g, '').split('');
                if (!digits.length) return;
                e.preventDefault();
                boxes.forEach(function (b, j) {
                    b.value = digits[j] || '';
                    b.classList.toggle('filled', b.value !== '');
                });
                sync();
                var nextEmpty = boxes.findIndex(function (b) { return !b.value; });
                var focusTarget = nextEmpty === -1 ? boxes[boxes.length - 1] : boxes[nextEmpty];
                focusTarget.focus();
                focusTarget.select();
            });
        });

        // If the form re-renders with a server-side error, flash the boxes red.
        if (wrapper.dataset.hasError === '1') {
            boxes.forEach(function (b) { b.classList.add('otp-error'); });
            setTimeout(function () {
                boxes.forEach(function (b) { b.classList.remove('otp-error'); });
            }, 350);
        }

        boxes[0].focus();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.otp-boxes').forEach(initOtpBoxes);
        document.querySelectorAll('[data-resend-timer]').forEach(initResendTimer);
    });

    function initResendTimer(el) {
        var seconds = parseInt(el.dataset.resendTimer, 10) || 0;
        var timerLabel = el.querySelector('.otp-timer');
        var resendBtn = el.querySelector('button');
        var waitingText = el.querySelector('.otp-waiting-text');

        function render() {
            var m = Math.floor(seconds / 60).toString().padStart(2, '0');
            var s = (seconds % 60).toString().padStart(2, '0');
            if (timerLabel) timerLabel.textContent = m + ':' + s;
        }

        function finish() {
            if (waitingText) waitingText.style.display = 'none';
            if (resendBtn) resendBtn.style.display = '';
        }

        function tick() {
            seconds--;
            if (seconds <= 0) {
                clearInterval(interval);
                finish();
                return;
            }
            render();
        }

        if (seconds > 0) {
            render();
            var interval = setInterval(tick, 1000);
        } else {
            finish();
        }
    }
})();
