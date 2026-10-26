<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStudent();

$student_id = $_SESSION['user_id'];
$eid = (int)($_GET['id'] ?? 0);

// Get student's course
$sc_stmt = $pdo->prepare("SELECT course FROM students WHERE id = ?");
$sc_stmt->execute([$student_id]);
$student_course = $sc_stmt->fetchColumn();

// Load the election — must match student course AND show_results enabled
$stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ? AND course = ? AND show_results = 1");
$stmt->execute([$eid, $student_course]);
$election = $stmt->fetch();

// If not found or results not yet enabled, redirect back
if (!$election) {
    header('Location: events.php'); exit;
}

// Get positions and vote counts
$positions = [];
$pos = $pdo->prepare("SELECT * FROM positions WHERE election_id = ? ORDER BY sort_order");
$pos->execute([$eid]);
foreach ($pos->fetchAll() as $p) {
    $cands = $pdo->prepare("
        SELECT c.id, s.first_name, s.last_name, s.course, c.photo,
               pl.name as party_name, pl.color as party_color,
               COUNT(v.id) as votes
        FROM candidates c
        JOIN students s ON c.student_id = s.id
        LEFT JOIN party_lists pl ON c.party_list_id = pl.id
        LEFT JOIN votes v ON v.candidate_id = c.id AND v.election_id = ?
        WHERE c.position_id = ? AND c.is_approved = 1
        GROUP BY c.id
        ORDER BY votes DESC
    ");
    $cands->execute([$eid, $p['id']]);
    $p['candidates'] = $cands->fetchAll();

    // Count abstains for this position
    $abstainStmt = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE election_id = ? AND position_id = ? AND is_abstain = 1");
    $abstainStmt->execute([$eid, $p['id']]);
    $p['abstains'] = (int)$abstainStmt->fetchColumn();

    // total = actual candidate votes + abstains
    $p['candidate_votes'] = array_sum(array_column($p['candidates'], 'votes'));
    $p['total'] = $p['candidate_votes'] + $p['abstains'];

    $positions[] = $p;
}

$totalVoters   = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM votes WHERE election_id = ?");
$totalVoters->execute([$eid]);
$totalVoters   = $totalVoters->fetchColumn();
$totalStudents = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active = 1 AND course = ?");
$totalStudents->execute([$election['course']]);
$totalStudents  = $totalStudents->fetchColumn();
$turnout       = $totalStudents > 0 ? round($totalVoters / $totalStudents * 100) : 0;

// Overall abstain % — average across all positions that have voters
$totalAbstainPct = 0;
$positionsWithVotes = 0;
foreach ($positions as $p) {
    if ($p['total'] > 0) {
        $totalAbstainPct += round($p['abstains'] / $p['total'] * 100);
        $positionsWithVotes++;
    }
}
$overallAbstainPct = $positionsWithVotes > 0 ? round($totalAbstainPct / $positionsWithVotes) : 0;

$unread = getUnreadNotifCount($pdo, $student_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.election_results', 'Election Results') ?> — GNC-OSA</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body class="theme-election">
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub"><?= t('nav.student_portal', 'Student Portal') ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?>
        <div class="avatar"><?= strtoupper(substr($_SESSION['name'], 0, 2)) ?></div>
        <span class="uname"><?= e($_SESSION['name']) ?></span>
    </div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a><a class="sitem" href="help.php"><i class="ti ti-book"></i> <?= t('sidebar.osa_guide', 'OSA Guide') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <?php if ($election): ?>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem active" href="voting.php"><i class="ti ti-checkbox"></i> <?= t('sidebar.vote_now', 'Vote Now') ?></a>
    <?php endif; ?><div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?>
        <?php if ($unread > 0): ?><span class="badge-count"><?= $unread ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <div>
            <h1><?= t('results.title', 'Election Results') ?></h1>
            <p><?= e($election['title']) ?></p>
        </div>
        <span id="live-indicator" style="display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;color:#059669;background:#D1FAE5;padding:4px 10px;border-radius:20px;">
            <span style="width:7px;height:7px;border-radius:50%;background:#059669;display:inline-block;"></span> <?= tx('Live') ?>
        </span>
    </div>

    <div class="stat-grid" id="live-stats" data-election-id="<?= $eid ?>" style="margin-bottom:20px;">
        <div class="stat-card"><div class="stat-num" id="stat-voted"><?= $totalVoters ?></div><div class="stat-label"><?= t('ui.voted_ca78', 'Voted') ?></div></div>
        <div class="stat-card"><div class="stat-num" id="stat-not-voted"><?= $totalStudents - $totalVoters ?></div><div class="stat-label"><?= t('ui.not_yet_voted_c6aa', 'Not yet voted') ?></div></div>
        <div class="stat-card"><div class="stat-num" id="stat-turnout"><?= $turnout ?>%</div><div class="stat-label"><?= t('ui.voter_turnout_1376', 'Voter Turnout') ?></div></div>
        <div class="stat-card abstain-stat"><div class="stat-num" id="stat-abstain"><?= $overallAbstainPct ?>%</div><div class="stat-label"><?= t('ui.overall_abstain_rate_fd17', 'Overall Abstain Rate') ?></div></div>
    </div>

    <div class="res-acc-tools"><button type="button" class="btn btn-sm btn-outline" id="res-acc-toggle" data-state="expand"><i class="ti ti-chevrons-down"></i> <span><?= t('ui.expand_all_d0aa', 'Expand all') ?></span></button></div>
<script>
(function(){
    var btn = document.getElementById('res-acc-toggle');
    if (!btn) return;
    function items(){ return document.querySelectorAll('.res-acc'); }
    function allOpen(){ var l = items(); if (!l.length) return false; for (var i=0;i<l.length;i++) if (!l[i].open) return false; return true; }
    function sync(){
        var open = allOpen();
        btn.dataset.state = open ? 'collapse' : 'expand';
        btn.querySelector('span').textContent = open ? 'Collapse all' : __t('Expand all');
        btn.querySelector('i').className = 'ti ' + (open ? 'ti-chevrons-up' : 'ti-chevrons-down');
    }
    btn.addEventListener('click', function(){
        var open = allOpen();
        items().forEach(function(d){ d.open = !open; });
        sync();
    });
    document.addEventListener('toggle', function(e){ if (e.target.classList && e.target.classList.contains('res-acc')) sync(); }, true);
    sync();
})();
</script>
    <div class="live-positions-grid">
    <?php foreach ($positions as $pos): ?>
    <details class="card res-acc" style="margin-bottom:0;">
        <summary class="res-acc-head">
            <span class="res-acc-title"><i class="ti ti-crown"></i> <?= e($pos['title']) ?></span>
            <span class="res-acc-meta"><?= count($pos['candidates']) ?> <?= t('count.candidate', 'candidate' . (count($pos['candidates']) != 1 ? 's' : '')) ?></span>
            <i class="ti ti-chevron-down res-acc-chev"></i>
        </summary>
        <div class="res-acc-body">
        <?php if (empty($pos['candidates'])): ?>
            <div class="alert alert-info" style="margin:0;"><i class="ti ti-info-circle"></i> <?= t('ui.no_candidates_for_this_position_641e', 'No candidates for this position.') ?></div>
        <?php else: ?>
        <?php foreach ($pos['candidates'] as $i => $cand): ?>
        <?php $pct = $pos['total'] > 0 ? round($cand['votes'] / $pos['total'] * 100) : 0; ?>
        <div class="live-cand-row" data-candidate-id="<?= $cand['id'] ?>" style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
            <div style="position:relative;flex-shrink:0;">
                <?php if ($cand['photo']): ?>
                    <img src="../uploads/<?= e($cand['photo']) ?>" alt=""
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid <?= ($i === 0 && $cand['votes'] > 0) ? '#C9943A' : '#E5E7EB' ?>;">
                <?php else: ?>
                    <div style="width:44px;height:44px;border-radius:50%;background:#F9EEF0;border:2px solid <?= ($i === 0 && $cand['votes'] > 0) ? '#C9943A' : '#E5E7EB' ?>;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#6B1A2A;">
                        <?= strtoupper(substr($cand['first_name'],0,1).substr($cand['last_name'],0,1)) ?>
                    </div>
                <?php endif; ?>
                <?php if ($i === 0 && $cand['votes'] > 0): ?>
                    <span style="position:absolute;bottom:-4px;right:-4px;font-size:14px;line-height:1;">🥇</span>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:600;margin-bottom:4px;">
                    <span><?= e($cand['first_name'] . ' ' . $cand['last_name']) ?> <span style="color:#9CA3AF;font-weight:400;font-size:11px;"><?= e($cand['course']) ?></span>
                        <?php if (!empty($cand['party_name'])): ?>
                        <span style="font-size:11px;font-weight:600;color:<?= e($cand['party_color'] ?: '#6B1A2A') ?>;">· <?= e($cand['party_name']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="live-cand-count" style="color:var(--maroon);white-space:nowrap;"><?= $cand['votes'] ?> <?= t('count.vote', 'vote' . ($cand['votes'] != 1 ? 's' : '')) ?> (<?= $pct ?>%)</span>
                </div>
                <div class="progress"><div class="progress-fill live-cand-bar" style="width:<?= $pct ?>%;"></div></div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($pos['total'] > 0): ?>
        <?php $abstainPct = round($pos['abstains'] / $pos['total'] * 100); ?>
        <div class="abstain-results-row" data-position-id="<?= $pos['id'] ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                <span style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#92400E;">
                    <i class="ti ti-ban"></i> <?= tx('Abstain') ?>
                </span>
                <span class="live-abstain-count" style="font-size:13px;font-weight:600;color:#92400E;"><?= $pos['abstains'] ?> (<?= $abstainPct ?>%)</span>
            </div>
            <div class="progress"><div class="progress-fill abstain-fill live-abstain-bar" style="width:<?= $abstainPct ?>%;"></div></div>
        </div>
        <?php endif; ?>

        <div style="font-size:11px;color:#9CA3AF;margin-top:8px;">
            <?= t('ui.total_votes_cast_for_this_position_b9f5', 'Total votes cast for this position:') ?> <?= $pos['candidate_votes'] ?> &nbsp;·&nbsp; <?= tx('Abstained:') ?> <?= $pos['abstains'] ?>
        </div>
        <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
    </div>

    <div style="margin-top:8px;">
        <a href="events.php" class="btn btn-outline"><i class="ti ti-home"></i> <?= t('ui.back_to_events_f1d4', 'Back to Events') ?></a>
    </div>
</div></div>

<script>
(function() {
    var statGrid = document.getElementById('live-stats');
    if (!statGrid) return;

    var electionId = statGrid.dataset.electionId;

    function pulseLiveDot() {
        var dot = document.getElementById('live-indicator');
        if (!dot) return;
        dot.style.opacity = '0.4';
        setTimeout(function() { dot.style.opacity = '1'; }, 200);
    }

    function refreshResults() {
        fetch('../live-results-data.php?id=' + encodeURIComponent(electionId))
            .then(function(res) { return res.ok ? res.json() : null; })
            .then(function(data) {
                if (!data) return;

                document.getElementById('stat-voted').textContent = data.voted;
                document.getElementById('stat-not-voted').textContent = data.not_voted;
                document.getElementById('stat-turnout').textContent = data.turnout + '%';
                document.getElementById('stat-abstain').textContent = data.abstain_rate + '%';

                data.positions.forEach(function(pos) {
                    pos.candidates.forEach(function(cand) {
                        var row = document.querySelector('.live-cand-row[data-candidate-id="' + cand.id + '"]');
                        if (!row) return;
                        var bar   = row.querySelector('.live-cand-bar');
                        var count = row.querySelector('.live-cand-count');
                        if (bar)   bar.style.width = cand.pct + '%';
                        if (count) count.textContent = cand.votes + ' vote' + (cand.votes != 1 ? 's' : '') + ' (' + cand.pct + '%)';
                    });

                    var abstainRow = document.querySelector('.abstain-results-row[data-position-id="' + pos.id + '"]');
                    if (abstainRow) {
                        var abar = abstainRow.querySelector('.live-abstain-bar');
                        var acnt = abstainRow.querySelector('.live-abstain-count');
                        if (abar) abar.style.width = pos.abstain_pct + '%';
                        if (acnt) acnt.textContent = pos.abstains + ' (' + pos.abstain_pct + '%)';
                    }
                });

                pulseLiveDot();
            })
            .catch(function() { /* skip this tick if the network hiccups */ });
    }

    setInterval(refreshResults, 5000);
})();
</script>

<script src="../assets/js/sidebar.js?v=5"></script>
<?php include __DIR__.'/../includes/ask-osa-widget.php'; ?>
</body>
</html>
