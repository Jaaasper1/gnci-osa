<?php
// ONE-TIME tool: fixes capitalization of existing students' first/last names
// (e.g. "JUAN DELA CRUZ" -> "Juan Dela Cruz"). Shows a preview first.
// DELETE THIS FILE AFTER USE.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/functions.php';
if (($_SESSION['user_type'] ?? '') !== 'admin') { http_response_code(403); die('Admin only.'); }

function e2($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$rows = $pdo->query("SELECT id, student_id, first_name, last_name FROM students ORDER BY last_name, first_name")->fetchAll();
$changes = [];
foreach ($rows as $r) {
    $nf = formatPersonName($r['first_name']);
    $nl = formatPersonName($r['last_name']);
    if ($nf !== $r['first_name'] || $nl !== $r['last_name']) {
        $changes[(int)$r['id']] = ['sid' => $r['student_id'], 'of' => $r['first_name'], 'ol' => $r['last_name'], 'nf' => $nf, 'nl' => $nl];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $picked = array_map('intval', (array)($_POST['apply'] ?? []));
    $n = 0;
    $up = $pdo->prepare("UPDATE students SET first_name=?, last_name=? WHERE id=?");
    foreach ($picked as $id) {
        if (!isset($changes[$id])) continue;           // only apply what was previewed
        $c = $changes[$id];
        $up->execute([$c['nf'], $c['nl'], $id]);
        try { auditLog($pdo, 'edit', 'student', $id, ['first_name' => $c['of'], 'last_name' => $c['ol']], ['first_name' => $c['nf'], 'last_name' => $c['nl']]); } catch (Throwable $t) {}
        $n++;
    }
    die('Done. Fixed ' . $n . ' name(s). Now DELETE admin/fix-names.php from the server.');
}
?><!DOCTYPE html><meta charset="utf-8"><title>Fix names</title>
<body style="font-family:sans-serif;max-width:760px;margin:30px auto;padding:0 12px">
<h2>Fix student names</h2>
<?php if (!$changes): ?>
  <p>All names are already formatted correctly. Nothing to change. You can delete this file.</p>
<?php else: ?>
  <p><b><?= count($changes) ?></b> name(s) will be changed. Uncheck any you want to leave as is (e.g. names like McDonald).</p>
  <form method="POST"><?= csrfField() ?>
  <table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px">
    <tr style="background:#eee"><th></th><th>Student ID</th><th>Current</th><th>New</th></tr>
    <?php foreach ($changes as $id => $c): ?>
    <tr>
      <td><input type="checkbox" name="apply[]" value="<?= $id ?>" checked></td>
      <td><?= e2($c['sid']) ?></td>
      <td><?= e2($c['ol'] . ', ' . $c['of']) ?></td>
      <td><b><?= e2($c['nl'] . ', ' . $c['nf']) ?></b></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p><button style="padding:10px 18px">Apply checked changes</button></p>
  </form>
<?php endif; ?>
</body>
