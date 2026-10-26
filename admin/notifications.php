<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/notif-ui.php';
requireStaff();
$staff_id = $_SESSION['user_id'];
$notifCount = getUnreadStaffNotifCount($pdo, $staff_id);

// ── AJAX: delete one / delete all ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'delete_one' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("DELETE FROM staff_notifications WHERE id = ? AND staff_id = ?");
        $stmt->execute([(int)$_POST['id'], $staff_id]);
        echo json_encode(['ok' => true]);
    } elseif ($_POST['action'] === 'delete_all') {
        $pdo->prepare("DELETE FROM staff_notifications WHERE staff_id = ?")->execute([$staff_id]);
        echo json_encode(['ok' => true]);
    } elseif ($_POST['action'] === 'mark_one' && isset($_POST['id'])) {
        $pdo->prepare("UPDATE staff_notifications SET is_read=1 WHERE id=? AND staff_id=?")->execute([(int)$_POST['id'], $staff_id]);
        echo json_encode(['ok' => true]);
    } elseif ($_POST['action'] === 'mark_all_read') {
        $pdo->prepare("UPDATE staff_notifications SET is_read=1 WHERE staff_id=?")->execute([$staff_id]);
        echo json_encode(['ok' => true]);
    } elseif (notifHandleBulk($pdo, 'staff_notifications', (int)$staff_id)) {
        // handled (mark unread / bulk read / bulk unread / bulk delete)
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ── Tabs + pagination (20 per page) ─────────────────────────────────────────
$filter = notifNormalizeFilter($_GET['f'] ?? 'all', 'staff_notifications');
$page   = max(1, (int)($_GET['p'] ?? 1));
$counts = notifTabCounts($pdo, 'staff_notifications', (int)$staff_id);
$totalForFilter = $counts[$filter] ?? 0;
$lastPage = max(1, (int)ceil($totalForFilter / notifPerPage()));
if ($page > $lastPage) $page = $lastPage;
$notifs = notifFetchPage($pdo, 'staff_notifications', (int)$staff_id, $filter, $page);

// NOTE: opening this page no longer marks anything as read. A notification becomes read when it is
// clicked, via its check button, or with "Mark all as read".
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.notifications', 'Notifications') ?> — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <style>
<?php notifCss('#7C3AED', '#EDE9FE'); ?>
        .notif-row {
            display: flex; gap: 12px; padding: 14px 0;
            border-bottom: 1px solid #F3F4F6; align-items: flex-start;
            transition: opacity 0.25s ease;
        }
        .notif-row:last-child { border-bottom: none; }
        .notif-icon {
            width: 36px; height: 36px; border-radius: 50%;
            background: #EDE9FE;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .notif-body  { flex: 1; min-width: 0; }
        .notif-msg   { font-size: 13px; color: #1F2937; }
        .notif-time  { font-size: 11px; color: #9CA3AF; margin-top: 3px; }
        .notif-link  { font-size: 12px; color: #7C3AED; }
        .notif-delete-btn {
            flex-shrink: 0; background: none; border: none; cursor: pointer;
            color: #D1D5DB; padding: 4px 6px; border-radius: 6px;
            transition: color 0.15s, background 0.15s; font-size: 16px; line-height: 1;
        }
        .notif-delete-btn:hover { color: #EF4444; background: #FEF2F2; }
        .notif-header-row {
            display: flex; align-items: center;
            justify-content: space-between; margin-bottom: 10px;
        }
        .btn-delete-all {
            font-size: 13px; color: #EF4444; background: #FEF2F2;
            border: 1px solid #FECACA; border-radius: 8px;
            padding: 6px 14px; cursor: pointer;
            display: flex; align-items: center; gap: 5px; transition: background 0.15s;
        }
        .btn-delete-all:hover { background: #FEE2E2; }
        .modal-overlay {
            display: none;
            position: fixed; inset: 0; z-index: 500;
            background: rgba(0,0,0,0.45);
            align-items: center; justify-content: center;
            animation: fadeIn 0.18s ease;
        }
        .modal-overlay.open { display: flex; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-box {
            background: #fff;
            border-radius: 14px;
            padding: 28px 28px 22px;
            width: 100%; max-width: 360px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.18);
            animation: slideUp 0.2s ease;
            text-align: center;
        }
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
        .modal-icon {
            width: 52px; height: 52px; border-radius: 50%;
            background: #FEF2F2;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
        }
        .modal-icon i { font-size: 26px; color: #EF4444; }
        .modal-title {
            font-size: 16px; font-weight: 600;
            color: #1F2937; margin-bottom: 6px;
        }
        .modal-desc {
            font-size: 13px; color: #6B7280; margin-bottom: 22px; line-height: 1.5;
        }
        .modal-actions { display: flex; gap: 10px; justify-content: center; }
        .modal-actions .btn { min-width: 100px; justify-content: center; }
    </style>
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>

<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <div class="modal-icon"><i class="ti ti-trash" id="modalIcon"></i></div>
        <div class="modal-title" id="modalTitle"><?= t('ui.delete_notification_9006', 'Delete Notification') ?></div>
        <div class="modal-desc"  id="modalDesc"><?= t('ui.are_you_sure_you_want_to_delete_th_a039', 'Are you sure you want to delete this notification? This cannot be undone.') ?></div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeModal()"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <button class="btn btn-danger"  id="modalConfirmBtn"><?= t('ui.delete_f2a6', 'Delete') ?></button>
        </div>
    </div>
</div>

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
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?></a><a class="sitem" href="../osa/messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?></a>
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
    <a class="sitem active" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>

<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('nav.notifications', 'Notifications') ?></h1></div>

    <?php notifRenderCard($notifs, $counts, $filter, $page, $totalForFilter, notifPerPage(), 'staff_notifications'); ?>
</div></div>

<script>
let pendingAction = null;

function openModal(title, desc, onConfirm) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalDesc').textContent  = desc;
    document.getElementById('modalConfirmBtn').onclick = function() {
        closeModal();
        onConfirm();
    };
    document.getElementById('confirmModal').classList.add('open');
}

function closeModal() {
    document.getElementById('confirmModal').classList.remove('open');
}

document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function askDeleteOne(id) {
    openModal(
        <?= json_encode(t('notif.delete_one_title', 'Delete Notification')) ?>,
        <?= json_encode(t('notif.delete_one_desc', 'Are you sure you want to delete this notification? This cannot be undone.')) ?>,
        () => doDeleteOne(id)
    );
}

function askDeleteAll() {
    openModal(
        <?= json_encode(t('notif.delete_all_title', 'Delete All Notifications')) ?>,
        <?= json_encode(t('notif.delete_all_desc', 'This will permanently remove all your notifications. Are you sure?')) ?>,
        () => doDeleteAll()
    );
}

function doDeleteOne(id) {
    const row = document.getElementById('notif-' + id);
    if (!row) return;
    row.style.opacity = '0.4';
    fetch('notifications.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete_one&id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) { row.remove(); checkEmpty(); }
        else row.style.opacity = '1';
    })
    .catch(() => { row.style.opacity = '1'; });
}

function doDeleteAll() {
    fetch('notifications.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete_all'
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            document.getElementById('notif-list').innerHTML = '';
            checkEmpty();
        }
    });
}

function checkEmpty() {
    const list     = document.getElementById('notif-list');
    const noNotifs = document.getElementById('no-notifs');
    const header   = document.getElementById('notif-header');
    if (list && list.querySelectorAll('.notif-row').length === 0) {
        noNotifs.style.display = 'flex';
        if (header) header.style.display = 'none';
    }
}

<?php notifJs(); ?>
function markAllRead() {
    fetch('notifications.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=mark_all_read'
    }).then(r => r.json()).then(d => { if (d.ok) location.reload(); });
}
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
</body>
</html>
