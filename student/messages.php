<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/messages.php';
requireStudent();
ensureMessageTables($pdo);

$student_id = (int)$_SESSION['user_id'];
$notifCount = getUnreadNotifCount($pdo, $student_id);
$election   = getAnyActiveOrRecentElection($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'new') {
        $body = trim($_POST['body'] ?? '');
        // One ongoing conversation: if a thread already exists, the message goes into it as a reply.
        $existing = msgListForStudent($pdo, $student_id);
        if ($existing) {
            $tid = (int)$existing[0]['id'];
            if ($body === '' && !msgHasVoiceUpload()) {
                $error = t('msg.please_write_your_message_or_record_a_vo_eabf', 'Please write your message or record a voice message.');
            } elseif (mb_strlen($body) > 2000) {
                $error = t('msg.the_message_must_be_2000_characters_or_l_e0d8', 'The message must be 2000 characters or less.');
            } else {
                $voice = msgSaveUploadedVoice();
                if ($voice['error']) {
                    $error = $voice['error'];
                } else {
                    msgAddReply($pdo, $tid, 'student', $student_id, $_SESSION['name'], $body, $voice['file']);
                    auditLog($pdo, 'reply', 'message', $tid, [], ['by' => 'student', 'voice' => (bool)$voice['file']]);
                    msgNotifyStaff($pdo, 'New message from ' . $_SESSION['name'] . ' on: ' . $existing[0]['subject'], 'messages.php?id=' . $tid);
                    header('Location: messages.php?id=' . $tid . '&sent=1'); exit;
                }
            }
        } else {
        if ($body === '' && !msgHasVoiceUpload()) {
            $error = t('msg.please_write_your_message_or_record_a_vo_eabf', 'Please write your message or record a voice message.');
        } elseif (mb_strlen($body) > 2000) {
            $error = t('msg.the_message_must_be_2000_characters_or_l_e0d8', 'The message must be 2000 characters or less.');
        } else {
            $voice = msgSaveUploadedVoice();
            if ($voice['error']) {
                $error = $voice['error'];
            } else {
                // Subject is taken from the start of the message (or "Voice message").
                $subject = $body !== '' ? mb_substr(preg_replace('/\s+/', ' ', $body), 0, 60) : 'Voice message';
                $id = msgStartThread($pdo, $student_id, $subject, $body, $_SESSION['name'], $voice['file']);
                msgNotifyStaff($pdo, 'New message from ' . $_SESSION['name'] . ': ' . $subject, 'messages.php?id=' . $id);
                header('Location: messages.php?id=' . $id . '&sent=1'); exit;
            }
        }
        }   // end: no existing thread
    }

    if ($action === 'reply') {
        $tid  = (int)($_POST['message_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        $thread = msgGetOwned($pdo, $tid, $student_id);
        if (!$thread) {
            $error = t('msg.message_not_found_cb53', 'Message not found.');
        } elseif ($body === '' && !msgHasVoiceUpload()) {
            $error = t('msg.please_write_a_reply_or_record_a_voice_m_8bec', 'Please write a reply or record a voice message.');
        } elseif (mb_strlen($body) > 2000) {
            $error = t('msg.reply_must_be_2000_characters_or_less_c5e4', 'Reply must be 2000 characters or less.');
        } else {
            $voice = msgSaveUploadedVoice();
            if ($voice['error']) {
                $error = $voice['error'];
            } else {
                msgAddReply($pdo, $tid, 'student', $student_id, $_SESSION['name'], $body, $voice['file']);
                auditLog($pdo, 'reply', 'message', $tid, [], ['by' => 'student', 'voice' => (bool)$voice['file']]);
                msgNotifyStaff($pdo, 'New reply from ' . $_SESSION['name'] . ' on: ' . $thread['subject'], 'messages.php?id=' . $tid);
                header('Location: messages.php?id=' . $tid); exit;
            }
        }
    }
}

$list = msgListForStudent($pdo, $student_id);
// One ongoing conversation: opening Messages goes straight to it.
if (!isset($_GET['id']) && !isset($_GET['new']) && $list) {
    header('Location: messages.php?id=' . (int)$list[0]['id']); exit;
}

$viewId  = (int)($_GET['id'] ?? 0);
$thread  = $viewId ? msgGetOwned($pdo, $viewId, $student_id) : null;
$replies = [];
if ($thread) {
    msgMarkRead($pdo, $viewId, 'student');
    $replies = msgReplies($pdo, $viewId);
}
$composing = !$thread && !$list;
$staffName = 'OSA Office';
foreach ($replies as $r) {
    if ($r['sender_role'] === 'staff') $staffName = $r['sender_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.messages', 'Messages') ?> — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <link rel="stylesheet" href="../assets/css/messages.css?v=1">
    <link rel="stylesheet" href="../assets/css/messages-student.css?v=1">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub"><?= t('ui.student_portal_5703', 'Student Portal') ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar"><?= strtoupper(substr($_SESSION['name'], 0, 2)) ?></div><span class="uname"><?= e($_SESSION['name']) ?></span>
    </div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a>
    <a class="sitem" href="help.php"><i class="ti ti-book"></i> <?= t('sidebar.osa_guide', 'OSA Guide') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <?php if ($election): ?>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="voting.php"><i class="ti ti-checkbox"></i> <?= t('sidebar.vote_now', 'Vote Now') ?></a>
    <?php endif; ?>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>

<div class="main"><div class="content">
    <div class="page-header">
        <div><h1><?= t('ui.messages_41de', 'Messages') ?></h1><p><?= t('ui.send_a_message_to_the_osa_office_f_f201', 'Send a message to the OSA office. For concerns that need tracking, use My Complaints.') ?></p></div>
    </div>

    <?php if (isset($_GET['sent'])): ?><div class="alert alert-success"><i class="ti ti-check"></i> <?= t('ui.your_message_was_sent_to_the_osa_o_b1cd', 'Your message was sent to the OSA office.') ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= e($error) ?></div><?php endif; ?>

    <div class="msg-list-head"><?= t('ui.your_messages_ceb6', 'Your messages (') ?><?= count($list) ?>)</div>
    <div class="msg-wrap">
        <div>
                        <?php if (!$list): ?>
                <div class="msg-card msg-empty"><i class="ti ti-inbox"></i><?= t('ui.no_messages_yet_5b9c', 'No messages yet.') ?></div>
            <?php endif; ?>
            <?php foreach ($list as $m): ?>
                <a class="msg-item <?= $thread && (int)$thread['id'] === (int)$m['id'] ? 'active' : '' ?> <?= $m['student_unread'] ? 'unread' : '' ?>" href="messages.php?id=<?= (int)$m['id'] ?>">
                    <div class="msg-subj"><?= tx('OSA Office') ?>
                        <?php if ($m['student_unread']): ?><span class="msg-pill new"><?= t('ui.new_reply_7036', 'New reply') ?></span><?php endif; ?>
                        <?php if ($m['status'] === 'closed'): ?><span class="msg-pill closed"><?= t('ui.closed_03f4', 'Closed') ?></span><?php endif; ?>
                    </div>
                    <div class="msg-sub"><?= e(tlDate('M j, Y g:i A', strtotime($m['last_activity']))) ?></div>
                </a>
            <?php endforeach; ?>
        </div>

        <div>
        <?php if ($thread): ?>
            <div class="msg-card">
                <div class="msg-head"><div><div class="msg-title"><?= e($staffName === 'OSA Office' ? tx('OSA Office') : $staffName) ?></div><?php if ($staffName !== 'OSA Office'): ?><div class="msg-sub"><?= t('auto.osa_office_8fa9', 'OSA Office') ?></div><?php endif; ?></div><span class="msg-pill <?= $thread['status'] === 'closed' ? 'closed' : 'open' ?>"><?= $thread['status'] === 'closed' ? tx('Closed') : tx('Open') ?></span></div>
                <div class="msg-thread">
                <?php foreach ($replies as $r): $mine = $r['sender_role'] === 'student'; ?>
                    <div class="msg-row <?= $mine ? 'mine' : 'theirs' ?>">
                        <div class="msg-meta"><?= $r['sender_role'] === 'staff' ? e($r['sender_name']) . ' (OSA)' : e($r['sender_name']) ?> · <?= e(tlDate('M j, Y g:i A', strtotime($r['created_at']))) ?></div>
                        <?php if (!empty($r['audio_file'])): ?><audio class="msg-audio" controls preload="none" src="../voice.php?id=<?= (int)$r['id'] ?>"></audio><?php endif; ?><?php if ($r['body'] !== ''): ?><div class="msg-bubble"><?= e($r['body']) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>

                <?php if ($thread['status'] === 'closed'): ?>
                    <div class="msg-closed-note"><?= t('ui.this_conversation_is_closed_sendin_7d3e', 'This conversation is closed. Sending a reply will reopen it.') ?></div>
                <?php endif; ?>

                <form method="POST" class="msg-reply" enctype="multipart/form-data" data-voice-form>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="message_id" value="<?= (int)$thread['id'] ?>">
                    <div class="form-group">
                        <label><?= t('ui.reply_25d8', 'Reply') ?></label>
                        <textarea name="body" class="form-control" rows="3" maxlength="2000" placeholder="<?= htmlspecialchars(t('auto.write_your_reply_c2f1', 'Write your reply...'), ENT_QUOTES) ?>"></textarea>
                    </div>
                    <div class="voice-row"><button type="button" class="btn btn-outline btn-sm voice-btn" data-voice-btn><i class="ti ti-microphone"></i> <span><?= tx('Voice message') ?></span></button><span class="voice-status" data-voice-status></span><input type="file" name="voice" accept="audio/*" hidden data-voice-input></div>
                    <div class="msg-reply-actions"><button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-send"></i> <?= t('ui.send_reply_662f', 'Send reply') ?></button></div>
                </form>
            </div>

        <?php elseif ($composing || !$list): ?>
            <div class="msg-card">
                <div class="msg-head"><div class="msg-title"><i class="ti ti-pencil-plus"></i> <?= t('ui.new_message_b55a', 'New message') ?></div></div>
                <form method="POST" enctype="multipart/form-data" data-voice-form>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="new">
                    <div class="form-group">
                        <label><?= t('ui.message_4c2a', 'Message') ?></label>
                        <textarea name="body" class="form-control" rows="6" maxlength="2000" placeholder="<?= htmlspecialchars(t('auto.write_your_message_to_the_osa_office_2505', 'Write your message to the OSA office...'), ENT_QUOTES) ?>" autofocus></textarea>
                    </div>
                    <div class="voice-row"><button type="button" class="btn btn-outline btn-sm voice-btn" data-voice-btn><i class="ti ti-microphone"></i> <span><?= tx('Voice message') ?></span></button><span class="voice-status" data-voice-status></span><input type="file" name="voice" accept="audio/*" hidden data-voice-input></div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-send"></i> <?= t('ui.send_to_osa_office_6db9', 'Send to OSA Office') ?></button>
                </form>
            </div>

        <?php else: ?>
            <div class="msg-card msg-empty"><i class="ti ti-messages"></i><?= t('ui.select_a_message_to_read_it_1bdb', 'Select a message to read it.') ?></div>
        <?php endif; ?>
        </div>
    </div>
</div></div>

<script src="../assets/js/sidebar.js?v=5"></script>
<script src="../assets/js/message-tts.js?v=1"></script>
<script src="../assets/js/voice-record.js?v=1"></script>
<?php include __DIR__.'/../includes/ask-osa-widget.php'; ?>
</body></html>