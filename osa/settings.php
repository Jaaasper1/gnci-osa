<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStaff();

$staff_id = $_SESSION['user_id'];
$notifCount = getUnreadStaffNotifCount($pdo, $staff_id);
$success = $error = '';

// Fetch current staff data
$stmt = $pdo->prepare("SELECT * FROM osa_staff WHERE id = ?");
$stmt->execute([$staff_id]);
$staff = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Update Basic Info ---
    if ($action === 'update_info') {
        // Username, first/last name are managed by the Admin (Staff Accounts page).
        // Only the email address may be updated here.
        $email = trim($_POST['email'] ?? '');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('register.err_email', 'Invalid email address.');
        } else {
            $pdo->prepare("UPDATE osa_staff SET email=? WHERE id=?")
                ->execute([$email ?: null, $staff_id]);
            $success = t('settings.profile_updated', 'Profile updated successfully!');
            $staff['email'] = $email;
        }
    }

    // --- Change Password ---
    if ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new_pass = trim($_POST['new_password'] ?? '');
        $confirm  = trim($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $staff['password'])) {
            $error = t('settings.err_current_wrong', 'Current password is incorrect.');
        } elseif (strlen($new_pass) < 9) {
            $error = t('register.err_pw_length', 'New password must be at least 9 characters.');
        } elseif ($new_pass !== $confirm) {
            $error = t('register.err_pw_mismatch', 'Passwords do not match.');
        } else {
            $pdo->prepare("UPDATE osa_staff SET password=? WHERE id=?")
                ->execute([password_hash($new_pass, PASSWORD_DEFAULT), $staff_id]);
            $success = t('settings.password_changed', 'Password changed successfully!');
            $staff['password'] = password_hash($new_pass, PASSWORD_DEFAULT);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body>

<!-- TOPNAV -->
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand">
        <img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);">
        <div class="brand-text">
            <div class="name">GNC-OSA Portal</div>
            <div class="sub"><?= t('nav.osa_portal', 'OSA Staff Portal') ?></div>
        </div>
    </div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?>
        <button class="notif-btn" onclick="location.href='notifications.php'">
            <i class="ti ti-bell"></i>
            <?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?>
        </button>
        <div style="display:flex;align-items:center;gap:8px;">
            <div class="avatar"><?= strtoupper(substr($_SESSION['name'], 0, 2)) ?></div>
            <span class="uname"><?= e($_SESSION['name']) ?></span>
        </div>
    </div>
</nav>

<!-- SIDEBAR -->
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.complaints', 'Complaints') ?></a>
    <div class="sidebar-section">Events</div><a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a><div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem active" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>

<!-- MAIN -->
<div class="main"><div class="content">
    <div class="page-header">
        <h1><?= t('settings.title', 'Account Settings') ?></h1>
        <p><?= t('settings.subtitle', 'Update your profile information and password') ?></p>
    </div>

    <?php if ($success): ?>
    <div class="alert-success" style="display:flex;align-items:center;gap:10px;background:#D1FAE5;border:1px solid #6EE7B7;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#065F46;">
        <i class="ti ti-circle-check" style="font-size:18px;"></i> <?= e($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert-error" style="display:flex;align-items:center;gap:10px;background:#FEE2E2;border:1px solid #FECACA;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#991B1B;">
        <i class="ti ti-alert-circle" style="font-size:18px;"></i> <?= e($error) ?>
    </div>
    <?php endif; ?>

    <div class="form-grid-2">

        <!-- Basic Info -->
        <div class="card">
            <div class="card-title"><i class="ti ti-user"></i> <?= t('settings.basic_info', 'Basic Information') ?></div>
            <form method="POST">
                <input type="hidden" name="action" value="update_info">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label><?= t('register.first_name', 'First Name') ?></label>
                        <input type="text" class="form-control" value="<?= e($staff['first_name']) ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                    </div>
                    <div class="form-group">
                        <label><?= t('register.last_name', 'Last Name') ?></label>
                        <input type="text" class="form-control" value="<?= e($staff['last_name']) ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                    </div>
                </div>
                <div style="font-size:11px;color:#9CA3AF;margin:-6px 0 14px;"><i class="ti ti-info-circle"></i> <?= t('settings.name_locked', "Your name can't be changed here. Contact the Admin if there's an error.") ?></div>
                <div class="form-group">
                    <label><?= t('common.username', 'Username') ?></label>
                    <input type="text" class="form-control" value="<?= e($staff['username']) ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                    <div style="font-size:11px;color:#9CA3AF;margin-top:3px;"><i class="ti ti-info-circle"></i> <?= t('settings.contact_admin', 'Contact admin to change.') ?></div>
                </div>
                <div class="form-group">
                    <label><?= t('common.email', 'Email') ?></label>
                    <input type="email" name="email" class="form-control" value="<?= e($staff['email'] ?? '') ?>" placeholder="yourname@gnc.edu.ph">
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ti ti-device-floppy"></i> <?= t('settings.save_changes', 'Save Changes') ?></button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-title"><i class="ti ti-lock"></i> <?= t('settings.change_password', 'Change Password') ?></div>
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label><?= t('settings.current_password', 'Current Password') ?> <span style="color:#EF4444;">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="current_password" id="cur-pass" class="form-control" placeholder="<?= t('settings.current_password_ph', 'Enter current password') ?>" style="padding-right:38px;" required>
                        <button type="button" onclick="toggleVis('cur-pass','cur-eye')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:16px;" tabindex="-1"><i class="ti ti-eye" id="cur-eye"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label><?= t('newpass.label', 'New Password') ?> <span style="color:#EF4444;">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="new_password" id="new-pass" class="form-control" placeholder="<?= t('register.min_chars', 'Min. 9 characters') ?>" style="padding-right:38px;" required>
                        <button type="button" onclick="toggleVis('new-pass','new-eye')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:16px;" tabindex="-1"><i class="ti ti-eye" id="new-eye"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label><?= t('register.confirm_password', 'Confirm New Password') ?> <span style="color:#EF4444;">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="confirm_password" id="con-pass" class="form-control" placeholder="<?= t('register.reenter_password', 'Re-enter password') ?>" style="padding-right:38px;" required>
                        <button type="button" onclick="toggleVis('con-pass','con-eye')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:16px;" tabindex="-1"><i class="ti ti-eye" id="con-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ti ti-lock"></i> <?= t('settings.change_password', 'Change Password') ?></button>
            </form>
        </div>

    </div>
</div></div>

<script>
function toggleVis(inputId, eyeId) {
    var inp = document.getElementById(inputId);
    var eye = document.getElementById(eyeId);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    eye.className = inp.type === 'password' ? 'ti ti-eye' : 'ti ti-eye-off';
}
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
</body>
</html>
