<?php
// Start session FIRST before any includes
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/pager.php';
require_once '../includes/mailer.php';
require_once '../includes/csrf.php';

// AJAX handler — check BEFORE requireAdmin() so session redirect doesn't break fetch()
if (isset($_POST['ajax_action'])) {
    // Clear any accidental output, send JSON only
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Still verify the user is actually an admin via session
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in again.']);
        exit;
    }

    // CSRF check — same-origin session check above isn't enough on its own,
    // this stops a third-party site from silently triggering approve/reject/edit.
    $sentToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired request. Please refresh the page and try again.']);
        exit;
    }

    $action = trim($_POST['ajax_action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid student ID.']);
        exit;
    }

    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE students SET is_active=1, approved_at=NOW() WHERE id=? AND is_active=0");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            // Return student data so JS can add the row immediately without refresh
            $student = $pdo->prepare("SELECT * FROM students WHERE id=?");
            $student->execute([$id]);
            $s = $student->fetch();

            // Notify the student: in-app notification + email (if they gave one)
            addNotification($pdo, $s['id'], 'Your account has been approved! You can now log in.', '../index.php');
            if (!empty($s['email'])) {
                sendApprovalEmail($s['email'], $s['first_name']);
            }

            echo json_encode([
                'success'    => true,
                'message'    => 'Student account approved!',
                'student'    => [
                    'id'         => $s['id'],
                    'student_id' => $s['student_id'],
                    'first_name' => $s['first_name'],
                    'last_name'  => $s['last_name'],
                    'course'     => $s['course'] ?? '',
                    'is_active'  => 1,
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found or already approved.']);
        }
    } elseif ($action === 'reject') {
        // A reason is required so the student knows why they were rejected
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);
        if ($reason === '') {
            echo json_encode(['success' => false, 'message' => 'Please enter a reason for rejecting this registration.']);
            exit;
        }
        // Fetch details first — we need them for the rejection email, and the
        // row will be deleted (so there's no account left to notify in-app).
        $student = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=0");
        $student->execute([$id]);
        $s = $student->fetch();

        $stmt = $pdo->prepare("DELETE FROM students WHERE id=? AND is_active=0");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            $emailed = false;
            if ($s && !empty($s['email'])) {
                $emailed = sendRejectionEmail($s['email'], $s['first_name'], $reason);
            }
            echo json_encode(['success' => true, 'message' => $emailed
                ? 'Registration rejected. The reason was emailed to the student.'
                : 'Registration rejected. (No email sent — the student has no email on file or sending failed.)']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found or already processed.']);
        }
    } elseif ($action === 'edit') {
        $student_id = trim($_POST['student_id'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $course     = trim($_POST['course']     ?? '');
        $email      = trim($_POST['email']      ?? '');

        if (!$first_name || !$last_name || !$student_id) {
            echo json_encode(['success' => false, 'message' => 'First name, last name, and Student ID are required.']);
            exit;
        }

        if (!ctype_digit($student_id)) {
            echo json_encode(['success' => false, 'message' => 'Student ID must contain numbers only (no letters or symbols).']);
            exit;
        }

        // Check duplicate student_id (excluding current record)
        $dup = $pdo->prepare("SELECT id FROM students WHERE student_id=? AND id!=?");
        $dup->execute([$student_id, $id]);
        if ($dup->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Student ID already exists for another student.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE students SET student_id=?, first_name=?, last_name=?, course=?, email=? WHERE id=?");
        $stmt->execute([$student_id, $first_name, $last_name, $course, $email ?: null, $id]);

        echo json_encode([
            'success'    => true,
            'message'    => 'Student updated successfully!',
            'student'    => [
                'id'         => $id,
                'student_id' => $student_id,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'course'     => $course,
                'email'      => $email,
            ]
        ]);
    } elseif ($action === 'enable_disabled') {
        $stmt = $pdo->prepare("UPDATE students SET is_active=1, disabled_at=NULL, approved_at=COALESCE(approved_at, NOW()) WHERE id=? AND is_active=0 AND disabled_at IS NOT NULL");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            $student = $pdo->prepare("SELECT * FROM students WHERE id=?");
            $student->execute([$id]);
            $s = $student->fetch();

            addNotification($pdo, $s['id'], 'Your account has been re-enabled. You can now log in.', '../index.php');
            if (!empty($s['email'])) {
                sendApprovalEmail($s['email'], $s['first_name']);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Student account enabled!',
                'student' => [
                    'id'         => $s['id'],
                    'student_id' => $s['student_id'],
                    'first_name' => $s['first_name'],
                    'last_name'  => $s['last_name'],
                    'course'     => $s['course'] ?? '',
                    'is_active'  => 1,
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found or not currently disabled.']);
        }
    } elseif ($action === 'delete_disabled') {
        $adminPassword = $_POST['admin_password'] ?? '';
        if (!verifyAdminPassword($pdo, $_SESSION['user_id'] ?? 0, $adminPassword)) {
            echo json_encode(['success' => false, 'message' => 'Incorrect admin password.']);
            exit;
        }

        $student = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=0 AND disabled_at IS NOT NULL");
        $student->execute([$id]);
        $s = $student->fetch();

        $stmt = $pdo->prepare("DELETE FROM students WHERE id=? AND is_active=0 AND disabled_at IS NOT NULL");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Disabled account deleted permanently.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found or not currently disabled.']);
        }
    } elseif ($action === 'delete_active') {
        $adminPassword = $_POST['admin_password'] ?? '';
        if (!verifyAdminPassword($pdo, $_SESSION['user_id'] ?? 0, $adminPassword)) {
            echo json_encode(['success' => false, 'message' => 'Incorrect admin password.']);
            exit;
        }

        $student = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=1");
        $student->execute([$id]);
        $s = $student->fetch();

        $stmt = $pdo->prepare("DELETE FROM students WHERE id=? AND is_active=1");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Student account deleted permanently.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Student not found or no longer active.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
    exit;
}

requireAdmin();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$success = $error = '';



if (isset($_GET['toggle'])) {
    requireCsrf();
    $val = (int)$_GET['val'];
    if ($val === 1) {
        $pdo->prepare("UPDATE students SET is_active=1, disabled_at=NULL, approved_at=COALESCE(approved_at, NOW()) WHERE id=?")->execute([(int)$_GET['id']]);
    } else {
        $pdo->prepare("UPDATE students SET is_active=0, disabled_at=NOW() WHERE id=?")->execute([(int)$_GET['id']]);
    }
    header('Location: students.php'); exit;
}

// Approve a pending self-registration
if (isset($_GET['approve'])) {
    $approveId = (int)$_GET['approve'];
    $pdo->prepare("UPDATE students SET is_active=1, approved_at=NOW() WHERE id=? AND is_active=0 AND disabled_at IS NULL")->execute([$approveId]);
    $s = $pdo->prepare("SELECT * FROM students WHERE id=?");
    $s->execute([$approveId]);
    $s = $s->fetch();
    if ($s) {
        addNotification($pdo, $s['id'], 'Your account has been approved! You can now log in.', '../index.php');
        if (!empty($s['email'])) sendApprovalEmail($s['email'], $s['first_name']);
    }
    $success = 'Student account approved!';
}

// Reject (delete) a pending self-registration
if (isset($_GET['reject'])) {
    $rejectId = (int)$_GET['reject'];
    $s = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=0 AND disabled_at IS NULL");
    $s->execute([$rejectId]);
    $s = $s->fetch();
    $pdo->prepare("DELETE FROM students WHERE id=? AND is_active=0 AND disabled_at IS NULL")->execute([$rejectId]);
    if ($s && !empty($s['email'])) sendRejectionEmail($s['email'], $s['first_name']);
    $success = 'Pending registration removed.';
}

// Auto-delete accounts that have been disabled for 30+ days (at most once every 5 minutes, not on every request)
if (empty($_SESSION['stu_clean_ts']) || time() - (int)$_SESSION['stu_clean_ts'] > 300) {
    $pdo->exec("DELETE FROM students WHERE is_active=0 AND disabled_at IS NOT NULL AND disabled_at <= (NOW() - INTERVAL 30 DAY)");
    $_SESSION['stu_clean_ts'] = time();
}

// Pending = brand-new self-registrations awaiting approval (never disabled_at)
$pending  = $pdo->query("SELECT * FROM students WHERE is_active=0 AND disabled_at IS NULL ORDER BY created_at DESC")->fetchAll();

// Disabled = previously active accounts an admin turned off; these auto-delete after 30 days
$disabled = $pdo->query("SELECT * FROM students WHERE is_active=0 AND disabled_at IS NOT NULL ORDER BY disabled_at DESC")->fetchAll();

// Tab that opens first: Pending Approvals if anyone is waiting, otherwise Active Students
$startTab = count($pending) > 0 ? 'pending' : 'active';

// Get the currently active election (if any) so we can show each student's voting status
$activeElection = getActiveElection($pdo);
// ── Active Students table: server-side search / filters / sort / pagination ──
// Only one page (25 rows) is loaded and rendered at a time, so the page stays fast with thousands of students.
$perPage   = pagerSize([25, 50, 100], 25);
$page      = max(1, (int)($_GET['page'] ?? 1));
$search    = trim($_GET['search'] ?? '');
$courseSel = trim($_GET['course'] ?? '');
$sort      = $_GET['sort'] ?? '';
$dir       = (($_GET['dir'] ?? '') === 'desc') ? 'desc' : 'asc';
$dYear     = (int)($_GET['dyear'] ?? 0);
$dMonth    = (int)($_GET['dmonth'] ?? 0);
$dMonthTo  = (int)($_GET['dmonth_to'] ?? 0);
if ($dYear < 2000 || $dYear > 2100) { $dYear = 0; }
if (!$dYear || $dMonth < 1 || $dMonth > 12) { $dMonth = 0; }
if (!$dMonth || $dMonthTo <= $dMonth || $dMonthTo > 12) { $dMonthTo = 0; }

$where  = 's.is_active = 1';
$params = [];
if ($search !== '') {
    $where .= " AND CONCAT_WS(' ', s.student_id, s.first_name, s.last_name, s.course) LIKE ?";
    $params[] = '%' . $search . '%';
}
if ($courseSel !== '') { $where .= ' AND s.course = ?'; $params[] = $courseSel; }
if ($dYear) {
    $m1 = $dMonth ?: 1;
    $m2 = $dMonth ? ($dMonthTo ?: $dMonth) : 12;
    $dStart = new DateTime(sprintf('%04d-%02d-01', $dYear, $m1));
    $dEnd   = (clone $dStart)->modify('+' . ($m2 - $m1 + 1) . ' months');
    $where .= ' AND s.approved_at >= ? AND s.approved_at < ?';
    array_push($params, $dStart->format('Y-m-d'), $dEnd->format('Y-m-d'));
}
$hasFilter = ($search !== '' || $courseSel !== '' || $dYear);

$votedExpr = $activeElection ? '(EXISTS (SELECT 1 FROM votes v WHERE v.student_id = s.id AND v.election_id = ' . (int)$activeElection['id'] . '))' : '0';
$D = strtoupper($dir);
$sortSql = [
    'sid'      => "CAST(s.student_id AS UNSIGNED) $D, s.student_id $D",
    'name'     => "s.last_name $D, s.first_name $D",
    'approved' => "(s.approved_at IS NULL) ASC, s.approved_at $D",
    'voting'   => "$votedExpr $D",
];
if (!isset($sortSql[$sort])) { $sort = ''; $orderBy = 's.last_name ASC, s.first_name ASC'; }
else                         { $orderBy = $sortSql[$sort]; }
$orderBy .= ', s.id ASC';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE $where");
$cnt->execute($params);
$totalRows  = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT s.*, $votedExpr AS voted_now FROM students s WHERE $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
$st->execute($params);
$students = $st->fetchAll();

// Header-menu data: every course, and the months/years that have approvals
$courseOptions = $pdo->query("SELECT DISTINCT course FROM students WHERE is_active=1 AND course IS NOT NULL AND course <> '' ORDER BY course")->fetchAll(PDO::FETCH_COLUMN);
$dateMonths = [];
foreach ($pdo->query("SELECT YEAR(approved_at) y, MONTH(approved_at) m FROM students WHERE is_active=1 AND approved_at IS NOT NULL GROUP BY y, m ORDER BY y, m")->fetchAll() as $r) {
    $dateMonths[(string)$r['y']][] = (int)$r['m'];
}
$anyActive = ($totalRows > 0) || $hasFilter;

function stuUrl($overrides = []) {
    $p = array_merge([
        'search' => $_GET['search'] ?? '', 'course' => $_GET['course'] ?? '',
        'dmonth' => $_GET['dmonth'] ?? '', 'dmonth_to' => $_GET['dmonth_to'] ?? '', 'dyear' => $_GET['dyear'] ?? '',
        'sort' => $_GET['sort'] ?? '', 'dir' => $_GET['dir'] ?? '', 'page' => $_GET['page'] ?? 1,
        'pp' => $_GET['pp'] ?? '',
    ], $overrides);
    $p = array_filter($p, function ($v) { return $v !== '' && $v !== null; });
    return 'students.php?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=23">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
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
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> All Complaints</a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> Elections</a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> Candidates</a>
    <a class="sitem" href="party-lists.php"><i class="ti ti-flag"></i> Party Lists</a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="results.php"><i class="ti ti-chart-pie"></i> Live Results</a>
    <div class="sidebar-section">Events</div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> Events</a>
    <div class="sidebar-section">System</div>
    <a class="sitem active" href="students.php"><i class="ti ti-user-plus"></i> Students</a>
    <a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> OSA Staff</a>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> Reports</a>
    <div class="sidebar-section">Account</div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> Notifications
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header" id="students-page-header"><h1><?= t('admin.students.title', 'Manage Students') ?></h1><p><?= t('admin.students.subtitle', 'Add and manage student accounts') ?></p></div>
    <?php if ($success): ?><div class="alert alert-success"><i class="ti ti-check"></i><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= e($error) ?></div><?php endif; ?>

    <!-- Toast notification -->
    <div id="toast" style="position:fixed;top:20px;right:24px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,0.15);display:none;align-items:center;gap:10px;"></div>

    <!-- Custom Confirm Modal -->
    <div id="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:8000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:14px;padding:28px 28px 22px;width:100%;max-width:380px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <div id="modal-icon" style="width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;"></div>
                <div>
                    <div id="modal-title" style="font-size:15px;font-weight:600;color:#1F2937;"></div>
                    <div id="modal-body" style="font-size:13px;color:#6B7280;margin-top:3px;"></div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                <button onclick="closeModal()" class="btn btn-outline btn-sm">Cancel</button>
                <button id="modal-confirm-btn" class="btn btn-sm">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Reject Reason Modal -->
    <div id="reject-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:8500;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;border-radius:14px;padding:26px;width:100%;max-width:440px;box-shadow:0 8px 32px rgba(0,0,0,0.18);">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <div style="width:42px;height:42px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="ti ti-user-x" style="color:#991B1B;font-size:20px;"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:600;color:#1F2937;">Reject Registration</div>
                    <div id="reject-modal-name" style="font-size:13px;color:#6B7280;margin-top:2px;"></div>
                </div>
            </div>
            <p style="font-size:12px;color:#991B1B;background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:9px 12px;margin-bottom:12px;">
                This permanently deletes the registration and cannot be undone.
            </p>
            <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:6px;">Reason for rejection <span style="color:#DC2626;">*</span></label>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
                <button type="button" class="btn btn-outline btn-sm reject-chip" data-reason="COR is unclear or unreadable.">Unclear COR</button>
                <button type="button" class="btn btn-outline btn-sm reject-chip" data-reason="Student ID does not match our records.">ID mismatch</button>
                <button type="button" class="btn btn-outline btn-sm reject-chip" data-reason="Not currently enrolled.">Not enrolled</button>
                <button type="button" class="btn btn-outline btn-sm reject-chip" data-reason="Duplicate registration.">Duplicate</button>
            </div>
            <textarea id="reject-reason" class="form-control" rows="3" maxlength="500" placeholder="Type the reason (this will be emailed to the student)..." style="resize:vertical;margin-bottom:4px;" oninput="document.getElementById('reject-modal-error').style.display='none'"></textarea>
            <div id="reject-modal-warn" style="display:none;color:#92400E;font-size:12px;margin-bottom:4px;"><i class="ti ti-mail-off"></i> This student has no email on file, so the reason cannot be sent.</div>
            <div id="reject-modal-error" style="display:none;color:#991B1B;font-size:12px;margin-bottom:4px;">Please enter a reason.</div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">
                <button type="button" onclick="closeRejectModal()" class="btn btn-outline btn-sm">Cancel</button>
                <button type="button" onclick="submitReject()" class="btn btn-sm btn-danger"><i class="ti ti-x"></i> Reject Registration</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal (requires admin password) -->
    <div id="delete-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:8600;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,0.18);">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <div style="width:42px;height:42px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="ti ti-trash" style="color:#991B1B;font-size:20px;"></i>
                </div>
                <div>
                    <div style="font-size:15px;font-weight:600;color:#1F2937;">Delete Student Account</div>
                    <div id="delete-modal-name" style="font-size:13px;color:#6B7280;margin-top:2px;"></div>
                </div>
            </div>
            <p style="font-size:12px;color:#991B1B;background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 12px;margin-bottom:14px;">
                This will permanently delete this student account. This cannot be undone.
            </p>
            <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Enter your admin password to confirm</label>
            <input type="password" id="delete-modal-password" class="form-control" placeholder="Admin password" style="margin-bottom:6px;" autocomplete="current-password">
            <div id="delete-modal-error" style="display:none;color:#991B1B;font-size:12px;margin-bottom:4px;"></div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
                <button onclick="closeDeleteModal()" class="btn btn-outline btn-sm">Cancel</button>
                <button id="delete-modal-confirm-btn" onclick="submitDeleteModal()" class="btn btn-sm btn-danger"><i class="ti ti-trash"></i> Delete Permanently</button>
            </div>
        </div>
    </div>

    <div class="stu-tabs">
        <button type="button" class="stu-tab <?= $startTab === 'pending' ? 'active' : '' ?>" id="stu-tab-btn-pending" onclick="switchStuTab('pending')">
            <i class="ti ti-clock"></i> Pending Approvals
            <span id="pending-tab-badge" class="stu-tab-badge" style="background:#D97706;<?= count($pending) === 0 ? 'display:none;' : '' ?>"><?= count($pending) ?></span>
        </button>
        <button type="button" class="stu-tab <?= $startTab === 'active' ? 'active' : '' ?>" id="stu-tab-btn-active" onclick="switchStuTab('active')">
            <i class="ti ti-users"></i> Active Students
        </button>
        <button type="button" class="stu-tab" id="stu-tab-btn-archived" onclick="switchStuTab('archived')">
            <i class="ti ti-user-off"></i> Disabled
            <?php if (count($disabled) > 0): ?><span id="archived-tab-badge" class="stu-tab-badge"><?= count($disabled) ?></span><?php endif; ?>
        </button>
    </div>

    <div id="tabpanel-pending" class="stu-tabpanel" style="<?= $startTab === 'pending' ? '' : 'display:none;' ?>">
    <div class="card" id="pending-card" style="border:2px solid #FCD34D;background:#FFFBEB;margin-bottom:20px;">
        <div class="card-title" style="color:#92400E;">
            <i class="ti ti-clock" style="color:#D97706;"></i>
            Pending Approvals
            <span id="pending-count" style="background:#D97706;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;margin-left:6px;"><?= count($pending) ?></span>
        </div>
        <div class="table-wrap" id="pending-table-wrap" style="<?= count($pending) === 0 ? 'display:none;' : '' ?>"><table>
            <thead><tr><th>Student ID</th><th>Name</th><th>Course</th><th>Email</th><th>COR</th><th>Date Registered</th><th>Action</th></tr></thead>
            <tbody id="pending-tbody">
            <?php foreach ($pending as $p): ?>
            <tr id="pending-row-<?= $p['id'] ?>">
                <td><?= e($p['student_id']) ?></td>
                <td><?= e($p['last_name'].', '.$p['first_name']) ?></td>
                <td><?= e($p['course'] ?: '—') ?></td>
                <td><?= e($p['email'] ?: '—') ?></td>
                <td style="white-space:nowrap;">
                    <?php if (!empty($p['cor_file'])): ?>
                        <a href="../uploads/<?= e($p['cor_file']) ?>" target="_blank" class="btn btn-sm btn-outline">
                            <i class="ti ti-file-text"></i> View COR
                        </a>
                    <?php else: ?>
                        <span style="color:#9CA3AF;font-size:12px;">No file</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;"><?= date('M d, Y', strtotime($p['created_at'])) ?></td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-sm btn-gold" onclick="handleApproval(<?= $p['id'] ?>, 'approve')">
                        <i class="ti ti-check"></i> Approve
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="handleApproval(<?= $p['id'] ?>, 'reject')" style="margin-left:4px;">
                        <i class="ti ti-x"></i> Reject
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div id="pending-empty" style="<?= count($pending) > 0 ? 'display:none;' : '' ?>text-align:center;padding:24px;color:#92400E;font-size:13px;">
            <i class="ti ti-circle-check" style="font-size:28px;display:block;margin-bottom:6px;"></i>
            No pending approvals.
        </div>
    </div>
    </div>

    <div id="tabpanel-archived" class="stu-tabpanel" style="display:none;">
    <div class="card" id="disabled-card" style="border:2px solid #FCA5A5;background:#FEF2F2;margin-bottom:20px;">
        <div class="card-title" style="color:#991B1B;">
            <i class="ti ti-user-off" style="color:#DC2626;"></i>
            Disabled Accounts
            <span id="disabled-count" style="background:#DC2626;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;margin-left:6px;"><?= count($disabled) ?></span>
        </div>
        <p style="font-size:12px;color:#991B1B;margin:-4px 0 12px;">Disabled accounts land here and are automatically and permanently deleted 30 days after being disabled, unless re-enabled before then.</p>
        <div id="disabled-search-wrap" style="<?= count($disabled) === 0 ? 'display:none;' : '' ?>position:relative;max-width:340px;margin-bottom:12px;">
            <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:15px;"></i>
            <input type="text" id="disabled-search" placeholder="Search disabled by name, ID, course..."
                   class="form-control" style="padding-left:32px;margin-bottom:0;background:#fff;"
                   oninput="filterDisabled(this.value)">
        </div>
        <div class="table-wrap" id="disabled-table-wrap" style="<?= count($disabled) === 0 ? 'display:none;' : '' ?>"><table>
            <thead><tr><th>Student ID</th><th>Name</th><th>Course</th><th>Disabled On</th><th>Auto-Delete In</th><th>Action</th></tr></thead>
            <tbody id="disabled-tbody">
            <?php foreach ($disabled as $d):
                $disabledTs   = strtotime($d['disabled_at']);
                $deleteTs     = strtotime('+30 days', $disabledTs);
                $daysLeft     = max(0, (int)ceil(($deleteTs - time()) / 86400));
            ?>
            <tr id="disabled-row-<?= $d['id'] ?>" data-search="<?= strtolower(e($d['student_id'].' '.$d['first_name'].' '.$d['last_name'].' '.$d['last_name'].', '.$d['first_name'].' '.$d['course'])) ?>">
                <td><?= e($d['student_id']) ?></td>
                <td><?= e($d['last_name'].', '.$d['first_name']) ?></td>
                <td><?= e($d['course'] ?: '—') ?></td>
                <td style="white-space:nowrap;"><?= date('M d, Y', $disabledTs) ?></td>
                <td style="white-space:nowrap;">
                    <span class="badge <?= $daysLeft <= 5 ? 'badge-closed' : 'badge-active' ?>" style="<?= $daysLeft <= 5 ? 'background:#FEE2E2;color:#991B1B;' : '' ?>">
                        <?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?>
                    </span>
                </td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-sm btn-gold" onclick="handleDisabledAction(<?= $d['id'] ?>, 'enable_disabled')">
                        <i class="ti ti-user-check"></i> Enable
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="openDeleteModal(<?= $d['id'] ?>, 'delete_disabled', '<?= addslashes(e($d['last_name'].', '.$d['first_name'])) ?>')" style="margin-left:4px;">
                        <i class="ti ti-trash"></i> Delete
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div id="disabled-no-results" style="display:none;text-align:center;padding:20px;color:#991B1B;font-size:13px;">
            <i class="ti ti-search-off" style="font-size:24px;display:block;margin-bottom:4px;"></i>
            No disabled account matches your search.
        </div>
        <div id="disabled-empty" style="<?= count($disabled) > 0 ? 'display:none;' : '' ?>text-align:center;padding:24px;color:#991B1B;font-size:13px;">
            <i class="ti ti-mood-smile" style="font-size:28px;display:block;margin-bottom:6px;"></i>
            No disabled accounts.
        </div>
    </div>
    </div>

<style>
.stu-tabs { display:flex; gap:4px; border-bottom:2px solid #E5E7EB; margin-bottom:18px; }
.stu-tab { display:flex; align-items:center; gap:6px; padding:10px 16px; font-size:13px; font-weight:600; color:#6B7280; background:none; border:none; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; transition:all .15s; }
.stu-tab:hover { color:#7C3AED; }
.stu-tab.active { color:#7C3AED; border-bottom-color:#7C3AED; }
.stu-tab-badge { background:#DC2626; color:#fff; border-radius:20px; padding:1px 8px; font-size:11px; }
</style>

<script>
var pendingCount = <?= count($pending) ?>;
var disabledCount = <?= count($disabled) ?>;
var _pendingExists = pendingCount > 0;
var _modalCallback = null;

function showToast(message, type) {
    var toast = document.getElementById('toast');
    var isSuccess = type === 'success';
    toast.style.background = isSuccess ? '#D1FAE5' : '#FEE2E2';
    toast.style.color      = isSuccess ? '#065F46' : '#991B1B';
    toast.style.border     = '1px solid ' + (isSuccess ? '#6EE7B7' : '#FECACA');
    toast.innerHTML = '<i class="ti ti-' + (isSuccess ? 'circle-check' : 'circle-x') + '" style="font-size:18px;"></i> ' + message;
    toast.style.opacity = '1';
    toast.style.transition = 'none';
    toast.style.display = 'flex';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(function() {
        toast.style.transition = 'opacity 0.5s ease';
        toast.style.opacity = '0';
        setTimeout(function() { toast.style.display = 'none'; toast.style.opacity = '1'; }, 500);
    }, 3000);
}

function openModal(title, body, confirmLabel, confirmClass, callback) {
    document.getElementById('modal-title').textContent = title;
    document.getElementById('modal-body').textContent  = body;
    var isApprove = confirmClass === 'btn-gold';
    var icon = document.getElementById('modal-icon');
    icon.style.background = isApprove ? '#FEF3C7' : '#FEE2E2';
    icon.innerHTML = '<i class="ti ti-' + (isApprove ? 'user-check' : 'user-x') + '" style="color:' + (isApprove ? '#D97706' : '#991B1B') + ';"></i>';
    var btn = document.getElementById('modal-confirm-btn');
    btn.textContent = confirmLabel;
    btn.className   = 'btn btn-sm ' + confirmClass;
    _modalCallback  = callback;
    var overlay = document.getElementById('modal-overlay');
    overlay.style.display = 'flex';
    setTimeout(function() { btn.focus(); }, 50);
}

function closeModal() {
    document.getElementById('modal-overlay').style.display = 'none';
    _modalCallback = null;
}

var _confirmBtn = document.getElementById('modal-confirm-btn');
var _overlay    = document.getElementById('modal-overlay');

if (_confirmBtn) {
    _confirmBtn.addEventListener('click', function() {
        var cb = _modalCallback; // save BEFORE closeModal() nulls it
        closeModal();
        if (cb) cb();
    });
}

if (_overlay) {
    _overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

function doApproval(id, action, reason) {
    var row = document.getElementById('pending-row-' + id);
    row.querySelectorAll('button').forEach(function(b) { b.disabled = true; b.style.opacity = '0.5'; });

    var formData = new FormData();
    formData.append('ajax_action', action);
    formData.append('id', id);
    if (reason) formData.append('reason', reason);
    formData.append('csrf_token', CSRF_TOKEN);

    // Use window.location.pathname to get the correct absolute URL regardless of subfolder
    var scriptUrl = window.location.pathname.replace(/\?.*$/, '');

    fetch(scriptUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) {
            // If we got redirected to login, session expired
            if (r.redirected || r.url.indexOf('index.php') !== -1) {
                showToast('Session expired. Please log in again.', 'error');
                setTimeout(function() { window.location.href = '../index.php'; }, 1500);
                return null;
            }
            // Check content type before parsing JSON
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return r.text().then(function(txt) {
                    console.error('Non-JSON response:', txt);
                    throw new Error('Server returned non-JSON. Check PHP errors.');
                });
            }
            return r.json();
        })
        .then(function(data) {
            if (!data) return;
            if (data.success) {
                row.style.transition = 'opacity 0.4s';
                row.style.opacity = '0';
                setTimeout(function() {
                    row.remove();
                    pendingCount--;
                    var countEl = document.getElementById('pending-count');
                    if (countEl) countEl.textContent = pendingCount;
                    var pBadge = document.getElementById('pending-tab-badge');
                    if (pBadge) { pBadge.textContent = pendingCount; pBadge.style.display = pendingCount > 0 ? '' : 'none'; }
                    if (pendingCount <= 0) {
                        var pWrap = document.getElementById('pending-table-wrap');
                        var pEmpty = document.getElementById('pending-empty');
                        if (pWrap) pWrap.style.display = 'none';
                        if (pEmpty) pEmpty.style.display = 'block';
                    }
                }, 400);
                showToast(data.message, 'success');
                // If approved, add the new row to Active Students table immediately
                if (action === 'approve' && data.student) {
                    var s = data.student;
                    var tbody = document.getElementById('student-tbody');
                    if (tbody) {
                        var searchVal = (s.student_id + ' ' + s.first_name + ' ' + s.last_name + ' ' + s.course + ' ').toLowerCase();
                        var newRow = document.createElement('tr');
                        newRow.setAttribute('data-search', searchVal);
                        newRow.setAttribute('data-approved-year', String(new Date().getFullYear()));
                        newRow.setAttribute('data-approved-month', String(new Date().getMonth() + 1));
                        newRow.innerHTML =
                            '<td>' + s.student_id + '</td>' +
                            '<td>' + s.last_name + ', ' + s.first_name + '</td>' +
                            '<td>' + (s.course || '—') + '</td>' +
                            '<td style="white-space:nowrap;">Just now <span class="badge badge-active" style="margin-left:4px;">NEW</span></td>' +
                            '<td><span class="badge badge-active">Active</span></td>' +
                            '<td><span class="badge badge-closed">Not Voted</span></td>' +
                            '<td><a href="?toggle=1&id=' + s.id + '&val=0&csrf_token=' + encodeURIComponent(CSRF_TOKEN) + '" class="btn btn-sm btn-outline">Disable</a></td>';
                        newRow.style.opacity = '0';
                        newRow.style.transition = 'opacity 0.4s';
                        tbody.insertBefore(newRow, tbody.firstChild);
                        setTimeout(function() { newRow.style.opacity = '1'; }, 50);
                        // Update active student count
                        var countEl = document.getElementById('student-count');
                        if (countEl) countEl.textContent = parseInt(countEl.textContent || '0') + 1;
                    }
                }
            } else {
                showToast(data.message || 'Something went wrong. Please try again.', 'error');
                row.querySelectorAll('button').forEach(function(b) { b.disabled = false; b.style.opacity = '1'; });
            }
        })
        .catch(function(err) {
            console.error('Approval error:', err);
            showToast('Error: ' + (err.message || 'Network error. Please try again.'), 'error');
            row.querySelectorAll('button').forEach(function(b) { b.disabled = false; b.style.opacity = '1'; });
        });
}

function decrementDisabledCount() {
    disabledCount = Math.max(0, disabledCount - 1);
    var countEl = document.getElementById('disabled-count');
    if (countEl) countEl.textContent = disabledCount;
    var tabBadge = document.getElementById('archived-tab-badge');
    if (tabBadge) {
        if (disabledCount > 0) tabBadge.textContent = disabledCount;
        else tabBadge.remove();
    }
    if (disabledCount <= 0) {
        var wrap = document.getElementById('disabled-table-wrap');
        var empty = document.getElementById('disabled-empty');
        if (wrap) wrap.style.display = 'none';
        if (empty) empty.style.display = 'block';
        var sw = document.getElementById('disabled-search-wrap');
        if (sw) sw.style.display = 'none';
        var nr = document.getElementById('disabled-no-results');
        if (nr) nr.style.display = 'none';
    } else {
        var si = document.getElementById('disabled-search');
        if (si && si.value) filterDisabled(si.value);
    }
}

function filterDisabled(query) {
    var q = (query || '').toLowerCase().trim();
    var rows = document.querySelectorAll('#disabled-tbody tr');
    var visible = 0;
    rows.forEach(function(row) {
        var match = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    var wrap = document.getElementById('disabled-table-wrap');
    var nr = document.getElementById('disabled-no-results');
    if (wrap) wrap.style.display = (visible === 0 && rows.length > 0) ? 'none' : '';
    if (nr) nr.style.display = (visible === 0 && rows.length > 0) ? 'block' : 'none';
}

function doDisabledAction(id, action) {
    var row = document.getElementById('disabled-row-' + id);
    row.querySelectorAll('button').forEach(function(b) { b.disabled = true; b.style.opacity = '0.5'; });

    var formData = new FormData();
    formData.append('ajax_action', action);
    formData.append('id', id);
    formData.append('csrf_token', CSRF_TOKEN);

    var scriptUrl = window.location.pathname.replace(/\?.*$/, '');

    fetch(scriptUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) {
            if (r.redirected || r.url.indexOf('index.php') !== -1) {
                showToast('Session expired. Please log in again.', 'error');
                setTimeout(function() { window.location.href = '../index.php'; }, 1500);
                return null;
            }
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return r.text().then(function(txt) {
                    console.error('Non-JSON response:', txt);
                    throw new Error('Server returned non-JSON. Check PHP errors.');
                });
            }
            return r.json();
        })
        .then(function(data) {
            if (!data) return;
            if (data.success) {
                row.style.transition = 'opacity 0.4s';
                row.style.opacity = '0';
                setTimeout(function() {
                    row.remove();
                    decrementDisabledCount();
                }, 400);
                showToast(data.message, 'success');
                // If re-enabled, add the row back into the Active Students table immediately
                if (action === 'enable_disabled' && data.student) {
                    var s = data.student;
                    var tbody = document.getElementById('student-tbody');
                    if (tbody) {
                        var searchVal = (s.student_id + ' ' + s.first_name + ' ' + s.last_name + ' ' + s.course + ' ').toLowerCase();
                        var newRow = document.createElement('tr');
                        newRow.id = 'student-row-' + s.id;
                        newRow.setAttribute('data-search', searchVal);
                        newRow.setAttribute('data-approved-year', String(new Date().getFullYear()));
                        newRow.setAttribute('data-approved-month', String(new Date().getMonth() + 1));
                        newRow.innerHTML =
                            '<td>' + s.student_id + '</td>' +
                            '<td>' + s.last_name + ', ' + s.first_name + '</td>' +
                            '<td>' + (s.course || '—') + '</td>' +
                            '<td style="white-space:nowrap;">Just now <span class="badge badge-active" style="margin-left:4px;">NEW</span></td>' +
                            '<td><span class="badge badge-active">Active</span></td>' +
                            '<td><span class="badge badge-closed">Not Voted</span></td>' +
                            '<td><a href="?toggle=1&id=' + s.id + '&val=0&csrf_token=' + encodeURIComponent(CSRF_TOKEN) + '" class="btn btn-sm btn-outline">Disable</a> ' +
                            '<button class="btn btn-sm btn-danger" onclick="openDeleteModal(' + s.id + ', \'delete_active\', \'' + (s.last_name + ', ' + s.first_name).replace(/'/g,"\\'") + '\')" style="margin-left:4px;"><i class="ti ti-trash"></i> Delete</button></td>';
                        newRow.style.opacity = '0';
                        newRow.style.transition = 'opacity 0.4s';
                        tbody.insertBefore(newRow, tbody.firstChild);
                        setTimeout(function() { newRow.style.opacity = '1'; }, 50);
                        var countEl = document.getElementById('student-count');
                        if (countEl) countEl.textContent = parseInt(countEl.textContent || '0') + 1;
                    }
                }
            } else {
                showToast(data.message || 'Something went wrong. Please try again.', 'error');
                row.querySelectorAll('button').forEach(function(b) { b.disabled = false; b.style.opacity = '1'; });
            }
        })
        .catch(function(err) {
            console.error('Disabled account action error:', err);
            showToast('Error: ' + (err.message || 'Network error. Please try again.'), 'error');
            row.querySelectorAll('button').forEach(function(b) { b.disabled = false; b.style.opacity = '1'; });
        });
}

function handleDisabledAction(id, action) {
    openModal(
        'Enable Student',
        'This will re-activate the account and cancel its scheduled deletion.',
        'Enable', 'btn-gold',
        function() { doDisabledAction(id, 'enable_disabled'); }
    );
}

function handleApproval(id, action) {
    if (action === 'approve') {
        openModal(
            'Approve Student',
            'Are you sure you want to approve this student account?',
            'Approve', 'btn-gold',
            function() { doApproval(id, 'approve'); }
        );
    } else {
        openRejectModal(id);
    }
}

var _rejectId = null;
function openRejectModal(id) {
    _rejectId = id;
    var row = document.getElementById('pending-row-' + id);
    var name = row ? row.children[1].textContent.trim() : '';
    var email = row ? row.children[3].textContent.trim() : '';
    document.getElementById('reject-modal-name').textContent = name;
    document.getElementById('reject-modal-warn').style.display = (!email || email === '—') ? 'block' : 'none';
    document.getElementById('reject-reason').value = '';
    document.getElementById('reject-modal-error').style.display = 'none';
    document.getElementById('reject-modal-overlay').style.display = 'flex';
    setTimeout(function() { document.getElementById('reject-reason').focus(); }, 50);
}
function closeRejectModal() {
    document.getElementById('reject-modal-overlay').style.display = 'none';
    _rejectId = null;
}
function submitReject() {
    var reason = document.getElementById('reject-reason').value.trim();
    if (reason.length < 3) {
        document.getElementById('reject-modal-error').style.display = 'block';
        document.getElementById('reject-reason').focus();
        return;
    }
    var id = _rejectId;
    closeRejectModal();
    if (id) doApproval(id, 'reject', reason);
}
document.querySelectorAll('.reject-chip').forEach(function(chip) {
    chip.addEventListener('click', function() {
        var ta = document.getElementById('reject-reason');
        ta.value = chip.getAttribute('data-reason');
        document.getElementById('reject-modal-error').style.display = 'none';
        ta.focus();
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeRejectModal();
});

function switchStuTab(tab) {
    var panels = { pending: 'tabpanel-pending', active: 'tabpanel-active', archived: 'tabpanel-archived' };
    var buttons = { pending: 'stu-tab-btn-pending', active: 'stu-tab-btn-active', archived: 'stu-tab-btn-archived' };
    Object.keys(panels).forEach(function(key) {
        document.getElementById(panels[key]).style.display = (key === tab) ? '' : 'none';
        document.getElementById(buttons[key]).classList.toggle('active', key === tab);
    });
    // "Manage Students" header is hidden on the Disabled tab
    var hdr = document.getElementById('students-page-header');
    if (hdr) hdr.style.display = (tab === 'archived') ? 'none' : '';
}
</script>
    <div id="tabpanel-active" class="stu-tabpanel" style="<?= $startTab === 'active' ? '' : 'display:none;' ?>">
    <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <div class="card-title" style="margin-bottom:0;"><i class="ti ti-users"></i> Active Students (<span id="student-count"><?= $totalRows ?></span>)</div>
                    <button onclick="openStudentImportModal()" class="btn btn-outline btn-sm" style="color:#7C3AED;border-color:#7C3AED;gap:5px;white-space:nowrap;">
                        <i class="ti ti-file-spreadsheet"></i> Import Excel
                    </button>
                    <?php if ($anyActive): ?>
                    <?= exportMenu('export.php?type=students', 'btn btn-outline btn-sm', 'color:#059669;border-color:#059669;gap:5px;white-space:nowrap;') ?>
                    <?php endif; ?>
                </div>
                <form id="student-filter-form" method="GET" action="students.php" onsubmit="return false;" style="position:relative;min-width:220px;margin:0;">
                    <?php if ($courseSel !== ''): ?><input type="hidden" name="course" value="<?= e($courseSel) ?>"><?php endif; ?>
                    <?php if ($sort): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>"><?php endif; ?>
                    <?php if ($dYear): ?><input type="hidden" name="dyear" value="<?= (int)$dYear ?>"><?php endif; ?>
                    <?php if ($dMonth): ?><input type="hidden" name="dmonth" value="<?= (int)$dMonth ?>"><?php endif; ?>
                    <?php if ($dMonthTo): ?><input type="hidden" name="dmonth_to" value="<?= (int)$dMonthTo ?>"><?php endif; ?>
                    <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:15px;"></i>
                    <input type="text" id="student-search" name="search" value="<?= e($search) ?>" placeholder="Search by name, ID, course..."
                           class="form-control" style="padding-left:32px;margin-bottom:0;" autocomplete="off">
                </form>
            </div>
            <div id="students-live"><div class="table-wrap"><table data-tt data-tt-server data-tt-form="student-filter-form" data-tt-live="students-live" data-sort-current="<?= e($sort) ?>" data-sort-dir="<?= e($dir) ?>">
                <thead><tr>
                    <th data-sort-key="sid" data-type="num">Student ID</th>
                    <th data-sort-key="name" data-type="text">Name</th>
                    <th data-tt-filter="course" data-tt-all="All Courses" data-tt-icon="ti-school" data-tt-value="<?= e($courseSel) ?>" data-tt-options="<?= e(json_encode(array_values($courseOptions))) ?>">Course</th>
                    <th data-sort-key="approved" data-type="date" data-tt-date data-tt-year="<?= $dYear ?: '' ?>" data-tt-month="<?= $dMonth ?: '' ?>" data-tt-month-to="<?= $dMonthTo ?: '' ?>" data-tt-months="<?= e(json_encode((object)$dateMonths)) ?>">Date Approved</th>
                    <th data-nosort>Status</th>
                    <th data-sort-key="voting" data-type="voting">Voting Status</th>
                    <th></th>
                </tr></thead>
                <tbody id="student-tbody"><?php if (empty($students)): ?>
                <tr class="tt-empty"><td colspan="7" style="text-align:center;padding:40px;color:#9CA3AF;">
                    <i class="ti ti-mood-sad" style="font-size:28px;display:block;margin-bottom:6px;"></i>
                    <?= $hasFilter ? 'No students match your filters.' : 'No students found.' ?>
                </td></tr>
                <?php endif; ?>
                <?php foreach ($students as $s):
                    $votedLabel = !$activeElection ? '' : (!empty($s['voted_now']) ? 'voted' : 'not voted');
                    $isRecent = !empty($s['approved_at']) && (strtotime($s['approved_at']) >= strtotime('-24 hours'));
                ?>
                <tr id="student-row-<?= $s['id'] ?>" data-sid="<?= e($s['student_id']) ?>" data-name="<?= strtolower(e($s['last_name'].' '.$s['first_name'])) ?>" data-course="<?= strtolower(e($s['course'])) ?>" data-approved="<?= !empty($s['approved_at']) ? strtotime($s['approved_at']) : 0 ?>" data-approved-year="<?= !empty($s['approved_at']) ? date('Y', strtotime($s['approved_at'])) : '' ?>" data-approved-month="<?= !empty($s['approved_at']) ? (int)date('n', strtotime($s['approved_at'])) : '' ?>" data-status="<?= $s['is_active'] ? 1 : 0 ?>" data-voting="<?= !$activeElection ? -1 : (!empty($s['voted_now']) ? 1 : 0) ?>" data-search="<?= strtolower(e($s['student_id'].' '.$s['first_name'].' '.$s['last_name'].' '.$s['course'].' '.$votedLabel)) ?>">
                    <td><?= e($s['student_id']) ?></td>
                    <td><?= e($s['last_name'].', '.$s['first_name']) ?></td>
                    <td><?= e($s['course']) ?></td>
                    <td style="white-space:nowrap;">
                        <?php if (!empty($s['approved_at'])): ?>
                            <?= date('M d, Y g:ia', strtotime($s['approved_at'])) ?>
                            <?php if ($isRecent): ?>
                                <span class="badge badge-active" style="margin-left:4px;">NEW</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#9CA3AF;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $s['is_active'] ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-closed">Inactive</span>' ?></td>
                    <td>
                        <?php if (!$activeElection): ?>
                            <span class="badge badge-closed" title="No election is currently active">No active election</span>
                        <?php elseif (!empty($s['voted_now'])): ?>
                            <span class="badge badge-active"><i class="ti ti-check"></i> Voted</span>
                        <?php else: ?>
                            <span class="badge badge-closed">Not Voted</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm btn-primary"
                            onclick="openEditModal(<?= $s['id'] ?>,'<?= addslashes(e($s['student_id'])) ?>','<?= addslashes(e($s['first_name'])) ?>','<?= addslashes(e($s['last_name'])) ?>','<?= addslashes(e($s['course'])) ?>','<?= addslashes(e($s['email'] ?? '')) ?>')">
                            <i class="ti ti-pencil"></i> Edit
                        </button>
                        <a href="?toggle=1&id=<?= $s['id'] ?>&val=<?= $s['is_active']?0:1 ?>&<?= csrfParam() ?>" class="btn btn-sm btn-outline"><?= $s['is_active']?'Disable':'Enable' ?></a>
                        <button class="btn btn-sm btn-danger" onclick="openDeleteModal(<?= $s['id'] ?>, 'delete_active', '<?= addslashes(e($s['last_name'].', '.$s['first_name'])) ?>')" style="margin-left:4px;">
                            <i class="ti ti-trash"></i> Delete
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php gpBar($page, $perPage, $totalRows, function (int $p, ?int $pp = null) {
                return $pp !== null ? stuUrl(['pp' => $pp, 'page' => 1]) : stuUrl(['page' => $p]);
            }, [25, 50, 100], true, 'student'); ?>
            </div><!-- /#students-live -->
        </div>
</div>
</div></div>
<script>
// Focus search on Ctrl+F or /
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey && e.key === 'f') || e.key === '/') {
        var s = document.getElementById('student-search');
        if (s && document.activeElement !== s) {
            e.preventDefault();
            s.focus();
        }
    }
});
</script>

<script src="../assets/js/table-tools.js?v=4"></script>
<script>
// Live search + paging for the Active Students list (the server returns just the current page)
(function () {
    var form = document.getElementById('student-filter-form');
    var input = document.getElementById('student-search');
    var live = document.getElementById('students-live');
    if (!form || !input || !live) return;
    var timer = null, seq = 0;
    function swapTo(url, scrollTop) {
        var mine = ++seq;
        live.style.opacity = '0.55';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                if (mine !== seq) return;
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.getElementById('students-live');
                var cnt = doc.getElementById('student-count');
                if (fresh) {
                    live.innerHTML = fresh.innerHTML;
                    if (cnt) document.getElementById('student-count').textContent = cnt.textContent;
                    if (window.TableTools) TableTools.init(live);
                    history.replaceState(null, '', url);
                    if (scrollTop) live.scrollIntoView({ block: 'start' });
                }
                live.style.opacity = '';
            })
            .catch(function () { if (mine === seq) live.style.opacity = ''; });
    }
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var p = new URLSearchParams(new FormData(form));
            p.set('page', '1');
            swapTo('students.php?' + p.toString());
        }, 300);
    });
    live.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('.gp-nav a') : null;
        if (!a) return;
        e.preventDefault();
        e.stopPropagation();
        swapTo(a.href, true);
    });
    live.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('.gp-size select')) swapTo(e.target.value, true);
    });
})();
</script>
<!-- ===== EDIT STUDENT MODAL ===== -->
<div id="edit-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:480px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;max-height:90vh;overflow-y:auto;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:38px;height:38px;border-radius:50%;background:#EDE9FE;display:flex;align-items:center;justify-content:center;">
                    <i class="ti ti-pencil" style="color:#7C3AED;font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-size:16px;font-weight:700;color:#1F2937;">Edit Student</div>
                    <div style="font-size:12px;color:#6B7280;">Update student information</div>
                </div>
            </div>
            <button onclick="closeEditModal()" style="background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:22px;line-height:1;padding:4px;"><i class="ti ti-x"></i></button>
        </div>

        <input type="hidden" id="edit-id">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Student ID <span style="color:#EF4444;">*</span></label>
                <input type="text" id="edit-student-id" class="form-control" placeholder="e.g. 20193372" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/[^0-9]/g,'')" style="margin-bottom:0;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">First Name <span style="color:#EF4444;">*</span></label>
                <input type="text" id="edit-first-name" class="form-control" placeholder="First name" style="margin-bottom:0;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Last Name <span style="color:#EF4444;">*</span></label>
                <input type="text" id="edit-last-name" class="form-control" placeholder="Last name" style="margin-bottom:0;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Course</label>
                <input type="text" id="edit-course" class="form-control" placeholder="e.g. BSIT" style="margin-bottom:0;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Email</label>
                <input type="email" id="edit-email" class="form-control" placeholder="student@gnc.edu.ph" style="margin-bottom:0;">
            </div>
        </div>

        <div id="edit-error" style="display:none;margin-top:14px;padding:10px 14px;background:#FEE2E2;color:#991B1B;border-radius:8px;font-size:13px;"></div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;">
            <button onclick="closeEditModal()" class="btn btn-outline btn-sm">Cancel</button>
            <button onclick="submitEdit()" id="edit-save-btn" class="btn btn-sm btn-primary"><i class="ti ti-device-floppy"></i> Save Changes</button>
        </div>
    </div>
</div>

<script>
var CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;

var _deleteTarget = null; // { id, action, row }

function openDeleteModal(id, action, name) {
    var row = (action === 'delete_active')
        ? document.getElementById('student-row-' + id)
        : document.getElementById('disabled-row-' + id);
    _deleteTarget = { id: id, action: action, row: row };
    document.getElementById('delete-modal-name').textContent = name || '';
    document.getElementById('delete-modal-password').value = '';
    document.getElementById('delete-modal-error').style.display = 'none';
    var btn = document.getElementById('delete-modal-confirm-btn');
    btn.disabled = false;
    btn.innerHTML = '<i class="ti ti-trash"></i> Delete Permanently';
    document.getElementById('delete-modal-overlay').style.display = 'flex';
    setTimeout(function() { document.getElementById('delete-modal-password').focus(); }, 80);
}

function closeDeleteModal() {
    document.getElementById('delete-modal-overlay').style.display = 'none';
    _deleteTarget = null;
}

document.getElementById('delete-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
document.getElementById('delete-modal-password').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') submitDeleteModal();
});

function submitDeleteModal() {
    if (!_deleteTarget) return;
    var password = document.getElementById('delete-modal-password').value;
    var errEl = document.getElementById('delete-modal-error');
    if (!password) {
        errEl.textContent = 'Please enter your admin password.';
        errEl.style.display = 'block';
        return;
    }

    var target = _deleteTarget;
    var btn = document.getElementById('delete-modal-confirm-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader-2" style="animation:spin 1s linear infinite;display:inline-block;"></i> Deleting...';
    errEl.style.display = 'none';

    var fd = new FormData();
    fd.append('ajax_action', target.action);
    fd.append('id', target.id);
    fd.append('admin_password', password);
    fd.append('csrf_token', CSRF_TOKEN);

    var scriptUrl = window.location.pathname.replace(/\?.*$/, '');
    fetch(scriptUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) {
            if (r.redirected || r.url.indexOf('index.php') !== -1) {
                showToast('Session expired. Please log in again.', 'error');
                setTimeout(function() { window.location.href = '../index.php'; }, 1500);
                return null;
            }
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return r.text().then(function(txt) {
                    console.error('Non-JSON response:', txt);
                    throw new Error('Server returned non-JSON. Check PHP errors.');
                });
            }
            return r.json();
        })
        .then(function(data) {
            if (!data) return;
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-trash"></i> Delete Permanently';
            if (data.success) {
                closeDeleteModal();
                showToast(data.message, 'success');
                if (target.row) {
                    target.row.style.transition = 'opacity 0.4s';
                    target.row.style.opacity = '0';
                    setTimeout(function() {
                        target.row.remove();
                        if (target.action === 'delete_active') {
                            var countEl = document.getElementById('student-count');
                            if (countEl) countEl.textContent = Math.max(0, parseInt(countEl.textContent || '0') - 1);
                        } else if (target.action === 'delete_disabled') {
                            decrementDisabledCount();
                        }
                    }, 400);
                }
            } else {
                errEl.textContent = data.message || 'Something went wrong.';
                errEl.style.display = 'block';
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-trash"></i> Delete Permanently';
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = 'block';
        });
}

function openEditModal(id, studentId, firstName, lastName, course, email) {
    document.getElementById('edit-id').value          = id;
    document.getElementById('edit-student-id').value  = studentId;
    document.getElementById('edit-first-name').value  = firstName;
    document.getElementById('edit-last-name').value   = lastName;
    document.getElementById('edit-course').value      = course;
    document.getElementById('edit-email').value       = email;
    document.getElementById('edit-error').style.display = 'none';
    document.getElementById('edit-modal-overlay').style.display = 'flex';
    setTimeout(function(){ document.getElementById('edit-first-name').focus(); }, 80);
}

function closeEditModal() {
    document.getElementById('edit-modal-overlay').style.display = 'none';
}

document.getElementById('edit-modal-overlay').addEventListener('click', function(e){
    if (e.target === this) closeEditModal();
});

function submitEdit() {
    var id         = document.getElementById('edit-id').value;
    var studentId  = document.getElementById('edit-student-id').value.trim();
    var firstName  = document.getElementById('edit-first-name').value.trim();
    var lastName   = document.getElementById('edit-last-name').value.trim();
    var course     = document.getElementById('edit-course').value.trim();    var email      = document.getElementById('edit-email').value.trim();
    var errEl      = document.getElementById('edit-error');

    if (!studentId || !firstName || !lastName) {
        errEl.textContent = 'Student ID, first name, and last name are required.';
        errEl.style.display = 'block';
        return;
    }

    if (!/^[0-9]+$/.test(studentId)) {
        errEl.textContent = 'Student ID must contain numbers only (no letters or symbols).';
        errEl.style.display = 'block';
        return;
    }

    var saveBtn = document.getElementById('edit-save-btn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="ti ti-loader-2" style="animation:spin 1s linear infinite;display:inline-block;"></i> Saving...';
    errEl.style.display = 'none';

    var fd = new FormData();
    fd.append('ajax_action', 'edit');
    fd.append('csrf_token',  CSRF_TOKEN);
    fd.append('id',          id);
    fd.append('student_id',  studentId);
    fd.append('first_name',  firstName);
    fd.append('last_name',   lastName);
    fd.append('course',      course);
    fd.append('email',       email);

    var scriptUrl = window.location.pathname.replace(/\?.*$/, '');
    fetch(scriptUrl, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(data){
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="ti ti-device-floppy"></i> Save Changes';
            if (data.success) {
                // Update the row in the table
                var s = data.student;
                var rows = document.querySelectorAll('#student-tbody tr');
                rows.forEach(function(row) {
                    var editBtn = row.querySelector('button[onclick^="openEditModal(' + s.id + ',"]');
                    if (!editBtn) {
                        // Try matching by checking onclick attribute
                        var btns = row.querySelectorAll('button');
                        btns.forEach(function(b){
                            if (b.getAttribute('onclick') && b.getAttribute('onclick').indexOf('openEditModal(' + s.id + ',') === 0) {
                                editBtn = b;
                            }
                        });
                    }
                    if (editBtn) {
                        var cells = row.querySelectorAll('td');
                        cells[0].textContent = s.student_id;
                        cells[1].textContent = s.last_name + ', ' + s.first_name;
                        cells[2].textContent = s.course || '—';
                        // Update search attribute
                        row.setAttribute('data-search', (s.student_id + ' ' + s.first_name + ' ' + s.last_name + ' ' + s.course).toLowerCase());
                        // Update edit button onclick with new values
                        editBtn.setAttribute('onclick',
                            "openEditModal(" + s.id + ",'" + s.student_id.replace(/'/g,"\\'") + "','" + s.first_name.replace(/'/g,"\\'") + "','" + s.last_name.replace(/'/g,"\\'") + "','" + (s.course||'').replace(/'/g,"\\'") + "','" + (s.email||'').replace(/'/g,"\\'") + "')"
                        );
                        // Highlight the row briefly
                        row.style.transition = 'background 0.3s';
                        row.style.background = '#EDE9FE';
                        setTimeout(function(){ row.style.background = ''; }, 1200);
                    }
                });
                closeEditModal();
                showToast(data.message, 'success');
            } else {
                errEl.textContent = data.message || 'Something went wrong.';
                errEl.style.display = 'block';
            }
        })
        .catch(function(err){
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="ti ti-device-floppy"></i> Save Changes';
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = 'block';
        });
}
</script>
<style>
@keyframes spin { to { transform: rotate(360deg); } }
.btn-primary { background:#7C3AED;color:#fff;border-color:#7C3AED; }
.btn-primary:hover { background:#6D28D9; }
</style>

<!-- ===== IMPORT STUDENTS MODAL ===== -->
<div id="student-import-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9300;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:14px;padding:28px;width:100%;max-width:640px;box-shadow:0 8px 32px rgba(0,0,0,0.18);max-height:90vh;overflow-y:auto;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <div style="font-size:15px;font-weight:600;color:#1F2937;"><i class="ti ti-file-spreadsheet" style="margin-right:6px;color:#7C3AED;"></i> Import Students via Excel</div>
            <button onclick="closeStudentImportModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;">&times;</button>
        </div>

        <div style="background:#EDE9FE;border:1px solid #C4B5FD;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
            <div style="font-size:13px;font-weight:600;color:#5B21B6;margin-bottom:6px;"><i class="ti ti-download" style="margin-right:4px;"></i> Step 1 — Get a file to edit</div>
            <div style="font-size:12px;color:#6D28D9;margin-bottom:10px;line-height:1.5;">
                Use the blank template below, <strong>or</strong> just edit an exported Student Masterlist directly (Students → Export) and re-upload it — both work.<br>
                Fill in: <strong>student_id, first_name, last_name</strong> (required) + course, email (optional).<br>
                <strong>Default password = Student ID.</strong> Students must change it on first login.<br>
                <strong>Tip:</strong> use an <strong>existing</strong> Student ID to edit that student's info instead of adding a new one — it will update their name/course/email, not duplicate the record.
            </div>
            <a href="import-students.php?template=1" class="btn btn-sm" style="background:#7C3AED;color:#fff;">
                <i class="ti ti-table-export"></i> Download Template (.xlsx)
            </a>
        </div>

        <div style="font-size:13px;font-weight:600;color:#1F2937;margin-bottom:10px;">
            <i class="ti ti-upload" style="margin-right:4px;color:#6B1A2A;"></i> Step 2 — Upload filled Excel file, then review the changes before importing
        </div>

        <div id="stu-drop-zone" style="border:2px dashed #D1D5DB;border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:all 0.2s;margin-bottom:14px;"
             onclick="document.getElementById('stu-excel-input').click()"
             ondragover="event.preventDefault();this.style.borderColor='#7C3AED';this.style.background='#F5F3FF';"
             ondragleave="this.style.borderColor='#D1D5DB';this.style.background='';"
             ondrop="handleStuDrop(event)">
            <i class="ti ti-file-spreadsheet" style="font-size:32px;color:#9CA3AF;display:block;margin-bottom:8px;"></i>
            <div id="stu-drop-label" style="font-size:13px;color:#6B7280;">Click to browse or drag & drop your Excel file here</div>
            <div style="font-size:11px;color:#9CA3AF;margin-top:4px;">.xlsx or .xls · max 10MB</div>
        </div>
        <input type="file" id="stu-excel-input" accept=".xlsx,.xls" style="display:none;" onchange="handleStuFile(this.files[0])">

        <div id="stu-import-result" style="display:none;"></div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
            <button onclick="closeStudentImportModal()" class="btn btn-outline btn-sm">Cancel</button>
            <button id="stu-import-btn" onclick="submitStudentImport()" class="btn btn-sm" style="background:#7C3AED;color:#fff;" disabled>
                <i class="ti ti-eye"></i> Preview Changes
            </button>
        </div>
    </div>
</div>

<script>
var _stuFile = null;
var _stuPreview = null;   // result of the last preview (null = not previewed yet)
var _STU_BTN_PREVIEW = '<i class="ti ti-eye"></i> Preview Changes';

function stuEsc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}
function stuResetPreview() {
    _stuPreview = null;
    var btn = document.getElementById('stu-import-btn');
    btn.innerHTML = _STU_BTN_PREVIEW;
    btn.style.background = '#7C3AED';
    btn.disabled = !_stuFile;
    document.getElementById('stu-import-result').style.display = 'none';
}
function openStudentImportModal() {
    _stuFile = null;
    document.getElementById('stu-excel-input').value = '';
    document.getElementById('stu-drop-label').textContent = 'Click to browse or drag & drop your Excel file here';
    document.getElementById('stu-drop-zone').style.cssText = 'border:2px dashed #D1D5DB;border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:all 0.2s;margin-bottom:14px;';
    stuResetPreview();
    document.getElementById('student-import-overlay').style.display = 'flex';
}
function closeStudentImportModal() {
    document.getElementById('student-import-overlay').style.display = 'none';
}
document.getElementById('student-import-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeStudentImportModal();
});
function handleStuFile(file) {
    if (!file) return;
    _stuFile = file;
    document.getElementById('stu-drop-label').innerHTML = '<strong style="color:#1F2937;">' + stuEsc(file.name) + '</strong> <span style="color:#9CA3AF;">(' + (file.size/1024).toFixed(1) + ' KB)</span>';
    document.getElementById('stu-drop-zone').style.borderColor = '#7C3AED';
    document.getElementById('stu-drop-zone').style.background  = '#F5F3FF';
    stuResetPreview();   // a new file always needs a fresh preview
}
function handleStuDrop(e) {
    e.preventDefault();
    document.getElementById('stu-drop-zone').style.borderColor = '#D1D5DB';
    document.getElementById('stu-drop-zone').style.background  = '';
    if (e.dataTransfer.files[0]) handleStuFile(e.dataTransfer.files[0]);
}

// One button, two steps: "Preview Changes" first, then "Confirm Import".
function submitStudentImport() {
    if (!_stuFile) return;
    if (_stuPreview) { confirmStudentImport(); } else { runStudentPreview(); }
}

function stuPost(preview) {
    var fd = new FormData();
    fd.append('excel_file', _stuFile);
    fd.append('csrf_token', CSRF_TOKEN);
    if (preview) fd.append('preview', '1');
    return fetch('import-students.php', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); });
}

function stuValue(v) {
    return v === '' ? '<em style="color:#9CA3AF;">(blank)</em>' : stuEsc(v);
}
function stuChangeChip(c) {
    // Highlight the dangerous case: a value in the system that the file would erase.
    var clearing = c.new === '' && c.old !== '';
    return '<span style="display:inline-flex;align-items:center;flex-wrap:wrap;gap:5px;background:' + (clearing ? '#FEF2F2' : '#F5F3FF') + ';border:1px solid ' + (clearing ? '#FECACA' : '#DDD6FE') + ';border-radius:6px;padding:3px 8px;font-size:12px;margin:2px 4px 2px 0;">' +
        '<span style="color:#6B7280;">' + stuEsc(c.field) + ':</span>' +
        '<span style="color:#B91C1C;text-decoration:line-through;">' + stuValue(c.old) + '</span>' +
        '<i class="ti ti-arrow-right" style="color:#6B7280;"></i>' +
        '<strong style="color:#047857;">' + stuValue(c.new) + '</strong>' +
        (clearing ? '<span style="color:#B91C1C;font-size:11px;">will be cleared</span>' : '') +
        '</span>';
}

function runStudentPreview() {
    var btn = document.getElementById('stu-import-btn');
    var result = document.getElementById('stu-import-result');
    btn.disabled = true; btn.innerHTML = '<i class="ti ti-loader"></i> Checking file...';
    result.innerHTML = appSkeletonHTML(3);
    result.style.display = 'block';

    stuPost(true).then(function(data) {
        if (!data.success) {
            stuResetPreview();
            result.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:8px;padding:12px 14px;color:#991B1B;"><i class="ti ti-alert-circle" style="margin-right:6px;"></i>' + stuEsc(data.message) + '</div>';
            result.style.display = 'block';
            return;
        }
        _stuPreview = data;
        var total = data.new + data.updated;
        var html = '';

        // Summary chips
        html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">' +
            '<span style="background:#ECFDF5;color:#065F46;border:1px solid #A7F3D0;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:600;">' + data.new + ' new</span>' +
            '<span style="background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:600;">' + data.updated + ' will change</span>' +
            '<span style="background:#F3F4F6;color:#4B5563;border:1px solid #E5E7EB;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:600;">' + data.unchanged + ' no change</span>' +
            (data.skipped.length ? '<span style="background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;border-radius:999px;padding:4px 12px;font-size:12px;font-weight:600;">' + data.skipped.length + ' skipped</span>' : '') +
            '</div>';

        if (total === 0) {
            html += '<div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:12px 14px;color:#4B5563;font-size:13px;"><i class="ti ti-info-circle" style="margin-right:6px;"></i>Nothing to import — this file has no new students and no changes to existing ones.</div>';
        } else {
            html += '<div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">Review what will happen:</div>';
            html += '<div style="max-height:280px;overflow-y:auto;border:1px solid #E5E7EB;border-radius:10px;">';
            data.rows.forEach(function(r, idx) {
                html += '<div style="padding:10px 12px;' + (idx ? 'border-top:1px solid #F3F4F6;' : '') + '">';
                html += '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;">' +
                    '<div style="font-size:13px;color:#1F2937;"><strong>' + stuEsc(r.name) + '</strong> <span style="color:#9CA3AF;font-size:11px;">· ' + stuEsc(r.student_id) + ' · row ' + r.row + '</span></div>' +
                    (r.action === 'add'
                        ? '<span style="background:#ECFDF5;color:#065F46;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;">NEW</span>'
                        : '<span style="background:#EFF6FF;color:#1E40AF;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;">UPDATE</span>') +
                    '</div>';
                if (r.action === 'add') {
                    html += '<div style="font-size:12px;color:#6B7280;margin-top:3px;">Course: ' + stuValue(r.course) + ' &nbsp;·&nbsp; Email: ' + stuValue(r.email) + ' &nbsp;·&nbsp; Default password = Student ID</div>';
                } else {
                    html += '<div style="margin-top:4px;">' + r.changes.map(stuChangeChip).join('') + '</div>';
                }
                html += '</div>';
            });
            html += '</div>';
            if (data.truncated) html += '<div style="font-size:11px;color:#6B7280;margin-top:6px;">Showing the first ' + data.rows.length + ' changes. The rest will also be imported.</div>';
        }

        if (data.skipped.length) {
            html += '<details style="margin-top:12px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:10px 14px;"><summary style="font-size:12px;font-weight:600;color:#92400E;cursor:pointer;">' + data.skipped.length + ' row(s) will be skipped</summary><ul style="margin:8px 0 0;padding-left:16px;">' +
                data.skipped.map(function(m){ return '<li style="font-size:11px;color:#92400E;margin-bottom:3px;">' + stuEsc(m) + '</li>'; }).join('') + '</ul></details>';
        }
        result.innerHTML = html; result.style.display = 'block';

        if (total > 0) {
            btn.disabled = false;
            btn.style.background = '#059669';
            btn.innerHTML = '<i class="ti ti-check"></i> Confirm Import (' + total + ')';
        } else {
            btn.disabled = true;
            btn.innerHTML = _STU_BTN_PREVIEW;
            _stuPreview = null;
        }
    }).catch(function() {
        stuResetPreview();
        result.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:8px;padding:12px;color:#991B1B;">Network error. Try again.</div>';
        result.style.display = 'block';
    });
}

function confirmStudentImport() {
    var p = _stuPreview;
    var parts = [];
    if (p.new)     parts.push(p.new + ' new student' + (p.new === 1 ? '' : 's') + ' will be added');
    if (p.updated) parts.push(p.updated + ' existing student' + (p.updated === 1 ? '' : 's') + ' will be changed');
    appConfirm({
        title:   'Import these changes?',
        message: parts.join(' and ') + '. Existing records will be overwritten with what is in the file.',
        btn:     'Yes, import'
    }, doStudentImport);
}

function doStudentImport() {
    var btn = document.getElementById('stu-import-btn');
    var result = document.getElementById('stu-import-result');
    btn.disabled = true; btn.innerHTML = '<i class="ti ti-loader"></i> Importing...';
    result.innerHTML = appSkeletonHTML(3);
    result.style.display = 'block';

    stuPost(false).then(function(data) {
        var html = '';
        if (data.added > 0 || data.updated > 0) {
            var parts = [];
            if (data.added > 0) parts.push(data.added + ' added');
            if (data.updated > 0) parts.push(data.updated + ' updated');
            html += '<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:8px;padding:12px 14px;margin-bottom:10px;"><i class="ti ti-circle-check" style="color:#059669;margin-right:6px;"></i><strong style="color:#065F46;">' + parts.join(', ') + '!</strong></div>';
        }
        if (data.skipped && data.skipped.length) html += '<div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;padding:12px 14px;"><div style="font-size:12px;font-weight:600;color:#92400E;margin-bottom:6px;"><i class="ti ti-alert-triangle" style="margin-right:4px;"></i>' + data.skipped.length + ' row(s) skipped:</div><ul style="margin:0;padding-left:16px;">' + data.skipped.map(function(m){ return '<li style="font-size:11px;color:#92400E;margin-bottom:3px;">' + stuEsc(m) + '</li>'; }).join('') + '</ul></div>';
        if (!data.success && !data.added && !data.updated) html = '<div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:8px;padding:12px 14px;color:#991B1B;"><i class="ti ti-alert-circle" style="margin-right:6px;"></i>' + stuEsc(data.message) + '</div>';
        result.innerHTML = html; result.style.display = 'block';
        if (data.added > 0 || data.updated > 0) {
            btn.innerHTML = '<i class="ti ti-check"></i> Done';
            setTimeout(function(){ location.reload(); }, 2000);
        } else {
            _stuPreview = null;
            btn.disabled = false; btn.style.background = '#7C3AED'; btn.innerHTML = _STU_BTN_PREVIEW;
        }
    }).catch(function() {
        btn.disabled = false; btn.innerHTML = '<i class="ti ti-check"></i> Confirm Import';
        result.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:8px;padding:12px;color:#991B1B;">Network error. Try again.</div>';
        result.style.display = 'block';
    });
}
</script>
<script src="../assets/js/alerts.js"></script>
<script src="../assets/js/sidebar.js?v=4"></script>
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body></html>