<?php
// JSON endpoint for the Ask OSA chat bubble: a real conversation with OSA staff.
// Uses the same message tables as student/messages.php.
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';
require_once '../includes/messages.php';
require_once '../includes/osa-assistant.php';
if (!function_exists('t')) { require_once __DIR__ . '/../includes/lang.php'; }

header('Content-Type: application/json; charset=utf-8');

function chatJson(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    chatJson(['ok' => false, 'error' => t('msg.please_log_in_again_467d', 'Please log in again.')], 401);
}

ensureMessageTables($pdo);

// AI questions allowed per student (total). Change this number to adjust the limit.
const AI_QUESTION_LIMIT = 5;
$pdo->exec("CREATE TABLE IF NOT EXISTS osa_ai_usage (
    student_id INT(11) NOT NULL PRIMARY KEY,
    used       INT(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$student_id = (int)$_SESSION['user_id'];
$student_name = $_SESSION['name'] ?? 'Student';

// Latest thread for this student (one ongoing conversation, like student/messages.php).
function chatLatestThread(PDO $pdo, int $studentId): ?array {
    $list = msgListForStudent($pdo, $studentId);
    return $list[0] ?? null;
}

function aiUsed(PDO $pdo, int $studentId): int {
    $st = $pdo->prepare("SELECT used FROM osa_ai_usage WHERE student_id = ?");
    $st->execute([$studentId]);
    return (int)($st->fetchColumn() ?: 0);
}

function aiLeft(PDO $pdo, int $studentId): int {
    return max(0, AI_QUESTION_LIMIT - aiUsed($pdo, $studentId));
}

function chatFormat(PDO $pdo, array $thread, int $studentId): array {
    $rows = msgReplies($pdo, (int)$thread['id']);
    $msgs = [];
    foreach ($rows as $r) {
        $mine = $r['sender_role'] === 'student';
        $msgs[] = [
            'id'   => (int)$r['id'],
            'mine' => $mine,
            'name' => $mine ? tx('You') : ($r['sender_role'] === 'assistant' ? tx('Ask OSA (AI)') : tx('OSA Office')),
            'body' => (string)$r['body'],
            'at'   => tlDate('M j, g:i A', strtotime($r['created_at'])),
        ];
    }
    return [
        'id'      => (int)$thread['id'],
        'subject' => $thread['subject'],
        'status'  => $thread['status'],
        'messages'=> $msgs,
    ];
}

// ---- GET: load the conversation ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $thread = chatLatestThread($pdo, $student_id);
    if (!$thread) {
        chatJson(['ok' => true, 'csrf' => csrfToken(), 'thread' => null, 'messages' => [], 'ai_left' => aiLeft($pdo, $student_id)]);
    }
    // ?peek=1 is used by the background check and must not mark messages as read.
    if (!isset($_GET['peek'])) msgMarkRead($pdo, (int)$thread['id'], 'student');
    $data = chatFormat($pdo, $thread, $student_id);
    chatJson(['ok' => true, 'csrf' => csrfToken(), 'thread' => $data, 'messages' => $data['messages'], 'ai_left' => aiLeft($pdo, $student_id)]);
}

// ---- POST: send a message ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf_token'] ?? '';
    if (!$sent || !hash_equals(csrfToken(), $sent)) {
        chatJson(['ok' => false, 'error' => t('msg.your_session_expired_refresh_the_page_an_5c7e', 'Your session expired. Refresh the page and try again.')], 403);
    }
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body === '') chatJson(['ok' => false, 'error' => t('msg.type_a_message_first_9728', 'Type a message first.')], 422);
    if (mb_strlen($body) > 2000) chatJson(['ok' => false, 'error' => t('msg.the_message_must_be_2000_characters_or_l_e0d8', 'The message must be 2000 characters or less.')], 422);

    $thread = chatLatestThread($pdo, $student_id);
    if ($thread) {
        $tid = (int)$thread['id'];
        msgAddReply($pdo, $tid, 'student', $student_id, $student_name, $body, null);
        auditLog($pdo, 'reply', 'message', $tid, [], ['by' => 'student', 'voice' => false, 'via' => 'chat']);
        msgNotifyStaff($pdo, 'New message from ' . $student_name . ' on: ' . $thread['subject'], 'messages.php?id=' . $tid);
    } else {
        $subject = mb_substr(preg_replace('/\s+/', ' ', $body), 0, 60);
        $tid = msgStartThread($pdo, $student_id, $subject, $body, $student_name, null);
        msgNotifyStaff($pdo, 'New message from ' . $student_name . ': ' . $subject, 'messages.php?id=' . $tid);
    }

    // AI first: answers only from the OSA FAQ. If it cannot, the thread waits for staff.
    $answer = null;
    if (aiLeft($pdo, $student_id) > 0) {
        // Count the question first, so a failed AI call still uses one of the student's questions.
        $pdo->prepare("INSERT INTO osa_ai_usage (student_id, used) VALUES (?, 1) ON DUPLICATE KEY UPDATE used = used + 1")
            ->execute([$student_id]);
        try { $answer = osaAssistantAnswer($body); } catch (Throwable $ex) { $answer = null; }
    }
    if ($answer !== null) {
        msgAddReply($pdo, $tid, 'assistant', 0, 'Ask OSA (AI)', $answer);
        auditLog($pdo, 'auto_reply', 'message', $tid, [], ['by' => 'assistant']);
    } elseif (aiLeft($pdo, $student_id) === 0 && aiUsed($pdo, $student_id) >= AI_QUESTION_LIMIT) {
        msgAddReply($pdo, $tid, 'assistant', 0, 'Ask OSA (AI)', tx('You have used your AI questions. Please wait for the OSA admin or staff to reply to your message.'));
    } else {
        msgAddReply($pdo, $tid, 'assistant', 0, 'Ask OSA (AI)', tx('Please wait for the OSA admin or staff to reply to your message.'));
    }

    $thread = msgGetOwned($pdo, $tid, $student_id);
    chatJson(['ok' => true, 'csrf' => csrfToken(), 'thread' => chatFormat($pdo, $thread, $student_id), 'ai_left' => aiLeft($pdo, $student_id)]);
}

chatJson(['ok' => false, 'error' => t('msg.method_not_allowed_05fe', 'Method not allowed.')], 405);
