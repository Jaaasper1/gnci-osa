<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/lang.php';
require_once 'includes/csrf.php';
require_once 'includes/mailer.php';

if (isLoggedIn()) {
    if ($_SESSION['user_type'] === 'student') header('Location: student/events.php');
    elseif ($_SESSION['user_type'] === 'staff')  header('Location: osa/dashboard.php');
    elseif ($_SESSION['user_type'] === 'admin')  header('Location: admin/dashboard.php');
    exit;
}

$error = '';
$throttleWait = 0; // seconds left before another login code may be requested (drives the live countdown)

// Which screen to show: 'form' (Student ID only) or 'otp' (enter the code)
$stage = 'form';
if (!empty($_SESSION['login_pending'])) {
    $stage = 'otp';
}

// ── Cancel / start over ─────────────────────────────────────────────────────
if (isset($_GET['cancel'])) {
    unset($_SESSION['login_pending']);
    header('Location: index.php');
    exit;
}

// ── STEP 1: Student ID → find account → send OTP to its stored email ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? 'student_id') === 'student_id') {
    $username = trim($_POST['username'] ?? '');

    if (empty($username)) {
        $error = t('login.err_required_sid', 'Please enter your Student ID.');
    } elseif (!ctype_digit($username)) {
        $error = t('login.err_invalid_student', 'Invalid Student ID.');
    } else {
        $student = findActiveStudentById($pdo, $username);
        if (!$student) {
            $error = t('login.err_invalid_student', 'Invalid Student ID, or your account is not yet approved.');
        } elseif (empty($student['email'])) {
            $error = t('login.err_no_email', 'No email is on file for this account. Please visit the OSA office to have one added.');
        } elseif (($otpWait = otpThrottleWait($pdo, $student['student_id'])) > 0) {
            $throttleWait = $otpWait;
            $error = otpWaitMessage($otpWait);
        } else {
            otpThrottleRecord($pdo, $student['student_id']);
            $otp  = generateOtpCode();
            $sent = sendRegistrationOtp($student['email'], $student['first_name'], $otp, 'login');
            if (!$sent) {
                $error = t('login.err_otp_send', 'Could not send the verification code. Please try again or contact the OSA office.');
            } else {
                $_SESSION['login_pending'] = [
                    'student_id' => $student['student_id'],
                    'email'      => $student['email'],
                    'first_name' => $student['first_name'],
                    'otp'        => $otp,
                    'expires_at' => time() + 600,
                    'attempts'   => 0,
                    'last_sent'  => time(),
                ];
                $stage = 'otp';
            }
        }
    }
}

// ── STEP 2: verify OTP → finalize login ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'verify_otp') {
    requireCsrf();
    $stage = 'otp';

    if (empty($_SESSION['login_pending'])) {
        $error = t('login.err_session_expired', 'Your session expired. Please log in again.');
        $stage = 'form';
    } else {
        $pending    = $_SESSION['login_pending'];
        $enteredOtp = trim($_POST['otp'] ?? '');

        if (time() > $pending['expires_at']) {
            $error = t('register.err_otp_expired', 'This code has expired. Please request a new one.');
        } elseif ($pending['attempts'] >= 5) {
            $error = t('register.err_otp_attempts', 'Too many incorrect attempts. Please request a new code.');
        } elseif (!$enteredOtp || !hash_equals($pending['otp'], $enteredOtp)) {
            $_SESSION['login_pending']['attempts'] = $pending['attempts'] + 1;
            $error = t('register.err_otp_wrong', 'Incorrect code. Please try again.');
        } else {
            $student = findActiveStudentById($pdo, $pending['student_id']);
            unset($_SESSION['login_pending']);
            if (!$student) {
                $error = t('login.err_invalid_student', 'Invalid Student ID.');
                $stage = 'form';
            } else {
                finalizeStudentSession($student);
                header('Location: student/events.php'); exit;
            }
        }
    }
}

// ── Resend OTP ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'resend') {
    requireCsrf();
    $stage = 'otp';

    if (empty($_SESSION['login_pending'])) {
        $error = t('login.err_session_expired', 'Your session expired. Please log in again.');
        $stage = 'form';
    } elseif (time() - $_SESSION['login_pending']['last_sent'] < 30) {
        $error = t('register.err_otp_cooldown', 'Please wait a bit before requesting another code.');
    } elseif (($otpWait = otpThrottleWait($pdo, $_SESSION['login_pending']['student_id'])) > 0) {
        $error = otpWaitMessage($otpWait);
    } else {
        otpThrottleRecord($pdo, $_SESSION['login_pending']['student_id']);
        $otp  = generateOtpCode();
        $sent = sendRegistrationOtp($_SESSION['login_pending']['email'], $_SESSION['login_pending']['first_name'], $otp, 'login');
        if ($sent) {
            $_SESSION['login_pending']['otp']        = $otp;
            $_SESSION['login_pending']['expires_at'] = time() + 600;
            $_SESSION['login_pending']['attempts']   = 0;
            $_SESSION['login_pending']['last_sent']  = time();
        } else {
            $error = t('login.err_otp_send', 'Could not send the verification code. Please try again.');
        }
    }
}

$pendingEmail = $_SESSION['login_pending']['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GNC-OSA Portal — Login</title>
    <link rel="stylesheet" href="assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body class="auth-split-page">
<div class="auth-split">
    <?php include 'includes/auth-brand.php'; ?>

    <main class="auth-main">
        <div class="auth-lang"><?php renderLangSwitcher(); ?></div>

        <div class="auth-panel">
            <?php include 'includes/auth-id.php'; ?>
            <?php if ($stage === 'otp'): ?>
                <?php
                    $resendWait = 0;
                    if (!empty($_SESSION['login_pending']['last_sent'])) {
                        $resendWait = max(0, 30 - (time() - $_SESSION['login_pending']['last_sent']));
                    }
                    // Also respect the database cooldown (e.g. hourly cap), so the timer is accurate.
                    if (!empty($_SESSION['login_pending']['student_id'])) {
                        $resendWait = max($resendWait, otpThrottleWait($pdo, $_SESSION['login_pending']['student_id']));
                    }
                ?>
                <!-- OTP VERIFICATION STEP -->
                <a href="index.php?cancel=1" class="auth-back"><i class="ti ti-arrow-left"></i> <?= t('common.go_back', 'Go back') ?></a>
                <h1 class="auth-title">OTP Verification</h1>
                <p class="auth-sub">
                    A one-time verification code has been sent via email to
                    <strong><?= htmlspecialchars(maskEmail($pendingEmail)) ?></strong>.
                    Enter the code below to verify it.
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="index.php" id="otpVerifyForm" class="auth-form" data-loading-text="Verifying...">
                    <?= csrfField() ?>
                    <input type="hidden" name="step" value="verify_otp">
                    <div class="form-group">
                        <input type="hidden" name="otp" id="otp-login">
                        <div class="otp-boxes" data-target="otp-login" data-has-error="<?= $error ? '1' : '0' ?>" style="justify-content:flex-start;">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="one-time-code">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                        </div>
                    </div>
                </form>

                <form method="POST" action="index.php" id="otpResendForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="step" value="resend">
                </form>

                <div class="otp-resend-row" data-resend-timer="<?= (int)$resendWait ?>" style="text-align:left;">
                    <span class="otp-waiting-text" style="<?= $resendWait > 0 ? '' : 'display:none;' ?>">Resend OTP in <span class="otp-timer">00:30</span></span>
                    <button type="submit" form="otpResendForm" style="<?= $resendWait > 0 ? 'display:none;' : '' ?>">Resend code</button>
                </div>

                <button type="submit" form="otpVerifyForm" class="auth-btn">Verify OTP</button>

            <?php else: ?>
                <!-- STUDENT ID FORM (no password) -->
                <h1 class="auth-title"><?= t('login.welcome', 'Welcome!') ?></h1>
                <p class="auth-sub"><?= t('login.subtitle_otp', "Enter your Student ID and we'll email you a login code.") ?></p>

                <?php if ($error): ?>
                    <div class="alert alert-error" id="throttle-alert"<?= $throttleWait > 0 ? ' data-no-dismiss="1" data-wait="' . (int)$throttleWait . '"' : '' ?>><i class="ti ti-alert-circle"></i> <span id="throttle-alert-text"><?= htmlspecialchars($error) ?></span></div>
                <?php endif; ?>

                <form method="POST" action="index.php" class="auth-form" id="studentIdForm" data-loading-text="Sending code...">
                    <input type="hidden" name="step" value="student_id">
                    <div class="form-group<?= $error ? ' has-error' : '' ?>">
                        <label><?= t('login.label_student_id', 'Student ID') ?> <span style="color:#991B1B;">*</span></label>
                        <input type="text" name="username" class="form-control"
                               placeholder="ex. 20193372"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/[^0-9]/g,'')"
                               autocomplete="off" required autofocus>
                    </div>

                    <div class="auth-field-note"><?= t('login.no_email_help', "Can't receive a code? Visit the OSA office for help.") ?></div>

                    <button type="submit" class="auth-btn" id="sendCodeBtn">
                        <span id="sendCodeBtnLabel">Send Login Code</span>
                    </button>
                </form>


                <div class="auth-divider"><?= t('login.or_continue', 'Or continue as') ?></div>
                <a href="staff-login.php" class="auth-btn is-outline">
                    <i class="ti ti-shield-lock"></i> <?= t('login.tab_osa', 'OSA Staff') ?> / Admin
                </a>

                <div class="auth-aside-box">
                    <?= t('login.no_account', "Don't have an account yet?") ?>
                    <a href="register.php"><?= t('login.register', 'Register') ?></a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- ============ LIVE ELECTION RESULTS (floating) ============ -->
    <a href="live-results.php" class="liveresults-fab" data-loading-text="Loading live results...">
        <i class="ti ti-chart-pie"></i>
        <span>Live Election Results</span>
    </a>
</div>

<script>
// Live countdown when a login code was requested too soon: shows the time left
// and unlocks the button the moment the person can try again.
(function () {
    var alertEl = document.getElementById('throttle-alert');
    if (!alertEl || !alertEl.dataset.wait) return;
    var seconds = parseInt(alertEl.dataset.wait, 10) || 0;
    if (seconds <= 0) return;

    var text  = document.getElementById('throttle-alert-text');
    var btn   = document.getElementById('sendCodeBtn');
    var label = document.getElementById('sendCodeBtnLabel');
    var form  = document.getElementById('studentIdForm');
    var idleLabel = label ? label.textContent : '';

    function fmt(n) {
        var m = Math.floor(n / 60), s = n % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    function render() {
        if (text)  text.textContent = 'Please wait ' + fmt(seconds) + ' before requesting another code.';
        if (label) label.textContent = 'Try again in ' + fmt(seconds);
    }
    function unlock() {
        clearInterval(timer);
        alertEl.className = 'alert alert-success';
        var ic = alertEl.querySelector('i'); if (ic) ic.className = 'ti ti-circle-check';
        if (text)  text.textContent = 'You can request a new login code now.';
        if (label) label.textContent = idleLabel;
        if (btn)   btn.disabled = false;
    }

    // Block submitting (Enter key etc.) while the countdown runs. Registered before
    // sidebar.js, so it runs first and stops the "Sending code..." overlay too.
    if (form) form.addEventListener('submit', function (e) {
        if (seconds > 0) { e.preventDefault(); e.stopImmediatePropagation(); }
    });

    if (btn) btn.disabled = true;
    render();
    var timer = setInterval(function () {
        seconds--;
        if (seconds <= 0) unlock(); else render();
    }, 1000);
})();
</script>
<script>
// Some browsers ignore the muted attribute for autoplay - force it, then play
(function () {
    var v = document.querySelector('.login-bg-video');
    if (!v) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { v.removeAttribute('autoplay'); v.pause(); return; } // show the poster only
    v.muted = true;
    var p = v.play();
    if (p && p.catch) p.catch(function () { /* autoplay blocked: poster stays as the background */ });
})();
</script>
<script src="assets/js/sidebar.js?v=5"></script>
<script src="assets/js/otp-input.js"></script>
</body>
</html>