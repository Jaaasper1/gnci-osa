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
$throttleWait = 0;
$stage = empty($_SESSION['staff_login_pending']) ? 'form' : 'otp';

// Throttle key for staff (shares the login_otp_requests table with students;
// the "S" prefix keeps it from ever colliding with a numeric Student ID).
function staffThrottleKey($staffId) { return 'S' . (int)$staffId; }

// ── Cancel / start over ─────────────────────────────────────────────────────
if (isset($_GET['cancel'])) {
    unset($_SESSION['staff_login_pending']);
    header('Location: staff-login.php');
    exit;
}

// ── STEP 1: username → send OTP to the email on file ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? 'username') === 'username') {
    requireCsrf();
    $username = trim($_POST['username'] ?? '');
    // One generic message for unknown / disabled / no-email accounts, so the
    // form doesn't reveal which usernames exist.
    $genericErr = t('login.err_invalid_staff_otp', 'We could not send a code for this account. Check the username, or contact the system admin.');

    if ($username === '') {
        $error = t('login.err_required_username', 'Please enter your username.');
    } else {
        $staff = findActiveStaffByUsername($pdo, $username);
        if (!$staff || empty($staff['email'])) {
            $error = $genericErr;
        } elseif (($otpWait = otpThrottleWait($pdo, staffThrottleKey($staff['id']))) > 0) {
            $throttleWait = $otpWait;
            $error = otpWaitMessage($otpWait);
        } else {
            otpThrottleRecord($pdo, staffThrottleKey($staff['id']));
            $otp  = generateOtpCode();
            $sent = sendRegistrationOtp($staff['email'], $staff['first_name'], $otp, 'login');
            if (!$sent) {
                $error = t('login.err_otp_send', 'Could not send the verification code. Please try again or contact the OSA office.');
            } else {
                $_SESSION['staff_login_pending'] = [
                    'staff_id'   => (int)$staff['id'],
                    'email'      => $staff['email'],
                    'first_name' => $staff['first_name'],
                    'otp_hash'   => hash('sha256', $otp),   // never keep the plain code
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

    if (empty($_SESSION['staff_login_pending'])) {
        $error = t('login.err_session_expired', 'Your session expired. Please log in again.');
        $stage = 'form';
    } else {
        $pending    = $_SESSION['staff_login_pending'];
        $enteredOtp = trim($_POST['otp'] ?? '');

        if (time() > $pending['expires_at']) {
            $error = t('register.err_otp_expired', 'This code has expired. Please request a new one.');
        } elseif ($pending['attempts'] >= 5) {
            $error = t('register.err_otp_attempts', t('msg.too_many_incorrect_attempts_please_reque_2f99', 'Too many incorrect attempts. Please request a new code.'));
        } elseif (!$enteredOtp || !hash_equals($pending['otp_hash'], hash('sha256', $enteredOtp))) {
            $_SESSION['staff_login_pending']['attempts'] = $pending['attempts'] + 1;
            $error = t('register.err_otp_wrong', 'Incorrect code. Please try again.');
        } else {
            $stf = $pdo->prepare("SELECT * FROM osa_staff WHERE id = ? AND is_active = 1");
            $stf->execute([$pending['staff_id']]);
            $staff = $stf->fetch();
            unset($_SESSION['staff_login_pending']);   // code is single-use
            if (!$staff) {
                $error = t('login.err_invalid_staff_otp', 'We could not send a code for this account. Check the username, or contact the system admin.');
                $stage = 'form';
            } else {
                finalizeStaffSession($staff);
                if (!empty($staff['email'])) {
                    sendStaffLoginNotice($staff['email'], $staff['first_name']);
                }
                header('Location: ' . ($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'osa/dashboard.php'));
                exit;
            }
        }
    }
}

// ── Resend OTP ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'resend') {
    requireCsrf();
    $stage = 'otp';

    if (empty($_SESSION['staff_login_pending'])) {
        $error = t('login.err_session_expired', 'Your session expired. Please log in again.');
        $stage = 'form';
    } elseif (time() - $_SESSION['staff_login_pending']['last_sent'] < 30) {
        $error = t('register.err_otp_cooldown', 'Please wait a bit before requesting another code.');
    } elseif (($otpWait = otpThrottleWait($pdo, staffThrottleKey($_SESSION['staff_login_pending']['staff_id']))) > 0) {
        $error = otpWaitMessage($otpWait);
    } else {
        otpThrottleRecord($pdo, staffThrottleKey($_SESSION['staff_login_pending']['staff_id']));
        $otp  = generateOtpCode();
        $sent = sendRegistrationOtp($_SESSION['staff_login_pending']['email'], $_SESSION['staff_login_pending']['first_name'], $otp, 'login');
        if ($sent) {
            $_SESSION['staff_login_pending']['otp_hash']   = hash('sha256', $otp);
            $_SESSION['staff_login_pending']['expires_at'] = time() + 600;
            $_SESSION['staff_login_pending']['attempts']   = 0;
            $_SESSION['staff_login_pending']['last_sent']  = time();
        } else {
            $error = t('login.err_otp_send', 'Could not send the verification code. Please try again.');
        }
    }
}

$pendingEmail = $_SESSION['staff_login_pending']['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GNC-OSA Portal — Staff / Admin Login</title>
    <link rel="stylesheet" href="assets/css/style.css?v=29">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
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
                    if (!empty($_SESSION['staff_login_pending']['last_sent'])) {
                        $resendWait = max(0, 30 - (time() - $_SESSION['staff_login_pending']['last_sent']));
                    }
                    if (!empty($_SESSION['staff_login_pending']['staff_id'])) {
                        $resendWait = max($resendWait, otpThrottleWait($pdo, staffThrottleKey($_SESSION['staff_login_pending']['staff_id'])));
                    }
                ?>
                <!-- OTP VERIFICATION STEP -->
                <a href="staff-login.php?cancel=1" class="auth-back"><i class="ti ti-arrow-left"></i> <?= t('common.go_back', 'Go back') ?></a>
                <h1 class="auth-title"><?= t('auto.otp_verification_eb4b', 'OTP Verification') ?></h1>
                <p class="auth-sub">
                    <?= t('auto.a_one_time_verification_code_has_been_se_edbb', 'A one-time verification code has been sent via email to') ?>
                    <strong><?= htmlspecialchars(maskEmail($pendingEmail)) ?></strong>.
                    Enter the code below to verify it.
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="staff-login.php" id="otpVerifyForm" class="auth-form" data-loading-text="<?= htmlspecialchars(t('msg.verifying_1384', 'Verifying...'), ENT_QUOTES) ?>">
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

                <form method="POST" action="staff-login.php" id="otpResendForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="step" value="resend">
                </form>

                <div class="otp-resend-row" data-resend-timer="<?= (int)$resendWait ?>" style="text-align:left;">
                    <span class="otp-waiting-text" style="<?= $resendWait > 0 ? '' : 'display:none;' ?>"><?= t('auto.resend_otp_in_fae8', 'Resend OTP in') ?> <span class="otp-timer">00:30</span></span>
                    <button type="submit" form="otpResendForm" style="<?= $resendWait > 0 ? 'display:none;' : '' ?>"><?= t('auto.resend_code_c378', 'Resend code') ?></button>
                </div>

                <button type="submit" form="otpVerifyForm" class="auth-btn"><?= t('auto.verify_otp_60bf', 'Verify OTP') ?></button>

            <?php else: ?>
                <!-- USERNAME FORM (no password) -->
                <a href="index.php" class="auth-back"><i class="ti ti-arrow-left"></i> <?= t('common.go_back', 'Go back') ?></a>
                <h1 class="auth-title"><?= t('login.welcome', 'Welcome!') ?></h1>
                <p class="auth-sub"><?= t('auto.enter_your_staff_or_admin_username_and_w_b7dc', 'Enter your staff or admin username and we\'ll email you a login code.') ?></p>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="staff-login.php" class="auth-form" data-loading-text="<?= htmlspecialchars(t('msg.sending_code_5079', 'Sending code...'), ENT_QUOTES) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="step" value="username">
                    <div class="form-group<?= $error ? ' has-error' : '' ?>">
                        <label><?= t('common.username', 'Username') ?> <span style="color:#991B1B;">*</span></label>
                        <input type="text" name="username" class="form-control"
                               placeholder="<?= t('login.placeholder_osa_username', 'OSA username') ?>"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               autocomplete="off" required autofocus>
                    </div>

                    <button type="submit" class="auth-btn"><?= t('auto.send_login_code_a68e', 'Send Login Code') ?></button>
                </form>

                <p class="auth-hint">
                    <?= t('auto.this_login_is_for_cf79', 'This login is for') ?> <strong><?= t('auto.osa_staff_and_admin_accounts_only_97bf', 'OSA Staff and Admin accounts only') ?></strong><?= t('auto.students_should_use_the_student_login_in_5e50', '. Students should use the student login instead.') ?>
                </p>

                <div class="auth-aside-box">
                    <?= t('auto.are_you_a_student_ef24', 'Are you a student?') ?>
                    <a href="index.php"><?= t('auto.go_to_student_login_745c', 'Go to Student Login') ?></a>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="assets/js/sidebar.js?v=5"></script>
<script src="assets/js/otp-input.js"></script>
<script>
(function () {
    var v = document.querySelector('.login-bg-video');
    if (!v) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { v.removeAttribute('autoplay'); v.pause(); return; } // show the poster only
    v.muted = true;
    var p = v.play();
    if (p && p.catch) p.catch(function () {});
})();
</script>
</body>
</html>
