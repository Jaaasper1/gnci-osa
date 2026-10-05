<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/lang.php';

if (isLoggedIn()) {
    if ($_SESSION['user_type'] === 'student') header('Location: student/events.php');
    elseif ($_SESSION['user_type'] === 'staff')  header('Location: osa/dashboard.php');
    elseif ($_SESSION['user_type'] === 'admin')  header('Location: admin/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = t('login.err_required', 'Please enter your username/ID and password.');
    } elseif (loginStaff($pdo, $username, $password)) {
        if ($_SESSION['user_type'] === 'admin') {
            header('Location: admin/dashboard.php'); exit;
        } else {
            header('Location: osa/dashboard.php'); exit;
        }
    } else {
        $error = t('login.err_invalid_staff', 'Invalid username or password.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GNC-OSA Portal — Staff / Admin Login</title>
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
            <a href="index.php" class="auth-back"><i class="ti ti-arrow-left"></i> <?= t('common.go_back', 'Go back') ?></a>
            <h1 class="auth-title"><?= t('login.welcome', 'Welcome!') ?></h1>
            <p class="auth-sub">Please log in to your staff or admin account.</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="staff-login.php" class="auth-form">
                <div class="form-group<?= $error ? ' has-error' : '' ?>">
                    <label><?= t('common.username', 'Username') ?> <span style="color:#991B1B;">*</span></label>
                    <input type="text" name="username" class="form-control"
                           placeholder="<?= t('login.placeholder_osa_username', 'OSA username') ?>"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           autocomplete="off" required autofocus>
                </div>

                <div class="form-group<?= $error ? ' has-error' : '' ?>">
                    <label><?= t('common.password', 'Password') ?> <span style="color:#991B1B;">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="staff-login-password" class="form-control"
                               placeholder="<?= t('login.placeholder_password', 'Enter your password') ?>"
                               autocomplete="off" required style="padding-right:40px;">
                        <span onclick="toggleStaffLoginPw(this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;color:#6B7280;font-size:18px;">
                            <i class="ti ti-eye"></i>
                        </span>
                    </div>
                </div>

                <button type="submit" class="auth-btn"><?= t('login.submit', 'Log In') ?></button>
            </form>

            <p class="auth-hint">
                This login is for <strong>OSA Staff and Admin accounts only</strong>. Students should use the student login instead.
            </p>

            <div class="auth-aside-box">
                Are you a student?
                <a href="index.php">Go to Student Login</a>
            </div>
        </div>
    </main>
</div>

<script>
function toggleStaffLoginPw(el) {
    const input = document.getElementById('staff-login-password');
    const isText = input.type === 'text';
    input.type = isText ? 'password' : 'text';
    el.querySelector('i').className = isText ? 'ti ti-eye' : 'ti ti-eye-off';
}
</script>
<script src="assets/js/sidebar.js?v=5"></script>
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
