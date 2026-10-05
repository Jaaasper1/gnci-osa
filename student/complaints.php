<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStudent();

$student_id = $_SESSION['user_id'];
$notifCount = getUnreadNotifCount($pdo, $student_id);
$election = getAnyActiveOrRecentElection($pdo);

// Stats for the summary boxes
$statStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM complaints WHERE student_id = ? GROUP BY status");
$statStmt->execute([$student_id]);
$stats = ['New' => 0, 'In Review' => 0, 'Resolved' => 0, 'Rejected' => 0];
foreach ($statStmt->fetchAll() as $row) $stats[$row['status']] = $row['cnt'];
$totalComplaintsCount = array_sum($stats);

// Delete handler — only allow deleting "New" complaints owned by this student
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $check = $pdo->prepare("SELECT id FROM complaints WHERE id = ? AND student_id = ? AND status = 'New'");
    $check->execute([$del_id, $student_id]);
    if ($check->fetch()) {
        $pdo->prepare("DELETE FROM complaints WHERE id = ?")->execute([$del_id]);
    }
    header('Location: complaints.php'); exit;
}

$stmt = $pdo->prepare("SELECT * FROM complaints WHERE student_id = ? AND is_archived = 0 ORDER BY created_at DESC");
$stmt->execute([$student_id]);
$complaints = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Complaints — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=23">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub"><?= t('nav.student_portal', 'Student Portal') ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div><span class="uname"><?= htmlspecialchars($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <a class="sitem active" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <?php if ($election): ?>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="voting.php"><i class="ti ti-checkbox"></i> <?= t('sidebar.vote_now', 'Vote Now') ?></a>
    <?php endif; ?><div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div><h1><?= t('sidebar.my_complaints', 'My Complaints') ?></h1><p><?= t('complaints.all_submitted', 'All complaints you have submitted') ?></p></div>
        <a href="new-complaint.php" class="btn btn-primary"><i class="ti ti-plus"></i> New Complaint</a>
    </div>
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-num"><?= $totalComplaintsCount ?></div><div class="stat-label"><?= t('common.total', 'Total') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#5B21B6;"><?= $stats['New'] ?></div><div class="stat-label"><?= t('status.new', 'New') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#6B1A2A;"><?= $stats['In Review'] ?></div><div class="stat-label"><?= t('status.in_review', 'In Review') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $stats['Resolved'] ?></div><div class="stat-label"><?= t('status.resolved', 'Resolved') ?></div></div>
    </div>
    <div class="card">
        <?php if (empty($complaints)): ?>
            <div class="alert alert-info"><i class="ti ti-info-circle"></i> You have not submitted any complaints yet.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Category</th><th>Title</th><th>Date Submitted</th><th>Status</th><th>Anonymous?</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($complaints as $c): ?>
                <tr>
                                        <td><?= htmlspecialchars($c['category']) ?></td>
                    <td><?= htmlspecialchars($c['title']) ?></td>
                    <td><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                    <td><?= statusBadge($c['status']) ?></td>
                    <td><?= $c['is_anonymous'] ? '<span style="color:#059669;font-size:12px;">✓ Anonymous</span>' : '—' ?></td>
                    <td style="display:flex;gap:6px;">
                        <a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        <?php if ($c['status'] === 'New'): ?>
                        <button class="btn btn-sm" style="background:#FEE2E2;color:#991B1B;border-color:#FECACA;"
                            onclick="openDeleteModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['title'])) ?>')">
                            <i class="ti ti-trash"></i> Delete
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div></div>

<!-- Delete Confirm Modal -->
<div id="delete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:20px;">
            <div style="width:44px;height:44px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="ti ti-trash" style="color:#DC2626;font-size:22px;"></i>
            </div>
            <div>
                <div style="font-size:15px;font-weight:700;color:#1F2937;margin-bottom:5px;">Delete this complaint?</div>
                <div style="font-size:13px;color:#6B7280;line-height:1.6;">
                    "<span id="delete-title" style="font-weight:600;color:#374151;"></span>" will be permanently deleted and cannot be recovered.
                </div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button onclick="closeDeleteModal()" class="btn btn-outline btn-sm">Cancel</button>
            <a id="delete-confirm-btn" href="#" class="btn btn-sm" style="background:#DC2626;color:#fff;border-color:#DC2626;">
                <i class="ti ti-trash"></i> Yes, Delete
            </a>
        </div>
    </div>
</div>

<script>
function openDeleteModal(id, title) {
    document.getElementById('delete-title').textContent = title;
    document.getElementById('delete-confirm-btn').href = 'complaints.php?delete=' + id;
    document.getElementById('delete-modal').style.display = 'flex';
}
function closeDeleteModal() {
    document.getElementById('delete-modal').style.display = 'none';
}
document.getElementById('delete-modal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDeleteModal();
});
</script>
<script src="../assets/js/sidebar.js?v=4"></script>
</body>
</html>
