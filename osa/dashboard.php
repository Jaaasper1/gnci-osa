<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStaff();

$staff_id = $_SESSION['user_id'];
$notifCount = getUnreadStaffNotifCount($pdo, $staff_id);
$myNew    = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE assigned_to=? AND status='New'"); $myNew->execute([$staff_id]); $myNew = $myNew->fetchColumn();
$myReview = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE assigned_to=? AND status='In Review'"); $myReview->execute([$staff_id]); $myReview = $myReview->fetchColumn();
$myDone   = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE assigned_to=? AND status='Resolved'"); $myDone->execute([$staff_id]); $myDone = $myDone->fetchColumn();
$assigned = $pdo->prepare("SELECT c.*, s.first_name, s.last_name FROM complaints c JOIN students s ON c.student_id=s.id WHERE c.assigned_to=? ORDER BY c.created_at DESC LIMIT 10"); $assigned->execute([$staff_id]); $assigned = $assigned->fetchAll();
$unclaimedCount = $pdo->query("SELECT COUNT(*) FROM complaints WHERE assigned_to IS NULL AND is_archived=0")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OSA Dashboard — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=23">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub">OSA Staff</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><button class="notif-btn" onclick="location.href='notifications.php'"><i class="ti ti-bell"></i><?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?></button><div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem active" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.complaints', 'Complaints') ?> <span class="badge-count"><?= $myNew ?></span></a>
    <div class="sidebar-section">Events</div><a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a><div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?><?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?></a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('dashboard.greeting', 'Good day') ?>, <?= e(explode(' ',$_SESSION['name'])[0]) ?>!</h1><p><?= t('osa.assigned_complaints', 'Complaints assigned to you') ?></p></div>
    <div class="stat-grid">
        <a href="complaints.php?tab=unclaimed" style="text-decoration:none;">
        <div class="stat-card" style="cursor:pointer;">
            <div class="stat-num" style="color:#B45309;"><?= $unclaimedCount ?></div>
            <div class="stat-label"><?= t('osa.unclaimed', 'Unclaimed') ?></div>
        </div>
        </a>
        <div class="stat-card"><div class="stat-num" style="color:#5B21B6;"><?= $myNew ?></div><div class="stat-label">Newly Assigned</div></div>
        <div class="stat-card"><div class="stat-num" style="color:var(--maroon);"><?= $myReview ?></div><div class="stat-label">In Review</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $myDone ?></div><div class="stat-label">Resolved</div></div>
    </div>
    <div class="card">
        <div class="card-title"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></div>
        <?php if (empty($assigned)): ?>
            <div class="alert alert-info"><i class="ti ti-info-circle"></i> No complaints assigned to you yet.</div>
        <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>#</th><th>Student</th><th>Title</th><th>Date</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach ($assigned as $c): ?>
            <tr>
                <td>#<?= $c['id'] ?></td>
                <td><?= $c['is_anonymous'] ? '<em style="color:#9CA3AF;">Anonymous</em>' : e($c['first_name'].' '.$c['last_name']) ?></td>
                <td><?= e($c['title']) ?></td>
                <td><?= date('M j', strtotime($c['created_at'])) ?></td>
                <td><?= statusBadge($c['status']) ?></td>
                <td><a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Review</a></td>
            </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div></div>
<script src="../assets/js/sidebar.js?v=4"></script>
</body></html>
