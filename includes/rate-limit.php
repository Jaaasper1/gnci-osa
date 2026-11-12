<?php
// General-purpose rate limit: "at most $max times in the last $windowSeconds for this key".
// Used to stop repeated registration submits (and so repeated COR uploads and emails).
// Each allowed call is recorded. Returns true when the action may continue.
function actionRateLimit(PDO $pdo, string $action, string $key, int $max, int $windowSeconds): bool {
    $pdo->exec("CREATE TABLE IF NOT EXISTS action_rate_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action VARCHAR(40) NOT NULL,
        rkey VARCHAR(190) NOT NULL,
        at_time DATETIME NOT NULL,
        INDEX idx_action_key (action, rkey, at_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $st = $pdo->prepare("SELECT COUNT(*) FROM action_rate_log
                         WHERE action = ? AND rkey = ? AND at_time > (NOW() - INTERVAL ? SECOND)");
    $st->execute([$action, $key, $windowSeconds]);
    if ((int)$st->fetchColumn() >= $max) {
        return false;
    }
    $pdo->prepare("INSERT INTO action_rate_log (action, rkey, at_time) VALUES (?, ?, NOW())")
        ->execute([$action, $key]);
    if (random_int(1, 100) === 1) {
        $pdo->exec("DELETE FROM action_rate_log WHERE at_time < (NOW() - INTERVAL 2 DAY)");
    }
    return true;
}
