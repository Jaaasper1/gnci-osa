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

    if (!$m) {
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
    <link rel="stylesheet" href="../assets/css/messages.css?v=1">
    <link rel="stylesheet" href="../assets/css/messages-staff.css?v=2">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<?php
$isAdmin = ($_SESSION['user_type'] ?? '') === 'admin';
$base = $isAdmin ? '../admin/' : '';   // admins get the full admin menu, staff the OSA menu
?>
<nav class="topnav"><button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name"><?= $isAdmin ? 'GNC-OSA Admin' : 'GNC-OSA Portal' ?></div><div class="sub"><?= $isAdmin ? 'Administrator' : 'OSA Staff' ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><button class="notif-btn" onclick="location.href='<?= $base ?>notifications.php'"><i class="ti ti-bell"></i><?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?></button><div class="avatar"><?= strtoupper(substr($_SESSION['name'], 0, 2)) ?></div><span class="uname"><?= e($_SESSION['name']) ?></span>
    </div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<?php if ($isAdmin): ?>
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
    <a class="sitem" href="../admin/staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a>
    <a class="sitem" href="../admin/audit-log.php"><i class="ti ti-history"></i> <?= t('sidebar.audit_log', 'Audit Log') ?></a>
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
    <div class="page-header">
        <div><h1><?= t('ui.messages_41de', 'Messages') ?></h1><p><?= t('ui.non_complaint_messages_from_studen_bfc4', 'Non-complaint messages from students') ?></p></div>
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
            <form method="POST" style="margin:0;">
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
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="message_id" value="<?= (int)$thread['id'] ?>">
            <div class="form-group">
                <label><?= t('ui.reply_to_student_829c', 'Reply to student') ?></label>
                <textarea name="body" class="form-control" rows="2" maxlength="2000" placeholder="<?= htmlspecialchars(t('auto.write_your_reply_c2f1', 'Write your reply...'), ENT_QUOTES) ?>"></textarea>
            </div>
            <div class="voice-row"><button type="button" class="btn btn-outline btn-sm voice-btn" data-voice-btn><i class="ti ti-microphone"></i> <span>Voice message</span></button><span class="voice-status" data-voice-status></span><input type="file" name="voice" accept="audio/*" hidden data-voice-input></div>
            <div class="msg-reply-actions"><button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-send"></i> <?= t('ui.send_reply_662f', 'Send reply') ?></button></div>
        </form>
    </div>
<?php endif; ?>

</div></div>

<?php if (!$inConvo): ?>
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
