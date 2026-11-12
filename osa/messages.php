<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/messages.php';
require_once '../includes/audit.php';
requireStaff();
ensureMessageTables($pdo);

$staff_id   = (int)$_SESSION['user_id'];
$notifCount = getUnreadStaffNotifCount($pdo, $staff_id);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $tid    = (int)($_POST['message_id'] ?? 0);
    $m      = $tid ? msgGet($pdo, $tid) : null;

    if ($action === 'start') {
        // Staff starts a NEW conversation with a student (Messenger-style compose)
        $sid = (int)($_POST['student_id'] ?? 0);
        $stu = $pdo->prepare("SELECT id, first_name, last_name FROM students WHERE id = ? AND is_active = 1");
        $stu->execute([$sid]);
        $stu = $stu->fetch();
        $body = trim($_POST['body'] ?? '');
        if (!$stu) {
            $error = 'Student not found.';
        } elseif ($body === '' && !msgHasVoiceUpload()) {
            $error = t('msg.please_write_a_reply_or_record_a_voice_m_8bec', 'Please write a reply or record a voice message.');
        } elseif (mb_strlen($body) > 2000) {
            $error = t('msg.reply_must_be_2000_characters_or_less_c5e4', 'Reply must be 2000 characters or less.');
        } else {
            $voice = msgSaveUploadedVoice();
            if ($voice['error']) {
                $error = $voice['error'];
            } else {
                $pdo->prepare("INSERT INTO osa_messages (student_id, subject, staff_unread, last_activity) VALUES (?, ?, 0, NOW())")
                    ->execute([$sid, 'Message from OSA']);
                $newId = (int)$pdo->lastInsertId();
                msgAddReply($pdo, $newId, 'staff', $staff_id, $_SESSION['name'], $body, $voice['file']);
                auditLog($pdo, 'send', 'message', $newId, [], ['by' => 'staff', 'to_student' => $sid]);
                addNotification($pdo, $sid, 'The OSA office sent you a message.', 'messages.php?id=' . $newId);
                header('Location: messages.php?id=' . $newId . '&replied=1'); exit;
            }
        }
        if ($error && !empty($stu['id'])) $_GET['to'] = (int)$stu['id'];   // stay in the compose view
    } elseif (!$m) {
        $error = t('msg.message_not_found_cb53', 'Message not found.');
    } elseif ($action === 'reply') {
        $body = trim($_POST['body'] ?? '');
        if ($body === '' && !msgHasVoiceUpload()) {
            $error = t('msg.please_write_a_reply_or_record_a_voice_m_8bec', 'Please write a reply or record a voice message.');
        } elseif (mb_strlen($body) > 2000) {
            $error = t('msg.reply_must_be_2000_characters_or_less_c5e4', 'Reply must be 2000 characters or less.');
        } else {
            $voice = msgSaveUploadedVoice();
            if ($voice['error']) {
                $error = $voice['error'];
            } else {
                msgAddReply($pdo, $tid, 'staff', $staff_id, $_SESSION['name'], $body, $voice['file']);
                auditLog($pdo, 'reply', 'message', $tid, [], ['by' => 'staff', 'voice' => (bool)$voice['file']]);
                addNotification($pdo, (int)$m['student_id'], 'The OSA office replied to your message: ' . $m['subject'], 'messages.php?id=' . $tid);
                header('Location: messages.php?id=' . $tid . '&replied=1'); exit;
            }
        }
    } elseif ($action === 'close') {
        $pdo->prepare("UPDATE osa_messages SET status = 'closed' WHERE id = ?")->execute([$tid]);
        auditLog($pdo, 'close', 'message', $tid, ['status' => $m['status']], ['status' => 'closed']);
        header('Location: messages.php?id=' . $tid); exit;
    } elseif ($action === 'reopen') {
        $pdo->prepare("UPDATE osa_messages SET status = 'open' WHERE id = ?")->execute([$tid]);
        auditLog($pdo, 'reopen', 'message', $tid, ['status' => $m['status']], ['status' => 'open']);
        header('Location: messages.php?id=' . $tid); exit;
    }
}

// ---- list helpers (used by the page and by the live-search AJAX call) ----
function msgInitials($first, $last) {
    return strtoupper(mb_substr((string)$first, 0, 1) . mb_substr((string)$last, 0, 1));
}

// Search ALL conversations (any tab) by student name, Student ID, subject or any message text.
function msgSearchForStaff(PDO $pdo, string $q): array {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $st = $pdo->prepare("SELECT m.*, s.first_name, s.last_name, s.student_id AS student_no
                         FROM osa_messages m JOIN students s ON s.id = m.student_id
                         WHERE CONCAT(s.first_name, ' ', s.last_name) LIKE ?
                            OR CONCAT(s.last_name, ' ', s.first_name) LIKE ?
                            OR s.student_id LIKE ?
                            OR m.subject LIKE ?
                            OR EXISTS (SELECT 1 FROM osa_message_replies r WHERE r.message_id = m.id AND r.body LIKE ?)
                         ORDER BY m.last_activity DESC LIMIT 100");
    $st->execute([$like, $like, $like, $like, $like]);
    return $st->fetchAll();
}

// Last-message preview text for each conversation in a list.
function msgStaffPreviews(PDO $pdo, array $list): array {
    $out = [];
    $pv = $pdo->prepare("SELECT sender_role, body, audio_file FROM osa_message_replies WHERE message_id = ? ORDER BY id DESC LIMIT 1");
    foreach ($list as $m) {
        $pv->execute([(int)$m['id']]);
        $last = $pv->fetch();
        if (!$last) { $out[(int)$m['id']] = ''; continue; }
        $txt = trim(preg_replace('/\\s+/', ' ', (string)$last['body']));
        if ($txt === '' && !empty($last['audio_file'])) $txt = 'Voice message';
        if (mb_strlen($txt) > 70) $txt = mb_substr($txt, 0, 70) . '…';
        $who = $last['sender_role'] === 'staff' ? 'You: ' : ($last['sender_role'] === 'assistant' ? 'AI: ' : '');
        $out[(int)$m['id']] = $who . $txt;
    }
    return $out;
}

// Prints the conversation rows (inner HTML of #msgList).
function msgRenderStaffList(array $list, array $previews, string $filter, string $q): void {
    $qs = $q !== '' ? '&q=' . urlencode($q) : '';
    if ($q !== ''): ?>
        <div class="msgm-searchnote"><?= t('ui.searching_all_messages_for_6b64', 'Searching all messages for “') ?><?= e($q) ?>” (<?= count($list) ?> <?= t('ui.found_89af', 'found)') ?></div>
    <?php endif;
    if (!$list): ?>
        <div class="msg-card msg-empty"><i class="ti ti-<?= $q !== '' ? 'search-off' : 'inbox' ?>"></i><?= $q !== '' ? 'No messages match your search.' : 'No messages here.' ?></div>
    <?php endif;
    foreach ($list as $m): $unread = !empty($m['staff_unread']); ?>
        <a class="msgm-item <?= $unread ? 'unread' : '' ?>" href="messages.php?filter=<?= e($filter) ?>&amp;id=<?= (int)$m['id'] ?><?= e($qs) ?>">
            <span class="msgm-avatar"><?= e(msgInitials($m['first_name'], $m['last_name'])) ?></span>
            <div class="msgm-body">
                <div class="msgm-top">
                    <span class="msgm-name"><?= e($m['first_name'] . ' ' . $m['last_name']) ?><span class="msgm-sid"><?= e($m['student_no']) ?></span><?php if ($m['status'] === 'closed'): ?><span class="msg-pill closed"><?= t('ui.closed_03f4', 'Closed') ?></span><?php endif; ?></span>
                    <span class="msgm-time"><?= e(date('M j, g:i A', strtotime($m['last_activity']))) ?></span>
                </div>
                <div class="msgm-prev"><?= e($previews[(int)$m['id']] ?? '') ?></div>
            </div>
            <?php if ($unread): ?><span class="msgm-dot" title="<?= t('ui.new_message_b55a', 'New message') ?>"></span><?php endif; ?>
        </a>
    <?php endforeach;
}

$filter = $_GET['filter'] ?? 'open';
if (!in_array($filter, ['open', 'closed', 'unread'], true)) $filter = 'open';
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 80) $q = mb_substr($q, 0, 80);
$list = $q !== '' ? msgSearchForStaff($pdo, $q) : msgListForStaff($pdo, $filter);

// Live search: return only the list rows.
if (isset($_GET['ajax'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Msg-List: 1');
    header('Cache-Control: no-store');
    msgRenderStaffList($list, msgStaffPreviews($pdo, $list), $filter, $q);
    exit;
}

$viewId  = (int)($_GET['id'] ?? 0);
$thread  = $viewId ? msgGet($pdo, $viewId) : null;
$replies = [];
if ($thread) {
    msgMarkRead($pdo, $viewId, 'staff');
    $replies = msgReplies($pdo, $viewId);
}
// Messenger-style "New message": ?to=<student id> opens that student's open thread, or an empty compose view
$newTo = (int)($_GET['to'] ?? 0);
if (!$thread && $newTo) {
    $stu = $pdo->prepare("SELECT id, first_name, last_name, student_id AS student_no FROM students WHERE id = ? AND is_active = 1");
    $stu->execute([$newTo]);
    $stu = $stu->fetch();
    if (!$stu) { header('Location: messages.php'); exit; }
    $ex = $pdo->prepare("SELECT id FROM osa_messages WHERE student_id = ? AND status = 'open' ORDER BY last_activity DESC LIMIT 1");
    $ex->execute([$newTo]);
    if ($exId = $ex->fetchColumn()) { header('Location: messages.php?id=' . (int)$exId); exit; }
    $thread = ['id' => 0, 'student_id' => $stu['id'], 'first_name' => $stu['first_name'], 'last_name' => $stu['last_name'],
               'student_no' => $stu['student_no'], 'status' => 'open', 'subject' => 'Message from OSA'];
}
$unreadTotal = msgUnreadStaffCount($pdo);
$inConvo = (bool)$thread;   // false = conversation list (Messenger style)
$backFilter = isset($_GET['filter']) ? $filter : (($thread['status'] ?? 'open') === 'closed' ? 'closed' : 'open');
$backQs = $q !== '' ? '&q=' . urlencode($q) : '';

$previews = (!$inConvo && $list) ? msgStaffPreviews($pdo, $list) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.messages', 'Messages') ?> — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <link rel="stylesheet" href="../assets/css/messages.css?v=2">
    <link rel="stylesheet" href="../assets/css/messages-staff.css?v=2">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<?php
$isAdmin = ($_SESSION['user_type'] ?? '') === 'admin';
$base = '../admin/';   // admin and staff share the full menu
?>
<nav class="topnav"><button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name"><?= $isAdmin ? 'GNC-OSA Admin' : 'GNC-OSA Portal' ?></div><div class="sub"><?= $isAdmin ? 'Administrator' : 'OSA Staff' ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><button class="notif-btn" onclick="location.href='<?= $base ?>notifications.php'"><i class="ti ti-bell"></i><?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?></button><div class="avatar"><?= strtoupper(substr($_SESSION['name'], 0, 2)) ?></div><span class="uname"><?= e($_SESSION['name']) ?></span>
    </div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<?php if (true): ?>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.overview', 'Overview') ?></div>
    <a class="sitem" href="../admin/dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section"><?= t('sidebar.complaints', 'Complaints') ?></div>
    <a class="sitem" href="../admin/complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?></a>
    <a class="sitem active" href="../osa/messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?><?php if ($unreadTotal > 0): ?><span class="badge-count"><?= $unreadTotal ?></span><?php endif; ?></a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="../admin/elections.php"><i class="ti ti-trophy"></i> <?= t('sidebar.elections', 'Elections') ?></a>
    <a class="sitem" href="../admin/candidates.php"><i class="ti ti-users"></i> <?= t('sidebar.candidates', 'Candidates') ?></a>
    <a class="sitem" href="../admin/party-lists.php"><i class="ti ti-flag"></i> <?= t('sidebar.party_lists', 'Party Lists') ?></a>
    <a class="sitem" href="../admin/vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="../admin/results.php"><i class="ti ti-chart-pie"></i> <?= t('sidebar.live_results', 'Live Results') ?></a>
    <div class="sidebar-section"><?= t('sidebar.events', 'Events') ?></div>
    <a class="sitem" href="../admin/events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <div class="sidebar-section"><?= t('sidebar.system', 'System') ?></div>
    <a class="sitem" href="../admin/students.php"><i class="ti ti-user-plus"></i> <?= t('sidebar.students', 'Students') ?></a>
    <?php if ($isAdmin): ?><a class="sitem" href="../admin/staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a><?php endif; ?>
    <a class="sitem" href="../admin/reports.php"><i class="ti ti-report-analytics"></i> <?= t('sidebar.reports', 'Reports') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="../admin/notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?><?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?></a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<?php else: ?>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.complaints', 'Complaints') ?></a>
    <a class="sitem active" href="messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?><?php if ($unreadTotal > 0): ?><span class="badge-count"><?= $unreadTotal ?></span><?php endif; ?></a>
    <div class="sidebar-section"><?= t('sidebar.events', 'Events') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('sidebar.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?><?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?></a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<?php endif; ?>

<div class="main"><div class="content">

<?php if (!$inConvo): ?>
    <!-- ===== VIEW 1: conversation list (like Messenger's chat list) ===== -->
    <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;max-width:720px;">
        <div><h1><?= t('ui.messages_41de', 'Messages') ?></h1><p><?= t('ui.non_complaint_messages_from_studen_bfc4', 'Non-complaint messages from students') ?></p></div>
        <button type="button" id="composeBtn" class="msgm-compose" title="New message" aria-label="New message">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4H8a4 4 0 0 0-4 4v8a4 4 0 0 0 4 4h8a4 4 0 0 0 4-4v-4"/><path d="M18.4 3.6a1.9 1.9 0 0 1 2.7 2.7L12.5 15 9 15.8l.8-3.5z"/></svg>
        </button>
    </div>

    <?php if (isset($_GET['replied'])): ?><div class="alert alert-success"><i class="ti ti-check"></i> <?= t('ui.reply_sent_ce23', 'Reply sent.') ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= e($error) ?></div><?php endif; ?>


    <div class="msg-tabs" style="margin-bottom:14px;">
        <a href="messages.php?filter=open" class="<?= $filter === 'open' ? 'on' : '' ?>"><?= t('ui.open_c3bf', 'Open') ?></a>
        <a href="messages.php?filter=unread" class="<?= $filter === 'unread' ? 'on' : '' ?>"><?= t('ui.unread_c5d1', 'Unread (') ?><?= $unreadTotal ?>)</a>
        <a href="messages.php?filter=closed" class="<?= $filter === 'closed' ? 'on' : '' ?>"><?= t('ui.closed_03f4', 'Closed') ?></a>
    </div>

    <div class="msgm-search">
        <i class="ti ti-search"></i>
        <input type="text" id="msgSearch" value="<?= e($q) ?>" placeholder="<?= t('ui.search_name_student_id_or_message__ece8', 'Search name, Student ID, or message text…') ?>" autocomplete="off" maxlength="80" aria-label="<?= t('ui.search_messages_4863', 'Search messages') ?>">
        <button type="button" id="msgSearchClear" aria-label="<?= t('ui.clear_search_9983', 'Clear search') ?>" <?= $q === '' ? 'hidden' : '' ?>><i class="ti ti-x"></i></button>
    </div>

    <div class="msgm-list" id="msgList">
        <?php msgRenderStaffList($list, $previews, $filter, $q); ?>
    </div>

<?php else: ?>
    <!-- ===== VIEW 2: the conversation itself ===== -->
    <div class="msgm-convo">
        <div class="msgm-bar">
            <a class="msgm-back" href="messages.php?filter=<?= e($backFilter) ?><?= e($backQs) ?>" aria-label="<?= t('ui.back_to_messages_9ed1', 'Back to messages') ?>"><i class="ti ti-arrow-left"></i></a>
            <span class="msgm-avatar sm"><?= e(msgInitials($thread['first_name'], $thread['last_name'])) ?></span>
            <div class="msgm-bar-name">
                <?= e($thread['first_name'] . ' ' . $thread['last_name']) ?>
                <small>Student ID <?= e($thread['student_no']) ?></small>
            </div>
            <span class="msg-pill <?= $thread['status'] === 'closed' ? 'closed' : 'open' ?>"><?= $thread['status'] === 'closed' ? 'Closed' : 'Open' ?></span>
            <form method="POST" style="margin:0;<?= empty($thread['id']) ? 'display:none;' : '' ?>">
                <?= csrfField() ?>
                <input type="hidden" name="message_id" value="<?= (int)$thread['id'] ?>">
                <?php if ($thread['status'] === 'open'): ?>
                    <input type="hidden" name="action" value="close">
                    <button type="submit" class="btn btn-outline btn-sm"><i class="ti ti-check"></i> <span class="msgm-btn-text"><?= t('ui.mark_as_closed_b938', 'Mark as closed') ?></span></button>
                <?php else: ?>
                    <input type="hidden" name="action" value="reopen">
                    <button type="submit" class="btn btn-outline btn-sm"><i class="ti ti-refresh"></i> <span class="msgm-btn-text"><?= t('ui.reopen_b824', 'Reopen') ?></span></button>
                <?php endif; ?>
            </form>
        </div>

        <?php if (isset($_GET['replied'])): ?><div class="alert alert-success"><i class="ti ti-check"></i> <?= t('ui.reply_sent_ce23', 'Reply sent.') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= e($error) ?></div><?php endif; ?>

        <div class="msg-thread" id="msgThread">
        <?php foreach ($replies as $r): $mine = $r['sender_role'] === 'staff'; ?>
            <div class="msg-row <?= $mine ? 'mine' : 'theirs' ?>">
                <div class="msg-meta"><?= $r['sender_role'] === 'staff' ? e($r['sender_name']) . ' (OSA)' : e($r['sender_name']) ?> · <?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></div>
                <?php if (!empty($r['audio_file'])): ?><audio class="msg-audio" controls preload="none" src="../voice.php?id=<?= (int)$r['id'] ?>"></audio><?php endif; ?><?php if ($r['body'] !== ''): ?><div class="msg-bubble"><?= e($r['body']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>

        <form method="POST" class="msg-reply" enctype="multipart/form-data" data-voice-form>
            <?= csrfField() ?>
            <?php if (!empty($thread['id'])): ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="message_id" value="<?= (int)$thread['id'] ?>">
            <?php else: ?>
            <input type="hidden" name="action" value="start">
            <input type="hidden" name="student_id" value="<?= (int)$thread['student_id'] ?>">
            <?php endif; ?>
            <div class="form-group">
                <label><?= empty($thread['id']) ? 'Message to student' : t('ui.reply_to_student_829c', 'Reply to student') ?></label>
                <textarea name="body" class="form-control" rows="2" maxlength="2000" placeholder="<?= htmlspecialchars(t('auto.write_your_reply_c2f1', 'Write your reply...'), ENT_QUOTES) ?>"></textarea>
            </div>
            <div class="voice-row"><button type="button" class="btn btn-outline btn-sm voice-btn" data-voice-btn><i class="ti ti-microphone"></i> <span>Voice message</span></button><span class="voice-status" data-voice-status></span><input type="file" name="voice" accept="audio/*" hidden data-voice-input></div>
            <div class="msg-reply-actions"><button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-send"></i> <?= empty($thread['id']) ? 'Send message' : t('ui.send_reply_662f', 'Send reply') ?></button></div>
        </form>
    </div>
<?php endif; ?>

</div></div>

<?php if (!$inConvo): ?>
<?php
$__stu = msgStudentSignInList($pdo);
$__in  = 0; foreach ($__stu as $__s) if (!empty($__s['last_login_at'])) $__in++;
$__out = count($__stu) - $__in;
?>
<style>
  .msgm-compose{border:0;background:transparent;color:#6B1A2A;width:44px;height:44px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0}
  .msgm-compose:hover{background:#F6E7EA}
  .nm-overlay{position:fixed;inset:0;background:rgba(17,24,39,.55);display:none;align-items:flex-start;justify-content:center;padding:6vh 14px;z-index:1000}
  .nm-overlay.open{display:flex}
  .nm-panel{background:#fff;border-radius:16px;width:100%;max-width:460px;max-height:86vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.25)}
  .nm-top{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 6px}
  .nm-top h3{margin:0;font-size:17px;color:#1F2937}
  .nm-x{border:0;background:#F3F4F6;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:18px;color:#4B5563}
  .nm-search{margin:6px 16px 8px;position:relative}
  .nm-search input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #E5E7EB;border-radius:22px;background:#F3F4F6;font-size:14px}
  .nm-chips{display:flex;gap:6px;flex-wrap:wrap;padding:0 16px 8px}
  .nm-chip{border:1px solid #E5E7EB;background:#fff;color:#4B5563;border-radius:20px;padding:4px 11px;font-size:12px;font-weight:600;cursor:pointer}
  .nm-chip.on{background:#6B1A2A;border-color:#6B1A2A;color:#fff}
  .nm-list{overflow:auto;padding:0 8px 10px;flex:1}
  .nm-item{display:flex;align-items:center;gap:12px;padding:8px;border-radius:10px;text-decoration:none;color:inherit}
  .nm-item:hover{background:#F3F4F6}
  .nm-av{width:40px;height:40px;border-radius:50%;background:#6B1A2A;color:#fff;font-weight:700;font-size:14px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;position:relative}
  .nm-av.on::after{content:"";position:absolute;right:0;bottom:0;width:11px;height:11px;border-radius:50%;background:#22C55E;border:2px solid #fff}
  .nm-nm{font-weight:600;font-size:14px;color:#1F2937}
  .nm-sub{font-size:12px;color:#6B7280}
  .nm-more,.nm-empty{display:block;text-align:center;font-size:13px;color:#6B7280;padding:10px}
  .nm-more{border:0;background:transparent;color:#6B1A2A;font-weight:600;cursor:pointer;width:100%}
</style>
<div class="nm-overlay" id="nmOverlay" aria-hidden="true">
  <div class="nm-panel" role="dialog" aria-label="New message">
    <div class="nm-top"><h3>New message</h3><button type="button" class="nm-x" id="nmClose" aria-label="Close">&times;</button></div>
    <div class="nm-search"><input type="text" id="nmSearch" placeholder="Search student name, ID or course" autocomplete="off" maxlength="80"></div>
    <div class="nm-list" id="nmList">
      <?php foreach ($__stu as $__s): $__ok = !empty($__s['last_login_at']); ?>
        <a class="nm-item" href="messages.php?to=<?= (int)$__s['id'] ?>" data-s="<?= $__ok ? 'in' : 'out' ?>" data-search="<?= e(mb_strtolower($__s['student_id'] . ' ' . $__s['first_name'] . ' ' . $__s['last_name'] . ' ' . $__s['last_name'] . ' ' . $__s['first_name'] . ' ' . ($__s['course'] ?? ''))) ?>">
          <span class="nm-av <?= $__ok ? 'on' : '' ?>"><?= e(msgInitials($__s['first_name'], $__s['last_name'])) ?></span>
          <span><div class="nm-nm"><?= e($__s['first_name'] . ' ' . $__s['last_name']) ?></div>
          <div class="nm-sub"><?= e($__s['student_id']) ?><?= !empty($__s['course']) ? ' · ' . e($__s['course']) : '' ?> · <?= $__ok ? 'Signed in' : 'Not yet signed in' ?></div></span>
        </a>
      <?php endforeach; ?>
      <div class="nm-empty" id="nmEmpty" style="display:none;">No students match.</div>
      <button type="button" class="nm-more" id="nmMore" style="display:none;">Show more</button>
    </div>
  </div>
</div>
<script>
// "New message" picker: pick a student -> opens their conversation (or a new one).
(function () {
  var ov = document.getElementById('nmOverlay'), btn = document.getElementById('composeBtn'),
      input = document.getElementById('nmSearch'), more = document.getElementById('nmMore'),
      empty = document.getElementById('nmEmpty'), chips = document.querySelectorAll('.nm-chip'),
      items = Array.prototype.slice.call(document.querySelectorAll('#nmList .nm-item')),
      filter = 'all', limit = 40, STEP = 40;
  if (!ov || !btn) return;
  function apply() {
    var q = input.value.trim().toLowerCase(), matched = 0;
    items.forEach(function (r) {
      var ok = (filter === 'all' || r.getAttribute('data-s') === filter) && (!q || (r.getAttribute('data-search') || '').indexOf(q) !== -1);
      if (ok) { matched++; r.style.display = matched <= limit ? '' : 'none'; } else r.style.display = 'none';
    });
    empty.style.display = matched ? 'none' : 'block';
    more.style.display = matched > limit ? 'block' : 'none';
    more.textContent = 'Show more (' + (matched - limit) + ' left)';
  }
  function open() { ov.classList.add('open'); ov.setAttribute('aria-hidden', 'false'); limit = STEP; apply(); setTimeout(function () { input.focus(); }, 30); }
  function close() { ov.classList.remove('open'); ov.setAttribute('aria-hidden', 'true'); }
  btn.addEventListener('click', open);
  document.getElementById('nmClose').addEventListener('click', close);
  ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  input.addEventListener('input', function () { limit = STEP; apply(); });
  more.addEventListener('click', function () { limit += STEP; apply(); });
  chips.forEach(function (c) { c.addEventListener('click', function () {
    chips.forEach(function (x) { x.classList.remove('on'); }); c.classList.add('on');
    filter = c.getAttribute('data-f'); limit = STEP; apply();
  }); });
})();
</script>
<script>
// Live search: filters as you type (name, Student ID, subject, message text) across ALL messages.
(function () {
  var input = document.getElementById('msgSearch'), clear = document.getElementById('msgSearchClear'),
      box = document.getElementById('msgList'), FILTER = <?= json_encode($filter) ?>, timer = null, ctrl = null;
  if (!input || !box) return;
  function run() {
    var q = input.value.trim();
    clear.hidden = !q;
    if (ctrl) ctrl.abort();
    ctrl = new AbortController();
    box.classList.add('loading');
    fetch('messages.php?ajax=1&filter=' + encodeURIComponent(FILTER) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: ctrl.signal })
      .then(function (r) {
        if (r.headers.get('X-Msg-List') !== '1') { location.reload(); return null; }   // session expired etc.
        return r.text();
      })
      .then(function (html) {
        if (html === null) return;
        box.innerHTML = html;
        box.classList.remove('loading');
        history.replaceState(null, '', 'messages.php?filter=' + encodeURIComponent(FILTER) + (q ? '&q=' + encodeURIComponent(q) : ''));
      })
      .catch(function (e) { if (e.name !== 'AbortError') box.classList.remove('loading'); });
  }
  input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 250); });
  input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { input.value = ''; run(); } });
  clear.addEventListener('click', function () { input.value = ''; run(); input.focus(); });
})();
</script>
<?php endif; ?>
<script>
// Open the conversation scrolled to the newest message.
(function(){ var t=document.getElementById('msgThread'); if(t) t.scrollTop=t.scrollHeight; })();
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
<script src="../assets/js/message-tts.js?v=1"></script>
<script src="../assets/js/voice-record.js?v=1"></script>
</body></html>
