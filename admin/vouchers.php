<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStaff();
require_once __DIR__ . '/election-picker.php';
require_once '../includes/pager.php';

$admin_id = $_SESSION['user_id'];
$msg = $err = '';

// ── AJAX: generate voucher for one student ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    header('Content-Type: application/json');
    $election_id = (int)($_POST['election_id'] ?? 0);
    $student_id  = (int)($_POST['student_id']  ?? 0);
    if (!$election_id || !$student_id) { echo json_encode(['ok'=>false,'msg'=>t('msg.invalid_input_d3d1', 'Invalid input.')]); exit; }

    // Block re-generation if the student already voted (voucher was used)
    $existing = $pdo->prepare("SELECT is_used FROM voting_vouchers WHERE election_id=? AND student_id=?");
    $existing->execute([$election_id, $student_id]);
    $existingVoucher = $existing->fetch();
    if ($existingVoucher && $existingVoucher['is_used']) {
        echo json_encode(['ok'=>false, 'msg'=>t('msg.this_student_has_already_voted_using_thi_b1d2', 'This student has already voted using this voucher. A new code cannot be issued.')]);
        exit;
    }

    // Invalidate old voucher if exists (admin requested re-generate)
    $pdo->prepare("DELETE FROM voting_vouchers WHERE election_id=? AND student_id=?")
        ->execute([$election_id, $student_id]);

    // Generate unique 12-char alphanumeric code (uppercase, no O/0/I/1 for readability)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 12; $i++) $code .= $chars[random_int(0, strlen($chars)-1)];
        // Format as XXXX-XXXX-XXXX
        $formatted = substr($code,0,4).'-'.substr($code,4,4).'-'.substr($code,8,4);
        $exists = $pdo->prepare("SELECT id FROM voting_vouchers WHERE code=?");
        $exists->execute([$formatted]);
    } while ($exists->fetch());

    $stmt = $pdo->prepare("INSERT INTO voting_vouchers (election_id, student_id, code, generated_by) VALUES (?,?,?,?)");
    $stmt->execute([$election_id, $student_id, $formatted, $admin_id]);
    // The code itself is never written to the log
    auditLog($pdo, 'generate', 'voucher', (int)$pdo->lastInsertId(), [], ['election_id' => $election_id, 'student_id' => $student_id]);

    // Get student name for response
    $s = $pdo->prepare("SELECT first_name, last_name, student_id FROM students WHERE id=?");
    $s->execute([$student_id]);
    $s = $s->fetch();

    echo json_encode(['ok'=>true, 'code'=>$formatted, 'student'=>$s['first_name'].' '.$s['last_name'], 'sid'=>$s['student_id']]);
    exit;
}

// ── AJAX: generate vouchers for ALL students in election's course ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_all') {
    header('Content-Type: application/json');
    $election_id = (int)($_POST['election_id'] ?? 0);
    if (!$election_id) { echo json_encode(['ok'=>false,'msg'=>t('msg.invalid_election_f167', 'Invalid election.')]); exit; }

    $election = $pdo->prepare("SELECT * FROM elections WHERE id=?");
    $election->execute([$election_id]);
    $election = $election->fetch();
    if (!$election) { echo json_encode(['ok'=>false,'msg'=>t('msg.election_not_found_fee4', 'Election not found.')]); exit; }

    $students = $pdo->prepare("SELECT id FROM students WHERE course=? AND is_active=1");
    $students->execute([$election['course']]);
    $students = $students->fetchAll();

    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $generated = 0;
    foreach ($students as $st) {
        // Skip if already has a voucher
        $has = $pdo->prepare("SELECT id FROM voting_vouchers WHERE election_id=? AND student_id=?");
        $has->execute([$election_id, $st['id']]);
        if ($has->fetch()) continue;

        do {
            $code = '';
            for ($i = 0; $i < 12; $i++) $code .= $chars[random_int(0, strlen($chars)-1)];
            $formatted = substr($code,0,4).'-'.substr($code,4,4).'-'.substr($code,8,4);
            $ex = $pdo->prepare("SELECT id FROM voting_vouchers WHERE code=?");
            $ex->execute([$formatted]);
        } while ($ex->fetch());

        $pdo->prepare("INSERT INTO voting_vouchers (election_id, student_id, code, generated_by) VALUES (?,?,?,?)")
            ->execute([$election_id, $st['id'], $formatted, $admin_id]);
        $generated++;
    }
    auditLog($pdo, 'generate_all', 'voucher', $election_id, [], ['count' => $generated]);
    echo json_encode(['ok'=>true, 'count'=>$generated]);
    exit;
}

// ── AJAX: revoke voucher ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    header('Content-Type: application/json');
    $voucher_id = (int)($_POST['voucher_id'] ?? 0);
    $__before = auditRow($pdo, 'voting_vouchers', $voucher_id);
    unset($__before['code']);   // never log the code
    $pdo->prepare("DELETE FROM voting_vouchers WHERE id=? AND is_used=0")->execute([$voucher_id]);
    auditLog($pdo, 'revoke', 'voucher', $voucher_id, $__before);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── GET: load elections and vouchers ────────────────────────────────────────
$elections = $pdo->query("SELECT * FROM elections WHERE is_active=1 ORDER BY created_at DESC")->fetchAll();
$selected_election_id = (int)($_GET['election_id'] ?? ($elections[0]['id'] ?? 0));
auditLog($pdo, 'view', 'voucher', $selected_election_id, [], ['page' => 'vouchers']);
$selected_election = null;
$rows = [];
$stat = ['total' => 0, 'used' => 0, 'unused' => 0, 'none' => 0];
$q       = trim($_GET['q'] ?? '');
$vstatus = in_array($_GET['status'] ?? '', ['active', 'used', 'none'], true) ? $_GET['status'] : 'all';
$perPage = (int)($_GET['pp'] ?? 25);
if (!in_array($perPage, [25, 50, 100], true)) $perPage = 25;
$page    = max(1, (int)($_GET['p'] ?? 1));

// ── Sorting (?sort=name|course|sid|status|generated & ?dir=asc|desc) ──
$vSortCols = [
    'name'      => 's.last_name',
    'course'    => 's.course',
    'sid'       => 's.student_id',
    'status'    => 'vv.is_used',
    'generated' => 'vv.generated_at',
];
$vsort = isset($vSortCols[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'name';
$vdir  = (($_GET['dir'] ?? '') === 'desc') ? 'desc' : 'asc';
if (!function_exists('vSortUrl')) {
    function vSortUrl(string $key, string $curSort, string $curDir): string {
        $q = $_GET;
        unset($q['p']);
        $q['sort'] = $key;
        $q['dir']  = ($key === $curSort && $curDir === 'asc') ? 'desc' : 'asc';
        return '?' . http_build_query($q);
    }
    function vSortIcon(string $key, string $curSort, string $curDir): string {
        if ($key !== $curSort) return '';
        return ' <i class="ti ti-chevron-' . ($curDir === 'asc' ? 'up' : 'down') . '" style="font-size:12px;"></i>';
    }
}
$totalRows = 0;

if ($selected_election_id) {
    $se = $pdo->prepare("SELECT * FROM elections WHERE id=?");
    $se->execute([$selected_election_id]);
    $selected_election = $se->fetch();

    if ($selected_election) {
        // Stats (counts only — no rows loaded)
        $c = $pdo->prepare("SELECT COUNT(*) t, COALESCE(SUM(is_used=1),0) u, COALESCE(SUM(is_used=0),0) a FROM voting_vouchers WHERE election_id=?");
        $c->execute([$selected_election_id]);
        $cr = $c->fetch();
        $n = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE s.course=? AND s.is_active=1
                            AND NOT EXISTS (SELECT 1 FROM voting_vouchers v WHERE v.election_id=? AND v.student_id=s.id)");
        $n->execute([$selected_election['course'], $selected_election_id]);
        $stat['used'] = (int)$cr['u']; $stat['unused'] = (int)$cr['a']; $stat['none'] = (int)$n->fetchColumn();
        $stat['total'] = (int)$cr['t'] + $stat['none'];

        // One paginated list: students of this course (with or without voucher) + any voucher holder
        $from = "FROM students s
                 LEFT JOIN voting_vouchers vv ON vv.student_id = s.id AND vv.election_id = ?
                 WHERE (vv.id IS NOT NULL OR (s.course = ? AND s.is_active = 1))";
        $args = [$selected_election_id, $selected_election['course']];
        if ($vstatus === 'active') $from .= " AND vv.id IS NOT NULL AND vv.is_used = 0";
        if ($vstatus === 'used')   $from .= " AND vv.is_used = 1";
        if ($vstatus === 'none')   $from .= " AND vv.id IS NULL";
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $from .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_id LIKE ? OR CONCAT(s.first_name,' ',s.last_name) LIKE ?)";
            array_push($args, $like, $like, $like, $like);
        }
        $ct = $pdo->prepare("SELECT COUNT(*) $from"); $ct->execute($args);
        $totalRows = (int)$ct->fetchColumn();
        $lastPage = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $lastPage) $page = $lastPage;
        $off = ($page - 1) * $perPage;
        $vOrder = $vSortCols[$vsort] . ' ' . strtoupper($vdir) . ', s.last_name, s.first_name, s.id';
        $ls = $pdo->prepare("SELECT s.id AS stu_pk, s.first_name, s.last_name, s.student_id AS sid, s.course,
                                    vv.id AS vid, vv.code, vv.is_used, vv.used_at, vv.generated_at
                             $from ORDER BY $vOrder LIMIT $perPage OFFSET $off");
        $ls->execute($args);
        $rows = $ls->fetchAll();
    }
}

$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.voting_vouchers', 'Voting Vouchers') ?> — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <style>
<?php pagerCss(); ?>
        .voucher-code {
            font-family: 'Courier New', monospace;
            font-size: 15px; font-weight: 700; letter-spacing: 1px;
            color: var(--maroon-dark);
            background: var(--maroon-pale);
            padding: 4px 10px; border-radius: 6px;
            display: inline-block;
        }
        .voucher-code.used {
            color: #9CA3AF; background: #F3F4F6;
            text-decoration: line-through;
        }
        .status-badge-used    { background:#F3F4F6; color:#6B7280; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; }
        .status-badge-unused  { background:#D1FAE5; color:#065F46; border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; }
        .copy-btn {
            background: none; border: none; cursor: pointer;
            color: #9CA3AF; font-size: 15px; padding: 2px 5px;
            border-radius: 4px; transition: color 0.15s;
        }
        .copy-btn:hover { color: var(--maroon); }
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; min-width: 640px; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 10px 12px; border-bottom: 2px solid #F3F4F6; color: #6B7280; font-weight: 600; font-size: 12px; white-space: nowrap; }
        td { padding: 10px 12px; border-bottom: 1px solid #F3F4F6; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #FAFAFA; }
        .no-voucher-row td { color: #9CA3AF; font-style: italic; }

        /* Modal */
        .modal-overlay { display:none; position:fixed; inset:0; z-index:500; background:rgba(0,0,0,0.45); align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal-box { background:#fff; border-radius:14px; padding:28px; width:100%; max-width:380px; box-shadow:0 8px 32px rgba(0,0,0,0.18); text-align:center; }
        .modal-icon { width:52px; height:52px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 14px; }
        .modal-title { font-size:16px; font-weight:600; color:var(--maroon-dark); margin-bottom:6px; }
        .modal-desc  { font-size:13px; color:#6B7280; margin-bottom:16px; line-height:1.5; }
        .modal-code  { font-family:'Courier New',monospace; font-size:20px; font-weight:700; letter-spacing:2px; color:var(--maroon-dark); background:var(--maroon-pale); padding:10px 20px; border-radius:8px; display:inline-block; margin-bottom:20px; }
        .modal-actions { display:flex; gap:10px; justify-content:center; }
        .modal-actions .btn { min-width:110px; justify-content:center; }
    </style>
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body class="theme-election">
<!-- Result Modal (shows generated code) -->
<div class="modal-overlay" id="resultModal">
    <div class="modal-box">
        <div class="modal-icon" style="background:#D1FAE5;"><i class="ti ti-ticket" style="font-size:26px;color:#059669;"></i></div>
        <div class="modal-title"><?= t('ui.voucher_generated_3714', 'Voucher Generated!') ?></div>
        <div class="modal-desc" id="resultDesc"></div>
        <div class="modal-code" id="resultCode"></div>
        <p style="font-size:12px;color:#9CA3AF;margin-bottom:16px;"><?= t('ui.give_this_code_to_the_student_it_c_e01c', 'Give this code to the student. It can only be used once.') ?></p>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="copyCode(this)"><i class="ti ti-copy"></i> <?= t('ui.copy_code_d056', 'Copy Code') ?></button>
            <button class="btn btn-primary" onclick="closeResultModal()"><?= t('ui.done_f929', 'Done') ?></button>
        </div>
    </div>
</div>

<!-- Confirm Revoke Modal -->
<div class="modal-overlay" id="revokeModal">
    <div class="modal-box">
        <div class="modal-icon" style="background:#FEF2F2;"><i class="ti ti-trash" style="font-size:26px;color:#EF4444;"></i></div>
        <div class="modal-title"><?= t('ui.revoke_voucher_28a0', 'Revoke Voucher?') ?></div>
        <div class="modal-desc"><?= t('ui.this_will_permanently_delete_the_u_b153', 'This will permanently delete the unused voucher. The student will need a new one to vote.') ?></div>
        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeRevokeModal()"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <button class="btn btn-danger" id="revokeConfirmBtn"><?= t('ui.revoke_2131', 'Revoke') ?></button>
        </div>
    </div>
</div>

<nav class="topnav">
    <button class="hamburger" id="hamburger"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub"><?= t('ui.admin_panel_a0e0', 'Admin Panel') ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
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
    <a class="sitem active" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
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
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <div><h1><?= t('sidebar.vouchers', 'Voting Vouchers') ?></h1><p><?= t('admin.vouchers.subtitle', 'Generate and manage one-time voting codes per election.') ?></p></div>
        <?php if ($selected_election_id):
            // Course options = courses of students who have a voucher in this election
            $__vcStmt = $pdo->prepare("SELECT DISTINCT TRIM(s.course) FROM voting_vouchers vv JOIN students s ON vv.student_id = s.id WHERE vv.election_id = ? ORDER BY 1");
            $__vcStmt->execute([$selected_election_id]);
            $__vcOpts = [];
            foreach ($__vcStmt->fetchAll(PDO::FETCH_COLUMN) as $__vc) {
                if ($__vc === null || $__vc === '') { $__vcOpts['__none__'] = '(No course)'; }
                else { $__vcOpts[$__vc] = $__vc; }
            }
        ?>
        <?= exportMenu('export-vouchers-excel.php?election_id='.$selected_election_id.'&sort='.$vsort.'&dir='.$vdir, 'btn btn-outline', '', 'Export', ['xlsx','pdf','docx','csv'], [
            ['key' => 'course', 'label' => 'Course', 'options' => $__vcOpts],
        ]) ?>
        <?php endif; ?>
    </div>

    <?php if (empty($elections)): ?>
        <div class="alert alert-info"><i class="ti ti-info-circle"></i> <?= t('ui.no_active_elections_found_create_a_ca1b', 'No active elections found. Create an election first.') ?></div>
    <?php else: ?>

    <!-- Election selector (searchable, with course colors) -->
    <?php renderElectionPicker($elections, (int)$selected_election_id, 'election_id'); ?>

    <?php if ($selected_election): ?>
    <!-- Stats row -->
    <?php
        $totalStudents = $stat['total'];
        $usedCount     = $stat['used'];
        $unusedCount   = $stat['unused'];
        $noVoucherCount = $stat['none'];
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;">
        <div class="stat-card"><div class="stat-num"><?= $totalStudents ?></div><div class="stat-label"><?= t('ui.total_students_44da', 'Total Students') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#059669;"><?= $unusedCount ?></div><div class="stat-label"><?= t('ui.active_vouchers_f614', 'Active Vouchers') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:#6B7280;"><?= $usedCount ?></div><div class="stat-label"><?= t('ui.used_voted_7773', 'Used (Voted)') ?></div></div>
        <div class="stat-card"><div class="stat-num" style="color:var(--gold);"><?= $noVoucherCount ?></div><div class="stat-label"><?= t('ui.no_voucher_yet_0937', 'No Voucher Yet') ?></div></div>
    </div>

    <!-- Actions -->
    <div class="card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <div>
                <div style="font-size:14px;font-weight:600;color:var(--maroon-dark);margin-bottom:2px;"><?= t('ui.bulk_generate_3894', 'Bulk Generate') ?></div>
                <div style="font-size:12px;color:#6B7280;"><?= t('ui.generate_vouchers_for_all_1988', 'Generate vouchers for all') ?> <?= e($selected_election['course']) ?> <?= t('ui.students_who_don_t_have_one_yet_8a9f', 'students who don\'t have one yet.') ?></div>
            </div>
            <?php if ($noVoucherCount > 0): ?>
            <button class="btn btn-primary" onclick="generateAll()">
                <i class="ti ti-wand"></i> <?= t('ui.generate_for_e5c9', 'Generate for') ?> <?= $noVoucherCount ?> <?= t('count.student', 'student' . ($noVoucherCount>1?'s':'')) ?>
            </button>
            <?php else: ?>
            <span style="font-size:13px;color:#059669;"><i class="ti ti-circle-check"></i> <?= t('ui.all_students_have_vouchers_aa5f', 'All students have vouchers') ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Voucher table -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
            <div class="card-title" style="display:flex;align-items:center;gap:8px;font-weight:600;color:var(--maroon-dark);">
                <i class="ti ti-ticket"></i> <?= t('ui.voucher_list_71a7', 'Voucher List') ?>
            </div>
            <form method="get" id="voucherFilter" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="election_id" value="<?= (int)$selected_election_id ?>">
                <input type="hidden" name="pp" value="<?= (int)$perPage ?>">
                <select name="status" class="form-control" style="max-width:150px;" onchange="this.form.submit()">
                    <?php foreach (['all'=>'All','active'=>'Active','used'=>'Used (Voted)','none'=>'No voucher'] as $k=>$lb): ?>
                    <option value="<?= $k ?>" <?= $vstatus===$k?'selected':'' ?>><?= $lb ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" id="searchInput" name="q" value="<?= e($q) ?>" class="form-control" style="max-width:220px;" placeholder="<?= t('ui.search_name_or_student_id_e25c', 'Search name or student ID...') ?>" autocomplete="off">
            </form>
        </div>

        <div class="table-wrap">
        <table id="voucherTable">
            <thead>
                <tr>
                    <th><a href="<?= e(vSortUrl('name', $vsort, $vdir)) ?>" style="color:inherit;text-decoration:none;white-space:nowrap;"><?= t('ui.student_f5c0', 'Student') ?><?= vSortIcon('name', $vsort, $vdir) ?></a></th>
                    <th><a href="<?= e(vSortUrl('sid', $vsort, $vdir)) ?>" style="color:inherit;text-decoration:none;white-space:nowrap;">Student ID<?= vSortIcon('sid', $vsort, $vdir) ?></a></th>
                    <th><a href="<?= e(vSortUrl('course', $vsort, $vdir)) ?>" style="color:inherit;text-decoration:none;white-space:nowrap;"><?= t('ui.course_3730', 'Course') ?><?= vSortIcon('course', $vsort, $vdir) ?></a></th>
                    <th><?= t('ui.voucher_code_db56', 'Voucher Code') ?></th>
                    <th><a href="<?= e(vSortUrl('status', $vsort, $vdir)) ?>" style="color:inherit;text-decoration:none;white-space:nowrap;"><?= t('ui.status_ec53', 'Status') ?><?= vSortIcon('status', $vsort, $vdir) ?></a></th>
                    <th><a href="<?= e(vSortUrl('generated', $vsort, $vdir)) ?>" style="color:inherit;text-decoration:none;white-space:nowrap;"><?= t('ui.generated_5c5f', 'Generated') ?><?= vSortIcon('generated', $vsort, $vdir) ?></a></th>
                    <th><?= t('ui.actions_06df', 'Actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $v): if ($v['vid'] !== null): ?>
            <tr<?= !$v['is_used'] ? ' data-bulk-id="' . (int)$v['vid'] . '"' : '' ?>>
                <td><?= e($v['first_name'].' '.$v['last_name']) ?></td>
                <td><?= e($v['sid']) ?></td>
                <td><?= e($v['course'] ?? '') ?: '<span style="color:#9CA3AF;">—</span>' ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <span class="voucher-code <?= $v['is_used']?'used':'' ?>"><?= e($v['code']) ?></span>
                        <?php if (!$v['is_used']): ?>
                        <button class="copy-btn" onclick="copyText('<?= $v['code'] ?>', this)" title="<?= t('ui.copy_5fb6', 'Copy') ?>"><i class="ti ti-copy"></i></button>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php if ($v['is_used']): ?>
                        <span class="status-badge-used"><i class="ti ti-check"></i> <?= t('ui.used_019d', 'Used') ?></span>
                        <div style="font-size:11px;color:#9CA3AF;margin-top:2px;"><?= $v['used_at'] ? date('M j, Y g:i A', strtotime($v['used_at'])) : '' ?></div>
                    <?php else: ?>
                        <span class="status-badge-unused"><i class="ti ti-circle-check"></i> <?= t('ui.active_4d3d', 'Active') ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:#6B7280;"><?= date('M j, Y', strtotime($v['generated_at'])) ?></td>
                <td>
                    <?php if (!$v['is_used']): ?>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:nowrap;">
                        <button class="btn btn-sm btn-outline" style="white-space:nowrap;" onclick="generateOne(<?= $selected_election_id ?>, <?= $v['stu_pk'] ?>, '<?= e($v['first_name'].' '.$v['last_name']) ?>')">
                            <i class="ti ti-refresh"></i> <?= t('ui.re_generate_7589', 'Re-generate') ?>
                        </button>
                        <button class="btn btn-sm" style="color:#EF4444;border:1px solid #FECACA;background:#FEF2F2;flex-shrink:0;" onclick="askRevoke(<?= $v['vid'] ?>)">
                            <i class="ti ti-trash"></i>
                        </button>
                    </div>
                    <?php else: ?>
                    <span style="font-size:11px;color:#9CA3AF;"><i class="ti ti-lock"></i> <?= t('ui.voted_locked_df39', 'Voted — locked') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php else: $sw = ['id'=>$v['stu_pk'],'first_name'=>$v['first_name'],'last_name'=>$v['last_name'],'sid'=>$v['sid'],'course'=>$v['course']]; ?>

            <tr class="no-voucher-row">
                <td><?= e($sw['first_name'].' '.$sw['last_name']) ?></td>
                <td><?= e($sw['sid']) ?></td>
                <td><?= e($sw['course'] ?? '') ?: '<span style="color:#9CA3AF;">—</span>' ?></td>
                <td><span style="font-size:12px;color:#9CA3AF;"><?= t('ui.no_voucher_35bf', '— no voucher —') ?></span></td>
                <td><span style="font-size:12px;color:#9CA3AF;"><?= t('ui.not_generated_e0a5', 'Not generated') ?></span></td>
                <td>—</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="generateOne(<?= $selected_election_id ?>, <?= $sw['id'] ?>, '<?= e($sw['first_name'].' '.$sw['last_name']) ?>')">
                        <i class="ti ti-wand"></i> <?= t('ui.generate_32b9', 'Generate') ?>
                    </button>
                </td>
            </tr>
            <?php endif; endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;padding:36px;color:#9CA3AF;"><?= t('ui.no_students_match_c458', 'No students match.') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php pagerRender($page, $perPage, $totalRows); ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

</div></div>

<script>
const electionId = <?= $selected_election_id ?: 0 ?>;

// live search: submit the GET form shortly after typing stops
(function () {
    const inp = document.getElementById('searchInput'); if (!inp) return;
    let t; inp.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => inp.form.submit(), 450); });
    if (new URLSearchParams(location.search).get('q')) { inp.focus(); inp.setSelectionRange(inp.value.length, inp.value.length); }
})();

// ── Generate one ─────────────────────────────────────────────────────────────
function generateOne(electionId, studentId, studentName) {
    const label = document.querySelector(`button[onclick="generateOne(${electionId}, ${studentId}, '${studentName}')"]`);
    if (label) { label.disabled = true; label.innerHTML = '<i class="ti ti-loader"></i> Generating...'; }

    fetch('vouchers.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=generate&election_id=${electionId}&student_id=${studentId}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            document.getElementById('resultDesc').textContent = `Voucher for ${data.student} (${data.sid})`;
            document.getElementById('resultCode').textContent = data.code;
            document.getElementById('resultModal').classList.add('open');
            resultModalNeedsReload = true;
            // Table will refresh once the admin closes the modal (see closeResultModal),
            // giving them time to read/copy the new code first instead of it flashing away.
        } else {
            appToast(data.msg || __t('Error generating voucher.'), 'error');
            if (label) { label.disabled = false; label.innerHTML = '<i class="ti ti-wand"></i> Generate'; }
        }
    });
}

// ── Generate all ─────────────────────────────────────────────────────────────
function generateAll() {
    appConfirm({
        title: __t('Generate vouchers for all students?'),
        message: __t('This will generate a voucher for every student in this department who doesn\'t already have one.'),
        btn: 'Yes, Generate'
    }, function () {
        fetch('vouchers.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: `action=generate_all&election_id=${electionId}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                appToast(`Done! Generated ${data.count} voucher(s).`, 'success');
                setTimeout(function () { location.reload(); }, 1400);
            }
        });
    });
}

// ── Revoke ───────────────────────────────────────────────────────────────────
let pendingRevokeId = null;
function askRevoke(voucherId) {
    pendingRevokeId = voucherId;
    document.getElementById('revokeModal').classList.add('open');
}
function closeRevokeModal() {
    document.getElementById('revokeModal').classList.remove('open');
    pendingRevokeId = null;
}
document.getElementById('revokeConfirmBtn').onclick = function() {
    if (!pendingRevokeId) return;
    fetch('vouchers.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=revoke&voucher_id=${pendingRevokeId}`
    })
    .then(r => r.json())
    .then(data => { if (data.ok) location.reload(); });
    closeRevokeModal();
};

// ── Result modal ─────────────────────────────────────────────────────────────
function closeResultModal() {
    document.getElementById('resultModal').classList.remove('open');
    // Refresh the list now that the admin has seen/copied the new code.
    if (resultModalNeedsReload) location.reload();
}
let resultModalNeedsReload = false;
function copyCode(btn) {
    const code = document.getElementById('resultCode').textContent;
    copyText(code, btn);
}
function showCopyFeedback(btn, ok, text) {
    if (!btn) { if (!ok) appToast('Could not copy automatically. Code: ' + text, 'error'); return; }
    const orig = btn.innerHTML;
    btn.innerHTML = ok ? '<i class="ti ti-check"></i> Copied!' : '<i class="ti ti-x"></i> Failed';
    btn.disabled = true;
    setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; }, 1500);
    if (!ok) appToast('Could not copy automatically. Code: ' + text, 'error');
}
function fallbackCopy(text) {
    // Works even when navigator.clipboard is unavailable/blocked (older or in-app mobile browsers).
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.top = '-9999px';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, text.length);
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
}
function copyText(text, btn) {
    if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showCopyFeedback(btn, true, text);
        }).catch(() => {
            showCopyFeedback(btn, fallbackCopy(text), text);
        });
    } else {
        showCopyFeedback(btn, fallbackCopy(text), text);
    }
}

// Close modals on overlay click
['resultModal','revokeModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('open');
            if (id === 'revokeModal') pendingRevokeId = null;
        }
    });
});
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
<script src="../assets/js/bulk-select.js?v=4"></script>
<script>
(function () {
  var t = document.getElementById('voucherTable'); if (!t) return;
  BulkSelect.init({
    rows: '#voucherTable tbody tr[data-bulk-id]', before: t.closest('.table-wrap'), noun: 'voucher',
    actions: [{ label: __t('Revoke'), icon: 'ti-trash', danger: true,
      confirm: function (n) { return 'Revoke ' + n + __t(' unused voucher(s)? Used vouchers are never affected.'); },
      run: function (id) {
        return fetch('vouchers.php', { method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'action=revoke&voucher_id=' + encodeURIComponent(id) })
          .then(function (r) { return r.json(); }).then(function (d) { return !!(d && d.ok); });
      } }]
  });
})();
</script>
<script>window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;</script>
<script src="../assets/js/admin-otp.js?v=2"></script>
</body>
</html>