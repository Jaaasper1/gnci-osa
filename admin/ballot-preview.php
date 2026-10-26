<?php
// Read-only preview of an election's ballot, as students will see it. Nothing here records a vote.
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStaff();

$eid = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM elections WHERE id = ?");
$st->execute([$eid]);
$election = $st->fetch();
if (!$election) { http_response_code(404); exit('Election not found.'); }

$positions = $pdo->prepare("SELECT * FROM positions WHERE election_id = ? ORDER BY sort_order");
$positions->execute([$eid]);
$positions = $positions->fetchAll();

$cands = $pdo->prepare("SELECT c.*, s.first_name, s.last_name, s.course, pl.name AS party_name, pl.color AS party_color
                        FROM candidates c
                        JOIN students s ON c.student_id = s.id
                        LEFT JOIN party_lists pl ON c.party_list_id = pl.id
                        WHERE c.election_id = ? AND c.is_approved = 1 AND c.position_id = ?");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ballot preview — <?= htmlspecialchars($election['title']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <style>
        .bp-wrap { max-width: 900px; margin: 0 auto; padding: 24px 16px 60px; }
        .bp-banner { background:#FEF3C7; border:1px solid #FCD34D; color:#78350F; border-radius:12px; padding:12px 16px; font-size:13px; margin-bottom:18px; }
        .bp-pos { background:#fff; border:1px solid #E5E7EB; border-radius:14px; padding:18px; margin-bottom:16px; }
        .bp-pos-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6B1A2A; margin-bottom:12px; }
        .bp-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; }
        .bp-cand { border:1px solid #E5E7EB; border-radius:12px; padding:14px; text-align:center; }
        .bp-photo { width:64px; height:64px; border-radius:50%; background:#F3F4F6; margin:0 auto 8px; display:flex; align-items:center; justify-content:center; font-weight:700; color:#6B7280; overflow:hidden; }
        .bp-photo img { width:100%; height:100%; object-fit:cover; }
        .bp-name { font-weight:600; font-size:14px; color:#1F2937; }
        .bp-party { font-size:11px; font-weight:700; margin-top:3px; }
        .bp-course { font-size:11px; color:#6B7280; margin-top:2px; }
        .bp-abstain { margin-top:10px; border:1px dashed #D1D5DB; border-radius:10px; padding:10px; text-align:center; color:#6B7280; font-size:13px; }
        .bp-empty { font-size:13px; color:#B42318; }
        .bp-back { margin-bottom:12px; }
    </style>
</head>
<body>
<nav class="topnav">
    <div class="brand"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub">Ballot preview</div></div></div>
</nav>
<div class="bp-wrap">
    <div class="bp-back"><a href="elections.php" class="btn btn-outline btn-sm"><i class="ti ti-arrow-left"></i> Back to elections</a></div>
    <div class="bp-banner"><i class="ti ti-eye"></i> Preview only. This is how the ballot looks to students. No votes are recorded here.</div>
    <h1 style="font-size:20px;margin:0 0 4px;color:#1F2937;"><?= htmlspecialchars($election['title']) ?></h1>
    <div style="font-size:13px;color:#6B7280;margin-bottom:18px;"><?= htmlspecialchars($election['course'] ?? '') ?></div>

    <?php foreach ($positions as $pos):
        $cands->execute([$eid, $pos['id']]);
        $list = $cands->fetchAll();
    ?>
    <div class="bp-pos">
        <div class="bp-pos-title"><?= htmlspecialchars($pos['title']) ?></div>
        <?php if (!$list): ?>
            <div class="bp-empty">No approved candidates for this position yet.</div>
        <?php else: ?>
        <div class="bp-grid">
            <?php foreach ($list as $c): ?>
            <div class="bp-cand">
                <div class="bp-photo">
                    <?php if (!empty($c['photo'])): ?>
                        <img src="../uploads/<?= htmlspecialchars($c['photo']) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars(strtoupper(substr($c['first_name'], 0, 1) . substr($c['last_name'], 0, 1))) ?>
                    <?php endif; ?>
                </div>
                <div class="bp-name"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></div>
                <?php if (!empty($c['party_name'])): ?>
                    <div class="bp-party" style="color:<?= htmlspecialchars($c['party_color'] ?: '#6B1A2A') ?>;"><i class="ti ti-flag"></i> <?= htmlspecialchars($c['party_name']) ?></div>
                <?php else: ?>
                    <div class="bp-party" style="color:#9CA3AF;">Independent</div>
                <?php endif; ?>
                <div class="bp-course"><?= htmlspecialchars($c['course'] ?? '') ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="bp-abstain"><i class="ti ti-ban"></i> Abstain for this position</div>
    </div>
    <?php endforeach; ?>

    <?php if (!$positions): ?>
        <div class="bp-empty">This election has no positions yet.</div>
    <?php endif; ?>
</div>
</body>
</html>
