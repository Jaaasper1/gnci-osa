<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/push.php';
requireStudent();

$id = (int)($_GET['id'] ?? 0);
$student_id = $_SESSION['user_id'];
$notifCount = getUnreadNotifCount($pdo, $student_id);
$election = getAnyActiveOrRecentElection($pdo);

$stmt = $pdo->prepare("SELECT c.*, st.first_name as staff_fn, st.last_name as staff_ln FROM complaints c LEFT JOIN osa_staff st ON c.assigned_to=st.id WHERE c.id=? AND c.student_id=?");
$stmt->execute([$id, $student_id]);
$complaint = $stmt->fetch();
if (!$complaint) { echo 'Not found.'; exit; }

// Fetch existing review
$revStmt = $pdo->prepare("SELECT * FROM complaint_reviews WHERE complaint_id=? AND student_id=?");
$revStmt->execute([$id, $student_id]);
$existing_review = $revStmt->fetch();

$review_success = $review_error = '';

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reaction'])) {
    $reaction = $_POST['reaction'] ?? '';
    $comment  = trim($_POST['review_comment'] ?? '');
    $allowed  = ['😊','👍','😐','👎','😢'];

    if (!in_array($reaction, $allowed)) {
        $review_error = 'Please select a valid reaction.';
    } elseif (!in_array($complaint['status'], ['Resolved','Closed'])) {
        $review_error = 'You can only leave a review on resolved or closed complaints.';
    } else {
        if ($existing_review) {
            $pdo->prepare("UPDATE complaint_reviews SET reaction=?, comment=? WHERE complaint_id=? AND student_id=?")
                ->execute([$reaction, $comment ?: null, $id, $student_id]);
            $review_success = 'Your review has been updated!';
        } else {
            $pdo->prepare("INSERT INTO complaint_reviews (complaint_id, student_id, reaction, comment) VALUES (?,?,?,?)")
                ->execute([$id, $student_id, $reaction, $comment ?: null]);
            $review_success = 'Thank you for your feedback!';
        }

        // Notify the assigned staff member that a review was left
        if (!empty($complaint['assigned_to'])) {
            $reactionLabels2 = ['😊'=>'Satisfied','👍'=>'Good','😐'=>'Neutral','👎'=>'Not Satisfied','😢'=>'Disappointed'];
            addStaffNotification(
                $pdo,
                $complaint['assigned_to'],
                "A student left a review (" . ($reactionLabels2[$reaction] ?? $reaction) . " {$reaction}) on complaint #{$id}.",
                "view-complaint.php?id={$id}"
            );
        }

        // Notify admins in real time via push notification
        $reactionLabels = ['😊'=>'Satisfied','👍'=>'Good','😐'=>'Neutral','👎'=>'Not Satisfied','😢'=>'Disappointed'];
        sendAdminPush(
            'New Review: ' . ($reactionLabels[$reaction] ?? $reaction) . ' ' . $reaction,
            ($_SESSION['name'] ?? 'A student') . ' reviewed complaint "' . $complaint['title'] . '"',
            '/admin/view-complaint.php?id=' . $id
        );
        // Re-fetch review
        $revStmt->execute([$id, $student_id]);
        $existing_review = $revStmt->fetch();
    }
}

// Status badge styles
function sBadge($s) {
    $map = [
        'New'       => ['bg'=>'#DBEAFE','color'=>'#1D4ED8','icon'=>'ti-clock'],
        'In Review' => ['bg'=>'#FEF3C7','color'=>'#D97706','icon'=>'ti-search'],
        'Resolved'  => ['bg'=>'#D1FAE5','color'=>'#065F46','icon'=>'ti-circle-check'],
        'Rejected'  => ['bg'=>'#FEE2E2','color'=>'#991B1B','icon'=>'ti-circle-x'],
        'Closed'    => ['bg'=>'#F3F4F6','color'=>'#6B7280','icon'=>'ti-lock'],
    ];
    $st = $map[$s] ?? $map['New'];
    return "<span style=\"display:inline-flex;align-items:center;gap:5px;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;background:{$st['bg']};color:{$st['color']};\"><i class=\"ti {$st['icon']}\"></i>{$s}</span>";
}

$canReview = in_array($complaint['status'], ['Resolved','Closed']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Complaint — GNC-OSA Portal</title>
<link rel="stylesheet" href="../assets/css/style.css?v=25">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<style>
    /* Review section */
    .review-section { margin-top:20px; }
    .review-card { background:#fff; border:1.5px solid #E5E7EB; border-radius:14px; padding:20px; }
    .review-card.done { border-color:#D1FAE5; background:#F0FDF4; }
    .review-title { font-size:14px; font-weight:700; color:#1F2937; margin-bottom:4px; }
    .review-sub { font-size:12px; color:#9CA3AF; margin-bottom:18px; }

    /* Emoji reactions */
    .reactions { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
    .reaction-btn {
        display:flex; flex-direction:column; align-items:center; gap:4px;
        padding:10px 16px; border-radius:12px; border:2px solid #E5E7EB;
        background:#fff; cursor:pointer; transition:all 0.15s; font-size:11px;
        color:#6B7280; min-width:64px;
    }
    .reaction-btn .emoji { font-size:28px; line-height:1; }
    .reaction-btn:hover { border-color:#6B1A2A; background:#FDF2F4; color:#6B1A2A; }
    .reaction-btn.selected { border-color:#6B1A2A; background:#6B1A2A; color:#fff; }
    .reaction-btn.selected .label { color:#fff; }

    /* Comment box */
    .comment-wrap { margin-bottom:16px; }
    .comment-wrap label { font-size:13px; font-weight:500; color:#374151; margin-bottom:6px; display:block; }
    .comment-wrap textarea { width:100%; padding:10px 12px; border:1.5px solid #E5E7EB; border-radius:10px; font-size:13px; color:#1F2937; resize:vertical; min-height:80px; font-family:inherit; outline:none; transition:border 0.15s; }
    .comment-wrap textarea:focus { border-color:#6B1A2A; }

    /* Existing review display */
    .existing-review { display:flex; align-items:flex-start; gap:14px; padding:14px; background:#fff; border-radius:12px; border:1px solid #E5E7EB; }
    .existing-emoji { font-size:36px; flex-shrink:0; }
    .existing-meta { font-size:12px; color:#9CA3AF; margin-top:2px; }
    .existing-comment { font-size:13px; color:#4B5563; margin-top:6px; line-height:1.6; font-style:italic; }
</style>
</head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand">
        <img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);">
        <div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub"><?= t('nav.student_portal', 'Student Portal') ?></div></div>
    </div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?>
        <div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div>
        <span class="uname"><?= e($_SESSION['name']) ?></span>
    </div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <a class="sitem active" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.my_complaints', 'My Complaints') ?></a>
    <a class="sitem" href="new-complaint.php"><i class="ti ti-circle-plus"></i> <?= t('sidebar.new_complaint', 'New Complaint') ?></a>
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
    <div class="page-header"><h1><?= t('viewcomplaint.title', 'Complaint') ?> #<?= $id ?></h1><p><a href="complaints.php">← <?= t('viewcomplaint.back', 'Back to My Complaints') ?></a></p></div>

    <!-- COMPLAINT DETAILS -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
            <div>
                <div style="font-size:16px;font-weight:600;color:#1F2937;"><?= e($complaint['title']) ?></div>
                <div style="font-size:12px;color:#9CA3AF;margin-top:3px;">
                    <?= e($complaint['category']) ?> · <?= formatDate($complaint['created_at']) ?>
                    <?php if ($complaint['is_anonymous']): ?>
                    · <span style="color:#059669;font-size:11px;font-weight:600;">Anonymous</span>
                    <?php endif; ?>
                </div>
            </div>
            <?= sBadge($complaint['status']) ?>
        </div>

        <!-- Complaint message -->
        <div style="background:#F9FAFB;border-radius:10px;padding:14px;color:#4B5563;line-height:1.7;font-size:13px;">
            <?= nl2br(e($complaint['message'])) ?>
        </div>

        <!-- Attachment -->
        <?php if ($complaint['attachment']): ?>
        <div style="margin-top:12px;">
            <a href="../uploads/<?= e($complaint['attachment']) ?>" target="_blank" class="btn btn-outline btn-sm">
                <i class="ti ti-paperclip"></i> View Attachment
            </a>
        </div>
        <?php endif; ?>

        <!-- OSA Assigned -->
        <?php if ($complaint['staff_fn']): ?>
        <div style="margin-top:14px;font-size:12px;color:#6B7280;">
            <i class="ti ti-user-check" style="margin-right:4px;"></i>
            Assigned to: <strong><?= e($complaint['staff_fn'].' '.$complaint['staff_ln']) ?></strong>
        </div>
        <?php endif; ?>

        <!-- OSA Response -->
        <?php if ($complaint['admin_remarks'] || !empty($complaint['admin_attachment'])): ?>
        <div style="margin-top:16px;border-top:1px solid #E5E7EB;padding-top:14px;">
            <div style="font-size:12px;font-weight:600;color:var(--maroon);margin-bottom:6px;">
                <i class="ti ti-message-circle" style="margin-right:4px;"></i>
                Response from OSA<?= $complaint['staff_fn'] ? ' ('.e($complaint['staff_fn'].' '.$complaint['staff_ln']).')' : '' ?>:
            </div>
            <?php if ($complaint['admin_remarks']): ?>
            <div style="background:var(--maroon-pale);border-radius:8px;padding:12px;font-size:13px;color:#4B5563;line-height:1.7;">
                <?= nl2br(e($complaint['admin_remarks'])) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($complaint['admin_attachment'])): ?>
            <?php $aaf = $complaint['admin_attachment']; $aIsImg = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $aaf); ?>
            <div style="margin-top:10px;">
                <?php if ($aIsImg): ?>
                <a href="../uploads/<?= e($aaf) ?>" target="_blank">
                    <img src="../uploads/<?= e($aaf) ?>" alt="OSA Attachment" style="max-width:100%;max-height:220px;border-radius:8px;border:1px solid #E5E7EB;">
                </a>
                <?php else: ?>
                <a href="../uploads/<?= e($aaf) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="ti ti-file-download"></i> Download OSA Attachment
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Still pending notice -->
        <?php if (!$complaint['admin_remarks'] && !in_array($complaint['status'], ['Resolved','Closed','Rejected'])): ?>
        <div style="margin-top:14px;" class="alert alert-info">
            <i class="ti ti-clock"></i> The OSA is still reviewing your complaint. Please wait for an update.
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ REVIEW SECTION ══ -->
    <?php if ($canReview): ?>
    <div class="review-section">

        <?php if ($review_success): ?>
        <div class="alert alert-success" style="margin-bottom:14px;">
            <i class="ti ti-circle-check"></i> <?= $review_success ?>
        </div>
        <?php endif; ?>
        <?php if ($review_error): ?>
        <div class="alert alert-error" style="margin-bottom:14px;">
            <i class="ti ti-alert-circle"></i> <?= $review_error ?>
        </div>
        <?php endif; ?>

        <?php if ($existing_review): ?>
        <!-- Show existing review -->
        <div class="review-card done">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                <i class="ti ti-star-filled" style="color:#D97706;font-size:18px;"></i>
                <div class="review-title">Your Review</div>
                <span style="font-size:11px;color:#9CA3AF;margin-left:auto;"><?= date('M j, Y', strtotime($existing_review['created_at'])) ?></span>
            </div>
            <div class="existing-review">
                <div class="existing-emoji"><?= $existing_review['reaction'] ?></div>
                <div>
                    <div style="font-size:13px;font-weight:600;color:#1F2937;">
                        <?php
                        $labels = ['😊'=>'Satisfied','👍'=>'Good','😐'=>'Neutral','👎'=>'Not Satisfied','😢'=>'Disappointed'];
                        echo $labels[$existing_review['reaction']] ?? $existing_review['reaction'];
                        ?>
                    </div>
                    <div class="existing-meta">Submitted on <?= date('F j, Y \a\t g:i A', strtotime($existing_review['created_at'])) ?></div>
                    <?php if ($existing_review['comment']): ?>
                    <div class="existing-comment">"<?= e($existing_review['comment']) ?>"</div>
                    <?php endif; ?>
                </div>
            </div>
            <button onclick="document.getElementById('edit-review-form').style.display='block';this.style.display='none';"
                style="margin-top:14px;background:none;border:1.5px solid #6B1A2A;color:#6B1A2A;border-radius:8px;padding:6px 14px;font-size:12px;cursor:pointer;">
                <i class="ti ti-edit"></i> Edit Review
            </button>

            <!-- Edit form (hidden by default) -->
            <div id="edit-review-form" style="display:none;margin-top:16px;border-top:1px solid #D1FAE5;padding-top:16px;">
                <form method="POST">
                    <div class="reactions" id="reaction-group-edit">
                        <?php foreach (['😊'=>'Satisfied','👍'=>'Good','😐'=>'Neutral','👎'=>'Not Satisfied','😢'=>'Disappointed'] as $emoji=>$label): ?>
                        <button type="button"
                            class="reaction-btn <?= $existing_review['reaction']===$emoji?'selected':'' ?>"
                            onclick="selectReaction('<?= $emoji ?>', 'reaction-group-edit', 'reaction-input-edit')">
                            <span class="emoji"><?= $emoji ?></span>
                            <span class="label"><?= $label ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="reaction" id="reaction-input-edit" value="<?= e($existing_review['reaction']) ?>">
                    <div class="comment-wrap">
                        <label>Additional comment <span style="color:#9CA3AF;">(optional)</span></label>
                        <textarea name="review_comment" placeholder="Tell us more about your experience..."><?= e($existing_review['comment'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-send"></i> Update Review</button>
                    <button type="button" onclick="document.getElementById('edit-review-form').style.display='none';document.querySelector('.review-card .reaction-btn+button, .review-card>button[onclick*=edit]').style.display='inline-flex';"
                        style="margin-left:8px;" class="btn btn-outline btn-sm">Cancel</button>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- New review form -->
        <div class="review-card">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                <i class="ti ti-star" style="color:#D97706;font-size:18px;"></i>
                <div class="review-title">How was your experience?</div>
            </div>
            <div class="review-sub">
                Your complaint has been <?= strtolower($complaint['status']) ?>. Let us know how satisfied you are with the resolution.
            </div>

            <form method="POST">
                <div class="reactions" id="reaction-group-new">
                    <?php foreach (['😊'=>'Satisfied','👍'=>'Good','😐'=>'Neutral','👎'=>'Not Satisfied','😢'=>'Disappointed'] as $emoji=>$label): ?>
                    <button type="button"
                        class="reaction-btn"
                        onclick="selectReaction('<?= $emoji ?>', 'reaction-group-new', 'reaction-input-new')">
                        <span class="emoji"><?= $emoji ?></span>
                        <span class="label"><?= $label ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="reaction" id="reaction-input-new" value="">

                <div class="comment-wrap">
                    <label>Additional comment <span style="color:#9CA3AF;">(optional)</span></label>
                    <textarea name="review_comment" placeholder="Tell us more about your experience with how your complaint was handled..."></textarea>
                </div>

                <button type="submit" id="review-submit-btn" class="btn btn-primary" disabled style="opacity:0.5;cursor:not-allowed;">
                    <i class="ti ti-send"></i> Submit Review
                </button>
                <div style="font-size:11px;color:#9CA3AF;margin-top:8px;">
                    <i class="ti ti-info-circle" style="font-size:13px;"></i> Please select a reaction to submit your review.
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

</div></div>

<script>
function selectReaction(emoji, groupId, inputId) {
    // Update buttons
    document.querySelectorAll('#' + groupId + ' .reaction-btn').forEach(function(btn) {
        btn.classList.remove('selected');
        if (btn.querySelector('.emoji').textContent === emoji) {
            btn.classList.add('selected');
        }
    });
    // Set hidden input
    document.getElementById(inputId).value = emoji;

    // Enable submit button
    var submitBtn = document.getElementById('review-submit-btn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
    }
}
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
</body>
</html>
