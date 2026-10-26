<?php
// ============================================================
// notify-settings.php: admin notification Gmail (stored in DB)
// ------------------------------------------------------------
// Lets the OSA admin (osa_admin) set ONE Gmail address that receives
// all admin notification emails (new complaints, assignments, etc.),
// from the Admin > Notification Gmail page. The table is created
// automatically, so no SQL import is needed.
//
// NOTE: The SMTP sender (MAILER_FROM_EMAIL / MAILER_APP_PASSWORD)
// stays in includes/config.local.php on purpose. Never store the
// app password in the database.
// ============================================================

const ADMIN_NOTIFY_EMAIL_KEY = 'admin_notify_gmail';

function ensureAppSettingsTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `app_settings` (
        `setting_key`   varchar(64)  NOT NULL,
        `setting_value` text         DEFAULT NULL,
        `updated_at`    datetime     NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function getAppSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        ensureAppSettingsTable($pdo);
        $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $val = $st->fetchColumn();
        return ($val === false || $val === null) ? $default : (string) $val;
    } catch (Throwable $e) {
        return $default;
    }
}

function setAppSetting(PDO $pdo, string $key, string $value): void {
    ensureAppSettingsTable($pdo);
    $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([$key, $value]);
}

// The Gmail that receives admin notifications ('' when not set).
function getAdminNotifyEmail(PDO $pdo): string {
    return trim(getAppSetting($pdo, ADMIN_NOTIFY_EMAIL_KEY));
}

// Address that should receive notifications for one osa_staff row.
// Admin accounts use the admin Gmail when it is set; staff use their own email.
function notifyEmailFor(PDO $pdo, array $staff): string {
    if (($staff['role'] ?? '') === 'admin') {
        $gmail = getAdminNotifyEmail($pdo);
        if ($gmail !== '') return $gmail;
    }
    return trim((string) ($staff['email'] ?? ''));
}
