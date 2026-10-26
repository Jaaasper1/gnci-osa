<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/osa-assistant.php';
requireStudent();

$student_id = (int)$_SESSION['user_id'];
$notifCount = getUnreadNotifCount($pdo, $student_id);
$election   = getAnyActiveOrRecentElection($pdo);
$error = '';

// Conversation lives in the session only (nothing stored in the database).
$chat = $_SESSION['osa_ai_chat'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'clear') {
        unset($_SESSION['osa_ai_chat']);
        header('Location: assistant.php'); exit;
    }

    if ($action === 'ask') {
        $q = trim($_POST['question'] ?? '');
        if ($q === '') {
            $error = t('msg.please_type_your_question_a232', 'Please type your question.');
        } elseif (mb_strlen($q) > 1500) {
            $error = t('msg.your_question_is_too_long_max_1500_chara_bf53', 'Your question is too long (max 1500 characters).');
        } else {
            $answer = osaAssistantAnswer($q);
            $chat[] = ['role' => 'user', 'text' => $q];
            $chat[] = $answer === null
                ? ['role' => 'escalate', 'text' => "I couldn't find an answer to this in the OSA FAQ. You can send your question to the OSA office."]
                : ['role' => 'assistant', 'text' => $answer];
            $_SESSION['osa_ai_chat'] = array_slice($chat, -40);
            header('Location: assistant.php'); exit;   // PRG: no resubmit on refresh
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.ask_osa', 'Ask OSA') ?> — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <link rel="stylesheet" href="../assets/css/assistant.css?v=4">
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
    <a class="sitem" href="help.php"><i class="ti ti-book"></i> <?= t('sidebar.osa_guide', 'OSA Guide') ?></a>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a>
    <a class="sitem active" href="assistant.php"><i class="ti ti-message-question"></i> <?= t('sidebar.ask_osa', 'Ask OSA') ?></a>
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

<div class="main"><div class="content ai-page<?= $chat ? '' : ' ai-empty' ?>">
    <div class="ai-top">
        <?php if ($chat): ?>
        <form method="POST" class="ai-clear">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="ai-newchat"><i class="ti ti-plus"></i> <?= t('ui.new_chat_a917', 'New chat') ?></button>
        </form>
        <?php endif; ?>
    </div>

    <div class="ai-thread">
        <?php if (!$chat): ?>
            <div class="ai-hero">
                <img class="ai-hero-logo" src="../assets/img/osa-logo.jpg" alt="OSA Logo">
                <h2><?= t('ui.hi_bb37', 'Hi,') ?> <?= e($_SESSION['name']) ?></h2>
                <p><?= t('ui.what_would_you_like_to_know_about__447e', 'What would you like to know about the OSA?') ?></p>
                <p class="ai-note"><?= t('ui.answers_come_from_the_osa_faq_only_be02', 'Answers come from the OSA FAQ only. If your question isn\'t covered, you can send it to the office.') ?></p>
            </div>
        <?php else: foreach ($chat as $m): ?>
            <?php if ($m['role'] === 'user'): ?>
                <div class="ai-row mine"><div class="ai-bubble"><?= nl2br(e($m['text'])) ?></div></div>
            <?php elseif ($m['role'] === 'assistant'): ?>
                <div class="ai-row theirs">
                    <img class="ai-avatar" src="../assets/img/osa-logo.jpg" alt="">
                    <div class="ai-answer"><?= nl2br(e($m['text'])) ?></div>
                </div>
            <?php else: ?>
                <div class="ai-row theirs">
                    <img class="ai-avatar" src="../assets/img/osa-logo.jpg" alt="">
                    <div class="ai-answer ai-escalate">
                        <?= e($m['text']) ?>
                        <div><a class="ai-send-office" href="messages.php"><i class="ti ti-send"></i> <?= t('ui.send_to_osa_office_6db9', 'Send to OSA Office') ?></a></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i> <?= e($error) ?></div><?php endif; ?>

    <form method="POST" class="ai-composer">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="ask">
        <textarea name="question" rows="1" maxlength="1500" placeholder="<?= htmlspecialchars(t('auto.ask_about_the_osa_a1a2', 'Ask about the OSA...'), ENT_QUOTES) ?>" required></textarea>
        <button type="submit" class="ai-send" aria-label="<?= t('ui.send_9496', 'Send') ?>"><i class="ti ti-arrow-up"></i></button>
    </form>
</div></div>

<script src="../assets/js/sidebar.js?v=5"></script>
<script>
  const ta = document.querySelector('.ai-composer textarea');
  ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; });
  ta.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ta.form.requestSubmit(); }
  });
  const thread = document.querySelector('.ai-thread');
  thread.scrollTop = thread.scrollHeight;
</script>
</body>
</html>
