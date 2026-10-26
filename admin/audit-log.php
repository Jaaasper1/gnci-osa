<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/lang.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';
require_once '../includes/messages.php';
require_once '../includes/pager.php';

requireStaff();
auditEnsureTable($pdo);
// Keep one year of history; the purge itself is recorded
$purged = auditPurgeOld($pdo, 365);
if ($purged > 0) auditLogSystem($pdo, 'purge', 'audit_log', 0, ['deleted' => $purged]);

$notifCount = getUnreadStaffNotifCount($pdo, (int)$_SESSION['user_id']);
$msgUnread  = msgUnreadStaffCount($pdo);

$entityLabels = [
    'staff'      => 'Staff account',
    'student'    => 'Student',
    'candidate'  => 'Candidate',
    'party_list' => 'Party list',
    'election'   => 'Election',
    'event'      => 'Event',
    'complaint'  => 'Complaint',
    'message'    => 'Message',
    'account'    => 'Account',
    'position'   => 'Position',
    'voucher'    => 'Vouchers',
    'report'     => 'Report',
];
$actionLabels = [
    'update'        => ['Edited',       '#1D4ED8', '#DBEAFE'],
    'delete'        => ['Deleted',      '#991B1B', '#FEE2E2'],
    'disable'       => ['Disabled',     '#92400E', '#FEF3C7'],
    'enable'        => ['Enabled',      '#065F46', '#D1FAE5'],
    'cancel_toggle' => ['Cancel toggled', '#6D28D9', '#EDE9FE'],
    'open'          => ['Opened',     '#065F46', '#D1FAE5'],
    'close'         => ['Closed',     '#991B1B', '#FEE2E2'],
    'hold'          => ['On hold',    '#92400E', '#FEF3C7'],
    'resume'        => ['Resumed',    '#065F46', '#D1FAE5'],
    'complete'      => ['Completed',  '#1D4ED8', '#DBEAFE'],
    'reopen'        => ['Reopened',   '#1D4ED8', '#DBEAFE'],
    'results'       => ['Results visibility', '#6D28D9', '#EDE9FE'],
    'approve'       => ['Approved',   '#065F46', '#D1FAE5'],
    'reject'        => ['Rejected',   '#991B1B', '#FEE2E2'],
    'unarchive'     => ['Unarchived', '#1D4ED8', '#DBEAFE'],
    'send'          => ['Sent',       '#1D4ED8', '#DBEAFE'],
    'reply'         => ['Replied',    '#065F46', '#D1FAE5'],
    'create'        => ['Created',    '#065F46', '#D1FAE5'],
    'import'        => ['Imported',   '#1D4ED8', '#DBEAFE'],
    'claim'         => ['Claimed',    '#6D28D9', '#EDE9FE'],
    'unclaim'       => ['Unclaimed',  '#92400E', '#FEF3C7'],
    'login'         => ['Logged in',  '#065F46', '#D1FAE5'],
    'logout'        => ['Logged out', '#374151', '#F3F4F6'],
    'password_change' => ['Password changed', '#92400E', '#FEF3C7'],
    'export'        => ['Exported',   '#1D4ED8', '#DBEAFE'],
    'view'          => ['Viewed',     '#6B7280', '#F3F4F6'],
    'generate'      => ['Voucher generated', '#065F46', '#D1FAE5'],
    'generate_all' => ['Vouchers generated', '#065F46', '#D1FAE5'],
    'revoke'        => ['Voucher revoked', '#991B1B', '#FEE2E2'],
    'post_facebook' => ['Posted to Facebook', '#1877F2', '#E7F0FD'],
    'auto_archive'  => ['Auto-archived', '#6B7280', '#F3F4F6'],
    'auto_delete'   => ['Auto-deleted',  '#991B1B', '#FEE2E2'],
    'purge'         => ['Purged old entries', '#6B7280', '#F3F4F6'],
];
$fieldLabels = [
    'username'      => 'Username',
    'first_name'    => 'First name',
    'last_name'     => 'Last name',
    'student_id'    => 'Student ID',
    'course'        => 'Course',
    'email'         => 'Email',
    'bio'           => 'Bio',
    'credentials'   => 'Credentials',
    'party_list_id' => 'Party list',
    'name'          => 'Name',
    'platform'      => 'Platform',
    'color'         => 'Color',
    'title'         => 'Title',
    'description'   => 'Description',
    'start_date'    => 'Start date',
    'end_date'      => 'End date',
    'event_date'    => 'Event date',
    'location'      => 'Location',
    'is_active'     => 'Status',
    'subject'       => 'Subject',
    'added'         => 'Added',
    'updated'       => 'Updated',
    'assigned_to'   => 'Assigned to',
    'role'          => 'Role',
    'username'      => 'Username',
    'by'            => 'Sent by',
];

if (!function_exists('auditFieldValue')) {
    function auditFieldValue(string $key, $value): string {
        if ($key === 'is_active') return ((string)$value === '1') ? 'Active' : 'Inactive';
        if ($key === 'email') return trim((string)$value) === '' ? '—' : maskEmail($value);
        $v = trim((string)$value);
        return $v === '' ? '—' : $v;
    }
}

$auditMonthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
    7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

$filter = trim($_GET['entity'] ?? '');
if (!array_key_exists($filter, $entityLabels)) $filter = '';

// ── Date filter (dyear / dmonth / dmonth_to), same as the Students page ──
$dYear    = (int)($_GET['dyear'] ?? 0);
$dMonth   = (int)($_GET['dmonth'] ?? 0);
$dMonthTo = (int)($_GET['dmonth_to'] ?? 0);
if ($dYear < 2000 || $dYear > 2100) $dYear = 0;
if (!$dYear || $dMonth < 1 || $dMonth > 12) { $dMonth = 0; }
if (!$dMonth || $dMonthTo < 1 || $dMonthTo > 12 || $dMonthTo < $dMonth) { $dMonthTo = 0; }

$where = []; $params = [];
if ($filter !== '') { $where[] = 'entity = ?'; $params[] = $filter; }
if ($dYear) {
    $m1 = $dMonth ?: 1;
    $m2 = $dMonthTo ?: ($dMonth ?: 12);
    $rangeStart = new DateTime(sprintf('%04d-%02d-01', $dYear, $m1));
    $rangeEnd   = (new DateTime(sprintf('%04d-%02d-01', $dYear, $m2)))->modify('+1 month');
    $where[] = 'created_at >= ? AND created_at < ?';
    $params[] = $rangeStart->format('Y-m-d');
    $params[] = $rangeEnd->format('Y-m-d');
}
// ── Pagination (server side) ──
$whereSql = $where ? " WHERE " . implode(' AND ', $where) : '';
$perPage = pagerSize([25, 50, 100], 25);
$page    = max(1, (int)($_GET['p'] ?? 1));

$cst = $pdo->prepare("SELECT COUNT(*) FROM audit_log" . $whereSql);
$cst->execute($params);
$total = (int)$cst->fetchColumn();

$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT * FROM audit_log" . $whereSql
     . " ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// Action choices for the Action column filter (only actions that exist in the log)
$actionOptions = [];
foreach ($pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN) as $a) {
    $actionOptions[] = [$a, $actionLabels[$a][0] ?? ucfirst(str_replace('_', ' ', $a))];
}

// Years and months that have entries (feeds the date menu)
$dateMonths = [];
foreach ($pdo->query("SELECT DISTINCT YEAR(created_at) y, MONTH(created_at) m FROM audit_log ORDER BY y, m")->fetchAll() as $dm) {
    $dateMonths[(int)$dm['y']][] = (int)$dm['m'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.audit_log', 'Audit Log') ?> — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <style>
        .al-pill { display:inline-block; font-size:11px; font-weight:600; padding:3px 10px; border-radius:999px; }
        .al-change { margin-bottom:6px; line-height:1.5; }
        .al-change:last-child { margin-bottom:0; }
        .al-change-label { color:#6B7280; font-weight:600; margin-right:6px; }
        .al-old { color:#991B1B; text-decoration:line-through; word-break:break-word; }
        .al-new { color:#065F46; font-weight:600; word-break:break-word; }
        .al-arrow { color:#9CA3AF; margin:0 6px; }
    </style>
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub"><?= t('ui.administrator_7b7b', 'Administrator') ?></div></div></div>
    <div class="nav-right">
        <div class="avatar" style="background:#7C3AED;color:#fff;">AD</div><span class="uname"><?= e($_SESSION['name']) ?></span>
    </div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.overview', 'Overview') ?></div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section"><?= t('sidebar.complaints', 'Complaints') ?></div>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?></a>
    <a class="sitem" href="../osa/messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?><?php if ($msgUnread > 0): ?><span class="badge-count"><?= $msgUnread ?></span><?php endif; ?></a>
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
    <a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a>
    <a class="sitem active" href="audit-log.php"><i class="ti ti-history"></i> <?= t('sidebar.audit_log', 'Audit Log') ?></a>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> <?= t('sidebar.reports', 'Reports') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?><?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?></a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>

<div class="main"><div class="content">
    <div class="page-header">
        <div>
            <h1><?= t('ui.audit_log_5b24', 'Audit Log') ?></h1>
            <p><?= t('ui.changes_made_in_the_portal_newest__ce49', 'Changes made in the portal, newest first') ?></p>
        </div>
    </div>

    <div class="card al-filter" style="margin-bottom:16px;">
        <form id="audit-filter-form" method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <?php /* keep the month range (and rows-per-page) when the Type dropdown submits */ ?>
            <?php if ($dYear): ?><input type="hidden" name="dyear" value="<?= (int)$dYear ?>"><?php endif; ?>
            <?php if ($dMonth): ?><input type="hidden" name="dmonth" value="<?= (int)$dMonth ?>"><?php endif; ?>
            <?php if ($dMonthTo): ?><input type="hidden" name="dmonth_to" value="<?= (int)$dMonthTo ?>"><?php endif; ?>
            <?php if (isset($_GET['pp'])): ?><input type="hidden" name="pp" value="<?= (int)$perPage ?>"><?php endif; ?>
            <i class="ti ti-filter" style="color:#6B7280;"></i>
            <label style="font-size:13px;color:#374151;font-weight:600;"><?= t('ui.filter_by_2198', 'Filter by:') ?></label>

            <select name="entity" class="form-control" onchange="this.form.submit()" aria-label="<?= t('ui.type_a1fa', 'Type') ?>">
                <option value="" <?= $filter === '' ? 'selected' : '' ?>><?= t('ui.all_changes_512d', 'All changes') ?></option>
                <?php foreach ($entityLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="card">
        <div id="audit-live">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;font-size:12px;color:#6B7280;">
            <?php if ($filter !== '' || $dYear): ?>
                <a href="audit-log.php" style="color:#6B7280;text-decoration:underline;"><?= t('ui.clear_dc30', 'Clear') ?></a>
            <?php endif; ?>
            <span style="margin-left:auto;"><?= number_format($total) ?> <?= t('ui.change_s_084e', 'change(s)') ?></span>
        </div>
        <div class="table-wrap"><table data-tt data-tt-form="audit-filter-form" data-tt-live="audit-live">
            <thead><tr><th data-type="date" data-tt-date data-tt-year="<?= $dYear ?: '' ?>" data-tt-month="<?= $dMonth ?: '' ?>" data-tt-month-to="<?= $dMonthTo ?: '' ?>" data-tt-months="<?= e(json_encode((object)$dateMonths)) ?>"><?= t('ui.date_4474', 'Date') ?></th><th data-type="text">Username</th><th data-tt-cfilter="data-action" data-tt-all="All actions" data-tt-icon="ti-bolt" data-tt-options="<?= e(json_encode($actionOptions, JSON_UNESCAPED_UNICODE)) ?>"><?= t('ui.action_004b', 'Action') ?></th><th data-type="text"><?= t('auto.record_6a0d', 'Record') ?></th><th data-nosort><?= t('ui.changes_c112', 'Changes') ?></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5" style="text-align:center;color:#9CA3AF;padding:24px;"><?= t('ui.no_changes_recorded_yet_e54e', 'No changes recorded yet.') ?></td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $r):
                $d      = json_decode($r['details'] ?? '', true) ?: [];
                $before = $d['before'] ?? [];
                $after  = $d['after'] ?? [];

                $act  = $actionLabels[$r['action']] ?? [ucfirst($r['action']), '#374151', '#F3F4F6'];
                $ent  = $entityLabels[$r['entity']] ?? ucfirst($r['entity']);
                $when = date('M j, Y g:i A', strtotime($r['created_at']));

                $recName = '';
                $src = $before ?: $after;
                if (!empty($src['first_name']) || !empty($src['last_name'])) $recName = trim(($src['last_name'] ?? '') . ', ' . ($src['first_name'] ?? ''), ', ');
                elseif (!empty($src['title'])) $recName = $src['title'];
                elseif (!empty($src['subject'])) $recName = $src['subject'];
                elseif (!empty($src['name'])) $recName = $src['name'];
                elseif (!empty($src['username'])) $recName = $src['username'];
                $targetText = $recName !== '' ? $recName : ($ent . ' #' . (int)$r['entity_id']);

                $changes = [];
                foreach ($after as $k => $newVal) {
                    $oldVal = $before[$k] ?? null;
                    if ($before && (string)$oldVal === (string)$newVal) continue;
                    $changes[] = [
                        'label' => $fieldLabels[$k] ?? ucfirst(str_replace('_', ' ', $k)),
                        'old'   => $before ? auditFieldValue($k, $oldVal) : null,
                        'new'   => auditFieldValue($k, $newVal),
                    ];
                }
            ?>
                <tr data-action="<?= e($r['action']) ?>">
                    <td style="white-space:nowrap;font-size:12px;" data-sort="<?= strtotime($r['created_at']) ?>"><?= e($when) ?></td>
                    <td style="font-size:13px;" data-sort="<?= e(strtolower($r['actor_name'] ?: 'unknown')) ?>"><?= e($r['actor_name'] ?: 'Unknown') ?></td>
                    <td><span class="al-pill" style="color:<?= $act[1] ?>;background:<?= $act[2] ?>;"><?= e($act[0]) ?></span></td>
                    <td style="font-size:13px;"><?= e($targetText) ?><div style="font-size:11px;color:#9CA3AF;"><?= e($ent) ?></div></td>
                    <td style="font-size:12px;min-width:260px;">
                        <?php if ($changes): ?>
                            <?php foreach ($changes as $c): ?>
                                <div class="al-change">
                                    <span class="al-change-label"><?= e($c['label']) ?>:</span>
                                    <?php if ($c['old'] !== null): ?>
                                        <span class="al-old"><?= e($c['old']) ?></span><span class="al-arrow">→</span><span class="al-new"><?= e($c['new']) ?></span>
                                    <?php else: ?>
                                        <span class="al-new"><?= e($c['new']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($r['action'] === 'delete'): ?>
                            <span style="color:#9CA3AF;"><?= t('ui.record_deleted_a67e', 'Record deleted') ?></span>
                        <?php else: ?>
                            <span style="color:#9CA3AF;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php pagerRender($page, $perPage, $total, [25, 50, 100]); ?>
        </div><!-- /#audit-live -->
    </div>
</div></div>

<script src="../assets/js/table-tools.js?v=8"></script>
<script>
// Search + Action filter together. table-tools calls table._ttApply() whenever a filter changes.
// The list (#audit-live) is swapped in place when the date range changes, so the search bar sits
// outside it and is re-bound to the fresh table after every update.
document.addEventListener('DOMContentLoaded', function () {
    var live = document.getElementById('audit-live');
    if (!live) return;
    var bar = document.createElement('div');
    bar.className = 'tt-search';
    bar.innerHTML =
        '<div class="tt-search-box"><i class="ti ti-search"></i>' +
        '<input type="text" autocomplete="off" placeholder="Search username, action, record, or change...">' +
        '<button type="button" class="tt-clear" aria-label="Clear search"><i class="ti ti-x"></i></button></div>' +
        '<span class="tt-count"></span>';
    live.parentNode.insertBefore(bar, live);
    var input = bar.querySelector('input');
    var clear = bar.querySelector('.tt-clear');
    var count = bar.querySelector('.tt-count');

    function bind() {
        var table = live.querySelector('table[data-tt]');
        if (!table) return;
        table._ttApply = function () {
            var terms = input.value.toLowerCase().split(/\s+/).filter(Boolean);
            var f = table._ttFilters || {};
            var hasFilter = Object.keys(f).length > 0;
            var rows = Array.prototype.slice.call(table.tBodies[0].rows).filter(function (r) { return !r.classList.contains('tt-empty'); });
            var shown = 0;
            rows.forEach(function (r) {
                var hay = (r.textContent || '').toLowerCase();
                var okSearch = terms.every(function (t) { return hay.indexOf(t) !== -1; });
                var okFilter = Object.keys(f).every(function (k) { return (r.getAttribute(k) || '') === f[k]; });
                var ok = okSearch && okFilter;
                r.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            clear.style.display = input.value ? 'block' : 'none';
            count.textContent = (terms.length || hasFilter) ? (__t('Showing') + ' ' + shown + ' ' + __t('of') + ' ' + rows.length) : '';
        };
        table._ttApply();
    }
    function apply() { var t = live.querySelector('table[data-tt]'); if (t && t._ttApply) t._ttApply(); }

    input.addEventListener('input', apply);
    clear.addEventListener('click', function () { input.value = ''; input.focus(); apply(); });
    live.addEventListener('tt:updated', bind);
    bind();
});
</script>
<script src="../assets/js/alerts.js"></script>
<script src="../assets/js/sidebar.js?v=5"></script>
</body></html>
