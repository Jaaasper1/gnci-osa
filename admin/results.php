<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireAdmin();
require_once __DIR__ . '/election-picker.php';
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$elections = $pdo->query("SELECT * FROM elections ORDER BY course, created_at DESC")->fetchAll();
$eid = (int)($_GET['id'] ?? defaultElectionId($elections));
$election = null;
foreach ($elections as $e) { if ($e['id'] === $eid) { $election = $e; break; } }

$positions = [];
if ($election) {
    $pos = $pdo->prepare("SELECT * FROM positions WHERE election_id=? ORDER BY sort_order");
    $pos->execute([$eid]);
    foreach ($pos->fetchAll() as $p) {
        $cands = $pdo->prepare("SELECT c.id, c.photo, s.first_name, s.last_name, pl.name as party_name, pl.color as party_color, COUNT(v.id) as votes FROM candidates c JOIN students s ON c.student_id=s.id LEFT JOIN party_lists pl ON c.party_list_id=pl.id LEFT JOIN votes v ON v.candidate_id=c.id AND v.election_id=? WHERE c.position_id=? GROUP BY c.id ORDER BY votes DESC");
        $cands->execute([$eid, $p['id']]);
        $p['candidates'] = $cands->fetchAll();

        // Count abstains for this position
        $abstainStmt = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE election_id = ? AND position_id = ? AND is_abstain = 1");
        $abstainStmt->execute([$eid, $p['id']]);
        $p['abstains'] = (int)$abstainStmt->fetchColumn();

        $p['candidate_votes'] = array_sum(array_column($p['candidates'], 'votes'));
        $p['total'] = $p['candidate_votes'] + $p['abstains'];
        $positions[] = $p;
    }
}

$totalVoters = 0;
if ($eid) {
    $tvStmt = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM votes WHERE election_id=?");
    $tvStmt->execute([$eid]);
    $totalVoters = (int)$tvStmt->fetchColumn();
}
// Eligible voters: active students of the election's course (all active students if the election has no course)
$totalStudents = 0;
if ($election) {
    if (!empty($election['course'])) {
        $tsStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active = 1 AND course = ?");
        $tsStmt->execute([$election['course']]);
    } else {
        $tsStmt = $pdo->query("SELECT COUNT(*) FROM students WHERE is_active = 1");
    }
    $totalStudents = (int)$tsStmt->fetchColumn();
}

// Overall abstain % across all positions
$totalAbstainPct = 0;
$positionsWithVotes = 0;
foreach ($positions as $p) {
    if ($p['total'] > 0) {
        $totalAbstainPct += round($p['abstains'] / $p['total'] * 100);
        $positionsWithVotes++;
    }
}
$overallAbstainPct = $positionsWithVotes > 0 ? round($totalAbstainPct / $positionsWithVotes) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=23">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body class="theme-election">
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub">Administrator</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar" style="background:#7C3AED;color:#fff;">AD</div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section">Overview</div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section">Complaints</div>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> All Complaints</a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> Elections</a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> Candidates</a>
    <a class="sitem" href="party-lists.php"><i class="ti ti-flag"></i> Party Lists</a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem active" href="results.php"><i class="ti ti-chart-pie"></i> Live Results</a>
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
    <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
        <div>
            <h1><?= t('results.title', 'Election Results') ?></h1>
            <p><?= $election ? (!empty($election['course']) ? e($election['course']).' — '.e($election['title']) : e($election['title'])) : 'Select an election' ?></p>
        </div>
        <?php if ($election && !empty($positions)): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?= exportMenu('export.php?type=results&election='.$eid, 'btn btn-sm', 'background:#059669;color:#fff;white-space:nowrap;', 'Export Results') ?>
            <?php if (empty($election['is_active'])): ?>
            <?= exportMenu('export.php?type=winners&election='.$eid, 'btn btn-sm btn-outline', 'border-color:#059669;color:#059669;white-space:nowrap;', 'Export Winners', ['xlsx','pdf','docx']) ?>
            <?php endif; ?>
            <?= exportMenu('export.php?type=voters&election='.$eid, 'btn btn-sm btn-outline', 'white-space:nowrap;', 'Export Voters List') ?>
        </div>
        <?php endif; ?>
    </div>

    <?php renderElectionTabs($elections, $eid, 'id'); ?>

    <?php if ($election): ?>
    <div class="stat-grid" style="margin-bottom:16px;">
        <div class="stat-card"><div class="stat-num"><?= $totalVoters ?></div><div class="stat-label">Voted</div></div>
        <div class="stat-card"><div class="stat-num"><?= max(0, $totalStudents - $totalVoters) ?></div><div class="stat-label">Not yet voted</div></div>
        <div class="stat-card"><div class="stat-num"><?= $totalStudents > 0 ? round($totalVoters/$totalStudents*100) : 0 ?>%</div><div class="stat-label">Voter Turnout</div></div>
        <div class="stat-card abstain-stat"><div class="stat-num"><?= $overallAbstainPct ?>%</div><div class="stat-label">Overall Abstain Rate</div></div>
    </div>

    <div class="res-acc-tools"><button type="button" class="btn btn-sm btn-outline" onclick="document.querySelectorAll('.res-acc').forEach(function(d){d.open=true})"><i class="ti ti-chevrons-down"></i> Expand all</button> <button type="button" class="btn btn-sm btn-outline" onclick="document.querySelectorAll('.res-acc').forEach(function(d){d.open=false})"><i class="ti ti-chevrons-up"></i> Collapse all</button></div>
    <?php foreach ($positions as $pos): ?>
    <details class="card res-acc" style="margin-bottom:10px;">
        <summary class="res-acc-head">
            <span class="res-acc-title"><i class="ti ti-crown"></i> <?= e($pos['title']) ?></span>
            <span class="res-acc-meta"><?= count($pos['candidates']) ?> candidate<?= count($pos['candidates']) != 1 ? 's' : '' ?></span>
            <?php if ($pos['total'] > 0): ?><span class="abstain-badge"><i class="ti ti-ban"></i> <?= round($pos['abstains'] / $pos['total'] * 100) ?>% Abstain</span><?php endif; ?>
            <i class="ti ti-chevron-down res-acc-chev"></i>
        </summary>
        <div class="res-acc-body">
        <?php foreach ($pos['candidates'] as $cand): ?>
        <?php $pct = $pos['total'] > 0 ? round($cand['votes']/$pos['total']*100) : 0; ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="position:relative;flex-shrink:0;">
                <?php if ($cand['photo']): ?>
                    <img src="../uploads/<?= e($cand['photo']) ?>" alt=""
                         style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #E5E7EB;">
                <?php else: ?>
                    <div style="width:40px;height:40px;border-radius:50%;background:#F9EEF0;border:2px solid #E5E7EB;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#6B1A2A;">
                        <?= strtoupper(substr($cand['first_name'],0,1).substr($cand['last_name'],0,1)) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:500;margin-bottom:4px;">
                    <span>
                        <?= e($cand['first_name'].' '.$cand['last_name']) ?>
                        <?php if (!empty($cand['party_name'])): ?>
                        <span style="font-size:11px;font-weight:600;color:<?= e($cand['party_color'] ?: '#6B1A2A') ?>;margin-left:4px;">· <?= e($cand['party_name']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span style="color:var(--maroon);"><?= $cand['votes'] ?> votes (<?= $pct ?>%)</span>
                </div>
                <div class="progress"><div class="progress-fill" style="width:<?= $pct ?>%;"></div></div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($pos['total'] > 0): ?>
        <?php $abstainPct = round($pos['abstains'] / $pos['total'] * 100); ?>
        <div class="abstain-results-row">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                <span style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#92400E;">
                    <i class="ti ti-ban"></i> Abstain
                </span>
                <span style="font-size:13px;font-weight:600;color:#92400E;"><?= $pos['abstains'] ?> (<?= $abstainPct ?>%)</span>
            </div>
            <div class="progress"><div class="progress-fill abstain-fill" style="width:<?= $abstainPct ?>%;"></div></div>
        </div>
        <?php endif; ?>

        <div style="font-size:11px;color:#9CA3AF;margin-top:8px;">
            Total votes for this position: <?= $pos['candidate_votes'] ?> &nbsp;·&nbsp; Abstained: <?= $pos['abstains'] ?>
        </div>
        </div>
    </details>
    <?php endforeach; ?>
    <?php else: ?><div class="alert alert-info"><i class="ti ti-info-circle"></i> No election selected.</div><?php endif; ?>
</div></div>
<script src="../assets/js/sidebar.js?v=4"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body></html>
