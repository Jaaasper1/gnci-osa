<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStaff();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$totalComplaints = $pdo->query("SELECT COUNT(*) FROM complaints WHERE is_archived=0")->fetchColumn();
$newComplaints   = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status='New' AND is_archived=0")->fetchColumn();
$totalStudents   = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalStaff      = $pdo->query("SELECT COUNT(*) FROM osa_staff WHERE role='staff'")->fetchColumn();
$election        = getActiveElection($pdo);
$totalVotes = 0;
if ($election) {
    $tvStmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM votes WHERE election_id=?");
    $tvStmt->execute([$election['id']]);
    $totalVotes = (int)$tvStmt->fetchColumn();
}
$recentComplaints = $pdo->query("SELECT c.*, s.first_name, s.last_name FROM complaints c JOIN students s ON c.student_id=s.id ORDER BY c.created_at DESC LIMIT 6")->fetchAll();

// ---- Student Reviews overview ----
$closedComplaints   = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status='Closed' AND is_archived=0")->fetchColumn();
$reviewedComplaints = $pdo->query("SELECT COUNT(DISTINCT cr.complaint_id) FROM complaint_reviews cr JOIN complaints c ON cr.complaint_id=c.id WHERE c.is_archived=0")->fetchColumn();
$pendingReviews     = max(0, $closedComplaints - $reviewedComplaints);

$totalReviews  = $pdo->query("SELECT COUNT(*) FROM complaint_reviews")->fetchColumn();
$reviewsToday  = $pdo->query("SELECT COUNT(*) FROM complaint_reviews WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$reviewsWeek   = $pdo->query("SELECT COUNT(*) FROM complaint_reviews WHERE YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)")->fetchColumn();
$reviewsMonth  = $pdo->query("SELECT COUNT(*) FROM complaint_reviews WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();
$reviewsYear   = $pdo->query("SELECT COUNT(*) FROM complaint_reviews WHERE YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();

// Closed complaints that still have no student review — list for admin follow-up
$pendingReviewList = $pdo->query(
    "SELECT c.id, c.title, c.category, c.updated_at, c.is_anonymous, s.first_name, s.last_name
     FROM complaints c
     JOIN students s ON c.student_id = s.id
     WHERE c.status = 'Closed' AND c.is_archived = 0
       AND NOT EXISTS (SELECT 1 FROM complaint_reviews cr WHERE cr.complaint_id = c.id)
     ORDER BY c.updated_at DESC
     LIMIT 8"
)->fetchAll();

// Most recent reviews submitted by students
$recentReviews = $pdo->query(
    "SELECT cr.*, c.title AS complaint_title, s.first_name, s.last_name
     FROM complaint_reviews cr
     JOIN complaints c ON cr.complaint_id = c.id
     JOIN students s ON cr.student_id = s.id
     ORDER BY cr.created_at DESC
     LIMIT 8"
)->fetchAll();

// Satisfaction rate: % of all reviews that were positive (😊 Satisfied or 👍 Good)
$positiveReviews = (int)$pdo->query("SELECT COUNT(*) FROM complaint_reviews WHERE reaction IN ('😊','👍')")->fetchColumn();
$satisfactionRate = $totalReviews > 0 ? round($positiveReviews / $totalReviews * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.admin_dashboard', 'Admin Dashboard') ?> — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub"><?= t('ui.administrator_7b7b', 'Administrator') ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar" style="background:#7C3AED;color:#fff;">AD</div><span class="uname"><?= htmlspecialchars($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.overview', 'Overview') ?></div>
    <a class="sitem active" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section"><?= t('sidebar.complaints', 'Complaints') ?></div>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?> <span class="badge-count"><?= $newComplaints ?></span></a><a class="sitem" href="../osa/messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?></a>
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
    <?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a><?php endif; ?><?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem" href="audit-log.php"><i class="ti ti-history"></i> <?= t('sidebar.audit_log', 'Audit Log') ?></a><?php endif; ?>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> <?= t('sidebar.reports', 'Reports') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>

<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('admin.dashboard.title', 'Admin Dashboard') ?></h1><p><?= t('admin.dashboard.subtitle', 'System overview') ?></p></div>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= $totalComplaints ?></div><div class="stat-label"><?= t('admin.dash.total_complaints', 'Total Complaints') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#5B21B6;"><?= $newComplaints ?></div><div class="stat-label"><?= t('admin.dash.new_complaints', 'New Complaints') ?></div></div>
        <div class="stat-card"><div class="stat-num"><?= $totalStudents ?></div><div class="stat-label"><?= t('sidebar.students', 'Students') ?></div></div>
        <div class="stat-card"><div class="stat-num"><?= $totalStaff ?></div><div class="stat-label"><?= t('sidebar.osa_staff', 'OSA Staff') ?></div></div>
        <?php if ($election): ?><div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $totalVotes ?></div><div class="stat-label"><?= t('admin.dash.votes_cast', 'Votes Cast') ?></div></div><?php endif; ?>
    </div>
    <?php if ($election): ?>
    <div class="election-banner" style="margin-bottom:16px;">
        <div><div class="el-title"><i class="ti ti-trophy" style="margin-right:6px;"></i><?= htmlspecialchars($election['title']) ?></div><div class="el-sub"><?= t('admin.dash.active', 'Active') ?> — <?= $totalVotes ?>/<?= $totalStudents ?> <?= t('admin.dash.votes_cast_lc', 'votes cast') ?></div></div>
        <div class="el-actions"><a href="results.php" class="btn btn-gold btn-sm"><i class="ti ti-chart-pie"></i> <?= t('sidebar.live_results', 'Live Results') ?></a><a href="elections.php" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,0.5);"><?= t('ui.manage_34e3', 'Manage') ?></a></div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-title" style="justify-content:space-between;"><span><i class="ti ti-message-report"></i> <?= t('admin.dash.recent_complaints', 'Recent Complaints') ?></span><a href="complaints.php" class="btn btn-outline btn-sm"><?= t('admin.dash.all', 'All') ?></a></div>
        <div class="table-wrap"><table>
            <thead><tr><th><?= t('common.student', 'Student') ?></th><th><?= t('common.category', 'Category') ?></th><th><?= t('common.title', 'Title') ?></th><th><?= t('common.date', 'Date') ?></th><th><?= t('common.status', 'Status') ?></th><th></th></tr></thead>
            <tbody><?php foreach ($recentComplaints as $c): ?>
            <tr>
                                <td><?= $c['is_anonymous'] ? '<em style="color:#9CA3AF;">' . t('common.anonymous', 'Anonymous') . '</em>' : e($c['first_name'].' '.$c['last_name']) ?></td>
                <td><?= e($c['category']) ?></td>
                <td><?= e($c['title']) ?></td>
                <td><?= date('M j', strtotime($c['created_at'])) ?></td>
                <td><?= statusBadge($c['status']) ?></td>
                <td><a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm"><?= t('osa.review', 'Review') ?></a></td>
            </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    </div>

    <div class="page-header" style="margin-top:24px;"><h1 style="font-size:20px;"><?= t('admin.dashboard.reviews', 'Student Reviews') ?></h1><p><?= t('admin.dashboard.reviews_sub', 'Feedback left by students on closed complaints') ?></p></div>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= $totalReviews ?></div><div class="stat-label"><?= t('admin.dash.total_reviews', 'Total Reviews') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#DC2626;"><?= $pendingReviews ?></div><div class="stat-label"><?= t('admin.dash.pending_reviews', 'Missing / Pending Review') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $satisfactionRate ?>%</div><div class="stat-label"><?= t('admin.dash.satisfaction', 'Satisfaction Rate') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $reviewsToday ?></div><div class="stat-label"><?= t('admin.dash.today', 'Today') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $reviewsWeek ?></div><div class="stat-label"><?= t('admin.dash.this_week', 'This Week') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $reviewsMonth ?></div><div class="stat-label"><?= t('admin.dash.this_month', 'This Month') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $reviewsYear ?></div><div class="stat-label"><?= t('admin.dash.this_year', 'This Year') ?></div></div>
    </div>

    <div class="card" style="background:#FDF2F4;border:1px solid #F3D2D9;text-align:center;padding:16px;">
        <p style="font-size:13px;color:#6B7280;margin-bottom:8px;"><?= t('admin.dash.reports_promo', 'Want the full emoji-by-emoji breakdown, complaint category trends, and downloadable Excel reports?') ?></p>
        <a href="reports.php" class="btn btn-primary btn-sm"><i class="ti ti-report-analytics"></i> <?= t('admin.dash.go_reports', 'Go to Reports') ?></a>
    </div>

    <div class="card">
        <div class="card-title" style="justify-content:space-between;">
            <span><i class="ti ti-alert-circle"></i> <?= t('admin.dash.awaiting', 'Awaiting Student Review') ?> (<?= $pendingReviews ?>)</span>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th><?= t('common.student', 'Student') ?></th><th><?= t('common.category', 'Category') ?></th><th><?= t('common.title', 'Title') ?></th><th><?= t('admin.dash.closed_on', 'Closed On') ?></th><th></th></tr></thead>
            <tbody>
            <?php if (empty($pendingReviewList)): ?>
                <tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;"><?= t('admin.dash.all_reviewed', 'All closed complaints have been reviewed.') ?></td></tr>
            <?php else: foreach ($pendingReviewList as $c): ?>
            <tr>
                <td><?= $c['is_anonymous'] ? '<em style="color:#9CA3AF;">' . t('common.anonymous', 'Anonymous') . '</em>' : e($c['first_name'].' '.$c['last_name']) ?></td>
                <td><?= e($c['category']) ?></td>
                <td><?= e($c['title']) ?></td>
                <td><?= date('M j, Y', strtotime($c['updated_at'])) ?></td>
                <td><a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm"><?= t('common.view', 'View') ?></a></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <div class="card-title" style="justify-content:space-between;">
            <span><i class="ti ti-star-filled"></i> <?= t('admin.dash.recent_reviews', 'Recent Reviews') ?></span>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th><?= t('common.student', 'Student') ?></th><th><?= t('admin.dash.complaint', 'Complaint') ?></th><th><?= t('admin.dash.reaction', 'Reaction') ?></th><th><?= t('admin.dash.comment', 'Comment') ?></th><th><?= t('common.date', 'Date') ?></th></tr></thead>
            <tbody>
            <?php if (empty($recentReviews)): ?>
                <tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;"><?= t('admin.dash.no_reviews', 'No reviews submitted yet.') ?></td></tr>
            <?php else: foreach ($recentReviews as $r): ?>
            <tr>
                <td><?= e($r['first_name'].' '.$r['last_name']) ?></td>
                <td><?= e($r['complaint_title']) ?></td>
                <td style="font-size:20px;"><?= e($r['reaction']) ?></td>
                <td><?= $r['comment'] ? e($r['comment']) : '<em style="color:#9CA3AF;">' . t('admin.dash.no_comment', 'No comment') . '</em>' ?></td>
                <td><?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div></div>
<style>

/* ── Mobile layout for the dashboard ── */
.el-actions { display:flex; gap:10px; flex-wrap:wrap; }
.election-banner { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.stat-grid { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); min-width:0; }
.main, .content { min-width:0; max-width:100%; }
.stat-grid > :last-child:nth-child(odd) { grid-column: auto; }
@media (max-width: 600px) {
    .content { padding: 12px 12px 24px; }
    .page-header h1 { font-size: 20px; }
    .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .stat-grid > :last-child:nth-child(odd) { grid-column: 1 / -1; }
    .stat-card { padding: 12px 8px; }
    .stat-card .stat-num { font-size: 22px; }
    .election-banner { flex-direction: column; align-items: flex-start; }
    .el-actions { width: 100%; }
    .el-actions .btn { flex: 1 1 auto; justify-content: center; }
    .table-wrap table:not(.mr) { min-width: 560px; }
    table.mr tbody tr.mr-r > td { overflow-wrap: anywhere; }
}
</style>
<script src="../assets/js/sidebar.js?v=5"></script>
</body></html>
