<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/osa-faq.php';
requireStudent();

$student_id = (int)$_SESSION['user_id'];
$notifCount = getUnreadNotifCount($pdo, $student_id);
$election   = getAnyActiveOrRecentElection($pdo);
$faq        = osaFaqEntries();   // only finished answers (TODO entries are skipped)

// How-to guides. Each: title, icon, short intro, and numbered steps.
$guides = [
    [
        'title' => 'Submit a complaint',
        'icon'  => 'ti-message-report',
        'intro' => 'Use this for concerns that need to be tracked by the OSA.',
        'steps' => [
            'Click <b>New Complaint</b> in the sidebar.',
            'Choose a <b>Category</b> and write a short <b>Complaint Title</b>.',
            'Describe the issue in <b>Details</b>. Attach a file if it helps (optional).',
            'Tick <b>Submit anonymously</b> if you do not want your name shown (optional).',
            'Click <b>Submit Complaint</b>. Track the status anytime in <b>My Complaints</b>.',
        ],
    ],
    [
        'title' => 'Send a message to the OSA',
        'icon'  => 'ti-messages',
        'intro' => 'Use Messages for questions and inquiries that do not need a complaint.',
        'steps' => [
            'Click <b>Messages</b> in the sidebar.',
            'Type your message, or press <b>Voice message</b> to record one.',
            'Click <b>Send to OSA Office</b>.',
            'Replies from the office appear in your thread. Check <b>Notifications</b> for new replies.',
        ],
    ],
    [
        'title' => 'Vote in the election',
        'icon'  => 'ti-checkbox',
        'intro' => 'The Vote Now link only appears while an election is active.',
        'steps' => [
            'Look for <b>Vote Now</b> in the sidebar, under Election.',
            'Review the candidates and choose your votes.',
            'Confirm your choices to submit your ballot.',
        ],
    ],
    [
        'title' => 'See events',
        'icon'  => 'ti-calendar-event',
        'intro' => 'Find upcoming and past OSA activities.',
        'steps' => [
            'Click <b>Events</b> in the sidebar.',
            'Browse the list and open an event for details.',
        ],
    ],
    [
        'title' => 'Check notifications',
        'icon'  => 'ti-bell',
        'intro' => 'Updates on your complaints, messages, and the election.',
        'steps' => [
            'Click <b>Notifications</b> in the sidebar.',
            'A red number shows how many unread notifications you have.',
        ],
    ],
    [
        'title' => 'Change your account settings',
        'icon'  => 'ti-settings',
        'intro' => 'Update your account details and preferences.',
        'steps' => [
            'Click <b>Settings</b> in the sidebar.',
            'Make your changes, then save.',
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.osa_guide', 'OSA Guide') ?> — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <link rel="stylesheet" href="../assets/css/help.css?v=1">
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
    <a class="sitem active" href="help.php"><i class="ti ti-book"></i> <?= t('sidebar.osa_guide', 'OSA Guide') ?></a>
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

<div class="main"><div class="content help-page">
    <header class="help-hero">
        <img src="../assets/img/osa-logo.jpg" alt="Office of Student Affairs logo" class="help-logo">
        <div>
            <h1><?= t('ui.osa_guide_7a84', 'OSA Guide') ?></h1>
            <p><?= t('ui.how_to_use_the_portal_and_answers__bd09', 'How to use the portal, and answers to common questions about the Office of Student Affairs.') ?></p>
        </div>
    </header>

    <div class="help-search">
        <i class="ti ti-search"></i>
        <input type="search" id="helpSearch" placeholder="<?= t('ui.search_guides_and_questions_b961', 'Search guides and questions...') ?>" aria-label="<?= t('ui.search_1334', 'Search') ?>">
    </div>

    <section class="help-section">
        <h2><?= t('ui.how_to_use_the_portal_4f2f', 'How to use the portal') ?></h2>
        <div class="help-grid">
            <?php foreach ($guides as $g): ?>
            <details class="help-card" data-search="<?= e(strtolower(tx($g['title']) . ' ' . tx($g['intro']))) ?>">
                <summary>
                    <span class="help-icon"><i class="ti <?= e($g['icon']) ?>"></i></span>
                    <span class="help-card-text">
                        <span class="help-card-title"><?= e(tx($g['title'])) ?></span>
                        <span class="help-card-intro"><?= e(tx($g['intro'])) ?></span>
                    </span>
                    <i class="ti ti-chevron-down help-caret"></i>
                </summary>
                <ol class="help-steps">
                    <?php foreach ($g['steps'] as $step): ?><li><?= tx($step) ?></li><?php endforeach; ?>
                </ol>
            </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="help-section">
        <h2><?= t('ui.frequently_asked_questions_af2a', 'Frequently asked questions') ?></h2>
        <?php if (!$faq): ?>
            <p class="help-empty"><?= t('ui.more_answers_are_coming_soon_58d2', 'More answers are coming soon.') ?></p>
        <?php else: ?>
        <div class="help-faq">
            <?php foreach ($faq as $item): ?>
            <details class="help-faq-item" data-search="<?= e(strtolower(tx($item['q']) . ' ' . tx($item['a']))) ?>">
                <summary><?= e(tx($item['q'])) ?><i class="ti ti-chevron-down help-caret"></i></summary>
                <div class="help-faq-answer"><?= nl2br(e(tx($item['a']))) ?></div>
            </details>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="help-contact">
        <div>
            <strong><?= t('ui.need_a_reply_from_osa_staff_42fb', 'Need a reply from OSA staff?') ?></strong>
            <p><?= t('ui.send_a_message_to_the_osa_admin_or_a47e', 'Send a message to the OSA admin or staff. They reply in your message thread, and this is not an automatic answer.') ?></p>
        </div>
        <a class="help-contact-btn" href="messages.php"><i class="ti ti-messages"></i> <?= t('ui.message_osa_staff_9645', 'Message OSA Staff') ?></a>
    </section>

    <p class="help-empty" id="helpNoResults" hidden><?= t('ui.no_results_found_try_another_word__9439', 'No results found. Try another word, or message the OSA office.') ?></p>
</div></div>

<script src="../assets/js/sidebar.js?v=5"></script>
<script>
  // Simple search: hides guide cards and FAQ items that do not match the text.
  const input = document.getElementById('helpSearch');
  const items = document.querySelectorAll('[data-search]');
  const none  = document.getElementById('helpNoResults');
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    let shown = 0;
    items.forEach(el => {
      const match = !q || el.dataset.search.includes(q);
      el.hidden = !match;
      if (match) shown++;
    });
    none.hidden = shown > 0;
  });
</script>
</body>
</html>
