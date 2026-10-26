<?php
require_once '../includes/db.php';
require_once '../includes/audit.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
requireStaff();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $id) auditLog($pdo, 'view', 'complaint', $id, [], []);
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
    auditLog($pdo, 'update', 'complaint', (int)$id, ['status' => $oldStatus, 'assigned_to' => $oldAssignedTo], ['status' => $status, 'assigned_to' => $assignName ?: $assign]);

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
    $success = t('msg.complaint_updated_successfully_1413', 'Complaint updated successfully!');
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
        .attach-preview img { width:100%; max-height:200px; object-fit:contain; background:#F3F4F6; display:block; }
        .attach-preview-footer { display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:#F9FAFB; font-size:12px; color:#6B7280; }
        .info-banner { display:flex; align-items:center; gap:10px; background:#F0FDF4; border:1px solid #BBF7D0; border-radius:10px; padding:12px 16px; margin-bottom:16px; font-size:13px; color:#065F46; }

        /* Mobile: stack label above value instead of squeezing side-by-side */
        @media (max-width: 600px) {
            .meta-row { flex-direction: column; align-items: flex-start; gap: 3px; }
            .meta-label { min-width: 0; }
            .attach-preview-footer { flex-wrap: wrap; gap: 8px; }
        }

        /* ===== Update Status: slide-in panel (no more long scrolling) ===== */
        .upd-overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:7900; opacity:0; visibility:hidden; transition:opacity .2s, visibility .2s; }
        .upd-overlay.open { opacity:1; visibility:visible; }
        .upd-drawer { position:fixed; top:0; right:0; bottom:0; width:min(440px,100vw); background:#fff; z-index:8000; transform:translateX(100%); transition:transform .25s ease; display:flex; flex-direction:column; box-shadow:-8px 0 32px rgba(0,0,0,.18); }
        .upd-drawer.open { transform:none; }
        .upd-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:16px 20px; border-bottom:1px solid #F3F4F6; font-size:16px; font-weight:700; color:#1F2937; }
        .upd-head button { width:34px; height:34px; border:none; border-radius:50%; background:#F3F4F6; color:#4B5563; font-size:18px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; }
        .upd-head button:hover { background:#E5E7EB; }
        .upd-body { padding:18px 20px 28px; overflow-y:auto; flex:1; }
        #upd-fab { position:fixed; right:80px; bottom:calc(20px + env(safe-area-inset-bottom,0px)); z-index:7000; border-radius:999px; padding:11px 18px; box-shadow:0 6px 18px rgba(0,0,0,.25); }
        #upd-fab[hidden] { display:none; }
        @media (max-width:600px) { #upd-fab { right:76px; padding:11px 14px; } }
    </style>
<?php include __DIR__.'/../includes/pwa-head.php'; ?></head>
<body>
<nav class="topnav">
    <button class="hamburger" id="hamburger" aria-label="<?= t('ui.toggle_menu_641f', 'Toggle menu') ?>"><span></span><span></span><span></span></button>
    <div class="brand"><img src="../assets/img/osa-logo.jpg" alt="OSA Logo" style="width:38px;height:38px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3);"><div class="brand-text"><div class="name">GNC-OSA Admin</div><div class="sub"><?= t('ui.administrator_7b7b', 'Administrator') ?></div></div></div>
    <div class="nav-right">
        <?php renderLangSwitcher(); ?><div class="avatar" style="background:#7C3AED;color:#fff;">AD</div><span class="uname"><?= e($_SESSION['name']) ?></span></div>
</nav>
<div class="sidebar-overlay" id="sidebar-overlay"></div>
<aside class="sidebar">
    <div class="sidebar-section"><?= t('sidebar.overview', 'Overview') ?></div>
    <a class="sitem" href="dashboard.php"><i class="ti ti-layout-dashboard"></i> <?= t('sidebar.dashboard', 'Dashboard') ?></a>
    <div class="sidebar-section"><?= t('sidebar.complaints', 'Complaints') ?></div>
    <a class="sitem active" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?></a><a class="sitem" href="../osa/messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?></a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> <?= t('sidebar.elections', 'Elections') ?></a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> <?= t('sidebar.candidates', 'Candidates') ?></a>
    <a class="sitem" href="party-lists.php"><i class="ti ti-flag"></i> <?= t('sidebar.party_lists', 'Party Lists') ?></a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="results.php"><i class="ti ti-chart-pie"></i> <?= t('sidebar.live_results', 'Live Results') ?></a>
    <div class="sidebar-section"><?= t('sidebar.events', 'Events') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <div class="sidebar-section"><?= t('sidebar.system', 'System') ?></div>
    <a class="sitem" href="students.php"><i class="ti ti-user-plus"></i> <?= t('sidebar.students', 'Students') ?></a>
    <?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a><?php endif; ?><?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem" href="audit-log.php"><i class="ti ti-history"></i> <?= t('sidebar.audit_log', 'Audit Log') ?></a><?php endif; ?>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> <?= t('sidebar.reports', 'Reports') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">

    <!-- Page Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
        <div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <h1 style="margin:0;font-size:22px;font-weight:700;color:#1F2937;"><?= t('ui.complaint_7118', 'Complaint #') ?><?= $id ?></h1>
                <span class="status-pill" style="background:<?= $ss['bg'] ?>;color:<?= $ss['color'] ?>;">
                    <i class="ti <?= $ss['icon'] ?>"></i> <?= e($complaint['status']) ?>
                </span>
                <button type="button" class="btn btn-primary btn-sm upd-open"><i class="ti ti-edit"></i> <?= t('ui.update_status_a90e', 'Update Status') ?></button>
            </div>
            <div style="margin-top:5px;">
                <a href="complaints.php" style="font-size:13px;color:#7C3AED;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                    <i class="ti ti-arrow-left" style="font-size:14px;"></i> <?= t('ui.back_to_all_complaints_69df', 'Back to All Complaints') ?>
                </a>
            </div>
        </div>
        <div style="font-size:12px;color:#9CA3AF;text-align:right;">
            <i class="ti ti-clock" style="vertical-align:middle;"></i>
            <?= t('ui.submitted_7abc', 'Submitted:') ?> <?= formatDate($complaint['created_at']) ?>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="info-banner"><i class="ti ti-circle-check" style="font-size:18px;"></i><?= $success ?></div>
    <?php endif; ?>

    <div>
        <!-- Complaint Details -->
        <div class="card">
                <div class="card-title"><i class="ti ti-message-report"></i> <?= t('ui.complaint_details_b461', 'Complaint Details') ?></div>

                <!-- Student Info -->
                <?php if ($complaint['is_anonymous']): ?>
                <div class="meta-row">
                    <span class="meta-label"><i class="ti ti-user-off" style="vertical-align:middle;margin-right:4px;"></i><?= t('ui.student_f5c0', 'Student') ?></span>
                    <span class="meta-value" style="font-style:italic;color:#9CA3AF;">Anonymous</span>
                </div>
                <?php else: ?>
                <div class="meta-row">
                    <span class="meta-label"><i class="ti ti-user" style="vertical-align:middle;margin-right:4px;"></i><?= t('ui.student_f5c0', 'Student') ?></span>
                    <span class="meta-value">
                        <strong><?= e($complaint['first_name'].' '.$complaint['last_name']) ?></strong>
                        <span style="color:#9CA3AF;margin-left:6px;">(<?= e($complaint['sid']) ?>)</span>
                    </span>
                </div>
                <div class="meta-row">
                    <span class="meta-label"><i class="ti ti-school" style="vertical-align:middle;margin-right:4px;"></i><?= t('ui.course_3730', 'Course') ?></span>
                    <span class="meta-value"><?= e($complaint['course']) ?> · Section</span>
                </div>
                <?php endif; ?>

                <div class="meta-row">
                    <span class="meta-label"><i class="ti ti-tag" style="vertical-align:middle;margin-right:4px;"></i><?= t('ui.category_3adb', 'Category') ?></span>
                    <span class="meta-value"><span style="background:#EDE9FE;color:#7C3AED;padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600;"><?= e($complaint['category']) ?></span></span>
                </div>

                <div class="meta-row">
                    <span class="meta-label"><i class="ti ti-file-text" style="vertical-align:middle;margin-right:4px;"></i><?= t('ui.title_b78a', 'Title') ?></span>
                    <span class="meta-value" style="font-weight:500;"><?= e($complaint['title']) ?></span>
                </div>

                <?php if ($complaint['assigned_to']): ?>
                <div class="meta-row">
                    <span class="meta-label"><i class="ti ti-user-check" style="vertical-align:middle;margin-right:4px;"></i><?= t('ui.assigned_to_1668', 'Assigned to') ?></span>
                    <span class="meta-value"><?= $assignedName ?></span>
                </div>
                <?php endif; ?>

                <!-- Message Body -->
                <div style="margin-top:14px;">
                    <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><i class="ti ti-align-left" style="vertical-align:middle;margin-right:4px;"></i><?= t('ui.message_4c2a', 'Message') ?></div>
                    <div class="message-box"><?= nl2br(e($complaint['message'])) ?></div>
                </div>

                <!-- Attachment -->
                <?php if ($attachFile): ?>
                <div style="margin-top:4px;">
                    <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:8px;"><i class="ti ti-paperclip" style="vertical-align:middle;margin-right:4px;"></i><?= t('ui.attachment_e9cb', 'Attachment') ?></div>
                    <?php if ($isImage): ?>
                    <div class="attach-preview">
                        <a href="../uploads/<?= e($attachFile) ?>" target="_blank">
                            <img src="../uploads/<?= e($attachFile) ?>" alt="<?= htmlspecialchars(t('auto.attachment_e9cb', 'Attachment'), ENT_QUOTES) ?>">
                        </a>
                        <div class="attach-preview-footer">
                            <span><i class="ti ti-photo"></i> <?= t('ui.image_attachment_0b73', 'Image attachment') ?></span>
                            <a href="../uploads/<?= e($attachFile) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11px;">
                                <i class="ti ti-external-link"></i> <?= t('ui.open_full_a899', 'Open Full') ?>
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <a href="../uploads/<?= e($attachFile) ?>" target="_blank" class="btn btn-outline btn-sm">
                        <i class="ti ti-file-download"></i> <?= t('ui.download_attachment_2fd3', 'Download Attachment') ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Admin Remarks (read-only display if set) -->
                <?php if (!empty($complaint['admin_remarks']) || !empty($complaint['admin_attachment'])): ?>
                <div style="margin-top:16px;padding:12px 14px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;">
                    <div style="font-size:12px;font-weight:600;color:#065F46;margin-bottom:6px;"><i class="ti ti-message-check"></i> <?= t('ui.admin_response_ecd2', 'Admin Response') ?></div>
                    <?php if (!empty($complaint['admin_remarks'])): ?>
                    <div style="font-size:13px;color:#374151;line-height:1.7;"><?= nl2br(e($complaint['admin_remarks'])) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($complaint['admin_attachment'])): ?>
                    <?php $aaf = $complaint['admin_attachment']; $aIsImg = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $aaf); ?>
                    <div style="margin-top:10px;">
                        <?php if ($aIsImg): ?>
                        <a href="../uploads/<?= e($aaf) ?>" target="_blank">
                            <img src="../uploads/<?= e($aaf) ?>" alt="<?= htmlspecialchars(t('auto.staff_attachment_0bd4', 'Staff Attachment'), ENT_QUOTES) ?>" style="max-width:100%;max-height:200px;border-radius:8px;border:1px solid #BBF7D0;">
                        </a>
                        <?php else: ?>
                        <a href="../uploads/<?= e($aaf) ?>" target="_blank" class="btn btn-outline btn-sm" style="border-color:#BBF7D0;color:#065F46;">
                            <i class="ti ti-file-download"></i> <?= t('ui.download_staff_attachment_96c0', 'Download Staff Attachment') ?>
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
                        <i class="ti ti-star-filled" style="color:#D97706;"></i> <?= t('ui.student_review_6f7a', 'Student Review') ?>
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
                    <i class="ti ti-star" style="font-size:16px;"></i> <?= t('ui.no_student_review_yet_81ad', 'No student review yet.') ?>
                </div>
                <?php endif; ?>
        </div>

        <!-- Update Status (slide-in panel) -->
        <div class="upd-overlay" id="upd-overlay"></div>
        <aside class="upd-drawer" id="upd-drawer" role="dialog" aria-label="<?= t('ui.update_status_f535', 'Update status') ?>" aria-hidden="true">
            <div class="upd-head"><span><i class="ti ti-settings"></i> <?= t('ui.update_status_a90e', 'Update Status') ?></span><button type="button" id="upd-close" aria-label="<?= t('ui.close_d3d2', 'Close') ?>"><i class="ti ti-x"></i></button></div>
            <div class="upd-body">
                <form method="POST" id="update-form" enctype="multipart/form-data">
                    <div class="form-group">
                        <label><?= t('ui.status_ec53', 'Status') ?></label>
                        <select name="status" class="form-control" id="status-select">
                            <?php foreach (['New','In Review','Resolved','Rejected','Closed'] as $s): ?>
                            <option <?= $complaint['status']===$s?'selected':'' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Live status preview -->
                    <div id="status-preview" style="margin-top:-8px;margin-bottom:14px;font-size:12px;color:#6B7280;display:flex;align-items:center;gap:6px;">
                        <span><?= t('ui.current_83a3', 'Current:') ?></span>
                        <span id="preview-pill" class="status-pill" style="font-size:11px;padding:2px 10px;background:<?= $ss['bg'] ?>;color:<?= $ss['color'] ?>;">
                            <i class="ti <?= $ss['icon'] ?>"></i> <?= e($complaint['status']) ?>
                        </span>
                    </div>

                    <div class="form-group">
                        <label><?= t('ui.assign_to_773e', 'Assign to') ?></label>
                        <input type="text" name="assigned_name" class="form-control" list="staff-names"
                               placeholder="<?= t('ui.type_a_staff_name_3d1e', 'Type a staff name...') ?>"
                               value="<?= $assignedName !== '— None —' ? $assignedName : '' ?>" autocomplete="off">
                        <datalist id="staff-names">
                            <?php foreach ($staffList as $st): ?>
                            <option value="<?= e($st['first_name'].' '.$st['last_name']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small style="color:#9CA3AF;font-size:11px;">
                            <?= t('ui.start_typing_for_suggestions_from__b847', 'Start typing for suggestions from current OSA staff, or type any name freely (useful if the staff member has already left) — leave blank to unassign.') ?>
                        </small>
                    </div>

                    <div class="form-group">
                        <label><?= t('ui.remarks_response_to_student_59ee', 'Remarks / Response to Student') ?></label>
                        <textarea name="admin_remarks" class="form-control" rows="5" placeholder="<?= htmlspecialchars(t('auto.explain_the_action_taken_or_to_be_taken_f635', 'Explain the action taken or to be taken...'), ENT_QUOTES) ?>"><?= e($complaint['admin_remarks'] ?? '') ?></textarea>
                        <div style="font-size:11px;color:#9CA3AF;margin-top:4px;"><i class="ti ti-info-circle"></i> <?= t('ui.this_message_will_be_visible_to_th_a6e6', 'This message will be visible to the student.') ?></div>
                    </div>

                    <div class="form-group">
                        <label><?= t('ui.attachment_e9cb', 'Attachment') ?> <span style="color:#9CA3AF;font-weight:400;"><?= t('ui.optional_f53d', '(optional)') ?></span></label>
                        <?php if (!empty($complaint['admin_attachment'])): ?>
                        <?php $af = $complaint['admin_attachment']; $isImg = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $af); ?>
                        <div style="margin-bottom:10px;padding:10px 12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;display:flex;align-items:center;gap:10px;font-size:13px;">
                            <i class="ti <?= $isImg ? 'ti-photo' : 'ti-file' ?>" style="color:#6B7280;font-size:18px;"></i>
                            <span style="flex:1;color:#374151;"><?= t('ui.current_83a3', 'Current:') ?> <a href="../uploads/<?= e($af) ?>" target="_blank" style="color:var(--maroon);"><?= e($af) ?></a></span>
                            <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:#9CA3AF;cursor:pointer;margin:0;">
                                <input type="checkbox" name="remove_admin_attachment" value="1"> <?= t('ui.remove_1063', 'Remove') ?>
                            </label>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="admin_attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                        <small style="color:#9CA3AF;font-size:11px;margin-top:4px;display:block;"><?= t('ui.allowed_jpg_png_pdf_doc_max_5mb_3c84', 'Allowed: JPG, PNG, PDF, DOC · Max 5MB') ?></small>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block" id="save-btn">
                        <i class="ti ti-device-floppy"></i> <?= t('ui.save_update_15d9', 'Save Update') ?>
                    </button>
                </form>
        </div></aside>
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
                <div style="font-size:15px;font-weight:700;color:#1F2937;margin-bottom:5px;"><?= t('ui.reject_this_complaint_5b9b', 'Reject this complaint?') ?></div>
                <div style="font-size:13px;color:#6B7280;line-height:1.6;"><?= t('ui.this_will_mark_the_complaint_as_6597', 'This will mark the complaint as') ?> <strong style="color:#DC2626;"><?= t('ui.rejected_d37b', 'Rejected') ?></strong> <?= t('ui.and_the_student_will_be_notified_t_fa6e', 'and the student will be notified. This action can still be changed later.') ?></div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button id="confirm-cancel-btn" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <button id="confirm-ok-btn" class="btn btn-sm" style="background:#DC2626;color:#fff;border-color:#DC2626;">
                <i class="ti ti-circle-x"></i> <?= t('ui.yes_reject_b796', 'Yes, Reject') ?>
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
<!-- Floating shortcut: shows once the header button has scrolled out of view -->
<button type="button" class="btn btn-primary upd-open" id="upd-fab" hidden><i class="ti ti-edit"></i> <?= t('ui.update_status_a90e', 'Update Status') ?></button>

<script>
// Update Status panel
(function () {
    var drawer  = document.getElementById('upd-drawer');
    var overlay = document.getElementById('upd-overlay');
    var closeBt = document.getElementById('upd-close');
    var fab     = document.getElementById('upd-fab');
    var hdrBtn  = document.querySelector('.upd-open');   // first one = header button
    function openPanel() {
        drawer.classList.add('open'); overlay.classList.add('open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { var s = document.getElementById('status-select'); if (s) s.focus(); }, 200);
    }
    function closePanel() {
        drawer.classList.remove('open'); overlay.classList.remove('open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    document.querySelectorAll('.upd-open').forEach(function (b) { b.addEventListener('click', openPanel); });
    overlay.addEventListener('click', closePanel);
    closeBt.addEventListener('click', closePanel);
    // Capture phase: if the __t("Reject?") confirm box is open, Esc only closes that box.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var cm = document.getElementById('confirm-modal-overlay');
        if (cm && cm.style.display === 'flex') return;
        closePanel();
    }, true);
    if ('IntersectionObserver' in window && hdrBtn) {
        new IntersectionObserver(function (en) { fab.hidden = en[0].isIntersecting; }, { threshold: 0 }).observe(hdrBtn);
    } else if (fab) { fab.hidden = false; }
})();
</script>
<script src="../assets/js/alerts.js"></script>
<script src="../assets/js/sidebar.js?v=5"></script>
</body></html>
