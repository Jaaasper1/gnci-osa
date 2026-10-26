<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/audit.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/pager.php';
requireStaff();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

// Auto-archive/cleanup is heavy on big tables, so it runs at most once every 5 minutes (not on every request)
if (empty($_SESSION['arc_ts']) || time() - (int)$_SESSION['arc_ts'] > 300) {
    autoArchiveComplaints($pdo);
    $_SESSION['arc_ts'] = time();
}

// Background check (JS polls this every 30s): answer with just the archived count, not the whole page
if (isset($_GET['_arc_check'])) {
    session_write_close();
    echo '<span data-archived-count="' . (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE is_archived=1")->fetchColumn() . '"></span>';
    exit;
}

// Release the session lock now. PHP locks the session per request, so without this the 30s poll,
// live search and page clicks queue up behind each other (feels like lag).
session_write_close();

// Unarchive handler
if (isset($_POST['unarchive'])) {
    requireCsrf();
    $pdo->prepare("UPDATE complaints SET is_archived=0 WHERE id=?")->execute([(int)$_POST['unarchive']]);
    auditLog($pdo, 'unarchive', 'complaint', (int)$_POST['unarchive'], [], ['is_archived' => 0]);
    header('Location: complaints.php?tab=archived'); exit;
}

$tab      = $_GET['tab']      ?? 'active';
$filter   = $_GET['status']   ?? '';
$category = $_GET['category'] ?? '';
$search   = trim($_GET['search'] ?? '');
$sort     = $_GET['sort'] ?? '';
$dir      = (($_GET['dir'] ?? '') === 'asc') ? 'asc' : 'desc';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = pagerSize([15, 30, 50], 15);
$offset   = ($page - 1) * $perPage;

// Build WHERE clause
$where  = $tab === 'archived' ? 'c.is_archived = 1' : 'c.is_archived = 0';
$params = [];

// Date filter (month range) from the Date column menu
$dYear    = (int)($_GET['dyear'] ?? 0);
$dMonth   = (int)($_GET['dmonth'] ?? 0);
$dMonthTo = (int)($_GET['dmonth_to'] ?? 0);
if ($dYear < 2000 || $dYear > 2100) { $dYear = 0; }
if (!$dYear || $dMonth < 1 || $dMonth > 12) { $dMonth = 0; }
if (!$dMonth || $dMonthTo <= $dMonth || $dMonthTo > 12) { $dMonthTo = 0; }
if ($dYear) {
    $m1 = $dMonth ?: 1;
    $m2 = $dMonth ? ($dMonthTo ?: $dMonth) : 12;
    $dStart = new DateTime(sprintf('%04d-%02d-01', $dYear, $m1));
    $dEnd   = (clone $dStart)->modify('+' . ($m2 - $m1 + 1) . ' months');
    $where .= ' AND c.created_at >= ? AND c.created_at < ?';
    array_push($params, $dStart->format('Y-m-d'), $dEnd->format('Y-m-d'));
}
$hasFilter = ($search !== '' || $filter || $category || $dYear);

if ($filter)   { $where .= ' AND c.status = ?';    $params[] = $filter; }
if ($category) { $where .= ' AND c.category = ?';  $params[] = $category; }
if ($search) {
    $where .= ' AND (c.title LIKE ? OR c.message LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_id LIKE ?)';
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like, $like);
}

$baseSQL = "FROM complaints c
            JOIN students s ON c.student_id = s.id
            LEFT JOIN osa_staff st ON c.assigned_to = st.id
            WHERE $where";

// Total count for pagination
$countSQL  = $search !== '' ? $baseSQL : "FROM complaints c WHERE $where";   // joins only needed for name search
$countStmt = $pdo->prepare("SELECT COUNT(*) $countSQL");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$page       = min($page, $totalPages);

// Sorting (column header menu). Whitelisted, so nothing from the URL goes into the SQL as-is.
$D = strtoupper($dir);
$assignedExpr = "NULLIF(TRIM(COALESCE(NULLIF(c.assigned_to_name,''), CONCAT_WS(' ', st.first_name, st.last_name))), '')";
$sortSql = [
    // anonymous complaints always go last so the order can't reveal who they are
    'student'  => "c.is_anonymous ASC, s.last_name $D, s.first_name $D",
    'title'    => "c.title $D",
    'date'     => "c.created_at $D",
    'assigned' => "($assignedExpr IS NULL) ASC, $assignedExpr $D",   // Unassigned always last
    'archived' => "c.updated_at $D",
];
if (!isset($sortSql[$sort])) { $sort = ''; $orderBy = 'c.created_at DESC'; }
else                         { $orderBy = $sortSql[$sort]; }
$orderBy .= ', c.id DESC';

// Actual data
$dataStmt = $pdo->prepare("SELECT c.*, s.first_name, s.last_name, s.student_id as sid,
                                   st.first_name as staff_fn, st.last_name as staff_ln
                            $baseSQL
                            ORDER BY $orderBy
                            LIMIT $perPage OFFSET $offset");
$dataStmt->execute($params);
$complaints = $dataStmt->fetchAll();

// Tab counts
$tabCounts     = $pdo->query("SELECT COALESCE(SUM(is_archived=0),0) a, COALESCE(SUM(is_archived=1),0) b FROM complaints")->fetch();
$activeCount   = (int)$tabCounts['a'];
$archivedCount = (int)$tabCounts['b'];

// Distinct categories for filter dropdown
$categories = $pdo->query("SELECT DISTINCT category FROM complaints WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// Header menu data: every status, and the months/years that actually have complaints in this tab
$statusOptions = ['New','In Review','Resolved','Rejected','Closed'];
$dateMonths = [];
$mq = $pdo->prepare("SELECT YEAR(created_at) y, MONTH(created_at) m FROM complaints WHERE is_archived = ? GROUP BY y, m ORDER BY y, m");
$mq->execute([$tab === 'archived' ? 1 : 0]);
foreach ($mq->fetchAll() as $r) { $dateMonths[(string)$r['y']][] = (int)$r['m']; }

// Build URL helper (keeps all params except page)
function buildUrl($overrides = []) {
    $params = array_merge([
        'tab'      => $_GET['tab']      ?? 'active',
        'status'   => $_GET['status']   ?? '',
        'category' => $_GET['category'] ?? '',
        'search'   => $_GET['search']   ?? '',
        'dmonth'   => $_GET['dmonth']   ?? '',
        'dmonth_to'=> $_GET['dmonth_to']?? '',
        'dyear'    => $_GET['dyear']    ?? '',
        'sort'     => $_GET['sort']     ?? '',
        'dir'      => $_GET['dir']      ?? '',
        'page'     => $_GET['page']     ?? 1,
        'pp'       => $_GET['pp']       ?? '',
    ], $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'complaints.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.complaints', 'Complaints') ?> — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <style>
        .filter-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:14px; }
        .filter-bar .form-control { margin-bottom:0; height:36px; font-size:13px; }
        .result-info { font-size:12px; color:#9CA3AF; margin-bottom:10px; }
        .search-wrap { position:relative; flex:1; min-width:200px; max-width:320px; }
        .search-wrap i { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#9CA3AF; font-size:15px; }
        .search-wrap input { padding-left:32px; }
        .clear-search { position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; color:#9CA3AF; font-size:14px; text-decoration:none; }

        /* ── Mobile complaint cards ── */
        .complaint-cards { display:none; }
        .complaint-card {
            border: 1px solid #E5E7EB; border-radius: 10px; padding: 14px;
            margin-bottom: 10px; background: #fff; position: relative;
        }
        .complaint-card .cc-top {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 8px; margin-bottom: 8px;
        }
        .complaint-card .cc-title {
            font-size: 13px; font-weight: 600; color: #1F2937; line-height: 1.4;
            flex: 1; overflow: hidden; display: -webkit-box;
            -webkit-line-clamp: 2; -webkit-box-orient: vertical;
        }
        .complaint-card .cc-meta {
            font-size: 11px; color: #9CA3AF; display: flex; flex-wrap: wrap; gap: 6px;
            align-items: center; margin-bottom: 10px;
        }
        .complaint-card .cc-meta span { display:flex; align-items:center; gap:3px; }
        .complaint-card .cc-bottom {
            display: flex; justify-content: space-between; align-items: center; gap: 8px;
        }

        @media (max-width: 768px) {
            .complaint-table-wrap { display: none; }
            .complaint-cards { display: block; }
            .filter-row { flex-direction: column; }
            .filter-row .search-wrap { max-width: 100%; width: 100%; }
            .filter-row select { width: 100% !important; }
            .filter-row .btn { width: 100%; justify-content: center; }
            .tabs-row { overflow-x: auto; }
        }

        @media (max-width: 480px) {
            .complaint-card { padding: 12px; }
        }
    </style>
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
    <a class="sitem active" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?></a><a class="sitem" href="../osa/messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?></a>
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
    <div class="page-header"><h1><?= t('admin.complaints.title', 'All Complaints') ?></h1><p><?= $tab === "archived" ? t('admin.complaints.archived_note', 'Archived complaints are automatically deleted after 30 days.') : t('admin.complaints.autoarchive_note', 'Resolved/Rejected complaints auto-archive after 30 days.') ?></p></div>

    <!-- Tabs -->
    <div style="display:flex;gap:8px;margin-bottom:16px;border-bottom:2px solid #F3F4F6;">
        <a href="<?= buildUrl(['tab'=>'active','status'=>'','category'=>'','search'=>'','page'=>1]) ?>"
           style="padding:8px 18px;font-size:13px;font-weight:600;border-radius:8px 8px 0 0;text-decoration:none;
                  background:<?= $tab==='active'?'#6B1A2E':'transparent' ?>;
                  color:<?= $tab==='active'?'#fff':'#6B7280' ?>;">
            <i class="ti ti-message-report" style="margin-right:4px;"></i> <?= t('ui.active_4d3d', 'Active') ?>
            <span style="background:<?= $tab==='active'?'rgba(255,255,255,0.25)':'#E5E7EB' ?>;color:<?= $tab==='active'?'#fff':'#374151' ?>;border-radius:20px;padding:1px 8px;font-size:11px;margin-left:4px;"><?= $activeCount ?></span>
        </a>
        <a href="<?= buildUrl(['tab'=>'archived','status'=>'','category'=>'','search'=>'','page'=>1]) ?>"
           style="padding:8px 18px;font-size:13px;font-weight:600;border-radius:8px 8px 0 0;text-decoration:none;
                  background:<?= $tab==='archived'?'#6B7280':'transparent' ?>;
                  color:<?= $tab==='archived'?'#fff':'#6B7280' ?>;">
            <i class="ti ti-archive" style="margin-right:4px;"></i> <?= t('ui.archived_7d69', 'Archived') ?>
            <span data-archived-count="<?= $archivedCount ?>" style="background:<?= $tab==='archived'?'rgba(255,255,255,0.25)':'#E5E7EB' ?>;color:<?= $tab==='archived'?'#fff':'#374151' ?>;border-radius:20px;padding:1px 8px;font-size:11px;margin-left:4px;"><?= $archivedCount ?></span>
        </a>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="complaints.php" id="complaint-filter-form">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <input type="hidden" name="page" value="1">
        <?php if ($sort): ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <input type="hidden" name="dir" value="<?= e($dir) ?>">
        <?php endif; ?>
        <?php if ($dYear): ?>
        <input type="hidden" name="dyear" value="<?= (int)$dYear ?>">
        <?php if ($dMonth): ?><input type="hidden" name="dmonth" value="<?= (int)$dMonth ?>"><?php endif; ?>
        <?php if ($dMonthTo): ?><input type="hidden" name="dmonth_to" value="<?= (int)$dMonthTo ?>"><?php endif; ?>
        <?php endif; ?>
        <?php if ($filter): ?>
        <input type="hidden" name="status" value="<?= e($filter) ?>">
        <?php endif; ?>
        <?php if ($category): ?>
        <input type="hidden" name="category" value="<?= e($category) ?>">
        <?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
            <!-- Search -->
            <div class="search-wrap" style="flex:1;min-width:180px;max-width:280px;">
                <i class="ti ti-search"></i>
                <input type="text" name="search" id="complaint-search" autocomplete="off" class="form-control" placeholder="<?= t('ui.search_2a37', 'Search...') ?>" value="<?= e($search) ?>" style="margin-bottom:0;height:36px;font-size:13px;">
                <?php if ($search): ?><a href="<?= buildUrl(['search'=>'','page'=>1]) ?>" class="clear-search"><i class="ti ti-x"></i></a><?php endif; ?>
            </div>

            <button type="submit" class="btn btn-sm btn-primary" style="height:36px;white-space:nowrap;"><i class="ti ti-search"></i> <?= t('ui.search_1334', 'Search') ?></button>

            <a href="complaints.php?tab=<?= e($tab) ?>" id="complaint-clear-link" class="btn btn-sm btn-outline" style="height:36px;<?= $hasFilter ? '' : 'display:none;' ?>">
                <i class="ti ti-x"></i> <?= t('ui.clear_dc30', 'Clear') ?>
            </a>

            <?= exportMenu('export.php?type=complaints', 'btn btn-sm btn-outline', 'height:36px;color:#059669;border-color:#059669;white-space:nowrap;') ?>
        </div>
    </form>

    <div id="complaints-live" data-filtered="<?= $hasFilter ? '1' : '0' ?>">
    <!-- Result info -->
    <div class="result-info">
        <?= t('ui.showing_b4e6', 'Showing') ?> <?= $totalRows === 0 ? 0 : $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> <?= t('ui.of_8bf8', 'of') ?> <?= number_format($totalRows) ?> <?= t('count.complaint', 'complaint' . ($totalRows !== 1 ? 's' : '')) ?>
        <?php if ($hasFilter): ?>
        <span style="color:#7C3AED;"><?= t('ui.filtered_e1b0', '(filtered)') ?></span>
        <?php endif; ?>
    </div>

    <div class="card">
        <?php $emptyMsg = $hasFilter ? 'No complaints match your filters.' : ($tab==='archived' ? 'No archived complaints yet.' : 'No complaints found.'); ?>

        <!-- ── DESKTOP TABLE ── -->
        <div class="complaint-table-wrap table-wrap"><table data-tt data-tt-server data-tt-form="complaint-filter-form" data-tt-live="complaints-live" data-sort-current="<?= e($sort) ?>" data-sort-dir="<?= e($dir) ?>">
            <thead><tr>
                <th data-sort-key="student" data-type="text"><?= t('ui.student_f5c0', 'Student') ?></th>
                <th data-tt-filter="category" data-tt-all="All Categories" data-tt-icon="ti-tag" data-tt-value="<?= e($category) ?>" data-tt-options="<?= e(json_encode(array_values($categories))) ?>"><?= t('ui.category_3adb', 'Category') ?></th>
                <th data-sort-key="title" data-type="text"><?= t('ui.title_b78a', 'Title') ?></th>
                <th data-sort-key="date" data-type="date" data-tt-date data-tt-year="<?= $dYear ?: '' ?>" data-tt-month="<?= $dMonth ?: '' ?>" data-tt-month-to="<?= $dMonthTo ?: '' ?>" data-tt-months="<?= e(json_encode((object)$dateMonths)) ?>"><?= t('ui.date_4474', 'Date') ?></th>
                <?php if ($tab === 'active'): ?><th data-sort-key="assigned" data-type="text"><?= t('ui.assigned_to_1668', 'Assigned to') ?></th><?php endif; ?>
                <th data-tt-filter="status" data-tt-all="All Statuses" data-tt-icon="ti-circle-dot" data-tt-value="<?= e($filter) ?>" data-tt-options="<?= e(json_encode($statusOptions)) ?>"><?= t('ui.status_ec53', 'Status') ?></th>
                <?php if ($tab === 'archived'): ?><th data-sort-key="archived" data-type="date"><?= t('ui.archived_7d69', 'Archived') ?></th><?php endif; ?>
                <th></th>
            </tr></thead>
            <tbody>
            <?php if (empty($complaints)): ?>
            <tr class="tt-empty">
                <td colspan="7" style="text-align:center;padding:40px;color:#9CA3AF;">
                    <i class="ti ti-inbox" style="font-size:36px;display:block;margin-bottom:10px;"></i>
                    <?= $emptyMsg ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php foreach ($complaints as $c): ?>
            <tr style="<?= $tab==='archived'?'opacity:0.75;':'' ?>">
                <td>
                    <?= $c['is_anonymous']
                        ? '<em style="color:#9CA3AF;">Anonymous</em>'
                        : '<span style="font-weight:500;">'.e($c['first_name'].' '.$c['last_name']).'</span><br><small style="color:#9CA3AF;">'.e($c['sid']).'</small>'
                    ?>
                </td>
                <td><span style="background:#EDE9FE;color:#7C3AED;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;"><?= e($c['category']) ?></span></td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($c['title']) ?></td>
                <td style="white-space:nowrap;font-size:12px;color:#6B7280;"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                <?php if ($tab === 'active'): ?>
                <td><?= !empty($c['assigned_to_name']) ? e($c['assigned_to_name']) : ($c['staff_fn'] ? e($c['staff_fn'].' '.$c['staff_ln']) : '<span style="color:#9CA3AF;font-size:12px;">Unassigned</span>') ?></td>
                <?php endif; ?>
                <td><?= statusBadge($c['status']) ?></td>
                <?php if ($tab === 'archived'): ?>
                <td style="font-size:11px;color:#9CA3AF;white-space:nowrap;"><?= date('M j, Y', strtotime($c['updated_at'])) ?></td>
                <?php endif; ?>
                <td style="white-space:nowrap;display:flex;gap:6px;">
                    <?php if ($tab === 'archived'): ?>
                    <button class="btn btn-outline btn-sm" style="font-size:12px;"
                        onclick="openUnarchiveModal(<?= $c['id'] ?>)">
                        <i class="ti ti-archive-off"></i> <?= t('ui.unarchive_9fd0', 'Unarchive') ?>
                    </button>
                    <?php endif; ?>
                    <a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-outline btn-sm"><?= t('ui.view_4351', 'View') ?></a>
                </td>
            </tr>
            <?php endforeach; ?></tbody>
        </table></div>

        <!-- ── MOBILE CARDS ── -->
        <div class="complaint-cards">
        <?php if (empty($complaints)): ?>
        <div style="text-align:center;padding:40px;color:#9CA3AF;">
            <i class="ti ti-inbox" style="font-size:36px;display:block;margin-bottom:10px;"></i>
            <?= $emptyMsg ?>
        </div>
        <?php endif; ?>
        <?php foreach ($complaints as $c): ?>
        <div class="complaint-card" style="<?= $tab==='archived'?'opacity:0.8;':'' ?>">
            <div class="cc-top">
                <div class="cc-title"><?= e($c['title']) ?></div>
                <?= statusBadge($c['status']) ?>
            </div>
            <div class="cc-meta">
                <span>
                    <i class="ti ti-user" style="font-size:12px;"></i>
                    <?= $c['is_anonymous'] ? '<em>Anonymous</em>' : e($c['first_name'].' '.$c['last_name']) ?>
                </span>
                <span style="color:#D1D5DB;">•</span>
                <span><i class="ti ti-tag" style="font-size:12px;"></i>
                    <span style="background:#EDE9FE;color:#7C3AED;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:600;"><?= e($c['category']) ?></span>
                </span>
                <span style="color:#D1D5DB;">•</span>
                <span><i class="ti ti-calendar" style="font-size:12px;"></i> <?= date('M j, Y', strtotime($c['created_at'])) ?></span>
                <?php if ($tab === 'active'): ?>
                <span style="color:#D1D5DB;">•</span>
                <span><i class="ti ti-user-check" style="font-size:12px;"></i>
                    <?= !empty($c['assigned_to_name']) ? e($c['assigned_to_name']) : ($c['staff_fn'] ? e($c['staff_fn'].' '.$c['staff_ln']) : '<span style="color:#9CA3AF;">Unassigned</span>') ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="cc-bottom">
                <div style="font-size:11px;color:#9CA3AF;">
                    <?php if ($tab === 'archived'): ?>
                        <?= t('ui.archived_7d69', 'Archived') ?> <?= date('M j, Y', strtotime($c['updated_at'])) ?>
                    <?php else: ?>
                        ID: <?= $c['is_anonymous'] ? '—' : e($c['sid']) ?>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:6px;">
                    <?php if ($tab === 'archived'): ?>
                    <button class="btn btn-outline btn-sm" onclick="openUnarchiveModal(<?= $c['id'] ?>)">
                        <i class="ti ti-archive-off"></i> <?= t('ui.unarchive_9fd0', 'Unarchive') ?>
                    </button>
                    <?php endif; ?>
                    <a href="view-complaint.php?id=<?= $c['id'] ?>" class="btn btn-primary btn-sm">
                        <i class="ti ti-eye"></i> <?= t('ui.view_4351', 'View') ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php gpBar($page, $perPage, $totalRows, function (int $p, ?int $pp = null) {
            return $pp !== null ? buildUrl(['pp' => $pp, 'page' => 1]) : buildUrl(['page' => $p]);
        }, [15, 30, 50], true, 'complaint'); ?>
    </div>
    </div><!-- /#complaints-live -->
</div></div>
<!-- Unarchive Confirm Modal -->
<div id="unarchive-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:20px;">
            <div style="width:44px;height:44px;border-radius:50%;background:#DBEAFE;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="ti ti-archive-off" style="color:#1D4ED8;font-size:22px;"></i>
            </div>
            <div>
                <div style="font-size:15px;font-weight:700;color:#1F2937;margin-bottom:5px;"><?= t('ui.move_back_to_active_3524', 'Move back to Active?') ?></div>
                <div style="font-size:13px;color:#6B7280;line-height:1.6;"><?= t('ui.this_complaint_will_be_restored_to_c715', 'This complaint will be restored to the Active list and can be managed again.') ?></div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button onclick="closeUnarchiveModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <a id="unarchive-confirm-btn" href="#" onclick="postAction({ unarchive: this.dataset.id }); return false;" class="btn btn-sm" style="background:#1D4ED8;color:#fff;border-color:#1D4ED8;">
                <i class="ti ti-archive-off"></i> <?= t('ui.yes_unarchive_553a', 'Yes, Unarchive') ?>
            </a>
        </div>
    </div>
</div>

<script src="../assets/js/table-tools.js?v=6"></script>
<script>
function openUnarchiveModal(id) {
    document.getElementById('unarchive-confirm-btn').dataset.id = id;
    document.getElementById('unarchive-modal').style.display = 'flex';
}
function closeUnarchiveModal() {
    document.getElementById('unarchive-modal').style.display = 'none';
}
document.getElementById('unarchive-modal').addEventListener('click', function(e) {
    if (e.target === this) closeUnarchiveModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeUnarchiveModal();
});

// Clear button follows the filters even when the list updates in place
function syncClear() {
    var live = document.getElementById('complaints-live');
    var link = document.getElementById('complaint-clear-link');
    var input = document.getElementById('complaint-search');
    if (!live || !link) return;
    var on = live.getAttribute('data-filtered') === '1' || (input && input.value.trim() !== '');
    link.style.display = on ? '' : 'none';
}
document.addEventListener('tt:updated', syncClear);

// Live search: the list updates while typing (no Enter/reload needed)
(function() {
    var form  = document.getElementById('complaint-filter-form');
    var input = document.getElementById('complaint-search');
    var live  = document.getElementById('complaints-live');
    if (!form || !input || !live) return;

    var timer = null, seq = 0;
    function run() {
        var params = new URLSearchParams(new FormData(form));
        params.set('page', '1');
        swapTo('complaints.php?' + params.toString());
    }
    function swapTo(url, scrollTop) {
        var mine = ++seq;
        live.style.opacity = '0.55';
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                if (mine !== seq) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById('complaints-live');
                if (fresh) {
                    live.innerHTML = fresh.innerHTML;
                    if (window.TableTools) TableTools.init(live);
                    syncClear();
                    history.replaceState(null, '', url);
                    if (scrollTop) live.scrollIntoView({ block: 'start' });
                }
                live.style.opacity = '';
            })
            .catch(function() { if (mine === seq) live.style.opacity = ''; });
    }
    input.addEventListener('input', function() {
        clearTimeout(timer);
        timer = setTimeout(run, 300);
    });
    // Page links update the list in place (no full reload of the whole page)
    live.addEventListener('click', function(e) {
        var a = e.target.closest ? e.target.closest('.gp-nav a') : null;
        if (!a) return;
        e.preventDefault();
        e.stopPropagation();
        swapTo(a.href, true);
    });
    live.addEventListener('change', function(e) {
        if (e.target.matches && e.target.matches('.gp-size select')) swapTo(e.target.value, true);
    });
})();

// Auto-refresh check every 30 seconds — silently triggers archive + reloads if counts changed
(function autoArchiveCheck() {
    setTimeout(function() {
        fetch('complaints.php?tab=<?= $tab ?>&_arc_check=1', { credentials: 'same-origin' })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                // Parse new archived count from response
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var newArchived = doc.querySelector('[data-archived-count]');
                var curArchived = document.querySelector('[data-archived-count]');
                if (newArchived && curArchived && newArchived.dataset.archivedCount !== curArchived.dataset.archivedCount) {
                    window.location.reload();
                } else {
                    autoArchiveCheck(); // schedule next check
                }
            })
            .catch(function() { autoArchiveCheck(); }); // retry on error
    }, 30000); // every 30 seconds
})();
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
<script>window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;</script>
<script src="../assets/js/post-action.js"></script>
<script src="../assets/js/admin-otp.js?v=2"></script>
</body></html>