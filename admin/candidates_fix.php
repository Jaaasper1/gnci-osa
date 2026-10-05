<?php
// Delete candidate redirect (add this at the top of candidates.php before any output)
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM candidates WHERE id=?")->execute([(int)$_GET['delete']]);
    header('Location: candidates.php?election=' . (int)($_GET['election'] ?? 0));
    exit;
}
