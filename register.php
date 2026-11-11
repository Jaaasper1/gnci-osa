<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/lang.php';
require_once 'includes/functions.php';
require_once 'includes/csrf.php';
require_once 'includes/mailer.php';
require_once 'includes/password-policy.php';

if (isLoggedIn()) {
    if ($_SESSION['user_type'] === 'student') header('Location: student/dashboard.php');
    elseif ($_SESSION['user_type'] === 'staff')  header('Location: admin/dashboard.php');
    elseif ($_SESSION['user_type'] === 'admin')  header('Location: admin/dashboard.php');
    exit;
}

$error   = '';
$success = false;
$courses = getCourseOptions();

// Which screen to show: 'form' (fill up details) or 'otp' (enter the code)
$stage = 'form';
if (!empty($_SESSION['reg_pending'])) {
    $stage = 'otp';
}

// Deletes a pending COR upload from disk (used on cancel / duplicate / failed send)
function deletePendingCor() {
    if (!empty($_SESSION['reg_pending']['cor_file'])) {
        $path = __DIR__ . '/uploads/' . $_SESSION['reg_pending']['cor_file'];
        if (is_file($path)) @unlink($path);
    }
}

// ── Handle "Cancel / start over" ────────────────────────────────────────────
if (isset($_GET['cancel'])) {
    deletePendingCor();
    unset($_SESSION['reg_pending']);
    header('Location: register.php');
    exit;
}

// ── STEP 1: submit registration form → validate → send OTP ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'details') {
    requireCsrf();

    $sid    = trim($_POST['student_id'] ?? '');
    $fname  = trim($_POST['first_name'] ?? '');
    $lname  = trim($_POST['last_name']  ?? '');
    $email  = trim($_POST['email']      ?? '');
    $course = trim($_POST['course']     ?? '');
    $pw     = (string)($_POST['password'] ?? '');
    $pw2    = (string)($_POST['password_confirm'] ?? '');

    if (!$sid || !$fname || !$lname || !$email || !$course) {
        $error = t('register.err_required', t('msg.please_fill_in_all_required_fields_d5a0', 'Please fill in all required fields.'));
    } elseif (!preg_match('/^\d{6,12}$/', $sid)) {
        $error = t('register.err_sid_format', 'Invalid Student ID format. Numbers only, no dashes (e.g. 20193372).');
    } elseif (!in_array($course, $courses, true)) {
        $error = t('msg.please_select_a_valid_course_from_the_li_ea27', 'Please select a valid course from the list.');
    } elseif (($pwErr = passwordPolicyError($pw, [$sid, $email], 8, [$fname, $lname])) !== null) {
        $error = $pwErr;
    } elseif ($pw !== $pw2) {
        $error = t('msg.the_passwords_do_not_match_f6d1', 'The passwords do not match.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = t('register.err_email', 'Invalid email address.');
    } elseif (!preg_match('/@gmail\.com$/i', $email)) {
        $error = t('register.err_email_gmail', 'Please use a Gmail address (must end in @gmail.com).');
    } elseif (empty($_FILES['cor_file']['name'])) {
        $error = t('register.err_cor_required', t('msg.please_attach_a_copy_of_your_certificate_1c59', 'Please attach a copy of your Certificate of Registration (COR).'));
    } else {
        $check = $pdo->prepare("SELECT id FROM students WHERE student_id = ? OR email = ?");
        $check->execute([$sid, $email]);
        if ($check->fetch()) {
            $error = t('register.err_duplicate', 'This Student ID or email is already registered. Contact the OSA office if you have a concern.');
        } else {
            $corResult = uploadCorFile($_FILES['cor_file']);
            if (!empty($corResult['error'])) {
                $error = $corResult['error'];
            } else {
                $otp = generateOtpCode();
                $sent = sendRegistrationOtp($email, $fname, $otp);

                if (!$sent) {
                    // Clean up — no point keeping the COR file if we couldn't send the OTP
                    @unlink(__DIR__ . '/uploads/' . $corResult['filename']);
                    $error = t('register.err_otp_send', 'Could not send the verification code. Please check your email address and try again.');
                } else {
                    $_SESSION['reg_pending'] = [
                        'student_id'  => $sid,
                        'first_name'  => $fname,
                        'last_name'   => $lname,
                        'email'       => $email,
                        'course'      => $course,
                        'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                        'cor_file'    => $corResult['filename'],
                        'otp'         => $otp,
                        'expires_at'  => time() + 600, // 10 minutes
                        'attempts'    => 0,
                        'last_sent'   => time(),
                    ];
                    $stage = 'otp';
                }
            }
        }
    }
}

// ── STEP 2: verify OTP → create account ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'verify') {
    requireCsrf();

    if (empty($_SESSION['reg_pending'])) {
        $error = t('register.err_session_expired', 'Your session expired. Please fill in the form again.');
        $stage = 'form';
    } else {
        $pending    = $_SESSION['reg_pending'];
        $enteredOtp = trim($_POST['otp'] ?? '');
        $stage = 'otp';

        if (time() > $pending['expires_at']) {
            $error = t('register.err_otp_expired', 'This code has expired. Please request a new one.');
        } elseif ($pending['attempts'] >= 5) {
            $error = t('register.err_otp_attempts', t('msg.too_many_incorrect_attempts_please_reque_2f99', 'Too many incorrect attempts. Please request a new code.'));
        } elseif (!$enteredOtp || !hash_equals($pending['otp'], $enteredOtp)) {
            $_SESSION['reg_pending']['attempts'] = $pending['attempts'] + 1;
            $error = t('register.err_otp_wrong', 'Incorrect code. Please try again.');
        } else {
            // OTP correct — double-check no duplicate slipped in, then create the account.
            // The account is passwordless (login is by Student ID + email OTP), but the
            // `password` column is NOT NULL, so we fill it with a random, unusable hash
            // purely to satisfy the schema — it is never shown or used to log in.
            $check = $pdo->prepare("SELECT id FROM students WHERE student_id = ? OR email = ?");
            $check->execute([$pending['student_id'], $pending['email']]);
            if ($check->fetch()) {
                $error = t('register.err_duplicate', 'This Student ID or email is already registered. Contact the OSA office if you have a concern.');
                deletePendingCor();
                unset($_SESSION['reg_pending']);
                $stage = 'form';
            } else {
                // Use the password the student chose. Older pending sessions without one get a random hash.
                $unusedHash = $pending['password_hash'] ?? password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO students (student_id, first_name, last_name, email, course, cor_file, password, is_active)
                               VALUES (?, ?, ?, ?, ?, ?, ?, 0)")
                    ->execute([
                        $pending['student_id'], $pending['first_name'], $pending['last_name'],
                        $pending['email'], $pending['course'], $pending['cor_file'] ?? null, $unusedHash,
                    ]);
                unset($_SESSION['reg_pending']);
                $success = true;
            }
        }
    }
}

// ── Resend OTP ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'resend') {
    requireCsrf();
    $stage = 'otp';

    if (empty($_SESSION['reg_pending'])) {
        $error = t('register.err_session_expired', 'Your session expired. Please fill in the form again.');
        $stage = 'form';
    } elseif (time() - $_SESSION['reg_pending']['last_sent'] < 30) {
        $error = t('register.err_otp_cooldown', 'Please wait a bit before requesting another code.');
    } else {
        $otp  = generateOtpCode();
        $sent = sendRegistrationOtp($_SESSION['reg_pending']['email'], $_SESSION['reg_pending']['first_name'], $otp);
        if ($sent) {
            $_SESSION['reg_pending']['otp']        = $otp;
            $_SESSION['reg_pending']['expires_at'] = time() + 600;
            $_SESSION['reg_pending']['attempts']   = 0;
            $_SESSION['reg_pending']['last_sent']  = time();
        } else {
            $error = t('register.err_otp_send', 'Could not send the verification code. Please try again.');
        }
    }
}

$pendingEmail = $_SESSION['reg_pending']['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.register', 'Register') ?> — GNC-OSA Portal</title>
    <link rel="stylesheet" href="assets/css/style.css?v=31">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php include __DIR__.'/includes/pwa-head.php'; ?></head>
<body class="auth-split-page">
<div class="auth-split">
    <?php include 'includes/auth-brand.php'; ?>

    <main class="auth-main">
        <div class="auth-lang"><?php renderLangSwitcher(); ?></div>

        <div class="auth-panel is-wide">
            <?php include 'includes/auth-id.php'; ?>
            <?php if ($success): ?>
                <!-- SUCCESS STATE -->
                <div style="width:64px;height:64px;background:#D1FAE5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:18px;">
                    <i class="ti ti-clock" style="font-size:30px;color:#059669;"></i>
                </div>
                <h1 class="auth-title"><?= t('register.submitted_title', 'Your request has been submitted!') ?></h1>
                <p class="auth-sub">
                    <?= t('register.submitted_body1', 'Your account is') ?> <strong><?= t('register.pending_approval', 'pending approval') ?></strong> <?= t('register.submitted_body2', 'from the OSA Admin. Wait for a notification or return here to log in once approved.') ?>
                </p>
                <div class="alert" style="background:#FEF3C7;border:1px solid #FCD34D;">
                    <i class="ti ti-info-circle" style="color:#92400E;"></i>
                    <span style="color:#92400E;"><?= t('register.approval_note', 'Once approved, log in anytime with just your Student ID — we\'ll email you a code.') ?></span>
                </div>
                <a href="index.php" class="auth-btn" data-loading-text="<?= htmlspecialchars(t('msg.back_to_login_6c85', 'Back to login...'), ENT_QUOTES) ?>">
                    <?= t('register.back_to_login', 'Back to Login') ?>
                </a>

            <?php elseif ($stage === 'otp'): ?>
                <?php
                    $resendWait = 0;
                    if (!empty($_SESSION['reg_pending']['last_sent'])) {
                        $resendWait = max(0, 30 - (time() - $_SESSION['reg_pending']['last_sent']));
                    }
                ?>
                <!-- OTP VERIFICATION STEP -->
                <a href="register.php?cancel=1" class="auth-back"><i class="ti ti-arrow-left"></i> <?= t('register.start_over', 'Start over') ?></a>
                <h1 class="auth-title"><?= t('auto.otp_verification_eb4b', 'OTP Verification') ?></h1>
                <p class="auth-sub">
                    <?= t('auto.a_one_time_verification_code_has_been_se_edbb', 'A one-time verification code has been sent via email to') ?>
                    <strong><?= htmlspecialchars(maskEmail($pendingEmail)) ?></strong>.
                    Enter the code below to verify it.
                </p>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="register.php" id="otpVerifyFormReg" class="auth-form" data-loading-text="<?= htmlspecialchars(t('msg.verifying_1384', 'Verifying...'), ENT_QUOTES) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="step" value="verify">
                    <div class="form-group">
                        <input type="hidden" name="otp" id="otp-register">
                        <div class="otp-boxes" data-target="otp-register" data-has-error="<?= $error ? '1' : '0' ?>" style="justify-content:flex-start;">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="one-time-code">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                            <input type="text" class="otp-box form-control" inputmode="numeric" maxlength="1" autocomplete="off">
                        </div>
                    </div>
                </form>

                <form method="POST" action="register.php" id="otpResendFormReg">
                    <?= csrfField() ?>
                    <input type="hidden" name="step" value="resend">
                </form>

                <div class="otp-resend-row" data-resend-timer="<?= (int)$resendWait ?>" style="text-align:left;">
                    <span class="otp-waiting-text" style="<?= $resendWait > 0 ? '' : 'display:none;' ?>"><?= t('auto.resend_otp_in_fae8', 'Resend OTP in') ?> <span class="otp-timer">00:30</span></span>
                    <button type="submit" form="otpResendFormReg" style="<?= $resendWait > 0 ? 'display:none;' : '' ?>"><?= t('auto.resend_code_c378', 'Resend code') ?></button>
                </div>

                <button type="submit" form="otpVerifyFormReg" class="auth-btn"><?= t('auto.verify_otp_60bf', 'Verify OTP') ?></button>

            <?php else: ?>
                <!-- REGISTER FORM (STEP 1) -->
                <a href="index.php" class="auth-back" data-loading-text="<?= htmlspecialchars(t('msg.back_to_login_6c85', 'Back to login...'), ENT_QUOTES) ?>"><i class="ti ti-arrow-left"></i> <?= t('common.go_back', 'Go back') ?></a>
                <h1 class="auth-title"><?= t('login.register', 'Register') ?></h1>
                <p class="auth-sub"><?= t('register.subtitle_otp', "Create an account. Set a password, or log in with an email code each time.") ?></p>

                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form id="reg-form" method="POST" action="register.php" class="auth-form" enctype="multipart/form-data" data-loading-text="<?= htmlspecialchars(t('msg.sending_verification_code_738a', 'Sending verification code...'), ENT_QUOTES) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="step" value="details">

                    <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:10px 14px;margin-bottom:18px;font-size:13px;color:#166534;">
                        <i class="ti ti-shield-check" style="margin-right:4px;"></i>
                        <?= t('register.approval_notice1', 'Your account must be') ?> <strong><?= t('register.approval_notice2', 'approved by the OSA Admin') ?></strong> <?= t('register.approval_notice3', 'before you can log in.') ?>
                    </div>

                    <!-- Student ID & Email -->
                    <div class="form-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label><?= t('login.label_student_id', 'Student ID') ?> <span style="color:#991B1B;">*</span></label>
                            <input type="text" name="student_id" class="form-control"
                                   placeholder="2019****"
                                   value="<?= htmlspecialchars($_POST['student_id'] ?? '') ?>"
                                   inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/[^0-9]/g,'')"
                                   autocomplete="off" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label><?= t('common.email', 'Email') ?> <span style="color:#991B1B;">*</span></label>
                            <input type="email" name="email" class="form-control"
                                   placeholder="juandelacruz@gmail.com"
                                   pattern="^[^\s@]+@gmail\.com$"
                                   title="<?= htmlspecialchars(t('auto.must_be_a_gmail_address_e_g_juan_gmail_c_f63c', 'Must be a Gmail address (e.g. juan@gmail.com)'), ENT_QUOTES) ?>"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="off" required>
                        </div>
                    </div>
                    <div style="font-size:12px;color:#6B7280;margin-top:4px;">
                        <i class="ti ti-info-circle"></i> <?= t('auto.must_be_a_gmail_address_this_is_also_how_24ae', 'Must be a Gmail address — this is also how you\'ll log in, we\'ll send a code here every time.') ?>
                    </div>

                    <div style="height:12px;"></div>

                    <div class="form-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label><?= t('register.first_name', 'First Name') ?> <span style="color:#991B1B;">*</span></label>
                            <input type="text" name="first_name" class="form-control"
                                   placeholder="Juan"
                                   value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
                                   autocomplete="off" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label><?= t('register.last_name', 'Last Name') ?> <span style="color:#991B1B;">*</span></label>
                            <input type="text" name="last_name" class="form-control"
                                   placeholder="Dela Cruz"
                                   value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"
                                   autocomplete="off" required>
                        </div>
                    </div>

                    <div style="height:12px;"></div>

                    <!-- Course -->
                    <div class="form-group" style="margin-bottom:0;">
                        <label><?= t('register.course', 'Course') ?> <span style="color:#EF4444;">*</span></label>
                        <select name="course" class="form-control" required>
                            <option value="">— <?= t('common.select', 'Select') ?> —</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= $c ?>" <?= (($_POST['course'] ?? '') === $c) ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="height:12px;"></div>

                    <!-- Password (used with Student ID to log in, or leave it and use the email code) -->
                    <div class="form-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Password <span style="color:#991B1B;">*</span></label>
                            <input type="password" name="password" class="form-control" minlength="8"
                                   placeholder="<?= htmlspecialchars(t('auto.at_least_8_characters_f641', 'At least 8 characters'), ENT_QUOTES) ?>" maxlength="72" autocomplete="new-password" required>
                            <ul id="pw-rules" class="pw-rules">
                        <li data-r="len"><i class="ti ti-circle"></i> <?= t('pw.rule_len', 'At least 8 characters') ?></li>
                        <li data-r="up"><i class="ti ti-circle"></i> <?= t('pw.rule_up', 'One uppercase letter (A-Z)') ?></li>
                        <li data-r="low"><i class="ti ti-circle"></i> <?= t('pw.rule_low', 'One lowercase letter (a-z)') ?></li>
                        <li data-r="num"><i class="ti ti-circle"></i> <?= t('pw.rule_num', 'One number (0-9)') ?></li>
                        <li data-r="sym"><i class="ti ti-circle"></i> <?= t('pw.rule_sym', 'One special character (! @ # $ % &)') ?></li>
                    </ul>
                    <div id="pw-bad" class="pw-bad" role="alert"></div>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label><?= t('auto.confirm_password_4c23', 'Confirm password') ?> <span style="color:#991B1B;">*</span></label>
                            <input type="password" name="password_confirm" class="form-control" minlength="8"
                                   placeholder="<?= htmlspecialchars(t('auto.retype_password_1778', 'Retype password'), ENT_QUOTES) ?>" autocomplete="new-password" required>
                            <div id="pw-match-msg" role="status" aria-live="polite" style="display:none;font-size:12px;margin-top:5px;font-weight:600;"></div>
                        </div>
                    </div>
                    <div style="font-size:12px;color:#6B7280;margin-top:4px;">
                        <i class="ti ti-info-circle"></i> <?= t('auto.you_can_log_in_with_your_password_or_use_b627', 'You can log in with your password, or use the email code.') ?>
                    </div>

                    <div style="height:12px;"></div>

                    <!-- Certificate of Registration (COR) attachment -->
                    <div class="form-group" style="margin-bottom:0;">
                        <label><?= t('register.cor_label', 'Certificate of Registration (COR)') ?> <span style="color:#991B1B;">*</span></label>
                        <input type="file" name="cor_file" id="cor-file" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                        <div id="cor-size" role="status" aria-live="polite" style="display:none;margin-top:6px;font-size:12.5px;font-weight:600;"></div>
                        <div style="font-size:12px;color:#6B7280;margin-top:4px;">
                            <i class="ti ti-info-circle"></i> <?= t('register.cor_hint', 'Upload a clear photo or PDF of your COR this school year, so OSA can confirm you\'re an enrolled student. JPG, PNG, or PDF, max 5MB.') ?>
                        </div>
                    </div>

                    <div style="height:20px;"></div>

                    <button type="submit" class="auth-btn"><?= t('auto.send_verification_code_fe80', 'Send Verification Code') ?></button>
                </form>

                <div class="auth-aside-box">
                    <?= t('register.have_account', 'Already have an account?') ?>
                    <a href="index.php" data-loading-text="<?= htmlspecialchars(t('msg.back_to_login_6c85', 'Back to login...'), ENT_QUOTES) ?>"><?= t('register.login_link', 'Log in') ?></a>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<style>
.pw-rules{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:3px;font-size:12px;color:#6B7280}
.pw-rules li i{font-size:13px;vertical-align:-1px}
.pw-rules li.ok{color:#15803D}
.pw-rules li.no{color:#B91C1C}
.pw-bad{display:none;margin-top:6px;font-size:12px;font-weight:600;color:#B91C1C}
html[data-theme="dark"] .pw-rules li.ok{color:#4ADE80}
html[data-theme="dark"] .pw-rules li.no{color:#F87171}
html[data-theme="dark"] .pw-bad{color:#F87171}
</style>
<script src="assets/js/sidebar.js?v=5"></script>
<script src="assets/js/pw-policy.js?v=1"></script>
<script>
PwPolicy.attach({
    input: document.querySelector('input[name="password"]'),
    list: document.getElementById('pw-rules'),
    bad: document.getElementById('pw-bad'),
    cfg: <?= json_encode(passwordPolicyJsConfig(8), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>,
    ctx: function(){ return [(document.querySelector('[name="student_id"]')||{}).value, (document.querySelector('[name="email"]')||{}).value]; },
    names: function(){ return [(document.querySelector('[name="first_name"]')||{}).value, (document.querySelector('[name="last_name"]')||{}).value]; }
});
</script>
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
<script>
// Live "passwords match" check on the Confirm password field
(function(){
    var p1 = document.querySelector('input[name="password"]');
    var p2 = document.querySelector('input[name="password_confirm"]');
    var msg = document.getElementById('pw-match-msg');
    if (!p1 || !p2 || !msg) return;
    var T_BAD = <?= json_encode(t('msg.passwords_do_not_match_live', 'Passwords do not match.')) ?>;
    var T_OK  = <?= json_encode(t('msg.passwords_match_live', 'Passwords match.')) ?>;
    function check(){
        if (p2.value === '') { msg.style.display = 'none'; p2.setCustomValidity(''); return; }
        var ok = p1.value === p2.value;
        msg.style.display = 'block';
        msg.style.color = ok ? '#15803D' : '#991B1B';
        msg.textContent = (ok ? '\u2714 ' : '\u2716 ') + (ok ? T_OK : T_BAD);
        p2.setCustomValidity(ok ? '' : T_BAD);
    }
    p1.addEventListener('input', check);
    p2.addEventListener('input', check);
})();
</script>
<script>
// COR: live size indicator (max 5MB) + submit via fetch so the page never reloads
// on an error -- the selected COR file stays in place.
(function(){
    var form = document.getElementById('reg-form');
    var file = document.getElementById('cor-file');
    var box  = document.getElementById('cor-size');
    if (!form || !file || !box) return;
    var MAX = 5 * 1024 * 1024;
    var T_OVER = <?= json_encode(t('register.cor_over', 'is too large. Max is 5 MB.')) ?>;
    var T_OVERBY = <?= json_encode(t('register.cor_over_by', 'Over by')) ?>;
    var T_BADTYPE = <?= json_encode(t('msg.cor_must_be_a_jpg_png_or_pdf_file_76ff', 'COR must be a JPG, PNG, or PDF file.')) ?>;
    function mb(b){ return (b / 1048576).toFixed(2) + ' MB'; }
    function typeOk(f){ return /\.(jpe?g|png|pdf)$/i.test(f.name); }
    function state(){
        var f = file.files && file.files[0];
        file.setCustomValidity('');
        if (!f) { box.style.display = 'none'; return true; }
        box.style.display = 'block';
        if (!typeOk(f)) {
            box.style.color = '#991B1B'; box.textContent = '\u2716 ' + T_BADTYPE;
            file.setCustomValidity(T_BADTYPE); return false;
        }
        if (f.size > MAX) {
            box.style.color = '#991B1B';
            box.textContent = '\u2716 ' + f.name + ' (' + mb(f.size) + ') ' + T_OVER + ' ' + T_OVERBY + ' ' + mb(f.size - MAX) + '.';
            file.setCustomValidity(T_OVER); return false;
        }
        box.style.color = '#15803D';
        box.textContent = '\u2714 ' + f.name + ' \u2014 ' + mb(f.size) + ' / 5 MB';
        return true;
    }
    file.addEventListener('change', state);

    function showError(msg){
        var el = document.getElementById('reg-ajax-alert');
        if (!el) {
            el = form.parentNode.querySelector('.alert-error');
            if (!el) { el = document.createElement('div'); form.parentNode.insertBefore(el, form); }
            el.id = 'reg-ajax-alert';
        }
        el.className = 'alert alert-error';
        el.innerHTML = '<i class="ti ti-alert-circle"></i> ';
        el.appendChild(document.createTextNode(msg));
        el.scrollIntoView({behavior:'smooth', block:'center'});
    }
    function hideOverlay(){
        var o = document.querySelector('.app-loading-overlay');
        if (o) o.classList.remove('show');
    }

    // Capture phase: runs before the global loading-overlay submit handler.
    document.addEventListener('submit', function(e){
        if (e.target !== form) return;
        if (!form.checkValidity()) { state(); return; }        // native messages first
        e.preventDefault(); e.stopImmediatePropagation();
        if (!state()) { form.reportValidity(); return; }
        if (window.appShowLoadingOverlay) window.appShowLoadingOverlay(form.dataset.loadingText || 'Loading...');
        fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin'})
          .then(function(r){ return r.text().then(function(t){ return {ok:r.ok, text:t}; }); })
          .then(function(res){
              var doc = new DOMParser().parseFromString(res.text, 'text/html');
              var newForm = doc.getElementById('reg-form');
              if (!newForm) {                     // success -> OTP screen
                  window.location.href = 'register.php'; return;
              }
              hideOverlay();
              var err = doc.querySelector('.auth-panel .alert-error');
              showError(err ? err.textContent.trim() : 'Something went wrong. Please try again.');
              var tok = newForm.querySelector('input[name="csrf_token"]');
              var cur = form.querySelector('input[name="csrf_token"]');
              if (tok && cur) cur.value = tok.value;
          })
          .catch(function(){
              hideOverlay();
              showError('Network error. Please check your connection and try again.');
          });
    }, true);
})();
</script>
</body>
</html>
