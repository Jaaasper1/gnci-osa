<?php
// Serves a voice message only to the student who owns the thread, or to OSA staff/admin.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$rid = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT r.audio_file, m.student_id
                     FROM osa_message_replies r JOIN osa_messages m ON m.id = r.message_id
                     WHERE r.id = ?");
$st->execute([$rid]);
$row = $st->fetch();
if (!$row || empty($row['audio_file'])) { http_response_code(404); exit('Not found'); }

$type = $_SESSION['user_type'] ?? '';
$uid  = (int)($_SESSION['user_id'] ?? 0);
$allowed = ($type === 'student' && $uid === (int)$row['student_id'])
        || in_array($type, ['staff', 'admin'], true);
if (!$allowed) { http_response_code(403); exit('Forbidden'); }

$name = basename($row['audio_file']);           // never trust a path from the database
$path = __DIR__ . '/uploads/voice/' . $name;
if (!is_file($path)) { http_response_code(404); exit('Not found'); }

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$ct  = ['webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $ct);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
