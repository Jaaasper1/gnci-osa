<?php
require_once 'includes/db.php';
require_once 'includes/audit.php';
require_once 'includes/auth.php';
require_once 'includes/lang.php';
require_once 'includes/csrf.php';
require_once 'includes/mailer.php';
require_once 'includes/password-login.php';

if (isLoggedIn()) {
    if ($_SESSION['user_type'] === 'student') header('Location: student/help.php');
    elseif ($_SESSION['user_type'] === 'staff')  header('Location: admin/dashboard.php');
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
    header('Location: index.php?mode=otp');
    exit;
}

// ── Login mode: 'password' (default) or 'otp' (email code, via ?mode=otp) ───
$mode = (($_GET['mode'] ?? '') === 'otp') ? 'otp' : 'password';

// ── Password login ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'password_login') {
    requireCsrf();
    $mode = 'password';
    $sid  = preg_replace('/[^0-9]/', '', (string)($_POST['username'] ?? ''));
    $pw   = (string)($_POST['password'] ?? '');
    $key  = 'P' . $sid;
    if ($sid === '' || $pw === '') {
        $error = t('msg.enter_your_student_id_and_password_91f1', 'Enter your Student ID and password.');
    } elseif (($wait = pwThrottleWait($pdo, $key)) > 0) {
        $error = sprintf(t('msg.too_many_wrong_attempts', 'Too many wrong attempts. Try again in %d minute(s), or use the email code instead.'), max(1, (int)ceil($wait / 60)));
    } elseif (loginStudent($pdo, $sid, $pw)) {
        pwThrottleClear($pdo, $key);
        auditLog($pdo, 'login', 'account', (int)$_SESSION['user_id'], [], ['role' => 'student', 'method' => 'password']);
        header('Location: student/help.php'); exit;
    } else {
        pwThrottleFail($pdo, $key);
        $error = t('msg.wrong_student_id_or_password_a91b', 'Wrong Student ID or password.');
    }
}

// ── STEP 1: Student ID → find account → send OTP to its stored email ──────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? 'student_id') === 'student_id') {
    $mode = 'otp';
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
            $error = t('register.err_otp_attempts', t('msg.too_many_incorrect_attempts_please_reque_2f99', 'Too many incorrect attempts. Please request a new code.'));
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
                auditLog($pdo, 'login', 'account', (int)$student['id'], [], ['role' => 'student']);
                header('Location: student/help.php'); exit;
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
    <link rel="stylesheet" href="assets/css/style.css?v=30">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php include __DIR__.'/includes/pwa-head.php'; ?></head>
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
                <h1 class="auth-title"><?= t('auto.otp_verification_eb4b', 'OTP Verification') ?></h1>
                <p class="auth-sub">
                    <?= t('auto.a_one_time_verification_code_has_been_se_edbb', 'A one-time verification code has been sent via email to') ?>
                    <strong><?= htmlspecialchars(maskEmail($pendingEmail)) ?></strong>.
                    Enter the code below to verify it.
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="index.php" id="otpVerifyForm" class="auth-form" data-loading-text="<?= htmlspecialchars(t('msg.verifying_1384', 'Verifying...'), ENT_QUOTES) ?>">
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
                    <span class="otp-waiting-text" style="<?= $resendWait > 0 ? '' : 'display:none;' ?>"><?= t('auto.resend_otp_in_fae8', 'Resend OTP in') ?> <span class="otp-timer">00:30</span></span>
                    <button type="submit" form="otpResendForm" style="<?= $resendWait > 0 ? 'display:none;' : '' ?>"><?= t('auto.resend_code_c378', 'Resend code') ?></button>
                </div>

                <button type="submit" form="otpVerifyForm" class="auth-btn"><?= t('auto.verify_otp_60bf', 'Verify OTP') ?></button>

            <?php else: ?>
                <?php if ($mode === 'password'): ?>
                <h1 class="auth-title"><?= t('auto.log_in_with_password_f159', 'Log in with password') ?></h1>
                <p class="auth-sub"><?= t('auto.enter_your_student_id_and_password_91f1', t('msg.enter_your_student_id_and_password_91f1', 'Enter your Student ID and password.')) ?></p>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <span><?= htmlspecialchars($error) ?></span></div>
                <?php endif; ?>

                <form method="POST" action="index.php?mode=password" class="auth-form" id="studentPwForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="step" value="password_login">
                    <div class="form-group">
                        <label>Student ID <span style="color:#991B1B;">*</span></label>
                        <input type="text" name="username" class="form-control" placeholder="ex. 2019****"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/[^0-9]/g,'')"
                               autocomplete="username" required autofocus>
                    </div>
                    <div class="form-group">
                        <label>Password <span style="color:#991B1B;">*</span></label>
                        <input type="password" name="password" class="form-control" autocomplete="current-password" required>
                    </div>
                    <button type="submit" class="auth-btn"><?= t('auto.log_in_bffe', 'Log in') ?></button>
                </form>

                <div class="auth-aside-box">
                    <a href="index.php?mode=otp"><?= t('auto.use_email_code_instead_4280', 'Use email code instead') ?></a>
                </div>
                <?php else: ?>
                <!-- STUDENT ID FORM (no password) -->
                <h1 class="auth-title"><?= t('login.welcome', 'Welcome!') ?></h1>
                <p class="auth-sub"><?= t('login.subtitle_otp', "Enter your Student ID and we'll email you a login code.") ?></p>

                <?php if ($error): ?>
                    <div class="alert alert-error" id="throttle-alert"<?= $throttleWait > 0 ? ' data-no-dismiss="1" data-wait="' . (int)$throttleWait . '"' : '' ?>><i class="ti ti-alert-circle"></i> <span id="throttle-alert-text"><?= htmlspecialchars($error) ?></span></div>
                <?php endif; ?>

                <form method="POST" action="index.php?mode=otp" class="auth-form" id="studentIdForm" data-loading-text="<?= htmlspecialchars(t('msg.sending_code_5079', 'Sending code...'), ENT_QUOTES) ?>">
                    <input type="hidden" name="step" value="student_id">
                    <div class="form-group<?= $error ? ' has-error' : '' ?>">
                        <label><?= t('login.label_student_id', 'Student ID') ?> <span style="color:#991B1B;">*</span></label>
                        <input type="text" name="username" class="form-control"
                               placeholder="ex. 2019****"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/[^0-9]/g,'')"
                               autocomplete="off" required autofocus>
                    </div>

                    <div class="auth-field-note"><?= t('login.no_email_help', "Can't receive a code? Visit the OSA office for help.") ?></div>

                    <button type="submit" class="auth-btn" id="sendCodeBtn">
                        <span id="sendCodeBtnLabel"><?= t('auto.send_login_code_a68e', 'Send Login Code') ?></span>
                    </button>
                </form>



                <div class="auth-aside-box">
                    <a href="index.php"><?= t('auto.log_in_with_password_instead_665e', 'Log in with password instead') ?></a>
                </div>
                <?php endif; ?>
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
    <a href="live-results.php" class="liveresults-fab" data-loading-text="<?= htmlspecialchars(t('msg.loading_live_results_d3d4', 'Loading live results...'), ENT_QUOTES) ?>">
        <i class="ti ti-chart-pie"></i>
        <span><?= t('auto.live_election_results_9de3', 'Live Election Results') ?></span>
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
        if (text)  text.textContent = <?= json_encode(t('msg.wait_before_code', 'Please wait %s before requesting another code.')) ?>.replace('%s', fmt(seconds));
        if (label) label.textContent = <?= json_encode(t('msg.try_again_in', 'Try again in %s')) ?>.replace('%s', fmt(seconds));
    }
    function unlock() {
        clearInterval(timer);
        alertEl.className = 'alert alert-success';
        var ic = alertEl.querySelector('i'); if (ic) ic.className = 'ti ti-circle-check';
        if (text)  text.textContent = __t('You can request a new login code now.');
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
<script>
// Live Results button: slide shut into a circle after 4s idle or when scrolled; hover/focus/tap opens it again.
(function(){
    var fab = document.querySelector('.liveresults-fab'); if (!fab) return;
    var timer = null;
    function collapse(){ fab.classList.add('collapsed'); }
    function expand(){ fab.classList.remove('collapsed'); clearTimeout(timer); timer = setTimeout(collapse, 4000); }
    timer = setTimeout(collapse, 4000);
    // Scroll events must NOT restart the 4s timer while the button is already open (they can fire repeatedly).
    // Only re-open when the visitor comes back up to the top AFTER having scrolled down; small scrolls near the top do nothing.
    var scrolledDown = false;
    window.addEventListener('scroll', function(){
        if (window.scrollY > 80) { scrolledDown = true; clearTimeout(timer); collapse(); }
        else if (scrolledDown) { scrolledDown = false; expand(); }
    }, {passive:true});
    fab.addEventListener('mouseenter', function(){ clearTimeout(timer); });
    fab.addEventListener('mouseleave', function(){ timer = setTimeout(collapse, 2500); });
    fab.addEventListener('touchstart', function(){ if (fab.classList.contains('collapsed')) expand(); }, {passive:true});
})();
</script>
<script src="assets/js/sidebar.js?v=5"></script>
<script src="assets/js/otp-input.js"></script>
</body>
</html>