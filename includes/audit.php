<?php
// ============================================================
// audit.php: activity log for admin changes (who, what, when, old values)
// The table is created automatically. Passwords are never logged.
// ============================================================

function auditEnsureTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `audit_log` (
        `id`          int(11)      NOT NULL AUTO_INCREMENT,
        `actor_id`    int(11)      NOT NULL DEFAULT 0,
        `actor_name`  varchar(120) NOT NULL DEFAULT '',
        `action`      varchar(40)  NOT NULL,
        `entity`      varchar(40)  NOT NULL,
        `entity_id`   int(11)      NOT NULL DEFAULT 0,
        `details`     text         DEFAULT NULL,
        `ip`          varchar(45)  NOT NULL DEFAULT '',
        `created_at`  datetime     NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_created` (`created_at`),
        KEY `idx_entity` (`entity`, `entity_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Current values of one row (password removed), taken before a change.
// $table is only ever a fixed table name from our own code.
function auditRow(PDO $pdo, string $table, int $id): array {
    try {
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) return [];
        unset($row['password']);
        return $row;
    } catch (Throwable $e) {
        return [];
    }
}

// Records one change. $before = old values, $after = new values (both optional).
function auditLog(PDO $pdo, string $action, string $entity, int $entityId, array $before = [], array $after = []): void {
    try {
        auditEnsureTable($pdo);
        $details = json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $pdo->prepare(
            "INSERT INTO audit_log (actor_id, actor_name, action, entity, entity_id, details, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            (int)($_SESSION['user_id'] ?? 0),
            substr((string)($_SESSION['username'] ?? $_SESSION['name'] ?? ''), 0, 120),
            substr($action, 0, 40),
            substr($entity, 0, 40),
            $entityId,
            $details,
            '',   // IP is not recorded
        ]);
    } catch (Throwable $e) {
        error_log('GNC-OSA audit log error: ' . $e->getMessage());
    }
}

// Logs something the system did on its own (no logged-in user), e.g. auto-archive.
function auditLogSystem(PDO $pdo, string $action, string $entity, int $entityId, array $after = []): void {
    try {
        auditEnsureTable($pdo);
        $pdo->prepare(
            "INSERT INTO audit_log (actor_id, actor_name, action, entity, entity_id, details, ip)
             VALUES (0, 'System', ?, ?, ?, ?, '')"
        )->execute([substr($action, 0, 40), substr($entity, 0, 40), $entityId,
            json_encode(['before' => [], 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
    } catch (Throwable $e) {
        error_log('GNC-OSA audit log error: ' . $e->getMessage());
    }
}

// Deletes audit entries older than $days (default one year). Returns how many were removed.
function auditPurgeOld(PDO $pdo, int $days = 365): int {
    try {
        auditEnsureTable($pdo);
        $n = $pdo->exec("DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL " . (int)$days . " DAY");
        return $n === false ? 0 : (int)$n;
    } catch (Throwable $e) {
        error_log('GNC-OSA audit purge error: ' . $e->getMessage());
        return 0;
    }
}
