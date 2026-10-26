<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/mailer.php';
require_once '../includes/push.php';
requireStudent();

$student_id = $_SESSION['user_id'];
$notifCount = getUnreadNotifCount($pdo, $student_id);
$election = getAnyActiveOrRecentElection($pdo);
$success = $error = '';
$wait_seconds = 0;

// ── Spam protection: 3-minute cooldown between complaints ──
$COOLDOWN = 180; // 3 minutes in seconds
$lastStmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed FROM complaints WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
$lastStmt->execute([$student_id]);
$lastRow = $lastStmt->fetch();

if ($lastRow) {
    $elapsed = (int)$lastRow['elapsed'];
    $wait_seconds = max(0, $COOLDOWN - $elapsed);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($wait_seconds > 0) {
        $error = tx('Please wait before submitting another complaint. See the timer below.');
    } else {
        $category    = $_POST['category'] ?? '';
        $title       = trim($_POST['title'] ?? '');
        $message     = trim($_POST['message'] ?? '');
        $is_anon     = isset($_POST['is_anonymous']) ? 1 : 0;
        $attachment  = null;

        if (!$category || !$title || !$message) {
            $error = t('msg.please_fill_in_all_fields_18f5', 'Please fill in all fields.');
        } else {
            if (!empty($_FILES['attachment']['name'])) {
                $upload = uploadAttachment($_FILES['attachment']);
                if (isset($upload['error'])) { $error = $upload['error']; }
                else { $attachment = $upload['filename']; }
            }
            if (!$error) {
                $stmt = $pdo->prepare("INSERT INTO complaints (student_id, category, title, message, attachment, is_anonymous) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$student_id, $category, $title, $message, $attachment, $is_anon]);
                $newComplaintId = (int)$pdo->lastInsertId();
                $success = t('msg.your_complaint_has_been_submitted_we_wil_ca91', 'Your complaint has been submitted! We will look into it.');
                $wait_seconds = $COOLDOWN;

                // In-app notification for every active admin (shows up in their bell/notifications page)
                $submitterName = $is_anon ? 'An anonymous student' : ($_SESSION['name'] ?? 'A student');
                $adminNotifMsg = "New complaint (#{$newComplaintId}, {$category}) submitted by {$submitterName}: \"{$title}\"";
                $admins = $pdo->query("SELECT id FROM osa_staff WHERE role='admin' AND is_active=1")->fetchAll();
                foreach ($admins as $adm) {
                    addStaffNotification($pdo, $adm['id'], $adminNotifMsg, "view-complaint.php?id={$newComplaintId}");
                }

                // Notify admins in real time via push notification
                sendAdminPush(
                    'New Complaint: ' . $category,
                    ($is_anon ? 'An anonymous student' : ($_SESSION['name'] ?? 'A student')) . ' submitted: "' . $title . '"',
                    '/admin/view-complaint.php?id=' . $newComplaintId
                );

                // ── Email notification to admin/OSA staff ──
                sendComplaintNotification($pdo, [
                    'title'        => $title,
                    'category'     => $category,
                    'is_anonymous' => $is_anon,
                    'student_name' => $_SESSION['name'],
                ], $newComplaintId);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.new_complaint', 'New Complaint') ?> — GNC-OSA Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub"><?= t('nav.student_portal', 'Student Portal') ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div><span class="uname"><?= htmlspecialchars($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a><a class="sitem" href="help.php"><i class="ti ti-book"></i> <?= t('sidebar.osa_guide', 'OSA Guide') ?></a>
    <a class="sitem active" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
    <?php if ($election): ?>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="voting.php"><i class="ti ti-checkbox"></i> <?= t('sidebar.vote_now', 'Vote Now') ?></a>
    <?php endif; ?><div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header"><h1><?= t('newcomplaint.title', 'Submit a Complaint') ?></h1><p><?= t('newcomplaint.subtitle', 'Let the OSA know about your concern') ?></p></div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="ti ti-check"></i><?= $success ?> <a href="complaints.php"><?= t('ui.view_my_complaints_31b0', 'View my complaints') ?></a></div>
    <?php endif; ?>
    <?php if ($error && $wait_seconds <= 0): ?>
        <div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($wait_seconds > 0): ?>
    <div class="alert alert-warning" id="cooldown-banner" style="margin-bottom:16px;">
        <i class="ti ti-clock" style="font-size:20px;flex-shrink:0;"></i>
        <div>
            <strong><?= t('ui.please_wait_before_submitting_agai_c975', 'Please wait before submitting again.') ?></strong><br>
            <?= t('ui.you_can_submit_another_complaint_i_035d', 'You can submit another complaint in') ?> <strong id="countdown-timer"></strong>.
        </div>
    </div>
    <script>
        var remaining = <?= (int)$wait_seconds ?>;
        function updateTimer() {
            var mins = Math.floor(remaining / 60);
            var secs = remaining % 60;
            var text = '';
            if (mins > 0) text += mins + ' minute' + (mins > 1 ? 's' : '') + ' and ';
            text += secs + ' second' + (secs !== 1 ? 's' : '');
            var el = document.getElementById('countdown-timer');
            if (el) el.textContent = text;

            var btn = document.getElementById('submit-btn');
            if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed'; }

            if (remaining <= 0) {
                var banner = document.getElementById('cooldown-banner');
                if (banner) banner.style.display = 'none';
                if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; }
                return;
            }
            remaining--;
            setTimeout(updateTimer, 1000);
        }
        updateTimer();
    </script>
    <?php endif; ?>

    <div class="card">
<div class="alert alert-warning" style="border-radius:10px; padding:16px 20px; margin-bottom:16px;">
    <p style="font-weight:600; margin:0 0 10px 0;">
        <i class="ti ti-lock"></i> <?= t('ui.reminders_d594', 'Reminders') ?>
    </p>
    <ul style="margin:0; padding-left:18px;">
        <li><?= t('ui.anonymous_submissions_will_be_acce_3629', 'Anonymous submissions will be accepted but cannot be given a formal resolution due to the absence of identifiable parties.') ?></li>
        <li><?= t('ui.identified_submissions_will_be_han_1cba', 'Identified submissions will be handled with 100% confidentiality and addressed only by the Grievance Committee under the Office of Student Affairs.') ?></li>
    </ul>
</div>
        <form method="POST" enctype="multipart/form-data" class="confirm-submit-form"
              data-confirm-title="<?= htmlspecialchars(t('auto.submit_this_complaint_e246', 'Submit this complaint?'), ENT_QUOTES) ?>"
              data-confirm-message="<?= htmlspecialchars(t('msg.please_make_sure_your_details_are_correc_0d22', 'Please make sure your details are correct before submitting. Once submitted, OSA will be notified and you won\'t be able to edit it.'), ENT_QUOTES) ?>"
              data-confirm-btn="<?= htmlspecialchars(t('msg.yes_submit_b882', 'Yes, Submit'), ENT_QUOTES) ?>">
            <div class="form-grid-2">
                <div class="form-group">
                    <label><?= t('ui.category_39fe', 'Category *') ?></label>
                    <select name="category" class="form-control" required>
                        <option value=""><?= t('ui.select_1f94', '— Select —') ?></option>
                        <option value="Department-related Issue"><?= tx('Department-related Issue') ?></option>
                        <option value="Student organization officer concern"><?= tx('Student organization officer concern') ?></option>
                        <option value="Mismanagement of organization duties/resources"><?= tx('Mismanagement of organization duties/resources') ?></option>
                        <option value="Unfair treatment / lack of transparency by officers"><?= tx('Unfair treatment / lack of transparency by officers') ?></option>
                        <option value="Others"><?= tx('Others') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label><?= t('ui.complaint_title_1f94', 'Complaint Title *') ?></label>
                    <input type="text" name="title" class="form-control" placeholder="<?= t('ui.short_title_1b99', 'Short title') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label><?= t('ui.details_41ce', 'Details *') ?></label>
                <textarea name="message" class="form-control" placeholder="<?= htmlspecialchars(t('auto.describe_your_complaint_clearly_detailed_f5f0', 'Describe your complaint clearly/detailed...'), ENT_QUOTES) ?>" required></textarea>
            </div>
            <div class="form-group">
                <label><?= t('ui.attachment_optional_4861', 'Attachment (optional)') ?></label>
                <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                <small style="color:#9CA3AF;font-size:11px;margin-top:4px;display:block;"><?= t('ui.allowed_jpg_png_pdf_doc_max_5mb_3c84', 'Allowed: JPG, PNG, PDF, DOC · Max 5MB') ?></small>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <label class="form-check"><input type="checkbox" name="is_anonymous"> <?= t('ui.submit_anonymously_2b3c', 'Submit anonymously') ?></label>
                <button type="submit" id="submit-btn" class="btn btn-primary"><i class="ti ti-send"></i> <?= t('ui.submit_complaint_4e97', 'Submit Complaint') ?></button>
            </div>
        </form>
    </div>
</div></div>
<script src="../assets/js/sidebar.js?v=5"></script>
<?php include __DIR__.'/../includes/ask-osa-widget.php'; ?>
</body>
</html>
