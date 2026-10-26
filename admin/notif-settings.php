<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/lang.php';
require_once '../includes/mailer.php';
require_once '../includes/notify-settings.php';
require_once '../includes/functions.php';
require_once '../includes/csrf.php';

requireAdmin();

$adminId    = (int) ($_SESSION['user_id'] ?? 0);
$notifCount = getUnreadStaffNotifCount($pdo, $adminId);
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $gmail = strtolower(trim($_POST['notify_gmail'] ?? ''));
        if ($gmail !== '' && !preg_match('/^[^\s@]+@gmail\.com$/', $gmail)) {
            $error = t('msg.please_enter_a_valid_gmail_address_must__5e25', 'Please enter a valid Gmail address (must end in @gmail.com).');
        } else {
            setAppSetting($pdo, ADMIN_NOTIFY_EMAIL_KEY, $gmail);
            $success = $gmail === ''
                ? 'Cleared. Admin notifications will go to the admin account email instead.'
                : 'Saved! Admin notifications will now be sent only to ' . $gmail . '.';
        }
    }

    if ($action === 'test') {
        $target = getAdminNotifyEmail($pdo);
        if ($target === '') {
            $error = t('msg.save_a_gmail_address_first_0c2d', 'Save a Gmail address first.');
        } elseif (sendNotificationEmail($target, 'OSA Admin', 'This is a test notification from the GNC-OSA Portal. If you received this, admin notifications are working.')) {
            $success = 'Test email sent to ' . $target . '. Check the inbox (and spam folder).';
        } else {
            $error = 'Could not send the test email. ' . ($GLOBALS['gnc_last_mail_error'] ?? 'Check the SMTP settings in includes/config.local.php.');
        }
    }
}

$currentGmail = getAdminNotifyEmail($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.notification_gmail', 'Notification Gmail') ?> — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub"><?= t('ui.administrator_7b7b', 'Administrator') ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar" style="background:#7C3AED;color:#fff;">AD</div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.overview', 'Overview') ?></div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section"><?= t('sidebar.complaints', 'Complaints') ?></div>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?></a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> <?= t('sidebar.elections', 'Elections') ?></a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> <?= t('sidebar.candidates', 'Candidates') ?></a>
    <a class="sitem" href="party-lists.php"><i class="ti ti-flag"></i> <?= t('sidebar.party_lists', 'Party Lists') ?></a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="results.php"><i class="ti ti-chart-pie"></i> <?= t('sidebar.live_results', 'Live Results') ?></a>
    <div class="sidebar-section"><?= t('sidebar.events', 'Events') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <div class="sidebar-section"><?= t('sidebar.system', 'System') ?></div>
    <a class="sitem" href="students.php"><i class="ti ti-user-plus"></i> <?= t('sidebar.students', 'Students') ?></a>
    <a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a>
    <a class="sitem active" href="notif-settings.php"><i class="ti ti-mail"></i> <?= t('sidebar.notif_gmail', 'Notification Gmail') ?></a>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> <?= t('sidebar.reports', 'Reports') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>

<div class="main"><div class="content">
    <div class="page-header">
        <div><h1><?= t('ui.notification_gmail_58ed', 'Notification Gmail') ?></h1><p><?= t('ui.set_the_one_gmail_address_that_rec_ad63', 'Set the one Gmail address that receives admin notification emails') ?></p></div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="ti ti-check"></i> <?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= e($error) ?></div><?php endif; ?>

    <div class="form-grid-2">
        <div class="card">
            <div class="card-title"><i class="ti ti-mail"></i> <?= t('ui.admin_notification_gmail_b19d', 'Admin Notification Gmail') ?></div>
            <p style="font-size:13px;color:#6B7280;margin:0 0 14px;">
                <?= t('ui.complaint_and_assignment_emails_fo_8048', 'Complaint and assignment emails for the') ?> <strong>osa_admin</strong> <?= t('ui.account_will_be_sent_only_to_this__0dc3', 'account will be sent only to this Gmail. Leave it blank to use the admin account\'s own email.') ?>
            </p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">
                <div class="form-group">
                    <label>Gmail address</label>
                    <input type="email" name="notify_gmail" class="form-control" value="<?= e($currentGmail) ?>"
                           placeholder="name@gmail.com" pattern="^[^\s@]+@gmail\.com$"
                           title="<?= t('ui.must_be_a_gmail_address_e_g_name_g_e4d0', 'Must be a Gmail address (e.g. name@gmail.com)') ?>">
                    <div style="font-size:11px;color:#9CA3AF;margin-top:3px;"><i class="ti ti-info-circle"></i> <?= t('ui.must_end_in_gmail_com_7646', 'Must end in @gmail.com') ?></div>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ti ti-device-floppy"></i> <?= t('ui.save_c9cc', 'Save') ?></button>
            </form>
            <form method="POST" style="margin-top:12px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="test">
                <button type="submit" class="btn btn-block" <?= $currentGmail === '' ? 'disabled' : '' ?>><i class="ti ti-send"></i> <?= t('ui.send_test_email_ee18', 'Send test email') ?></button>
            </form>
        </div>

        <div class="card">
            <div class="card-title"><i class="ti ti-info-circle"></i> <?= t('ui.current_setting_79bd', 'Current setting') ?></div>
            <p style="font-size:13px;color:#374151;margin:0 0 10px;">
                <?php if ($currentGmail !== ''): ?>
                    <?= t('ui.notifications_go_to_7e8d', 'Notifications go to:') ?> <strong><?= e($currentGmail) ?></strong>
                <?php else: ?>
                    <?= t('ui.no_gmail_set_notifications_go_to_t_5196', 'No Gmail set. Notifications go to the admin account email.') ?>
                <?php endif; ?>
            </p>
            <p style="font-size:12px;color:#9CA3AF;margin:0;">
                <?= t('ui.the_sending_account_smtp_is_set_in_d1e3', 'The sending account (SMTP) is set in') ?> <code>includes/config.local.php</code> <?= t('ui.and_is_not_changed_here_9282', 'and is not changed here.') ?>
            </p>
        </div>
    </div>
</div></div>

<script src="../assets/js/alerts.js"></script>
<script src="../assets/js/sidebar.js?v=5"></script>
</body></html>
