<?php
require_once 'includes/db.php';
// Public, read-only endpoint — same visibility rule as live-results.php:
// only returns data for elections the admin has published (show_results = 1).

header('Content-Type: application/json');

$eid = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM elections WHERE id = ? AND show_results = 1");
$stmt->execute([$eid]);
$election = $stmt->fetch();

if (!$election) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

$positions = [];
$pos = $pdo->prepare("SELECT * FROM positions WHERE election_id = ? ORDER BY sort_order");
$pos->execute([$eid]);

foreach ($pos->fetchAll() as $p) {
    $cands = $pdo->prepare("
        SELECT c.id, COUNT(v.id) as votes
        FROM candidates c
        LEFT JOIN votes v ON v.candidate_id = c.id AND v.election_id = ?
        WHERE c.position_id = ? AND c.is_approved = 1
        GROUP BY c.id
    ");
    $cands->execute([$eid, $p['id']]);
    $candRows = $cands->fetchAll();

    $abstainStmt = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE election_id = ? AND position_id = ? AND is_abstain = 1");
    $abstainStmt->execute([$eid, $p['id']]);
    $abstains = (int)$abstainStmt->fetchColumn();

    $candidateVotes = array_sum(array_column($candRows, 'votes'));
    $total = $candidateVotes + $abstains;

    $candidates = [];
    foreach ($candRows as $c) {
        $candidates[] = [
            'id'    => (int)$c['id'],
            'votes' => (int)$c['votes'],
            'pct'   => $total > 0 ? round($c['votes'] / $total * 100) : 0,
        ];
    }

    $positions[] = [
        'id'          => (int)$p['id'],
        'abstains'    => $abstains,
        'abstain_pct' => $total > 0 ? round($abstains / $total * 100) : 0,
        'candidates'  => $candidates,
    ];
}

$tv = $pdo->prepare("SELECT COUNT(DISTINCT student_id) FROM votes WHERE election_id = ?");
$tv->execute([$eid]);
$totalVoters = (int)$tv->fetchColumn();

$tsStmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active = 1 AND course = ?");
$tsStmt->execute([$election['course']]);
$totalStudents = (int)$tsStmt->fetchColumn();

$turnout = $totalStudents > 0 ? round($totalVoters / $totalStudents * 100) : 0;

$totalAbstainPct = 0; $positionsWithVotes = 0;
foreach ($positions as $p) {
    if (($p['abstains'] + array_sum(array_column($p['candidates'], 'votes'))) > 0) {
        $totalAbstainPct += $p['abstain_pct'];
        $positionsWithVotes++;
    }
}
$overallAbstainPct = $positionsWithVotes > 0 ? round($totalAbstainPct / $positionsWithVotes) : 0;

echo json_encode([
    'voted'          => $totalVoters,
    'not_voted'      => max(0, $totalStudents - $totalVoters),
    'turnout'        => $turnout,
    'abstain_rate'   => $overallAbstainPct,
    'positions'      => $positions,
]);
