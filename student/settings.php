<?php
require_once '../includes/db.php';
require_once '../includes/audit.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/csrf.php';
requireStudent();

$student_id = $_SESSION['user_id'];

// Fetch current student data
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

$success = $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // --- Update Basic Info ---
    if ($action === 'update_info') {
        // Name is permanent and cannot be changed by the student once registered.
        // Only the email address may be updated here.
        $email = trim($_POST['email'] ?? '');

        $pdo->prepare("UPDATE students SET email=? WHERE id=?")
            ->execute([$email ?: null, $student_id]);
        auditLog($pdo, 'update', 'account', (int)$student_id, [], ['email' => $email]);
        $success = t('msg.profile_updated_successfully_260c', 'Profile updated successfully!');
        $student['email'] = $email;
    }

    // --- Change Password ---
    if ($action === 'change_password') {
        $current  = (string)($_POST['current_password'] ?? '');
        $new_pass = trim((string)($_POST['new_password'] ?? ''));
        $confirm  = trim((string)($_POST['confirm_password'] ?? ''));
        // Logged in with the email code = no current password needed. Logged in with a password = must confirm it.
        $method = $_SESSION['login_method'] ?? 'password';
        if ($current !== '' && !password_verify($current, $student['password'])) {
            $error = t('settings.err_current_wrong', 'Current password is incorrect.');
        } elseif ($current === '' && $method === 'password') {
            $error = t('msg.enter_your_current_password_d333', 'Enter your current password.');
        } elseif (strlen($new_pass) < 8) {
            $error = t('msg.password_must_be_at_least_8_characters_7667', 'Password must be at least 8 characters.');
        } elseif ($new_pass !== $confirm) {
            $error = t('register.err_pw_mismatch', 'Passwords do not match.');
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE students SET password=? WHERE id=?")->execute([$hash, $student_id]);
            try { $pdo->prepare("UPDATE students SET must_change_password=0 WHERE id=?")->execute([$student_id]); } catch (Throwable $e) {}
            $_SESSION['must_change_password'] = false;
            auditLog($pdo, 'password_change', 'account', (int)$student_id);
            $success = t('settings.password_changed', 'Password changed successfully!');
            $student['password'] = $hash;
        }
    }
}

$openPw = (($_POST['action'] ?? '') === 'change_password');
$notifCount = getUnreadNotifCount($pdo, $student_id);
$election = getAnyActiveOrRecentElection($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.settings', 'Settings') ?> — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<style>
.set-acc{background:#fff;border:1px solid #E5E7EB;border-radius:14px;margin-bottom:12px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.set-acc summary{list-style:none;display:flex;align-items:center;gap:14px;padding:16px 18px;cursor:pointer;-webkit-user-select:none;user-select:none}
.set-acc summary::-webkit-details-marker{display:none}
.set-acc summary:hover{background:#F9FAFB}
.set-ic{width:38px;height:38px;border-radius:50%;background:var(--maroon-pale,#F9EEF0);color:var(--maroon,#6B1A2A);display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.set-tt{flex:1;min-width:0}
.set-tt b{display:block;font-size:14.5px;color:#1F2937;font-weight:600}
.set-tt span{display:block;font-size:12px;color:#6B7280;margin-top:2px}
.set-chev{color:#9CA3AF;font-size:18px;transition:transform .2s}
.set-acc[open] .set-chev{transform:rotate(90deg)}
.set-acc[open] summary{border-bottom:1px solid #F3F4F6}
.set-body{padding:18px}
html[data-theme="dark"] .set-acc{background:#242526;border-color:#3A3B3C}
html[data-theme="dark"] .set-acc summary:hover{background:#2D2E30}
html[data-theme="dark"] .set-tt b{color:#E4E6EB}
html[data-theme="dark"] .set-acc[open] summary{border-color:#3A3B3C}
</style>
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>

<!-- TOPNAV -->
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand">
        <img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);">
        <div class="brand-text">
            <div class="name">GNC-OSA Portal</div>
            <div class="sub"><?= t('nav.student_portal', 'Student Portal') ?></div>
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
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a><a class="sitem" href="help.php"><i class="ti ti-book"></i> <?= t('sidebar.osa_guide', 'OSA Guide') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <?php if ($election): ?>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="voting.php"><i class="ti ti-checkbox"></i> <?= t('sidebar.vote_now', 'Vote Now') ?></a>
    <?php endif; ?><div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
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
        <p><?= t('ui.update_your_profile_information_1f95', 'Update your profile information') ?></p>
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

    <div style="max-width: 560px; margin: 0 auto;">

        <!-- Basic Info -->
        <details class="set-acc" name="settings" <?= $openPw ? '' : 'open' ?>>
            <summary>
                <span class="set-ic"><i class="ti ti-user"></i></span>
                <span class="set-tt"><b><?= t('ui.basic_information_87ca', 'Basic Information') ?></b><span><?= t('settings.sub_profile', 'Your name, email, course and Student ID') ?></span></span>
                <i class="ti ti-chevron-right set-chev"></i>
            </summary>
            <div class="set-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_info">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label><?= t('ui.first_name_bc91', 'First Name') ?></label>
                        <input type="text" class="form-control" value="<?= e($student['first_name']) ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                    </div>
                    <div class="form-group">
                        <label><?= t('ui.last_name_7758', 'Last Name') ?></label>
                        <input type="text" class="form-control" value="<?= e($student['last_name']) ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                    </div>
                </div>
                <div style="font-size:11px;color:#9CA3AF;margin:-6px 0 14px;"><i class="ti ti-info-circle"></i> <?= t('ui.your_name_is_permanent_and_can_t_b_2a66', 'Your name is permanent and can\'t be changed. Contact the OSA/admin if there\'s an error.') ?></div>
                <div class="form-group">
                    <label><?= tx('Email') ?></label>
                    <input type="email" name="email" class="form-control" value="<?= e($student['email'] ?? '') ?>" placeholder="yourname@gnc.edu.ph">
                </div>
                <div class="form-group">
                        <label><?= t('ui.course_3730', 'Course') ?></label>
                        <input type="text" class="form-control" value="<?= e($student['course'] ?? '') ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                        <div style="font-size:11px;color:#9CA3AF;margin-top:3px;"><i class="ti ti-info-circle"></i> <?= t('ui.contact_admin_to_change_b839', 'Contact admin to change.') ?></div>
                    </div>
                <div class="form-group">
                    <label><?= tx('Student ID') ?></label>
                    <input type="text" class="form-control" value="<?= e($student['student_id']) ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                    <div style="font-size:11px;color:#9CA3AF;margin-top:3px;"><i class="ti ti-info-circle"></i> <?= t('ui.cannot_be_changed_a44a', 'Cannot be changed.') ?></div>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ti ti-device-floppy"></i> <?= t('ui.save_changes_f5d6', 'Save Changes') ?></button>
            </form>
            </div>
        </details>

        <!-- Change Password -->
        <details class="set-acc" name="settings" <?= $openPw ? 'open' : '' ?>>
            <summary>
                <span class="set-ic"><i class="ti ti-lock"></i></span>
                <span class="set-tt"><b><?= t('settings.change_password', 'Change Password') ?></b><span><?= t('settings.sub_password', 'Update the password you use to log in') ?></span></span>
                <i class="ti ti-chevron-right set-chev"></i>
            </summary>
            <div class="set-body">
            <form method="POST" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="change_password">
                <div style="font-size:11px;color:#6B7280;margin:-6px 0 12px;"><i class="ti ti-info-circle"></i> <?= t('ui.leave_the_current_password_blank_i_14a1', 'Leave the current password blank if you logged in with an email code and have no password yet.') ?></div>
                <div class="form-group">
                    <label><?= t('settings.current_password', 'Current Password') ?> <span style="color:#9CA3AF;"><?= t('ui.optional_if_you_logged_in_with_a_c_a7e5', '(optional if you logged in with a code)') ?></span></label>
                    <input type="password" name="current_password" class="form-control" placeholder="<?= htmlspecialchars(t('settings.current_password_ph', 'Enter current password'), ENT_QUOTES) ?>" autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label><?= t('newpass.label', 'New Password') ?> <span style="color:#EF4444;">*</span></label>
                    <input type="password" name="new_password" class="form-control" minlength="8" placeholder="<?= htmlspecialchars(t('ui.at_least_8_characters_f641', 'At least 8 characters'), ENT_QUOTES) ?>" autocomplete="new-password" required>
                </div>
                <div class="form-group">
                    <label><?= t('register.confirm_password', 'Confirm New Password') ?> <span style="color:#EF4444;">*</span></label>
                    <input type="password" name="confirm_password" class="form-control" minlength="8" placeholder="<?= htmlspecialchars(t('register.reenter_password', 'Re-enter password'), ENT_QUOTES) ?>" autocomplete="new-password" required>
                    <div id="pw-match-msg" role="status" aria-live="polite" style="display:none;font-size:12px;margin-top:5px;font-weight:600;"></div>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ti ti-lock"></i> <?= t('settings.change_password', 'Change Password') ?></button>
            </form>
            </div>
        </details>

    </div>
</div></div>

<script>
// Live "passwords match" check
(function(){
    var p1 = document.querySelector('input[name="new_password"]');
    var p2 = document.querySelector('input[name="confirm_password"]');
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
    p1.addEventListener('input', check); p2.addEventListener('input', check);
})();
</script>
<script>
document.querySelectorAll('details.set-acc').forEach(function(d){
    d.addEventListener('toggle', function(){
        if (!d.open) return;
        document.querySelectorAll('details.set-acc').forEach(function(o){ if (o !== d) o.open = false; });
    });
});
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
<?php include __DIR__.'/../includes/ask-osa-widget.php'; ?>
</body>
</html>
