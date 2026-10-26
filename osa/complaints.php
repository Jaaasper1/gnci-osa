<?php
require_once '../includes/db.php';
require_once '../includes/audit.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStaff();
$staff_id = $_SESSION['user_id'];
$notifCount = getUnreadStaffNotifCount($pdo, $staff_id);

// --- Claim a complaint (self-assign) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
    $claimId = (int)($_POST['id'] ?? 0);
    // Only allow claiming if it is still unassigned (avoid race with another staff member)
    $stmt = $pdo->prepare("UPDATE complaints SET assigned_to=?, assigned_to_name=? WHERE id=? AND assigned_to IS NULL");
    $stmt->execute([$staff_id, $_SESSION['name'] ?? null, $claimId]);
    auditLog($pdo, 'claim', 'complaint', $claimId, [], ['assigned_to' => $staff_id]);
    header('Location: complaints.php?tab=mine&claimed=1');
    exit;
}

// --- Unclaim a complaint (release back to the pool) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unclaim') {
    $unclaimId = (int)($_POST['id'] ?? 0);
    // Only the staff who currently owns it can release it, and only while it's
    // still New/In Review (already-Resolved/Rejected/Closed cases shouldn't be orphaned)
    $stmt = $pdo->prepare("UPDATE complaints SET assigned_to=NULL, assigned_to_name=NULL WHERE id=? AND assigned_to=? AND status IN ('New','In Review')");
    $stmt->execute([$unclaimId, $staff_id]);
    auditLog($pdo, 'unclaim', 'complaint', $unclaimId, ['assigned_to' => $staff_id], ['assigned_to' => null]);
    header('Location: complaints.php?tab=mine&unclaimed=1');
    exit;
}

$tab = ($_GET['tab'] ?? 'mine') === 'unclaimed' ? 'unclaimed' : 'mine';

$unclaimedCount = $pdo->query("SELECT COUNT(*) FROM complaints WHERE assigned_to IS NULL AND is_archived=0")->fetchColumn();

if ($tab === 'unclaimed') {
    $complaints = $pdo->query("SELECT c.*, s.first_name, s.last_name FROM complaints c JOIN students s ON c.student_id=s.id WHERE c.assigned_to IS NULL AND c.is_archived=0 ORDER BY c.created_at DESC")->fetchAll();
} else {
    $complaints = $pdo->prepare("SELECT c.*, s.first_name, s.last_name FROM complaints c JOIN students s ON c.student_id=s.id WHERE c.assigned_to=? AND c.is_archived=0 ORDER BY c.created_at DESC");
    $complaints->execute([$staff_id]);
    $complaints = $complaints->fetchAll();
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title><?= t('title.my_complaints', 'My Complaints') ?> — OSA</title><link rel="stylesheet" href="../assets/css/style.css?v=27"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css"><?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<nav class="topnav"><button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub"><?= t('ui.osa_staff_e4b4', 'OSA Staff') ?></div></div></div><div class="nav-right">
        <?php renderLangSwitcher(); ?><button class="notif-btn" onclick="location.href='notifications.php'"><i class="ti ti-bell"></i><?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?></button><div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div><span class="uname"><?= e($_SESSION['name']) ?></span></div></nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar"><div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div><a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a><a class="sitem active" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.complaints', 'Complaints') ?></a><a class="sitem" href="messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?></a><div class="sidebar-section"><?= t('sidebar.events', 'Events') ?></div><a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a><div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div><a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a><a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?><?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?></a><a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a></aside>
<div class="main"><div class="content">
<div class="page-header"><h1><?= t('sidebar.my_complaints', 'My Complaints') ?></h1></div>

<?php if (isset($_GET['claimed'])): ?>
<div class="alert-success" style="display:flex;align-items:center;gap:10px;background:#D1FAE5;border:1px solid #6EE7B7;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#065F46;">
    <i class="ti ti-circle-check" style="font-size:18px;"></i> <?= t('osa.claimed_success', 'Complaint claimed! It is now in your list.') ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['unclaimed'])): ?>
<div class="alert-success" style="display:flex;align-items:center;gap:10px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#92400E;">
    <i class="ti ti-arrow-back-up" style="font-size:18px;"></i> <?= t('osa.unclaimed_success', 'Complaint released back to the unclaimed pool.') ?>
</div>
<?php endif; ?>

<div style="display:flex;gap:8px;margin-bottom:18px;">
    <a href="complaints.php?tab=mine" style="padding:8px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;
        background:<?= $tab==='mine'?'#6B1A2E':'transparent' ?>;color:<?= $tab==='mine'?'#fff':'#6B7280' ?>;border:1px solid <?= $tab==='mine'?'#6B1A2E':'#E5E7EB' ?>;">
        <i class="ti ti-list-check"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?>
    </a>
    <a href="complaints.php?tab=unclaimed" style="padding:8px 16px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;
        background:<?= $tab==='unclaimed'?'#6B1A2E':'transparent' ?>;color:<?= $tab==='unclaimed'?'#fff':'#6B7280' ?>;border:1px solid <?= $tab==='unclaimed'?'#6B1A2E':'#E5E7EB' ?>;">
        <i class="ti ti-inbox"></i> <?= t('osa.unclaimed', 'Unclaimed') ?>
        <span style="background:<?= $tab==='unclaimed'?'rgba(255,255,255,0.25)':'#E5E7EB' ?>;color:<?= $tab==='unclaimed'?'#fff':'#374151' ?>;border-radius:20px;padding:1px 8px;font-size:11px;margin-left:4px;"><?= $unclaimedCount ?></span>
    </a>
</div>

<?php if ($tab === 'unclaimed'): ?>
<div style="font-size:12px;color:#9CA3AF;margin:-10px 0 14px;"><i class="ti ti-info-circle"></i> <?= t('osa.unclaimed_note', 'These complaints have not been assigned to anyone yet. Claim one to start working on it.') ?></div>
<?php endif; ?>

<div class="card"><div class="table-wrap"><table>
<thead><tr><th>#</th><th><?= t('common.student', 'Student') ?></th><th><?= t('common.category', 'Category') ?></th><th><?= t('common.title', 'Title') ?></th><th><?= t('common.date', 'Date') ?></th><th><?= t('common.status', 'Status') ?></th><th></th></tr></thead>
<tbody>
<?php if (empty($complaints)): ?>
<tr><td colspan="7" style="text-align:center;color:#9CA3AF;padding:24px;">
    <?= $tab === 'unclaimed' ? t('osa.no_unclaimed', 'No unclaimed complaints right now — nice work!') : t('osa.no_complaints', 'No complaints assigned to you yet.') ?>
</td></tr>
<?php else: foreach ($complaints as $c): ?><tr>
<td>#<?= $c['id'] ?></td>
<td><?= $c['is_anonymous'] ? '<em style="color:#9CA3AF;">'.t('common.anonymous', 'Anonymous').'</em>' : e($c['first_name'].' '.$c['last_name']) ?></td>
<td><?= e($c['category']) ?></td><td><?= e($c['title']) ?></td>
<td><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
<td><?= statusBadge($c['status']) ?></td>
<td>
<?php if ($tab === 'unclaimed'): ?>
    <form method="POST" style="display:inline;" class="confirm-submit-form"
          data-confirm-title="<?= e(t('osa.claim', 'Claim')) ?>"
          data-confirm-message="<?= e(t('osa.claim_confirm', 'Claim this complaint? It will be assigned to you.')) ?>"
          data-confirm-btn="<?= e(t('osa.claim', 'Claim')) ?>">
        <input type="hidden" name="action" value="claim">
        <input type="hidden" name="id" value="<?= $c['id'] ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-hand-stop"></i> <?= t('osa.claim', 'Claim') ?></button>
    </form>
<?php else: ?>
    <div style="display:flex;gap:6px;">
        <a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm"><?= t('osa.review', 'Review') ?></a>
        <?php if (in_array($c['status'], ['New','In Review'])): ?>
        <form method="POST" style="display:inline;" class="confirm-submit-form"
              data-confirm-title="<?= e(t('osa.unclaim', 'Unclaim')) ?>"
              data-confirm-message="<?= e(t('osa.unclaim_confirm', 'Release this complaint back to the unclaimed pool? Another staff member may pick it up.')) ?>"
              data-confirm-btn="<?= e(t('osa.unclaim', 'Unclaim')) ?>"
              data-confirm-danger="1">
            <input type="hidden" name="action" value="unclaim">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-sm" style="background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;" title="<?= t('osa.unclaim', 'Unclaim') ?>">
                <i class="ti ti-arrow-back-up"></i>
            </button>
        </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
</td>
</tr><?php endforeach; endif; ?></tbody>
</table></div></div>
</div></div><script src="../assets/js/sidebar.js?v=5"></script>
</body></html>
