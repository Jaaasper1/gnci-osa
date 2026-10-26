<?php
// Password login option for students and OSA staff/admin.
// The email-code (OTP) login stays the default. This adds a password option on the same login page.
// Failed password attempts are limited: 5 wrong tries locks that account for 15 minutes.

const PW_MAX_FAILS   = 5;
const PW_LOCK_MINUTES = 15;

function pwThrottleEnsureTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_login_attempts (
        login_key    VARCHAR(80) NOT NULL PRIMARY KEY,
        fails        INT(11) NOT NULL DEFAULT 0,
        locked_until DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Seconds left on a lock, or 0 when the account may try again.
function pwThrottleWait(PDO $pdo, string $key): int {
    pwThrottleEnsureTable($pdo);
    $st = $pdo->prepare("SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), locked_until)) FROM password_login_attempts WHERE login_key = ? AND locked_until > NOW()");
    $st->execute([$key]);
    return (int)($st->fetchColumn() ?: 0);
}

function pwThrottleFail(PDO $pdo, string $key): void {
    pwThrottleEnsureTable($pdo);
    $pdo->prepare("INSERT INTO password_login_attempts (login_key, fails) VALUES (?, 1)
                   ON DUPLICATE KEY UPDATE
                     fails = IF(locked_until IS NOT NULL AND locked_until <= NOW(), 1, fails + 1),
                     locked_until = IF(fails >= " . PW_MAX_FAILS . " AND (locked_until IS NULL OR locked_until <= NOW()),
                                       DATE_ADD(NOW(), INTERVAL " . PW_LOCK_MINUTES . " MINUTE), locked_until)")
        ->execute([$key]);
}

function pwThrottleClear(PDO $pdo, string $key): void {
    pwThrottleEnsureTable($pdo);
    $pdo->prepare("DELETE FROM password_login_attempts WHERE login_key = ?")->execute([$key]);
}

// ── Admin password as the confirmation method ──────────────────────────────
// Once an admin sets a password in Settings, the password is used instead of the
// emailed code for every admin confirmation. osa_staff.password_set is set to 1 then.
function pwEnsureStaffColumn(PDO $pdo): void {
    $has = $pdo->query("SHOW COLUMNS FROM osa_staff LIKE 'password_set'")->fetch();
    if (!$has) {
        $pdo->exec("ALTER TABLE osa_staff ADD COLUMN password_set TINYINT(1) NOT NULL DEFAULT 0");
    }
}

function adminHasOwnPassword(PDO $pdo, int $adminId): bool {
    pwEnsureStaffColumn($pdo);
    $st = $pdo->prepare("SELECT password_set FROM osa_staff WHERE id = ?");
    $st->execute([$adminId]);
    return (int)($st->fetchColumn() ?: 0) === 1;
}

function adminMarkPasswordSet(PDO $pdo, int $adminId): void {
    pwEnsureStaffColumn($pdo);
    $pdo->prepare("UPDATE osa_staff SET password_set = 1 WHERE id = ?")->execute([$adminId]);
}
