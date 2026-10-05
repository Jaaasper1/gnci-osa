<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireAdmin();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$totalComplaints = (int)$pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
$totalReviews     = (int)$pdo->query("SELECT COUNT(*) FROM complaint_reviews")->fetchColumn();
$newComplaints    = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status='New' AND is_archived=0")->fetchColumn();

// Complaints by category — Today / This Week / This Month / This Year / All-Time
$categoryBreakdown = $pdo->query(
    "SELECT category,
            SUM(DATE(created_at) = CURDATE()) AS today,
            SUM(YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)) AS this_week,
            SUM(MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) AS this_month,
            SUM(YEAR(created_at) = YEAR(CURDATE())) AS this_year,
            COUNT(*) AS all_time
     FROM complaints GROUP BY category ORDER BY all_time DESC, category ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Reviews by reaction — Today / This Week / This Month / This Year / All-Time
$reactionLabels = ['😊'=>'Satisfied','👍'=>'Good','😐'=>'Neutral','👎'=>'Not Satisfied','😢'=>'Disappointed'];
$reactionRaw = $pdo->query(
    "SELECT reaction,
            SUM(DATE(created_at) = CURDATE()) AS today,
            SUM(YEARWEEK(created_at,1) = YEARWEEK(CURDATE(),1)) AS this_week,
            SUM(MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) AS this_month,
            SUM(YEAR(created_at) = YEAR(CURDATE())) AS this_year,
            COUNT(*) AS all_time
     FROM complaint_reviews GROUP BY reaction"
)->fetchAll(PDO::FETCH_ASSOC);
$reactionBreakdown = [];
foreach ($reactionLabels as $emoji => $label) {
    $reactionBreakdown[$emoji] = ['today'=>0,'this_week'=>0,'this_month'=>0,'this_year'=>0,'all_time'=>0];
}
foreach ($reactionRaw as $row) {
    if (isset($reactionBreakdown[$row['reaction']])) {
        $reactionBreakdown[$row['reaction']] = [
            'today'=>(int)$row['today'],'this_week'=>(int)$row['this_week'],
            'this_month'=>(int)$row['this_month'],'this_year'=>(int)$row['this_year'],'all_time'=>(int)$row['all_time'],
        ];
    }
}

// ── Month-over-Month Comparison ──────────────────────────────────
function pctChange($current, $previous) {
    if ($previous == 0) return $current > 0 ? null : 0; // null = "new" (no baseline to compare to)
    return round((($current - $previous) / $previous) * 100, 1);
}

$complaintsThisMonth = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
$complaintsLastMonth = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH)")->fetchColumn();

$reviewsThisMonthCnt = (int)$pdo->query("SELECT COUNT(*) FROM complaint_reviews WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
$reviewsLastMonthCnt = (int)$pdo->query("SELECT COUNT(*) FROM complaint_reviews WHERE MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH)")->fetchColumn();

$posThisMonth  = (int)$pdo->query("SELECT COUNT(*) FROM complaint_reviews WHERE reaction IN ('😊','👍') AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
$posLastMonth  = (int)$pdo->query("SELECT COUNT(*) FROM complaint_reviews WHERE reaction IN ('😊','👍') AND MONTH(created_at)=MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(created_at)=YEAR(CURDATE() - INTERVAL 1 MONTH)")->fetchColumn();
$satThisMonth  = $reviewsThisMonthCnt > 0 ? round($posThisMonth / $reviewsThisMonthCnt * 100, 1) : 0;
$satLastMonth  = $reviewsLastMonthCnt > 0 ? round($posLastMonth / $reviewsLastMonthCnt * 100, 1) : 0;

$comparisonRows = [
    ['label'=>'Complaints Filed', 'this'=>$complaintsThisMonth, 'last'=>$complaintsLastMonth, 'change'=>pctChange($complaintsThisMonth, $complaintsLastMonth)],
    ['label'=>'Reviews Submitted', 'this'=>$reviewsThisMonthCnt, 'last'=>$reviewsLastMonthCnt, 'change'=>pctChange($reviewsThisMonthCnt, $reviewsLastMonthCnt)],
    ['label'=>'Satisfaction Rate', 'this'=>$satThisMonth.'%', 'last'=>$satLastMonth.'%', 'change'=>pctChange($satThisMonth, $satLastMonth)],
];

// ── Custom Date Range ────────────────────────────────────────────
$rangeStart = $_GET['start_date'] ?? '';
$rangeEnd   = $_GET['end_date'] ?? '';
$customCategoryBreakdown = null;
$customReactionBreakdown = null;
$validRange = false;

if ($rangeStart && $rangeEnd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rangeEnd) && $rangeStart <= $rangeEnd) {
    $validRange = true;
    $stmt = $pdo->prepare("SELECT category, COUNT(*) AS total FROM complaints WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY category ORDER BY total DESC, category ASC");
    $stmt->execute([$rangeStart, $rangeEnd]);
    $customCategoryBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare("SELECT reaction, COUNT(*) AS total FROM complaint_reviews WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY reaction");
    $stmt2->execute([$rangeStart, $rangeEnd]);
    $customReactionRaw = $stmt2->fetchAll(PDO::FETCH_KEY_PAIR);
    $customReactionBreakdown = [];
    foreach ($reactionLabels as $emoji => $label) {
        $customReactionBreakdown[$emoji] = ['label'=>$label, 'total'=>(int)($customReactionRaw[$emoji] ?? 0)];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=23">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <style>
        .card{margin-bottom:20px;}
        .report-range-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .report-range-grid > div{min-width:0;}
        @media (max-width:768px){
            .report-range-grid{grid-template-columns:1fr;}
        }
    </style>
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
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
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
    <a class="sitem active" href="reports.php"><i class="ti ti-report-analytics"></i> Reports</a>
    <div class="sidebar-section">Account</div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> Notifications
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('sidebar.reports', 'Reports') ?></h1><p><?= t('admin.reports.subtitle', 'Live breakdowns for complaints and student reviews — export any table to Excel') ?></p></div>

    <div class="card">
        <div class="card-title" style="justify-content:space-between;">
            <span><i class="ti ti-message-report"></i> <?= t('sidebar.complaints', 'Complaints') ?> by Category</span>
            <?= exportMenu('export-reports.php?type=complaints_analytics', 'btn btn-primary btn-sm') ?>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Category</th><th>Today</th><th>This Week</th><th>This Month</th><th>This Year</th><th>All-Time</th></tr></thead>
            <tbody>
            <?php foreach ($categoryBreakdown as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['category']) ?></td>
                <td><?= (int)$c['today'] ?></td>
                <td><?= (int)$c['this_week'] ?></td>
                <td><?= (int)$c['this_month'] ?></td>
                <td><?= (int)$c['this_year'] ?></td>
                <td><strong><?= (int)$c['all_time'] ?></strong></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$categoryBreakdown): ?><tr><td colspan="6" style="text-align:center;color:#9CA3AF;">No complaints yet.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <div class="card-title" style="justify-content:space-between;">
            <span><i class="ti ti-mood-smile"></i> Reviews by Reaction</span>
            <?= exportMenu('export-reports.php?type=reviews_analytics', 'btn btn-primary btn-sm') ?>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Reaction</th><th>Today</th><th>This Week</th><th>This Month</th><th>This Year</th><th>All-Time</th></tr></thead>
            <tbody>
            <?php foreach ($reactionLabels as $emoji => $label): $b = $reactionBreakdown[$emoji]; ?>
            <tr>
                <td style="font-size:18px;"><?= $emoji ?> <span style="font-size:13px;color:#6B7280;"><?= htmlspecialchars($label) ?></span></td>
                <td><?= $b['today'] ?></td>
                <td><?= $b['this_week'] ?></td>
                <td><?= $b['this_month'] ?></td>
                <td><?= $b['this_year'] ?></td>
                <td><strong><?= $b['all_time'] ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <div class="card-title" style="justify-content:space-between;">
            <span><i class="ti ti-chart-infographic"></i> Combined Overview</span>
            <?= exportMenu('export-reports.php?type=combined_overview', 'btn btn-primary btn-sm') ?>
        </div>
        <p style="font-size:13px;color:#6B7280;">One-page snapshot combining complaints and review satisfaction — great for a quick summary to present to OSA leadership.</p>
    </div>

    <div class="card">
        <div class="card-title"><i class="ti ti-arrows-diff"></i> This Month vs Last Month</div>
        <div class="table-wrap"><table>
            <thead><tr><th>Metric</th><th>This Month</th><th>Last Month</th><th>Change</th></tr></thead>
            <tbody>
            <?php foreach ($comparisonRows as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['label']) ?></td>
                <td><strong><?= htmlspecialchars((string)$c['this']) ?></strong></td>
                <td><?= htmlspecialchars((string)$c['last']) ?></td>
                <td>
                    <?php if ($c['change'] === null): ?>
                        <span style="color:#6B7280;">— new —</span>
                    <?php elseif ($c['change'] > 0): ?>
                        <span style="color:#059669;font-weight:600;"><i class="ti ti-arrow-up-right"></i> +<?= $c['change'] ?>%</span>
                    <?php elseif ($c['change'] < 0): ?>
                        <span style="color:#DC2626;font-weight:600;"><i class="ti ti-arrow-down-right"></i> <?= $c['change'] ?>%</span>
                    <?php else: ?>
                        <span style="color:#6B7280;">No change</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>

    <div class="card">
        <div class="card-title"><i class="ti ti-calendar-stats"></i> Custom Date Range</div>
        <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:12px;color:#6B7280;margin-bottom:4px;">Start Date</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($rangeStart) ?>" class="form-control" required>
            </div>
            <div>
                <label style="display:block;font-size:12px;color:#6B7280;margin-bottom:4px;">End Date</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($rangeEnd) ?>" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-filter"></i> Show</button>
            <?php if ($validRange): ?>
                <?= exportMenu('export-reports.php?type=custom_range&start_date='.urlencode($rangeStart).'&end_date='.urlencode($rangeEnd), 'btn btn-outline btn-sm', '', 'Export This Range') ?>
            <?php endif; ?>
        </form>

        <?php if ($rangeStart && $rangeEnd && !$validRange): ?>
            <p style="color:#DC2626;font-size:13px;">Invalid date range — make sure the start date is before or equal to the end date.</p>
        <?php elseif ($validRange): ?>
            <p style="font-size:12.5px;color:#6B7280;margin-bottom:10px;">Showing <?= date('M j, Y', strtotime($rangeStart)) ?> – <?= date('M j, Y', strtotime($rangeEnd)) ?></p>
            <div class="report-range-grid">
                <div>
                    <strong style="font-size:13px;">Complaints by Category</strong>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Category</th><th>Count</th></tr></thead>
                        <tbody>
                        <?php foreach ($customCategoryBreakdown as $c): ?>
                        <tr><td><?= htmlspecialchars($c['category']) ?></td><td><?= (int)$c['total'] ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$customCategoryBreakdown): ?><tr><td colspan="2" style="text-align:center;color:#9CA3AF;">No complaints in this range.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>
                <div>
                    <strong style="font-size:13px;">Reviews by Reaction</strong>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Reaction</th><th>Count</th></tr></thead>
                        <tbody>
                        <?php foreach ($customReactionBreakdown as $emoji => $r): ?>
                        <tr><td><?= $emoji ?> <?= htmlspecialchars($r['label']) ?></td><td><?= $r['total'] ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>
        <?php endif; ?>
    </div>



</div></div>
<script src="../assets/js/sidebar.js?v=4"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body></html>
