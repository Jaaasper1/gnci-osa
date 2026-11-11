<?php
// ONE-TIME cleanup: removes COR files of students who are already approved,
// and orphan cor_* files that no student record uses. DELETE THIS FILE AFTER USE.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/functions.php';
if (($_SESSION['user_type'] ?? '') !== 'admin') { http_response_code(403); die('Admin only.'); }

$dir = realpath(__DIR__ . '/../uploads');
$active  = $pdo->query("SELECT id, cor_file FROM students WHERE is_active=1 AND cor_file IS NOT NULL AND cor_file<>''")->fetchAll();
$pending = $pdo->query("SELECT cor_file FROM students WHERE is_active=0 AND cor_file IS NOT NULL AND cor_file<>''")->fetchAll(PDO::FETCH_COLUMN);
$keep = array_flip(array_map('basename', $pending));
$orphans = [];
foreach (glob($dir . '/cor_*') ?: [] as $f) {
    $b = basename($f);
    // skip pending-student files and anything newer than 24h (may be mid-registration)
    if (isset($keep[$b]) || filemtime($f) > time() - 86400) continue;
    $inActive = false;
    foreach ($active as $a) if (basename($a['cor_file']) === $b) { $inActive = true; break; }
    if (!$inActive) $orphans[] = $b;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $n = 0; $bytes = 0;
    foreach ($active as $a) {
        $p = $dir . '/' . basename($a['cor_file']);
        if (is_file($p)) $bytes += filesize($p);
        if (deleteCorFileByName($a['cor_file'])) $n++;
        $pdo->prepare("UPDATE students SET cor_file=NULL WHERE id=?")->execute([$a['id']]);
    }
    foreach ($orphans as $b) {
        $p = $dir . '/' . $b;
        if (is_file($p)) $bytes += filesize($p);
        if (deleteCorFileByName($b)) $n++;
    }
    die('Done. Deleted ' . $n . ' file(s), freed ' . round($bytes / 1048576, 2) . ' MB. Now DELETE admin/cleanup-cor.php from the server.');
}
?><!DOCTYPE html><meta charset="utf-8"><title>COR cleanup</title>
<body style="font-family:sans-serif;max-width:520px;margin:40px auto">
<h2>COR cleanup</h2>
<p>Approved students with a COR file: <b><?= count($active) ?></b><br>
Orphan COR files (no student record): <b><?= count($orphans) ?></b></p>
<p>Pending students' CORs are kept.</p>
<form method="POST"><?= csrfField() ?><button style="padding:10px 18px">Delete these files</button></form>
