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
    } elseif (in_array($action, ['bulk_delete', 'bulk_close', 'bulk_read', 'bulk_unread'], true)) {
        $ids = array_slice(array_values(array_filter(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))))), 0, 200);
        $n = 0;
        foreach ($ids as $bid) {
            $bm = msgGet($pdo, $bid);
            if (!$bm) continue;
            if ($action === 'bulk_delete') {
                auditLog($pdo, 'delete', 'message', $bid, ['student_id' => (int)$bm['student_id'], 'subject' => $bm['subject'], 'status' => $bm['status']], []);
                msgDeleteThread($pdo, $bid);
            } elseif ($action === 'bulk_close') {
                $pdo->prepare("UPDATE osa_messages SET status = 'closed' WHERE id = ?")->execute([$bid]);
                auditLog($pdo, 'close', 'message', $bid, ['status' => $bm['status']], ['status' => 'closed']);
            } else {   // bulk_read / bulk_unread: only changes the staff "unread" flag
                $pdo->prepare("UPDATE osa_messages SET staff_unread = ? WHERE id = ?")->execute([$action === 'bulk_unread' ? 1 : 0, $bid]);
            }
            $n++;
        }
        $bf = in_array($_POST['filter'] ?? '', ['open', 'unread'], true) ? $_POST['filter'] : 'open';
        header('Location: messages.php?filter=' . $bf . '&bulk=' . $n . '&did=' . ['bulk_delete' => 'deleted', 'bulk_close' => 'closed', 'bulk_read' => 'read', 'bulk_unread' => 'unread'][$action]); exit;
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
    } elseif ($action === 'delete') {
        auditLog($pdo, 'delete', 'message', $tid, ['student_id' => (int)$m['student_id'], 'subject' => $m['subject'], 'status' => $m['status']], []);
        msgDeleteThread($pdo, $tid);
        header('Location: messages.php?filter=' . ($m['status'] === 'closed' ? 'closed' : 'open')); exit;
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
        <a class="msgm-item <?= $unread ? 'unread' : '' ?>" data-unread="<?= $unread ? 1 : 0 ?>" data-status="<?= e($m['status']) ?>" href="messages.php?filter=<?= e($filter) ?>&amp;id=<?= (int)$m['id'] ?><?= e($qs) ?>">
            <input type="checkbox" class="msgm-check" data-id="<?= (int)$m['id'] ?>" tabindex="-1" aria-label="Select conversation">
            <span class="msgm-avatar"><?= e(msgInitials($m['first_name'], $m['last_name'])) ?></span>
            <div class="msgm-body">
                <div class="msgm-top">
                    <span class="msgm-name"><?= e($m['first_name'] . ' ' . $m['last_name']) ?><span class="msgm-sid"><?= e($m['student_no']) ?></span></span>
                    <span class="msgm-time"><?= e(date('M j, g:i A', strtotime($m['last_activity']))) ?></span>
                </div>
                <div class="msgm-prev"><?= e($previews[(int)$m['id']] ?? '') ?></div>
            </div>
            <?php if ($unread): ?><span class="msgm-dot" title="<?= t('ui.new_message_b55a', 'New message') ?>"></span><?php endif; ?>
        </a>
    <?php endforeach;
}

$filter = $_GET['filter'] ?? 'open';
if (!in_array($filter, ['open', 'unread'], true)) $filter = 'open';
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
$backFilter = isset($_GET['filter']) ? $filter : 'open';
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
    <link rel="stylesheet" href="../assets/css/messages.css?v=3">
    <link rel="stylesheet" href="../assets/css/messages-staff.css?v=3">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<div class="msgc-overlay" id="msgcModal" aria-hidden="true">
    <div class="msgc-box" role="dialog" aria-modal="true" aria-labelledby="msgcTitle">
        <div class="msgc-icon" id="msgcIcon"><i class="ti ti-trash" id="msgcIconI"></i></div>
        <div class="msgc-title" id="msgcTitle"></div>
        <div class="msgc-desc" id="msgcDesc"></div>
        <div class="msgc-actions">
            <button type="button" class="btn btn-outline" id="msgcCancel"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <button type="button" class="btn" id="msgcOk"></button>
        </div>
    </div>
</div>

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
    <?php if (isset($_GET['bulk'])): ?><div class="alert alert-success"><i class="ti ti-check"></i> <?= (int)$_GET['bulk'] ?> conversation(s) <?= ['closed' => 'marked as closed', 'read' => 'marked as read', 'unread' => 'marked as unread'][$_GET['did'] ?? ''] ?? 'deleted' ?>.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= e($error) ?></div><?php endif; ?>


    <div class="msg-tabs" style="margin-bottom:14px;">
        <a href="messages.php?filter=open" class="<?= $filter === 'open' ? 'on' : '' ?>"><?= t('ui.all_msgs', 'All') ?></a>
        <a href="messages.php?filter=unread" class="<?= $filter === 'unread' ? 'on' : '' ?>"><?= t('ui.unread_c5d1', 'Unread (') ?><?= $unreadTotal ?>)</a>
    </div>

    <div class="msgm-search">
        <i class="ti ti-search"></i>
        <input type="text" id="msgSearch" value="<?= e($q) ?>" placeholder="<?= t('ui.search_name_student_id_or_message__ece8', 'Search name, Student ID, or message text…') ?>" autocomplete="off" maxlength="80" aria-label="<?= t('ui.search_messages_4863', 'Search messages') ?>">
        <button type="button" id="msgSearchClear" aria-label="<?= t('ui.clear_search_9983', 'Clear search') ?>" <?= $q === '' ? 'hidden' : '' ?>><i class="ti ti-x"></i></button>
    </div>

    <div class="msgm-bulkbar" id="bulkBar" hidden>
        <label><input type="checkbox" id="selAll"> Select all</label>
        <span id="selCount">0 selected</span>
        <span style="flex:1"></span>
        <button type="button" id="bulkRead"><i class="ti ti-mail-opened"></i> Mark read</button>
        <button type="button" id="bulkUnread"><i class="ti ti-mail"></i> Mark unread</button>
        <button type="button" id="bulkDelete" class="b-del"><i class="ti ti-trash"></i> Delete</button>
        <button type="button" id="selCancel">Cancel</button>
    </div>
    <form id="bulkForm" method="POST" style="display:none">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="bulkAction" value="">
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <div id="bulkIds"></div>
    </form>

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
            <form method="POST" style="margin:0;<?= empty($thread['id']) ? 'display:none;' : '' ?>" data-msgc-title="Delete Conversation" data-msgc-desc="Delete this whole conversation permanently? This cannot be undone." data-msgc-ok="Delete" data-msgc-kind="danger">
                <?= csrfField() ?>
                <input type="hidden" name="message_id" value="<?= (int)$thread['id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="btn btn-sm" style="background:#991B1B;color:#fff;border:1px solid #991B1B;"><i class="ti ti-trash"></i> <span class="msgm-btn-text">Delete</span></button>
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
  .msgc-overlay{position:fixed;inset:0;background:rgba(17,24,39,.55);display:none;align-items:center;justify-content:center;padding:14px;z-index:2000}
  .msgc-overlay.open{display:flex}
  .msgc-box{background:#fff;border-radius:16px;width:100%;max-width:380px;padding:26px 24px 22px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.25);animation:msgcIn .18s ease}
  @keyframes msgcIn{from{transform:translateY(16px);opacity:0}to{transform:translateY(0);opacity:1}}
  .msgc-icon{width:52px;height:52px;border-radius:50%;background:#FEF2F2;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
  .msgc-icon i{font-size:26px;color:#EF4444}
  .msgc-icon.info{background:#F9EEF0}.msgc-icon.info i{color:#6B1A2A}
  .msgc-title{font-size:16px;font-weight:600;color:#4A0F1B;margin-bottom:6px}
  .msgc-desc{font-size:13px;color:#6B7280;margin-bottom:22px;line-height:1.5}
  .msgc-actions{display:flex;gap:10px;justify-content:center}
  .msgc-actions .btn{flex:1;justify-content:center}
  .msgc-ok-danger{background:#EF4444;color:#fff;border:1px solid #EF4444}
  .msgc-ok-info{background:#6B1A2A;color:#fff;border:1px solid #6B1A2A}
  html[data-theme="dark"] .msgc-box{background:#242526;color:#E4E6EB}
  html[data-theme="dark"] .msgc-title{color:#E4E6EB}
  html[data-theme="dark"] .msgc-desc{color:#B0B3B8}
  .msgm-bulkbar{max-width:720px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;position:sticky;top:0;z-index:20;background:#fff;padding:8px 0 10px;margin:0 0 6px;border-bottom:1px solid #F3F4F6;font-size:13px;color:#374151}
  .msgm-bulkbar[hidden]{display:none}
  .msgm-bulkbar label{display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;cursor:pointer}
  .msgm-bulkbar #selCount{font-weight:600;color:#1F2937}
  .msgm-bulkbar button{font-size:13px;border-radius:8px;padding:6px 12px;cursor:pointer;display:inline-flex;align-items:center;gap:5px;background:#fff;color:#374151;border:1px solid #D1D5DB}
  .msgm-bulkbar button:disabled{opacity:.45;cursor:not-allowed}
  .msgm-bulkbar button:not(:disabled):hover{background:#F3F4F6}
  .msgm-bulkbar .b-del{color:#EF4444;border-color:#FECACA;background:#FEF2F2}
  .msgm-bulkbar .b-del:not(:disabled):hover{background:#FEE2E2}
  .msgm-bulkbar input,.msgm-check{width:20px;height:20px;flex-shrink:0;cursor:pointer;appearance:none;-webkit-appearance:none;border:2px solid #9CA3AF;border-radius:50%;background:#fff center/12px no-repeat;transition:background-color .12s,border-color .12s}
  .msgm-bulkbar input:checked,.msgm-check:checked{background-color:#6B1A2A;border-color:#6B1A2A;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%23fff' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3.5 8.5l3 3 6-7'/%3E%3C/svg%3E")}
  .msgm-bulkbar input:hover,.msgm-check:hover{border-color:#6B1A2A}
  .msgm-check{display:none;margin:0 10px 0 0;align-self:center}
  @media (hover:hover) and (min-width:769px){ .msgm-check{display:block;opacity:0} .msgm-item:hover .msgm-check,.msgm-check:focus-visible{opacity:1} }
  .msgm-list.selecting .msgm-check{display:block;opacity:1}
  .msgm-item{-webkit-touch-callout:none}
  .msgm-list.selecting .msgm-item{cursor:pointer;-webkit-user-select:none;user-select:none}
  .msgm-list.selecting .msgm-item.selected{background:#F9EEF0}
  /* Dark mode: selection bar + selected row */
  html[data-theme="dark"] .msgm-bulkbar{background:#242526;border-bottom-color:#3E4042;color:#E4E6EB}
  html[data-theme="dark"] .msgm-bulkbar label,html[data-theme="dark"] .msgm-bulkbar #selCount{color:#E4E6EB}
  html[data-theme="dark"] .msgm-bulkbar button{background:#3A3B3C;color:#E4E6EB;border-color:#4E4F50}
  html[data-theme="dark"] .msgm-bulkbar button:not(:disabled):hover{background:#4E4F50}
  html[data-theme="dark"] .msgm-bulkbar .b-del{background:#3B1F22;color:#F87171;border-color:#7F1D1D}
  html[data-theme="dark"] .msgm-bulkbar .b-del:not(:disabled):hover{background:#4A2428}
  html[data-theme="dark"] .msgm-bulkbar input,html[data-theme="dark"] .msgm-check{background-color:#242526;border-color:#8A8D91}
  html[data-theme="dark"] .msgm-bulkbar input:checked,html[data-theme="dark"] .msgm-check:checked{background-color:#B84A60;border-color:#B84A60}
  html[data-theme="dark"] .msgm-bulkbar input:hover,html[data-theme="dark"] .msgm-check:hover{border-color:#B84A60}
  html[data-theme="dark"] .msgm-list.selecting .msgm-item.selected{background:#3A2128;border-color:#B84A60}
  html[data-theme="dark"] .msgm-list.selecting .msgm-item.selected .msgm-name{color:#F0F2F5}
  html[data-theme="dark"] .msgm-list.selecting .msgm-item.selected .msgm-prev{color:#C9CCD1}
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
  /* Dark mode: "New message" popup */
  html[data-theme="dark"] .nm-panel{background:#242526;box-shadow:0 20px 50px rgba(0,0,0,.6)}
  html[data-theme="dark"] .nm-top h3{color:#E4E6EB}
  html[data-theme="dark"] .nm-x{background:#3A3B3C;color:#E4E6EB}
  html[data-theme="dark"] .nm-x:hover{background:#4E4F50}
  html[data-theme="dark"] .nm-search input{background:#3A3B3C;border-color:#4E4F50;color:#E4E6EB}
  html[data-theme="dark"] .nm-search input::placeholder{color:#8A8D91}
  html[data-theme="dark"] .nm-chip{background:#3A3B3C;border-color:#4E4F50;color:#E4E6EB}
  html[data-theme="dark"] .nm-chip.on{background:#B84A60;border-color:#B84A60;color:#fff}
  html[data-theme="dark"] .nm-item:hover{background:#3A3B3C}
  html[data-theme="dark"] .nm-nm{color:#E4E6EB}
  html[data-theme="dark"] .nm-sub,html[data-theme="dark"] .nm-more,html[data-theme="dark"] .nm-empty{color:#B0B3B8}
  html[data-theme="dark"] .nm-more{color:#E58BA0}
  html[data-theme="dark"] .nm-av.on::after{border-color:#242526}
  html[data-theme="dark"] .msgm-compose:hover{background:#3A2128}
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
// In-system confirmation popup (replaces the browser's confirm()).
(function () {
  var ov = document.getElementById('msgcModal'); if (!ov) return;
  var tEl = document.getElementById('msgcTitle'), dEl = document.getElementById('msgcDesc'),
      ok = document.getElementById('msgcOk'), cancel = document.getElementById('msgcCancel'),
      ic = document.getElementById('msgcIcon'), icI = document.getElementById('msgcIconI'), cb = null;
  function close() { ov.classList.remove('open'); ov.setAttribute('aria-hidden', 'true'); cb = null; }
  window.msgConfirm = function (title, desc, okLabel, kind, onOk) {
    tEl.textContent = title; dEl.textContent = desc; ok.textContent = okLabel || 'OK';
    var danger = (kind === 'danger');
    ok.className = 'btn ' + (danger ? 'msgc-ok-danger' : 'msgc-ok-info');
    ic.className = 'msgc-icon' + (danger ? '' : ' info');
    icI.className = 'ti ' + (danger ? 'ti-trash' : 'ti-help-circle');
    cb = onOk; ov.classList.add('open'); ov.setAttribute('aria-hidden', 'false'); ok.focus();
  };
  ok.addEventListener('click', function () { var f = cb; close(); if (f) f(); });
  cancel.addEventListener('click', close);
  ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ov.classList.contains('open')) close(); });
  // Any form with data-msgc-title asks first, then submits.
  document.addEventListener('submit', function (e) {
    var f = e.target; if (!f.getAttribute || !f.getAttribute('data-msgc-title')) return;
    e.preventDefault();
    window.msgConfirm(f.getAttribute('data-msgc-title'), f.getAttribute('data-msgc-desc') || '', f.getAttribute('data-msgc-ok') || 'OK', f.getAttribute('data-msgc-kind') || 'info', function () { f.submit(); });
  });
})();
</script>
<script>
// Multi-select (same as Notifications): click the circle on a row (desktop) or long-press a row (phone).
(function () {
  var box = document.getElementById('msgList'), bar = document.getElementById('bulkBar');
  if (!box || !bar) return;
  var all = document.getElementById('selAll'), count = document.getElementById('selCount'),
      form = document.getElementById('bulkForm'), act = document.getElementById('bulkAction'), idsBox = document.getElementById('bulkIds');
  var sel = new Set(), selecting = false, autoExit = false, suppressClick = false;
  function cbs() { return Array.prototype.slice.call(box.querySelectorAll('.msgm-check')); }
  function rowOf(c) { return c.closest('.msgm-item'); }
  function render() {
    var cs = cbs(), present = {};
    cs.forEach(function (c) { present[c.dataset.id] = true; });
    sel.forEach(function (id) { if (!present[id]) sel.delete(id); });   // rows that disappeared (live search)
    var hasUnread = false, hasRead = false;
    cs.forEach(function (c) {
      var on = sel.has(c.dataset.id), it = rowOf(c);
      c.checked = on;
      if (it) it.classList.toggle('selected', on);
      if (on && it) {
        if (it.dataset.unread === '1') hasUnread = true; else hasRead = true;
      }
    });
    var n = sel.size;
    count.textContent = n + ' selected';
    all.checked = n > 0 && n === cs.length;
    all.indeterminate = n > 0 && n < cs.length;
    document.getElementById('bulkRead').disabled   = !hasUnread;
    document.getElementById('bulkUnread').disabled = !hasRead;
    document.getElementById('bulkDelete').disabled = (n === 0);
  }
  function enter(auto) { selecting = true; autoExit = !!auto; box.classList.add('selecting'); bar.hidden = false; render(); }
  function exit()      { selecting = false; autoExit = false; sel.clear(); box.classList.remove('selecting'); bar.hidden = true; render(); }
  function toggle(id, force) {
    id = String(id);
    var on = (typeof force === 'boolean') ? force : !sel.has(id);
    if (on) sel.add(id); else sel.delete(id);
    if (!selecting) enter(true);
    if (selecting && autoExit && sel.size === 0) { exit(); return; }
    render();
  }
  all.addEventListener('change', function () {
    cbs().forEach(function (c) { if (all.checked) sel.add(c.dataset.id); else sel.delete(c.dataset.id); });
    if (autoExit && sel.size === 0) { exit(); return; }
    render();
  });
  document.getElementById('selCancel').addEventListener('click', exit);

  // Clicks: the circle always selects; while selecting, clicking anywhere on a row selects too.
  box.addEventListener('click', function (e) {
    if (suppressClick) { suppressClick = false; e.preventDefault(); return; }
    var it = e.target.closest('.msgm-item'); if (!it) return;
    var c = it.querySelector('.msgm-check'); if (!c) return;
    if (e.target === c) { e.preventDefault(); toggle(c.dataset.id); setTimeout(render, 0); return; }
    if (selecting) { e.preventDefault(); toggle(c.dataset.id); }
  });

  // Long-press on a row (touch / pen) = start selecting
  var timer = null, sx = 0, sy = 0;
  function cancel() { if (timer) { clearTimeout(timer); timer = null; } }
  box.addEventListener('pointerdown', function (e) {
    if (e.pointerType === 'mouse') return;
    var it = e.target.closest('.msgm-item'); if (!it || e.target.closest('input')) return;
    var c = it.querySelector('.msgm-check'); if (!c) return;
    sx = e.clientX; sy = e.clientY; cancel();
    timer = setTimeout(function () {
      timer = null; suppressClick = true;
      if (navigator.vibrate) { try { navigator.vibrate(30); } catch (_) {} }
      toggle(c.dataset.id, true);
    }, 450);
  });
  box.addEventListener('pointermove', function (e) { if (timer && (Math.abs(e.clientX - sx) > 8 || Math.abs(e.clientY - sy) > 8)) cancel(); });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { box.addEventListener(ev, cancel); });
  ['pointerup', 'pointercancel'].forEach(function (ev) {
    box.addEventListener(ev, function () { if (suppressClick) setTimeout(function () { suppressClick = false; }, 350); });
  });
  box.addEventListener('contextmenu', function (e) { if (e.target.closest('.msgm-item') && 'ontouchstart' in window) e.preventDefault(); });

  new MutationObserver(render).observe(box, { childList: true });   // live search re-renders the rows

  function submit(action, title, msg, okLabel, kind) {
    if (!sel.size) return;
    window.msgConfirm(title, msg.replace('{n}', sel.size), okLabel, kind, function () {
      act.value = action; idsBox.innerHTML = '';
      sel.forEach(function (id) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; idsBox.appendChild(i); });
      form.submit();
    });
  }
  document.getElementById('bulkDelete').addEventListener('click', function () { submit('bulk_delete', 'Delete Conversations', 'Delete {n} conversation(s) permanently? This cannot be undone.', 'Delete', 'danger'); });
  document.getElementById('bulkRead').addEventListener('click', function () { submit('bulk_read', 'Mark as Read', 'Mark {n} conversation(s) as read?', 'Mark read', 'info'); });
  document.getElementById('bulkUnread').addEventListener('click', function () { submit('bulk_unread', 'Mark as Unread', 'Mark {n} conversation(s) as unread?', 'Mark unread', 'info'); });
  render();
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
