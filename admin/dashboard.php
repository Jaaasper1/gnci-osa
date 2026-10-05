<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireAdmin();
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
    <title>Admin Dashboard — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=25">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub">Administrator</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar" style="background:#7C3AED;color:#fff;">AD</div><span class="uname"><?= htmlspecialchars($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section">Overview</div>
    <a class="sitem active" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section">Complaints</div>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> All Complaints <span class="badge-count"><?= $newComplaints ?></span></a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> Elections</a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> Candidates</a>
    <a class="sitem" href="party-lists.php"><i class="ti ti-flag"></i> Party Lists</a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="results.php"><i class="ti ti-chart-pie"></i> Live Results</a>
    <div class="sidebar-section">Events</div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <div class="sidebar-section">System</div>
    <a class="sitem" href="students.php"><i class="ti ti-user-plus"></i> Students</a>
    <a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> OSA Staff</a>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> Reports</a>
    <div class="sidebar-section">Account</div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> Notifications
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('admin.dashboard.title', 'Admin Dashboard') ?></h1><p><?= t('admin.dashboard.subtitle', 'System overview') ?></p></div>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= $totalComplaints ?></div><div class="stat-label">Total Complaints</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#5B21B6;"><?= $newComplaints ?></div><div class="stat-label">New Complaints</div></div>
        <div class="stat-card"><div class="stat-num"><?= $totalStudents ?></div><div class="stat-label">Students</div></div>
        <div class="stat-card"><div class="stat-num"><?= $totalStaff ?></div><div class="stat-label">OSA Staff</div></div>
        <?php if ($election): ?><div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $totalVotes ?></div><div class="stat-label">Votes Cast</div></div><?php endif; ?>
    </div>
    <?php if ($election): ?>
    <div class="election-banner" style="margin-bottom:16px;">
        <div><div class="el-title"><i class="ti ti-trophy" style="margin-right:6px;"></i><?= htmlspecialchars($election['title']) ?></div><div class="el-sub">Active — <?= $totalVotes ?>/<?= $totalStudents ?> votes cast</div></div>
        <div style="display:flex;gap:10px;"><a href="results.php" class="btn btn-gold btn-sm"><i class="ti ti-chart-pie"></i> Live Results</a><a href="elections.php" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,0.5);">Manage</a></div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-title" style="justify-content:space-between;"><span><i class="ti ti-message-report"></i> Recent Complaints</span><a href="complaints.php" class="btn btn-outline btn-sm">All</a></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Student</th><th>Category</th><th>Title</th><th>Date</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach ($recentComplaints as $c): ?>
            <tr>
                                <td><?= $c['is_anonymous'] ? '<em style="color:#9CA3AF;">Anonymous</em>' : e($c['first_name'].' '.$c['last_name']) ?></td>
                <td><?= e($c['category']) ?></td>
                <td><?= e($c['title']) ?></td>
                <td><?= date('M j', strtotime($c['created_at'])) ?></td>
                <td><?= statusBadge($c['status']) ?></td>
                <td><a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Review</a></td>
            </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    </div>

    <div class="page-header" style="margin-top:24px;"><h1 style="font-size:20px;"><?= t('admin.dashboard.reviews', 'Student Reviews') ?></h1><p><?= t('admin.dashboard.reviews_sub', 'Feedback left by students on closed complaints') ?></p></div>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= $totalReviews ?></div><div class="stat-label">Total Reviews</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#DC2626;"><?= $pendingReviews ?></div><div class="stat-label">Missing / Pending Review</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $satisfactionRate ?>%</div><div class="stat-label">Satisfaction Rate</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $reviewsToday ?></div><div class="stat-label">Today</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $reviewsWeek ?></div><div class="stat-label">This Week</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $reviewsMonth ?></div><div class="stat-label">This Month</div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $reviewsYear ?></div><div class="stat-label">This Year</div></div>
    </div>

    <div class="card" style="background:#FDF2F4;border:1px solid #F3D2D9;text-align:center;padding:16px;">
        <p style="font-size:13px;color:#6B7280;margin-bottom:8px;">Want the full emoji-by-emoji breakdown, complaint category trends, and downloadable Excel reports?</p>
        <a href="reports.php" class="btn btn-primary btn-sm"><i class="ti ti-report-analytics"></i> Go to Reports</a>
    </div>

    <div class="card">
        <div class="card-title" style="justify-content:space-between;">
            <span><i class="ti ti-alert-circle"></i> Awaiting Student Review (<?= $pendingReviews ?>)</span>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Student</th><th>Category</th><th>Title</th><th>Closed On</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($pendingReviewList)): ?>
                <tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;">All closed complaints have been reviewed.</td></tr>
            <?php else: foreach ($pendingReviewList as $c): ?>
            <tr>
                <td><?= $c['is_anonymous'] ? '<em style="color:#9CA3AF;">Anonymous</em>' : e($c['first_name'].' '.$c['last_name']) ?></td>
                <td><?= e($c['category']) ?></td>
                <td><?= e($c['title']) ?></td>
                <td><?= date('M j, Y', strtotime($c['updated_at'])) ?></td>
                <td><a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <div class="card-title" style="justify-content:space-between;">
            <span><i class="ti ti-star-filled"></i> Recent Reviews</span>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Student</th><th>Complaint</th><th>Reaction</th><th>Comment</th><th>Date</th></tr></thead>
            <tbody>
            <?php if (empty($recentReviews)): ?>
                <tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:18px;">No reviews submitted yet.</td></tr>
            <?php else: foreach ($recentReviews as $r): ?>
            <tr>
                <td><?= e($r['first_name'].' '.$r['last_name']) ?></td>
                <td><?= e($r['complaint_title']) ?></td>
                <td style="font-size:20px;"><?= e($r['reaction']) ?></td>
                <td><?= $r['comment'] ? e($r['comment']) : '<em style="color:#9CA3AF;">No comment</em>' ?></td>
                <td><?= date('M j, Y g:i A', strtotime($r['created_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div></div>
<script src="../assets/js/sidebar.js?v=5"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body></html>
