<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireAdmin();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT c.*, s.first_name, s.last_name, s.student_id as sid, s.course FROM complaints c JOIN students s ON c.student_id=s.id WHERE c.id=?");
$stmt->execute([$id]);
$complaint = $stmt->fetch();
if (!$complaint) { echo 'Not found.'; exit; }

$staffList = $pdo->query("SELECT * FROM osa_staff WHERE is_active=1 ORDER BY first_name")->fetchAll();
// Also pull inactive/former staff so an old assignment still resolves to a real name
// instead of blank, even after their account is deactivated.
$allStaffById = [];
foreach ($pdo->query("SELECT * FROM osa_staff") as $st) { $allStaffById[$st['id']] = $st; }
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $status      = $_POST['status'] ?? $complaint['status'];
    $remarks     = trim($_POST['admin_remarks'] ?? '');
    $typedName   = trim($_POST['assigned_name'] ?? '');
    $oldStatus   = $complaint['status'];

    // Typed name matched against a real staff account (active or not) → keep the
    // link so notifications and future account changes still work correctly.
    // If it doesn't match anyone on file (e.g. staff who already left), we just
    // keep the typed name as plain text for record-keeping.
    $assign = null;
    foreach ($allStaffById as $st) {
        if (strcasecmp(trim($st['first_name'].' '.$st['last_name']), $typedName) === 0) {
            $assign = $st['id'];
            break;
        }
    }
    $assignName = $typedName !== '' ? $typedName : null;

    // Determine admin_attachment value
    $admin_attachment = $complaint['admin_attachment'] ?? null;

    // Handle remove checkbox
    if (isset($_POST['remove_admin_attachment'])) {
        $admin_attachment = null;
    }

    // Handle new file upload
    if (!empty($_FILES['admin_attachment']['name'])) {
        $upload = uploadAttachment($_FILES['admin_attachment']);
        if (isset($upload['error'])) {
            $success = ''; // suppress success, show error
            $admin_attachment = $complaint['admin_attachment'] ?? null; // keep old
        } else {
            $admin_attachment = $upload['filename'];
        }
    }

    // If status is Closed, archive immediately
    $isArchived = ($status === 'Closed') ? 1 : 0;
    $oldAssignedTo = $complaint['assigned_to'] ?? null;
    $stmt2 = $pdo->prepare("UPDATE complaints SET status=?, admin_remarks=?, admin_attachment=?, assigned_to=?, assigned_to_name=?, is_archived=? WHERE id=?");
    $stmt2->execute([$status, $remarks, $admin_attachment, $assign, $assignName, $isArchived, $id]);

    // Notify the newly-assigned staff member (only if it actually changed, and only
    // when the typed name matched a real account — can't notify a name with no account)
    if ($assign && $assign != $oldAssignedTo) {
        addStaffNotification($pdo, $assign, "A complaint (#{$id}: " . $complaint['title'] . ") has been assigned to you.", "view-complaint.php?id={$id}");
    }

    // Only notify the student if the status actually changed
    if ($status !== $oldStatus) {
        $notifMsg = $status === 'Closed'
            ? "Your complaint #{$id} has been closed by the OSA. You may now leave a review."
            : "Update on your complaint #{$id}: status is now {$status}.";
        addNotification($pdo, $complaint['student_id'], $notifMsg, "view-complaint.php?id={$id}");
    }
    $success = 'Complaint updated successfully!';
    $complaint['status'] = $status;
    $complaint['admin_remarks'] = $remarks;
    $complaint['assigned_to'] = $assign;
    $complaint['assigned_to_name'] = $assignName;
    $complaint['is_archived'] = $isArchived;
    $complaint['admin_attachment'] = $admin_attachment;
    // Trigger auto-archive check immediately after status update
    autoArchiveComplaints($pdo);
}

// Helper: status badge styles
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

// Find assigned staff name — prefer the typed name on file (works even for staff
// who already left / were removed), fall back to looking up the linked account.
$assignedName = '— None —';
if (!empty($complaint['assigned_to_name'])) {
    $assignedName = e($complaint['assigned_to_name']);
} elseif (!empty($complaint['assigned_to']) && isset($allStaffById[$complaint['assigned_to']])) {
    $st = $allStaffById[$complaint['assigned_to']];
    $assignedName = e($st['first_name'].' '.$st['last_name']);
}

// Image attachment check
$attachFile = $complaint['attachment'] ?? '';
$isImage = $attachFile && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $attachFile);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaint #<?= $id ?> — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
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
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub">Administrator</div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar" style="background:#7C3AED;color:#fff;">AD</div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section">Overview</div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section">Complaints</div>
    <a class="sitem active" href="complaints.php"><i class="ti ti-message-report"></i> All Complaints</a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> Elections</a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> Candidates</a>
    <a class="sitem" href="party-lists.php"><i class="ti ti-flag"></i> Party Lists</a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="results.php"><i class="ti ti-chart-pie"></i> Live Results</a>
    <div class="sidebar-section">Events</div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <div class="sidebar-section">System</div>
    <a class="sitem" href="students.php"><i class="ti ti-user-plus"></i> Students</a>
    <a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> OSA Staff</a>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> Reports</a>
    <div class="sidebar-section">Account</div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> Notifications
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">

    <!-- Page Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
        <div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <h1 style="margin:0;font-size:22px;font-weight:700;color:#1F2937;">Complaint #<?= $id ?></h1>
                <span class="status-pill" style="background:<?= $ss['bg'] ?>;color:<?= $ss['color'] ?>;">
                    <i class="ti <?= $ss['icon'] ?>"></i> <?= e($complaint['status']) ?>
                </span>
            </div>
            <div style="margin-top:5px;">
                <a href="complaints.php" style="font-size:13px;color:#7C3AED;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                    <i class="ti ti-arrow-left" style="font-size:14px;"></i> Back to All Complaints
                </a>
            </div>
        </div>
        <div style="font-size:12px;color:#9CA3AF;text-align:right;">
            <i class="ti ti-clock" style="vertical-align:middle;"></i>
            Submitted: <?= formatDate($complaint['created_at']) ?>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="info-banner"><i class="ti ti-circle-check" style="font-size:18px;"></i><?= $success ?></div>
    <?php endif; ?>

    <div>
        <!-- Complaint Details -->
        <div class="card">
                <div class="card-title"><i class="ti ti-message-report"></i> Complaint Details</div>

                <!-- Student Info -->
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

                <?php if ($complaint['assigned_to']): ?>
                <div class="meta-row">
                    <span class="meta-label"><i class="ti ti-user-check" style="vertical-align:middle;margin-right:4px;"></i>Assigned to</span>
                    <span class="meta-value"><?= $assignedName ?></span>
                </div>
                <?php endif; ?>

                <!-- Message Body -->
                <div style="margin-top:14px;">
                    <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><i class="ti ti-align-left" style="vertical-align:middle;margin-right:4px;"></i>Message</div>
                    <div class="message-box"><?= nl2br(e($complaint['message'])) ?></div>
                </div>

                <!-- Attachment -->
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

                <!-- Admin Remarks (read-only display if set) -->
                <?php if (!empty($complaint['admin_remarks']) || !empty($complaint['admin_attachment'])): ?>
                <div style="margin-top:16px;padding:12px 14px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;">
                    <div style="font-size:12px;font-weight:600;color:#065F46;margin-bottom:6px;"><i class="ti ti-message-check"></i> Admin Response</div>
                    <?php if (!empty($complaint['admin_remarks'])): ?>
                    <div style="font-size:13px;color:#374151;line-height:1.7;"><?= nl2br(e($complaint['admin_remarks'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($complaint['admin_attachment'])): ?>
                    <?php $aaf = $complaint['admin_attachment']; $aIsImg = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $aaf); ?>
                    <div style="margin-top:10px;">
                        <?php if ($aIsImg): ?>
                        <a href="../uploads/<?= e($aaf) ?>" target="_blank">
                            <img src="../uploads/<?= e($aaf) ?>" alt="Staff Attachment" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #BBF7D0;">
                        </a>
                        <?php else: ?>
                        <a href="../uploads/<?= e($aaf) ?>" target="_blank" class="btn btn-outline btn-sm" style="border-color:#BBF7D0;color:#065F46;">
                            <i class="ti ti-file-download"></i> Download Staff Attachment
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Student Review -->
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

                    <!-- Live status preview -->
                    <div id="status-preview" style="margin-top:-8px;margin-bottom:14px;font-size:12px;color:#6B7280;display:flex;align-items:center;gap:6px;">
                        <span>Current:</span>
                        <span id="preview-pill" class="status-pill" style="font-size:11px;padding:2px 10px;background:<?= $ss['bg'] ?>;color:<?= $ss['color'] ?>;">
                            <i class="ti <?= $ss['icon'] ?>"></i> <?= e($complaint['status']) ?>
                        </span>
                    </div>

                    <div class="form-group">
                        <label>Assign to</label>
                        <input type="text" name="assigned_name" class="form-control" list="staff-names"
                               placeholder="Type a staff name..."
                               value="<?= $assignedName !== '— None —' ? $assignedName : '' ?>" autocomplete="off">
                        <datalist id="staff-names">
                            <?php foreach ($staffList as $st): ?>
                            <option value="<?= e($st['first_name'].' '.$st['last_name']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small style="color:#9CA3AF;font-size:11px;">
                            Start typing for suggestions from current OSA staff, or type any name freely
                            (useful if the staff member has already left) — leave blank to unassign.
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Remarks / Response to Student</label>
                        <textarea name="admin_remarks" class="form-control" rows="5" placeholder="Explain the action taken or to be taken..."><?= e($complaint['admin_remarks'] ?? '') ?></textarea>
                        <div style="font-size:11px;color:#9CA3AF;margin-top:4px;"><i class="ti ti-info-circle"></i> This message will be visible to the student.</div>
                    </div>

                    <div class="form-group">
                        <label>Attachment <span style="color:#9CA3AF;font-weight:400;">(optional)</span></label>
                        <?php if (!empty($complaint['admin_attachment'])): ?>
                        <?php $af = $complaint['admin_attachment']; $isImg = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $af); ?>
                        <div style="margin-bottom:10px;padding:10px 12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;display:flex;align-items:center;gap:10px;font-size:13px;">
                            <i class="ti <?= $isImg ? 'ti-photo' : 'ti-file' ?>" style="color:#6B7280;font-size:18px;"></i>
                            <span style="flex:1;color:#374151;">Current: <a href="../uploads/<?= e($af) ?>" target="_blank" style="color:var(--maroon);"><?= e($af) ?></a></span>
                            <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:#9CA3AF;cursor:pointer;margin:0;">
                                <input type="checkbox" name="remove_admin_attachment" value="1"> Remove
                            </label>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="admin_attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                        <small style="color:#9CA3AF;font-size:11px;margin-top:4px;display:block;">Allowed: JPG, PNG, PDF, DOC · Max 5MB</small>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="save-btn">
                        <i class="ti ti-device-floppy"></i> Save Update
                    </button>
                </form>
        </div>
    </div>
</div></div>


<!-- Custom Reject Confirm Modal -->
<div id="confirm-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:flex-start;gap:14px;margin-bottom:20px;">
            <div style="width:44px;height:44px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="ti ti-circle-x" style="color:#DC2626;font-size:22px;"></i>
            </div>
            <div>
                <div style="font-size:15px;font-weight:700;color:#1F2937;margin-bottom:5px;">Reject this complaint?</div>
                <div style="font-size:13px;color:#6B7280;line-height:1.6;">This will mark the complaint as <strong style="color:#DC2626;">Rejected</strong> and the student will be notified. This action can still be changed later.</div>
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
// Live status preview pill update
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

// Confirm modal for Rejected status
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
<script src="../assets/js/alerts.js"></script>
<script src="../assets/js/sidebar.js?v=5"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body></html>
