<?php
require_once 'includes/db.php';
require_once 'includes/lang.php';
require_once 'includes/functions.php';
require_once __DIR__ . '/admin/election-picker.php';
// Intentionally NO requireStudent()/requireAdmin() — this page is public,
// meant to be viewed from the login page before anyone signs in.
// Only elections the admin has published (show_results = 1) are ever shown here.

$eid = (int)($_GET['id'] ?? 0);

$elections = $pdo->query("SELECT * FROM elections WHERE show_results = 1 ORDER BY course, created_at DESC")->fetchAll();

$election = null;
foreach ($elections as $e) { if ((int)$e['id'] === $eid) { $election = $e; break; } }
if (!$election && !empty($elections)) {
    $eid = defaultElectionId($elections);
    foreach ($elections as $e) { if ((int)$e['id'] === $eid) { $election = $e; break; } }
    if (!$election) { $election = $elections[0]; $eid = $election['id']; }
}

$positions = [];
$totalVoters = 0;
$totalStudents = 0;
$overallAbstainPct = 0;

if ($election) {
    $eid = $election['id'];
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

        $abstainStmt = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE election_id = ? AND position_id = ? AND is_abstain = 1");
        $abstainStmt->execute([$eid, $p['id']]);
        $p['abstains'] = (int)$abstainStmt->fetchColumn();

        $p['candidate_votes'] = array_sum(array_column($p['candidates'], 'votes'));
        $p['total'] = $p['candidate_votes'] + $p['abstains'];
        $positions[] = $p;
    }

    $tv = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM votes WHERE election_id = ?");
    $tv->execute([$eid]);
    $totalVoters = (int)$tv->fetchColumn();

    $tsStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active = 1 AND course = ?");
    $tsStmt->execute([$election['course']]);
    $totalStudents = (int)$tsStmt->fetchColumn();

    $totalAbstainPct = 0; $positionsWithVotes = 0;
    foreach ($positions as $p) {
        if ($p['total'] > 0) { $totalAbstainPct += round($p['abstains'] / $p['total'] * 100); $positionsWithVotes++; }
    }
    $overallAbstainPct = $positionsWithVotes > 0 ? round($totalAbstainPct / $positionsWithVotes) : 0;
}

$turnout = $totalStudents > 0 ? round($totalVoters / $totalStudents * 100) : 0;

// Group elections by status for the Active / Closed / Completed tabs
$statusTabs = [
    'active'    => ['Active',    'ti-player-play',  []],
    'closed'    => ['Closed',    'ti-lock',         []],
    'completed' => ['Completed', 'ti-circle-check', []],
];
foreach ($elections as $e) { $statusTabs[electionStatusKey($e)][2][] = $e; }
$curStatus = $election ? electionStatusKey($election) : 'active';
$isLive    = ($curStatus === 'active');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Election Results — GNC-OSA Portal</title>
    <link rel="stylesheet" href="assets/css/style.css?v=25">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
</head>
<body class="theme-election">
<nav class="topnav">
    <div class="brand"><img src="assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub">Live Election Results</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?>
        <a href="index.php" class="btn btn-sm" data-loading-text="Back to login..." style="background:rgba(255,255,255,0.15);color:#fff;"><i class="ti ti-arrow-left"></i> Back to Login</a>
    </div>
</nav>

<div style="margin-left:0;padding-top:var(--topnav-height);min-height:100vh;background:var(--gray-100);">
<div class="content" style="max-width:1080px;margin:0 auto;padding:24px 20px;">
    <div class="page-header">
        <h1><i class="ti ti-chart-pie"></i> Live Election Results</h1>
        <p>Real-time results, per department — no login needed to view</p>
    </div>

    <?php if (empty($elections)): ?>
        <div class="card" style="text-align:center;padding:40px 20px;color:#9CA3AF;">
            <i class="ti ti-chart-pie-off" style="font-size:32px;display:block;margin-bottom:10px;"></i>
            No live results are published yet. Please check back later.
        </div>
    <?php else: ?>

    <!-- Status tabs: Active / Closed / Completed -->
    <?php renderElectionTabs($elections, (int)$eid, 'id'); ?>

    <?php if ($election): ?>
    <div class="page-header" style="margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <p style="font-size:14px;font-weight:600;color:var(--gray-800);"><?= e($election['course']) ?> — <?= e($election['title']) ?></p>
        <?php if ($isLive): ?>
        <span id="live-indicator" style="display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;color:#059669;background:#D1FAE5;padding:4px 10px;border-radius:20px;">
            <span style="width:7px;height:7px;border-radius:50%;background:#059669;display:inline-block;animation:liveBlink 1.5s infinite;"></span> Live
        </span>
        <?php elseif ($curStatus === 'completed'): ?>
        <span style="display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;color:#1D4ED8;background:#DBEAFE;padding:4px 10px;border-radius:20px;"><i class="ti ti-circle-check"></i> Final Results</span>
        <?php else: ?>
        <span style="display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;color:#6B7280;background:#F3F4F6;padding:4px 10px;border-radius:20px;"><i class="ti ti-lock"></i> Voting Closed</span>
        <?php endif; ?>
    </div>

    <div class="stat-grid" id="live-stats" data-election-id="<?= $eid ?>" data-live="<?= $isLive ? 1 : 0 ?>" style="margin-bottom:16px;">
        <div class="stat-card"><div class="stat-num" id="stat-voted"><?= $totalVoters ?></div><div class="stat-label">Voted</div></div>
        <div class="stat-card"><div class="stat-num" id="stat-not-voted"><?= max(0, $totalStudents - $totalVoters) ?></div><div class="stat-label">Not yet voted</div></div>
        <div class="stat-card"><div class="stat-num" id="stat-turnout"><?= $turnout ?>%</div><div class="stat-label">Voter Turnout</div></div>
        <div class="stat-card abstain-stat"><div class="stat-num" id="stat-abstain"><?= $overallAbstainPct ?>%</div><div class="stat-label">Overall Abstain Rate</div></div>
    </div>

    <div class="res-acc-tools"><button type="button" class="btn btn-sm btn-outline" onclick="document.querySelectorAll('.res-acc').forEach(function(d){d.open=true})"><i class="ti ti-chevrons-down"></i> Expand all</button> <button type="button" class="btn btn-sm btn-outline" onclick="document.querySelectorAll('.res-acc').forEach(function(d){d.open=false})"><i class="ti ti-chevrons-up"></i> Collapse all</button></div>
    <div class="live-positions-grid">
    <?php foreach ($positions as $pos): ?>
    <details class="card res-acc" style="margin-bottom:0;">
        <summary class="res-acc-head">
            <span class="res-acc-title"><i class="ti ti-crown"></i> <?= e($pos['title']) ?></span>
            <span class="res-acc-meta"><?= count($pos['candidates']) ?> candidate<?= count($pos['candidates']) != 1 ? 's' : '' ?></span>
            <i class="ti ti-chevron-down res-acc-chev"></i>
        </summary>
        <div class="res-acc-body">
        <?php if (empty($pos['candidates'])): ?>
            <div class="alert alert-info" style="margin:0;"><i class="ti ti-info-circle"></i> No candidates for this position.</div>
        <?php else: ?>
        <?php foreach ($pos['candidates'] as $i => $cand): ?>
        <?php $pct = $pos['total'] > 0 ? round($cand['votes'] / $pos['total'] * 100) : 0; ?>
        <div class="live-cand-row" data-candidate-id="<?= $cand['id'] ?>" style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
            <div style="position:relative;flex-shrink:0;">
                <?php if ($cand['photo']): ?>
                    <img src="uploads/<?= e($cand['photo']) ?>" alt=""
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid <?= ($i === 0 && $cand['votes'] > 0) ? '#C9943A' : '#E5E7EB' ?>;">
                <?php else: ?>
                    <div style="width:44px;height:44px;border-radius:50%;background:#F9EEF0;border:2px solid <?= ($i === 0 && $cand['votes'] > 0) ? '#C9943A' : '#E5E7EB' ?>;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#6B1A2A;">
                        <?= strtoupper(substr($cand['first_name'],0,1).substr($cand['last_name'],0,1)) ?>
                    </div>
                <?php endif; ?>
                <?php if ($i === 0 && $cand['votes'] > 0): ?>
                    <span class="live-medal" style="position:absolute;bottom:-4px;right:-4px;font-size:14px;line-height:1;">🥇</span>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:600;margin-bottom:4px;">
                    <span><?= e($cand['first_name'] . ' ' . $cand['last_name']) ?>
                        <?php if (!empty($cand['party_name'])): ?>
                        <span style="font-size:11px;font-weight:600;color:<?= e($cand['party_color'] ?: '#6B1A2A') ?>;">· <?= e($cand['party_name']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="live-cand-count" style="color:var(--maroon);white-space:nowrap;"><?= $cand['votes'] ?> vote<?= $cand['votes'] != 1 ? 's' : '' ?> (<?= $pct ?>%)</span>
                </div>
                <div class="progress"><div class="progress-fill live-cand-bar" style="width:<?= $pct ?>%;"></div></div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($pos['total'] > 0): ?>
        <?php $abstainPct = round($pos['abstains'] / $pos['total'] * 100); ?>
        <div class="abstain-results-row" data-position-id="<?= $pos['id'] ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                <span style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#92400E;"><i class="ti ti-ban"></i> Abstain</span>
                <span class="live-abstain-count" style="font-size:13px;font-weight:600;color:#92400E;"><?= $pos['abstains'] ?> (<?= $abstainPct ?>%)</span>
            </div>
            <div class="progress"><div class="progress-fill abstain-fill live-abstain-bar" style="width:<?= $abstainPct ?>%;"></div></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<script>
(function() {
    var statGrid = document.getElementById('live-stats');
    if (!statGrid) return; // no election selected, nothing to poll

    var electionId = statGrid.dataset.electionId;

    // Animates a number from `from` to `to` over `duration` ms, formatting
    // each frame with `formatFn` (ease-out, so it settles smoothly).
    function animateNumber(el, from, to, duration, formatFn) {
        var start = null;
        function step(ts) {
            if (!start) start = ts;
            var progress = Math.min((ts - start) / duration, 1);
            var eased    = 1 - Math.pow(1 - progress, 3);
            var current  = Math.round(from + (to - from) * eased);
            el.textContent = formatFn(current);
            if (progress < 1) requestAnimationFrame(step);
            else el.textContent = formatFn(to);
        }
        requestAnimationFrame(step);
    }

    function animateStatNum(id, target, suffix) {
        suffix = suffix || '';
        var el = document.getElementById(id);
        if (!el) return;
        var current = parseInt(el.textContent, 10);
        if (isNaN(current)) current = 0;
        if (current === target) { el.textContent = target + suffix; return; }
        animateNumber(el, current, target, 500, function (v) { return v + suffix; });
    }

    function pulseLiveDot() {
        var dot = document.getElementById('live-indicator');
        if (!dot) return;
        dot.style.opacity = '0.4';
        setTimeout(function() { dot.style.opacity = '1'; }, 200);
    }

    // Entrance animation on first paint: bars grow from 0% to their real
    // width (transition is already defined on .progress-fill), and vote /
    // abstain counts count up from 0 instead of appearing instantly.
    function playEntrance() {
        document.querySelectorAll('.live-cand-bar, .live-abstain-bar').forEach(function (bar) {
            var target = bar.style.width;
            bar.style.width = '0%';
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { bar.style.width = target; });
            });
        });

        document.querySelectorAll('.live-cand-count').forEach(function (el) {
            var m = el.textContent.match(/(\d+)\s+vote/);
            if (!m) return;
            var votes = parseInt(m[1], 10);
            var pctMatch = el.textContent.match(/\((\d+)%\)/);
            var pct = pctMatch ? pctMatch[1] : '0';
            animateNumber(el, 0, votes, 800, function (v) {
                return v + ' vote' + (v != 1 ? 's' : '') + ' (' + pct + '%)';
            });
        });

        document.querySelectorAll('.live-abstain-count').forEach(function (el) {
            var m = el.textContent.match(/(\d+)\s*\((\d+)%\)/);
            if (!m) return;
            var count = parseInt(m[1], 10), pct = m[2];
            animateNumber(el, 0, count, 800, function (v) {
                return v + ' (' + pct + '%)';
            });
        });
    }
    playEntrance();

    function refreshResults() {
        fetch('live-results-data.php?id=' + encodeURIComponent(electionId))
            .then(function(res) { return res.ok ? res.json() : null; })
            .then(function(data) {
                if (!data) return;

                animateStatNum('stat-voted', data.voted);
                animateStatNum('stat-not-voted', data.not_voted);
                animateStatNum('stat-turnout', data.turnout, '%');
                animateStatNum('stat-abstain', data.abstain_rate, '%');

                data.positions.forEach(function(pos) {
                    pos.candidates.forEach(function(cand) {
                        var row = document.querySelector('.live-cand-row[data-candidate-id="' + cand.id + '"]');
                        if (!row) return;
                        var bar   = row.querySelector('.live-cand-bar');
                        var count = row.querySelector('.live-cand-count');
                        if (bar) bar.style.width = cand.pct + '%';
                        if (count) {
                            var m = count.textContent.match(/(\d+)\s+vote/);
                            var fromVotes = m ? parseInt(m[1], 10) : cand.votes;
                            if (fromVotes !== cand.votes) {
                                animateNumber(count, fromVotes, cand.votes, 500, function (v) {
                                    return v + ' vote' + (v != 1 ? 's' : '') + ' (' + cand.pct + '%)';
                                });
                            } else {
                                count.textContent = cand.votes + ' vote' + (cand.votes != 1 ? 's' : '') + ' (' + cand.pct + '%)';
                            }
                        }
                    });

                    var abstainRow = document.querySelector('.abstain-results-row[data-position-id="' + pos.id + '"]');
                    if (abstainRow) {
                        var abar = abstainRow.querySelector('.live-abstain-bar');
                        var acnt = abstainRow.querySelector('.live-abstain-count');
                        if (abar) abar.style.width = pos.abstain_pct + '%';
                        if (acnt) {
                            var am = acnt.textContent.match(/(\d+)\s*\(/);
                            var fromAbst = am ? parseInt(am[1], 10) : pos.abstains;
                            if (fromAbst !== pos.abstains) {
                                animateNumber(acnt, fromAbst, pos.abstains, 500, function (v) {
                                    return v + ' (' + pos.abstain_pct + '%)';
                                });
                            } else {
                                acnt.textContent = pos.abstains + ' (' + pos.abstain_pct + '%)';
                            }
                        }
                    }
                });

                pulseLiveDot();
            })
            .catch(function() { /* silently skip this tick if the network hiccups */ });
    }

    // Only keep polling while voting is still open; closed/completed results are static.
    if (statGrid.dataset.live === '1') setInterval(refreshResults, 5000);
})();
</script>

<script src="assets/js/sidebar.js?v=5"></script>
</body>
</html>
