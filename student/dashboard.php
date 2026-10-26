<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/lang.php';
require_once '../includes/functions.php';
requireStudent();

$student_id = $_SESSION['user_id'];
$name       = $_SESSION['name'];

// Stats
$stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM complaints WHERE student_id = ? GROUP BY status");
$stmt->execute([$student_id]);
$stats = ['New' => 0, 'In Review' => 0, 'Resolved' => 0, 'Rejected' => 0];
foreach ($stmt->fetchAll() as $row) $stats[$row['status']] = $row['cnt'];
$total = array_sum($stats);

// Recent complaints
$stmt = $pdo->prepare("SELECT * FROM complaints WHERE student_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$student_id]);
$recent = $stmt->fetchAll();

// Active election - filter by student's course
$student_course_stmt = $pdo->prepare("SELECT course FROM students WHERE id = ?");
$student_course_stmt->execute([$student_id]);
$student_course = $student_course_stmt->fetchColumn();
$election = getAnyActiveOrRecentElection($pdo, $student_course);
$electionOpen = $election && strtotime($election['end_date']) > time() && strtotime($election['start_date']) <= time();
$notifCount = getUnreadNotifCount($pdo, $student_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.dashboard', 'Dashboard') ?> — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>

<!-- TOPNAV -->
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand">
        <img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);">
        <div class="brand-text">
            <div class="name">GNC-OSA Portal</div>
            <div class="sub"><?= t('ui.student_portal_5703', 'Student Portal') ?></div>
        </div>
    </div>
    <div class="nav-right">
        <button class="notif-btn" onclick="location.href='notifications.php'">
            <i class="ti ti-bell"></i>
            <?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?>
        </button>
        <div style="display:flex;align-items:center;gap:8px;">
            <div class="avatar"><?= strtoupper(substr($name, 0, 2)) ?></div>
            <span class="uname"><?= htmlspecialchars($name) ?></span>
        </div>
        
    </div>
</nav>

<!-- SIDEBAR -->
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem active" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a><a class="sitem" href="help.php"><i class="ti ti-book"></i> <?= t('sidebar.osa_guide', 'OSA Guide') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <?php if ($election): ?>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="voting.php"><i class="ti ti-checkbox"></i> <?= t('sidebar.vote_now', 'Vote Now') ?></a>
    <?php endif; ?>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('sidebar.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>

<!-- MAIN -->
<div class="main">
    <div class="content">
        <div class="page-header">
            <h1><?= t('ui.good_day_3a55', 'Good day,') ?> <?= htmlspecialchars(explode(' ', $name)[0]) ?>!</h1>
            <p><?= t('ui.here_is_an_overview_of_your_compla_f236', 'Here is an overview of your complaints') ?></p>
        </div>

        <?php if ($election): ?>
        <div class="election-banner">
            <div>
                <div class="el-title"><i class="ti ti-award" style="margin-right:6px;font-size:16px;vertical-align:-2px;"></i><?= htmlspecialchars($election['title']) ?></div>
                <div class="el-sub"><?= t('ui.voting_period_ce2f', 'Voting period:') ?> <?= tlDate('M j', strtotime($election['start_date'])) ?> – <?= tlDate('M j, Y', strtotime($election['end_date'])) ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:14px;">
                <div class="countdown" style="text-align:right;">
                    <div class="time" id="countdown">--</div>
                    <div class="label"><?= t('ui.remaining_2626', 'remaining') ?></div>
                </div>
                <?php if ($electionOpen): ?>
                <a href="voting.php" class="btn btn-gold"><i class="ti ti-checkbox"></i> <?= t('ui.vote_now_a4d8', 'Vote Now') ?></a>
                <?php else: ?>
                <span class="btn btn-gold" style="opacity:0.45;cursor:not-allowed;pointer-events:none;"><i class="ti ti-lock"></i> <?= t('ui.voting_closed_dc17', 'Voting Closed') ?></span>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function() {
            const end = new Date('<?= $election['end_date'] ?>').getTime();
            function tick() {
                const diff = end - Date.now();
                if (diff <= 0) { document.getElementById('countdown').textContent = __t('Closed'); return; }
                const d = Math.floor(diff/86400000), h = Math.floor((diff%86400000)/3600000), m = Math.floor((diff%3600000)/60000);
                document.getElementById('countdown').textContent = d + 'd ' + h + 'h ' + m + 'm';
            }
            tick(); setInterval(tick, 60000);
        })();
        </script>
        <?php endif; ?>

        <div class="stat-grid">
            <div class="stat-card"><div class="stat-num"><?= $total ?></div><div class="stat-label"><?= t('ui.total_96b0', 'Total') ?></div></div>
            <div class="stat-card"><div class="stat-num" style="color:#5B21B6;"><?= $stats['New'] ?></div><div class="stat-label"><?= t('ui.new_03c2', 'New') ?></div></div>
            <div class="stat-card"><div class="stat-num" style="color:#6B1A2A;"><?= $stats['In Review'] ?></div><div class="stat-label"><?= t('ui.in_review_516d', 'In Review') ?></div></div>
            <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $stats['Resolved'] ?></div><div class="stat-label"><?= t('ui.resolved_f691', 'Resolved') ?></div></div>
        </div>

        <div class="card">
            <div class="card-title" style="justify-content:space-between;">
                <span><i class="ti ti-message-report"></i> <?= t('ui.recent_complaints_3025', 'Recent Complaints') ?></span>
                <a href="new-complaint.php" class="btn btn-primary btn-sm"><i class="ti ti-plus"></i> <?= t('ui.new_complaint_15f3', 'New Complaint') ?></a>
            </div>
            <?php if (empty($recent)): ?>
                <div class="alert alert-info"><i class="ti ti-info-circle"></i> <?= t('ui.you_have_not_submitted_any_complai_9866', 'You have not submitted any complaints yet.') ?> <a href="new-complaint.php"><?= t('ui.submit_one_now_168f', 'Submit one now') ?></a></div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th><?= t('ui.category_3adb', 'Category') ?></th><th><?= t('ui.title_b78a', 'Title') ?></th><th><?= t('ui.date_4474', 'Date') ?></th><th><?= t('ui.status_ec53', 'Status') ?></th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['category']) ?></td>
                            <td><?= htmlspecialchars($c['title']) ?></td>
                            <td><?= tlDate('M j, Y', strtotime($c['created_at'])) ?></td>
                            <td><?= statusBadge($c['status']) ?></td>
                            <td><a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm"><?= t('ui.view_4351', 'View') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="../assets/js/sidebar.js?v=5"></script>
<?php include __DIR__.'/../includes/ask-osa-widget.php'; ?>
</body>
</html>
