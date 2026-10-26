<?php
// ============================================================
// messages.php: general messages between students and the OSA office.
// For questions that are NOT complaints (e.g. membership, inquiries).
// Tables are created automatically.
// ============================================================
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';

function ensureMessageTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `osa_messages` (
        `id`            INT(11)      NOT NULL AUTO_INCREMENT,
        `student_id`    INT(11)      NOT NULL,
        `subject`       VARCHAR(150) NOT NULL,
        `status`        ENUM('open','closed') NOT NULL DEFAULT 'open',
        `student_unread` TINYINT(1)  NOT NULL DEFAULT 0,
        `staff_unread`   TINYINT(1)  NOT NULL DEFAULT 1,
        `last_activity` DATETIME     NOT NULL DEFAULT current_timestamp(),
        `created_at`    DATETIME     NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_student` (`student_id`),
        KEY `idx_activity` (`last_activity`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `osa_message_replies` (
        `id`          INT(11)      NOT NULL AUTO_INCREMENT,
        `message_id`  INT(11)      NOT NULL,
        `sender_role` ENUM('student','staff') NOT NULL,
        `sender_id`   INT(11)      NOT NULL DEFAULT 0,
        `sender_name` VARCHAR(120) NOT NULL DEFAULT '',
        `body`        TEXT         NOT NULL,
        `created_at`  DATETIME     NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_msg` (`message_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Voice messages: stored file name (NULL for text-only replies). Added once on existing installs.
    $has = $pdo->query("SHOW COLUMNS FROM `osa_message_replies` LIKE 'audio_file'")->fetch();
    if (!$has) {
        $pdo->exec("ALTER TABLE `osa_message_replies` ADD COLUMN `audio_file` VARCHAR(80) NULL DEFAULT NULL");
    }

    // AI assistant replies use sender_role 'assistant'.
    $role = $pdo->query("SHOW COLUMNS FROM `osa_message_replies` LIKE 'sender_role'")->fetch();
    if ($role && strpos($role['Type'], "'assistant'") === false) {
        $pdo->exec("ALTER TABLE `osa_message_replies` MODIFY `sender_role` ENUM('student','staff','assistant') NOT NULL");
    }
}

// Starts a new thread with its first message. Returns the message id.
function msgStartThread(PDO $pdo, int $studentId, string $subject, string $body, string $studentName, ?string $audioFile = null): int {
    $pdo->prepare("INSERT INTO osa_messages (student_id, subject, staff_unread, last_activity) VALUES (?, ?, 1, NOW())")
        ->execute([$studentId, $subject]);
    $id = (int)$pdo->lastInsertId();
    msgAddReply($pdo, $id, 'student', $studentId, $studentName, $body, $audioFile);
    auditLog($pdo, 'send', 'message', $id, [], ['subject' => $subject, 'by' => 'student']);
    return $id;
}

function msgAddReply(PDO $pdo, int $messageId, string $role, int $senderId, string $senderName, string $body, ?string $audioFile = null): void {
    $pdo->prepare("INSERT INTO osa_message_replies (message_id, sender_role, sender_id, sender_name, body, audio_file) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$messageId, $role, $senderId, $senderName, $body, $audioFile]);
    if ($role === 'student') {
        // A student reply reopens the thread and flags it for staff
        $pdo->prepare("UPDATE osa_messages SET last_activity = NOW(), status = 'open', staff_unread = 1 WHERE id = ?")->execute([$messageId]);
    } else {
        $pdo->prepare("UPDATE osa_messages SET last_activity = NOW(), student_unread = 1 WHERE id = ?")->execute([$messageId]);
    }
}

// A thread that belongs to this student, or null.
function msgGetOwned(PDO $pdo, int $messageId, int $studentId): ?array {
    $st = $pdo->prepare("SELECT * FROM osa_messages WHERE id = ? AND student_id = ?");
    $st->execute([$messageId, $studentId]);
    return $st->fetch() ?: null;
}

function msgGet(PDO $pdo, int $messageId): ?array {
    $st = $pdo->prepare("SELECT m.*, s.first_name, s.last_name, s.student_id AS student_no
                         FROM osa_messages m JOIN students s ON s.id = m.student_id WHERE m.id = ?");
    $st->execute([$messageId]);
    return $st->fetch() ?: null;
}

function msgReplies(PDO $pdo, int $messageId): array {
    $st = $pdo->prepare("SELECT * FROM osa_message_replies WHERE message_id = ? ORDER BY created_at ASC, id ASC");
    $st->execute([$messageId]);
    return $st->fetchAll();
}

function msgMarkRead(PDO $pdo, int $messageId, string $role): void {
    $col = $role === 'student' ? 'student_unread' : 'staff_unread';
    $pdo->prepare("UPDATE osa_messages SET $col = 0 WHERE id = ?")->execute([$messageId]);
}

function msgListForStudent(PDO $pdo, int $studentId): array {
    $st = $pdo->prepare("SELECT * FROM osa_messages WHERE student_id = ? ORDER BY last_activity DESC");
    $st->execute([$studentId]);
    return $st->fetchAll();
}

function msgListForStaff(PDO $pdo, string $filter): array {
    $where = $filter === 'closed' ? "WHERE m.status = 'closed'" : ($filter === 'unread' ? "WHERE m.staff_unread = 1" : "WHERE m.status = 'open'");
    return $pdo->query("SELECT m.*, s.first_name, s.last_name, s.student_id AS student_no
                        FROM osa_messages m JOIN students s ON s.id = m.student_id
                        $where ORDER BY m.last_activity DESC LIMIT 300")->fetchAll();
}

function msgUnreadStaffCount(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM osa_messages WHERE staff_unread = 1")->fetchColumn();
}

// Sends an in-app notification (and email) to every active staff/admin account.
function msgNotifyStaff(PDO $pdo, string $message, string $link): void {
    $ids = $pdo->query("SELECT id FROM osa_staff WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $sid) {
        addStaffNotification($pdo, (int)$sid, $message, $link);
    }
}

/* ── Voice messages ─────────────────────────────────────────────── */

if (!defined('VOICE_MAX_BYTES')) define('VOICE_MAX_BYTES', 5 * 1024 * 1024);

// True when the form sent an audio file (even if the upload failed).
function msgHasVoiceUpload(): bool {
    return isset($_FILES['voice']) && ($_FILES['voice']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

// Validates and stores the uploaded voice message in uploads/voice/.
// Returns ['file' => stored name or null, 'error' => message or null].
function msgSaveUploadedVoice(): array {
    if (!msgHasVoiceUpload()) return ['file' => null, 'error' => null];
    $f = $_FILES['voice'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return ['file' => null, 'error' => 'Hindi na-upload ang voice message. Subukan ulit.'];
    }
    if ($f['size'] <= 0 || $f['size'] > VOICE_MAX_BYTES) {
        return ['file' => null, 'error' => 'Masyadong mahaba ang voice message (max 5 MB).'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $map = [
        'audio/webm' => 'webm', 'video/webm' => 'webm',
        'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'video/ogg' => 'ogg',
        'audio/mp4' => 'm4a', 'video/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
    ];
    if (!isset($map[$mime])) {
        return ['file' => null, 'error' => 'Hindi suportadong audio file.'];
    }
    $dir = __DIR__ . '/../uploads/voice';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return ['file' => null, 'error' => 'Hindi makagawa ng folder para sa voice message.'];
    }
    $name = bin2hex(random_bytes(16)) . '.' . $map[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return ['file' => null, 'error' => 'Hindi ma-save ang voice message.'];
    }
    return ['file' => $name, 'error' => null];
}
