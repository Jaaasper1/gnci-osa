<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStaff();

$staff_id = $_SESSION['user_id'];
$notifCount = getUnreadStaffNotifCount($pdo, $staff_id);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT c.*, s.first_name, s.last_name, s.student_id as sid, s.course FROM complaints c JOIN students s ON c.student_id=s.id WHERE c.id=? AND c.assigned_to=?");
$stmt->execute([$id, $staff_id]);
$complaint = $stmt->fetch();
if (!$complaint) { echo 'Not found or not assigned to you.'; exit; }

// --- Unclaim (release back to the pool) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unclaim') {
    if (in_array($complaint['status'], ['New', 'In Review'])) {
        $pdo->prepare("UPDATE complaints SET assigned_to=NULL WHERE id=? AND assigned_to=? AND status IN ('New','In Review')")
            ->execute([$id, $staff_id]);
        header('Location: complaints.php?tab=mine&unclaimed=1');
        exit;
    }
}

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status  = $_POST['status'] ?? $complaint['status'];
    $remarks = trim($_POST['admin_remarks'] ?? '');
    $oldStatus = $complaint['status'];

    // Determine admin_attachment value
    $admin_attachment = $complaint['admin_attachment'] ?? null;

    // Handle remove checkbox
    if (isset($_POST['remove_admin_attachment'])) {
        $admin_attachment = null;
    }

    // Handle new file upload
    if (!empty($_FILES['admin_attachment']['name'])) {
        $upload = uploadAttachment($_FILES['admin_attachment']);
        if (!isset($upload['error'])) {
            $admin_attachment = $upload['filename'];
        }
    }

    $isArchived = ($status === 'Closed') ? 1 : 0;
    $stmt2 = $pdo->prepare("UPDATE complaints SET status=?, admin_remarks=?, admin_attachment=?, is_archived=? WHERE id=? AND assigned_to=?");
    $stmt2->execute([$status, $remarks, $admin_attachment, $isArchived, $id, $staff_id]);

    if ($status !== $oldStatus) {
        $notifMsg = $status === 'Closed'
            ? "Your complaint #{$id} has been closed by the OSA. You may now leave a review."
            : "Update on your complaint #{$id}: status is now {$status}.";
        addNotification($pdo, $complaint['student_id'], $notifMsg, "view-complaint.php?id={$id}");
    }

    $success = 'Complaint updated successfully!';
    $complaint['status'] = $status;
    $complaint['admin_remarks'] = $remarks;
    $complaint['is_archived'] = $isArchived;
    $complaint['admin_attachment'] = $admin_attachment;
    autoArchiveComplaints($pdo);
}

function statusStyle($s) {
    return match($s) {
        'New'       => ['bg'=>'#DBEAFE','color'=>'#1D4ED8','icon'=>'ti-clock'],
        'In Review' => ['bg'=>'#FEF3C7','color'=>'#D97706','icon'=>'ti-search'],
        'Resolved'  => ['bg'=>'#D1FAE5','color'=>'#065F46','icon'=>'ti-circle-check'],
        'Rejected'  => ['bg'=>'#FEE2E2','color'=>'#991B1B','icon'=>'ti-circle-x'],
        'Closed'    => ['bg'=>'#F3F4F6','color'=>'#6B7280','icon'=>'ti-lock'],
        default     => ['bg'=>'#F3F4F6','color'=>'#6B7280','icon'=>'ti-question-mark'],
    };
}

$ss = statusStyle($complaint['status']);
$attachFile = $complaint['attachment'] ?? '';
$isImage = $attachFile && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $attachFile);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaint #<?= $id ?> — OSA Staff</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=25">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <style>
        .meta-row { display:flex; align-items:center; gap:8px; padding:10px 0; border-bottom:1px solid #F3F4F6; font-size:13px; }
        .meta-row:last-child { border-bottom:none; }
        .meta-label { font-weight:600; color:#374151; min-width:110px; }
        .meta-value { color:#4B5563; }
        .status-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }
        .message-box { background:#F9FAFB; border:1px solid #E5E7EB; border-radius:10px; padding:16px; margin:14px 0; line-height:1.75; color:#374151; font-size:13.5px; white-space:pre-wrap; }
        .attach-preview { margin-top:10px; border-radius:10px; overflow:hidden; border:1px solid #E5E7EB; max-width:100%; }
        .attach-preview img { width:100%; max-height:280px; object-fit:cover; display:block; }
        .attach-preview-footer { display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:#F9FAFB; font-size:12px; color:#6B7280; }
        .info-banner { display:flex; align-items:center; gap:10px; background:#F0FDF4; border:1px solid #BBF7D0; border-radius:10px; padding:12px 16px; margin-bottom:16px; font-size:13px; color:#065F46; }

        /* Mobile: stack label above value instead of squeezing side-by-side */
        @media (max-width: 600px) {
            .meta-row { flex-direction: column; align-items: flex-start; gap: 3px; }
            .meta-label { min-width: 0; }
            .attach-preview-footer { flex-wrap: wrap; gap: 8px; }
        }
    </style>
</head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Portal</div><div class="sub">OSA Staff</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><button class="notif-btn" onclick="location.href='notifications.php'"><i class="ti ti-bell"></i><?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?></button><div class="avatar"><?= strtoupper(substr($_SESSION['name'],0,2)) ?></div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.main', 'Main') ?></div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <a class="sitem active" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.complaints', 'Complaints') ?></a>
    <div class="sidebar-section">Events</div><a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a><div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="settings.php"><i class="ti ti-settings"></i> <?= t('nav.settings', 'Settings') ?></a>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('nav.notifications', 'Notifications') ?><?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?></a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
        <div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <h1 style="margin:0;font-size:22px;font-weight:700;color:#1F2937;">Complaint #<?= $id ?></h1>
                <span class="status-pill" style="background:<?= $ss['bg'] ?>;color:<?= $ss['color'] ?>;">
                    <i class="ti <?= $ss['icon'] ?>"></i> <?= e($complaint['status']) ?>
                </span>
            </div>
            <div style="margin-top:5px;">
                <a href="complaints.php" style="font-size:13px;color:var(--maroon);text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                    <i class="ti ti-arrow-left" style="font-size:14px;"></i> Back to My Complaints
                </a>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:12px;color:#9CA3AF;">
                <i class="ti ti-clock" style="vertical-align:middle;"></i>
                Submitted: <?= formatDate($complaint['created_at']) ?>
            </div>
            <?php if (in_array($complaint['status'], ['New','In Review'])): ?>
            <form method="POST" style="margin-top:8px;" class="confirm-submit-form"
                  data-confirm-title="<?= e(t('osa.unclaim', 'Unclaim')) ?>"
                  data-confirm-message="<?= e(t('osa.unclaim_confirm', 'Release this complaint back to the unclaimed pool? Another staff member may pick it up.')) ?>"
                  data-confirm-btn="<?= e(t('osa.unclaim', 'Unclaim')) ?>"
                  data-confirm-danger="1">
                <input type="hidden" name="action" value="unclaim">
                <button type="submit" class="btn btn-sm" style="background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;">
                    <i class="ti ti-arrow-back-up"></i> <?= t('osa.unclaim', 'Unclaim') ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="info-banner"><i class="ti ti-circle-check" style="font-size:18px;"></i><?= $success ?></div>
    <?php endif; ?>

    <div>
        <!-- Complaint Details -->
        <div class="card">
            <div class="card-title"><i class="ti ti-message-report"></i> Complaint Details</div>

            <?php if ($complaint['is_anonymous']): ?>
            <div class="meta-row">
                <span class="meta-label"><i class="ti ti-user-off" style="vertical-align:middle;margin-right:4px;"></i>Student</span>
                <span class="meta-value" style="font-style:italic;color:#9CA3AF;">Anonymous</span>
            </div>
            <?php else: ?>
            <div class="meta-row">
                <span class="meta-label"><i class="ti ti-user" style="vertical-align:middle;margin-right:4px;"></i>Student</span>
                <span class="meta-value">
                    <strong><?= e($complaint['first_name'].' '.$complaint['last_name']) ?></strong>
                    <span style="color:#9CA3AF;margin-left:6px;">(<?= e($complaint['sid']) ?>)</span>
                </span>
            </div>
            <div class="meta-row">
                <span class="meta-label"><i class="ti ti-school" style="vertical-align:middle;margin-right:4px;"></i>Course</span>
                <span class="meta-value"><?= e($complaint['course']) ?> · Section</span>
            </div>
            <?php endif; ?>

            <div class="meta-row">
                <span class="meta-label"><i class="ti ti-tag" style="vertical-align:middle;margin-right:4px;"></i>Category</span>
                <span class="meta-value"><span style="background:#EDE9FE;color:#7C3AED;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600;"><?= e($complaint['category']) ?></span></span>
            </div>

            <div class="meta-row">
                <span class="meta-label"><i class="ti ti-file-text" style="vertical-align:middle;margin-right:4px;"></i>Title</span>
                <span class="meta-value" style="font-weight:500;"><?= e($complaint['title']) ?></span>
            </div>

            <div style="margin-top:14px;">
                <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><i class="ti ti-align-left" style="vertical-align:middle;margin-right:4px;"></i>Message</div>
                <div class="message-box"><?= nl2br(e($complaint['message'])) ?></div>
            </div>

            <?php if ($attachFile): ?>
            <div style="margin-top:4px;">
                <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:8px;"><i class="ti ti-paperclip" style="vertical-align:middle;margin-right:4px;"></i>Attachment</div>
                <?php if ($isImage): ?>
                <div class="attach-preview">
                    <a href="../uploads/<?= e($attachFile) ?>" target="_blank">
                        <img src="../uploads/<?= e($attachFile) ?>" alt="Attachment">
                    </a>
                    <div class="attach-preview-footer">
                        <span><i class="ti ti-photo"></i> Image attachment</span>
                        <a href="../uploads/<?= e($attachFile) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11px;">
                            <i class="ti ti-external-link"></i> Open Full
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <a href="../uploads/<?= e($attachFile) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="ti ti-file-download"></i> Download Attachment
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($complaint['admin_remarks'])): ?>
            <div style="margin-top:16px;padding:12px 14px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;">
                <div style="font-size:12px;font-weight:600;color:#065F46;margin-bottom:6px;"><i class="ti ti-message-check"></i> Staff Response</div>
                <div style="font-size:13px;color:#374151;line-height:1.7;"><?= nl2br(e($complaint['admin_remarks'])) ?></div>
            </div>
            <?php endif; ?>

            <?php
            $revStmt = $pdo->prepare("SELECT * FROM complaint_reviews WHERE complaint_id=?");
            $revStmt->execute([$id]);
            $review = $revStmt->fetch();
            if ($review):
                $labels = ['😊'=>'Satisfied','👍'=>'Good','😐'=>'Neutral','👎'=>'Not Satisfied','😢'=>'Disappointed'];
                $colors = ['😊'=>'#065F46','👍'=>'#1D4ED8','😐'=>'#92400E','👎'=>'#991B1B','😢'=>'#6B1A2A'];
                $bgs    = ['😊'=>'#D1FAE5','👍'=>'#DBEAFE','😐'=>'#FEF3C7','👎'=>'#FEE2E2','😢'=>'#FDF2F4'];
                $color  = $colors[$review['reaction']] ?? '#374151';
                $bg     = $bgs[$review['reaction']]    ?? '#F3F4F6';
                $label  = $labels[$review['reaction']]  ?? $review['reaction'];
            ?>
            <div style="margin-top:16px;padding:14px;border:1px solid #E5E7EB;border-radius:10px;background:#FAFAFA;">
                <div style="font-size:12px;font-weight:600;color:#6B7280;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                    <i class="ti ti-star-filled" style="color:#D97706;"></i> Student Review
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:32px;"><?= $review['reaction'] ?></span>
                    <div>
                        <span style="display:inline-block;padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;background:<?= $bg ?>;color:<?= $color ?>;">
                            <?= $label ?>
                        </span>
                        <div style="font-size:11px;color:#9CA3AF;margin-top:3px;">
                            <?= date('M j, Y g:i A', strtotime($review['created_at'])) ?>
                        </div>
                        <?php if ($review['comment']): ?>
                        <div style="font-size:13px;color:#4B5563;margin-top:6px;font-style:italic;">
                            "<?= e($review['comment']) ?>"
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php elseif (in_array($complaint['status'], ['Resolved','Closed'])): ?>
            <div style="margin-top:14px;font-size:12px;color:#9CA3AF;text-align:center;padding:10px;">
                <i class="ti ti-star" style="font-size:16px;"></i> No student review yet.
            </div>
            <?php endif; ?>
        </div>

        <!-- Update Status -->
        <div class="card" style="margin-top:16px;">
            <div class="card-title"><i class="ti ti-settings"></i> Update Status</div>
            <form method="POST" id="update-form" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control" id="status-select">
                        <?php foreach (['New','In Review','Resolved','Rejected','Closed'] as $s): ?>
                        <option <?= $complaint['status']===$s?'selected':'' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="status-preview" style="margin-top:-8px;margin-bottom:14px;font-size:12px;color:#6B7280;display:flex;align-items:center;gap:6px;">
                    <span>Current:</span>
                    <span id="preview-pill" class="status-pill" style="font-size:11px;padding:2px 10px;background:<?= $ss['bg'] ?>;color:<?= $ss['color'] ?>;">
                        <i class="ti <?= $ss['icon'] ?>"></i> <?= e($complaint['status']) ?>
                    </span>
                </div>

                <div class="form-group">
                    <label>Remarks / Response to Student</label>
                    <textarea name="admin_remarks" class="form-control" rows="5" placeholder="Explain the action taken or to be taken..."><?= e($complaint['admin_remarks'] ?? '') ?></textarea>
                    <div style="font-size:11px;color:#9CA3AF;margin-top:4px;"><i class="ti ti-info-circle"></i> This message will be visible to the student.</div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="save-btn">
                    <i class="ti ti-device-floppy"></i> Save Update
                </button>
            </form>
        </div>
    </div>
</div></div>

<!-- Reject Confirm Modal -->
<div id="confirm-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:20px;">
            <div style="width:44px;height:44px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="ti ti-circle-x" style="color:#DC2626;font-size:22px;"></i>
            </div>
            <div>
                <div style="font-size:15px;font-weight:700;color:#1F2937;margin-bottom:5px;">Reject this complaint?</div>
                <div style="font-size:13px;color:#6B7280;line-height:1.6;">This will mark the complaint as <strong style="color:#DC2626;">Rejected</strong> and the student will be notified.</div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button id="confirm-cancel-btn" class="btn btn-outline btn-sm">Cancel</button>
            <button id="confirm-ok-btn" class="btn btn-sm" style="background:#DC2626;color:#fff;border-color:#DC2626;">
                <i class="ti ti-circle-x"></i> Yes, Reject
            </button>
        </div>
    </div>
</div>

<script>
var statusColors = {
    'New':       { bg:'#DBEAFE', color:'#1D4ED8', icon:'ti-clock' },
    'In Review': { bg:'#FEF3C7', color:'#D97706', icon:'ti-search' },
    'Resolved':  { bg:'#D1FAE5', color:'#065F46', icon:'ti-circle-check' },
    'Rejected':  { bg:'#FEE2E2', color:'#991B1B', icon:'ti-circle-x' },
    'Closed':    { bg:'#F3F4F6', color:'#6B7280', icon:'ti-lock' }
};
document.getElementById('status-select').addEventListener('change', function() {
    var val = this.value;
    var s = statusColors[val] || { bg:'#F3F4F6', color:'#6B7280', icon:'ti-question-mark' };
    var pill = document.getElementById('preview-pill');
    pill.style.background = s.bg;
    pill.style.color = s.color;
    pill.innerHTML = '<i class="ti ' + s.icon + '"></i> ' + val;
});
document.getElementById('update-form').addEventListener('submit', function(e) {
    if (document.getElementById('status-select').value === 'Rejected') {
        e.preventDefault();
        document.getElementById('confirm-modal-overlay').style.display = 'flex';
        setTimeout(function(){ document.getElementById('confirm-ok-btn').focus(); }, 80);
    }
});
document.getElementById('confirm-ok-btn').onclick = function() {
    document.getElementById('confirm-modal-overlay').style.display = 'none';
    HTMLFormElement.prototype.submit.call(document.getElementById('update-form'));
};
document.getElementById('confirm-cancel-btn').onclick = function() {
    document.getElementById('confirm-modal-overlay').style.display = 'none';
};
document.getElementById('confirm-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('confirm-modal-overlay').style.display = 'none';
});
</script>
<script src="../assets/js/sidebar.js?v=5"></script>
</body>
</html>
