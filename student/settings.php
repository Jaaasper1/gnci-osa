<?php
require_once '../includes/db.php';
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
        $success = 'Profile updated successfully!';
        $student['email'] = $email;
    }
}

$notifCount = getUnreadNotifCount($pdo, $student_id);
$election = getAnyActiveOrRecentElection($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=25">
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
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a>
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
        <p>Update your profile information</p>
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
        <div class="card">
            <div class="card-title"><i class="ti ti-user"></i> Basic Information</div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_info">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" class="form-control" value="<?= e($student['first_name']) ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                    </div>
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" class="form-control" value="<?= e($student['last_name']) ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                    </div>
                </div>
                <div style="font-size:11px;color:#9CA3AF;margin:-6px 0 14px;"><i class="ti ti-info-circle"></i> Your name is permanent and can't be changed. Contact the OSA/admin if there's an error.</div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($student['email'] ?? '') ?>" placeholder="yourname@gnc.edu.ph">
                </div>
                <div class="form-group">
                        <label>Course</label>
                        <input type="text" class="form-control" value="<?= e($student['course'] ?? '') ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                        <div style="font-size:11px;color:#9CA3AF;margin-top:3px;"><i class="ti ti-info-circle"></i> Contact admin to change.</div>
                    </div>
                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" class="form-control" value="<?= e($student['student_id']) ?>" disabled style="background:#F9FAFB;color:#9CA3AF;">
                    <div style="font-size:11px;color:#9CA3AF;margin-top:3px;"><i class="ti ti-info-circle"></i> Cannot be changed.</div>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="ti ti-device-floppy"></i> Save Changes</button>
            </form>
        </div>

    </div>
</div></div>

<script src="../assets/js/sidebar.js?v=5"></script>
</body>
</html>
