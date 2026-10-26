<?php
// Start session FIRST before any includes
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/admin-otp.php';
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
    if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'] ?? '', ['staff', 'admin'], true)) {
        echo json_encode(['success' => false, 'message' => t('msg.unauthorized_please_log_in_again_f919', 'Unauthorized. Please log in again.')]);
        exit;
    }

    // CSRF check — same-origin session check above isn't enough on its own,
    // this stops a third-party site from silently triggering approve/reject/edit.
    $sentToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
        echo json_encode(['success' => false, 'message' => t('msg.invalid_or_expired_request_please_refres_4159', 'Invalid or expired request. Please refresh the page and try again.')]);
        exit;
    }

    $action = trim($_POST['ajax_action'] ?? '');
    if (in_array($action, ['request_admin_otp', 'delete_active', 'delete_disabled', 'disable_student', 'bulk_delete_active', 'bulk_delete_disabled', 'bulk_disable_student'], true) && ($_SESSION['user_type'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'message' => t('msg.only_the_admin_can_do_this_0cd7', 'Only the admin can do this.')]);
        exit;
    }
    $id     = (int)($_POST['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => t('msg.invalid_student_id_5ade', 'Invalid student ID.')]);
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
                'message'    => t('msg.student_account_approved_08fa', 'Student account approved!'),
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
            echo json_encode(['success' => false, 'message' => t('msg.student_not_found_or_already_approved_a2d6', 'Student not found or already approved.')]);
        }
    } elseif ($action === 'reject') {
        // A reason is required so the student knows why they were rejected
        $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 500);
        if ($reason === '') {
            echo json_encode(['success' => false, 'message' => t('msg.please_enter_a_reason_for_rejecting_this_1945', 'Please enter a reason for rejecting this registration.')]);
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
                : 'Registration rejected. (No email sent — ' . (empty($s['email']) ? 'the student has no email on file.' : 'sending failed: ' . ($GLOBALS['gnc_last_mail_error'] ?? 'unknown error')) . ')']);
        } else {
            echo json_encode(['success' => false, 'message' => t('msg.student_not_found_or_already_processed_c1d0', 'Student not found or already processed.')]);
        }
    } elseif ($action === 'edit') {
        [$otpOk, $otpMsg] = adminOtpVerify('edit_student', $id, (int)($_SESSION['user_id'] ?? 0), $_POST['admin_otp'] ?? '');
        if (!$otpOk) {
            echo json_encode(['success' => false, 'message' => $otpMsg]);
            exit;
        }
        $student_id = trim($_POST['student_id'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $course     = trim($_POST['course']     ?? '');
        $email      = trim($_POST['email']      ?? '');
        $new_pw     = (string)($_POST['new_password'] ?? '');   // optional: blank = keep current password

        if ($new_pw !== '' && mb_strlen($new_pw) < 8) {
            echo json_encode(['success' => false, 'message' => t('msg.new_password_must_be_at_least_8_characte_88cc', 'New password must be at least 8 characters.')]);
            exit;
        }

        if (!$first_name || !$last_name || !$student_id) {
            echo json_encode(['success' => false, 'message' => t('msg.first_name_last_name_and_student_id_are__2fe7', 'First name, last name, and Student ID are required.')]);
            exit;
        }

        if (!ctype_digit($student_id)) {
            echo json_encode(['success' => false, 'message' => t('msg.student_id_must_contain_numbers_only_no__984a', 'Student ID must contain numbers only (no letters or symbols).')]);
            exit;
        }

        // Check duplicate student_id (excluding current record)
        $dup = $pdo->prepare("SELECT id FROM students WHERE student_id=? AND id!=?");
        $dup->execute([$student_id, $id]);
        if ($dup->fetch()) {
            echo json_encode(['success' => false, 'message' => t('msg.student_id_already_exists_for_another_st_7e81', 'Student ID already exists for another student.')]);
            exit;
        }

        $__before = auditRow($pdo, 'students', (int)$id);
        $stmt = $pdo->prepare("UPDATE students SET student_id=?, first_name=?, last_name=?, course=?, email=? WHERE id=?");
        $stmt->execute([$student_id, $first_name, $last_name, $course, $email ?: null, $id]);

        $passwordReset = false;
        if ($new_pw !== '') {
            $pdo->prepare("UPDATE students SET password=?, must_change_password=1 WHERE id=?")
                ->execute([password_hash($new_pw, PASSWORD_DEFAULT), $id]);
            $passwordReset = true;
        }
        auditLog($pdo, 'update', 'student', (int)$id, $__before, ['student_id' => $student_id, 'first_name' => $first_name, 'last_name' => $last_name, 'course' => $course, 'email' => $email, 'password_reset' => $passwordReset]);

        echo json_encode([
            'success'    => true,
            'message'    => t('msg.student_updated_successfully_4ef8', 'Student updated successfully!'),
            'student'    => [
                'id'         => $id,
                'student_id' => $student_id,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'course'     => $course,
                'email'      => $email,
            ]
        ]);
    } elseif ($action === 'request_admin_otp') {
        $op = trim($_POST['op'] ?? '');
        if (!in_array($op, ['delete_active', 'delete_disabled', 'disable_student', 'edit_student', 'bulk_delete_active', 'bulk_delete_disabled', 'bulk_disable_student'], true)) {
            echo json_encode(['success' => false, 'message' => t('msg.unknown_action_9258', 'Unknown action.')]);
            exit;
        }
        $method = in_array($_POST['method'] ?? '', ['password', 'email'], true) ? $_POST['method'] : 'auto';
        [$sent, $msg, $mode] = adminOtpSend($pdo, (int)($_SESSION['user_id'] ?? 0), $op, (int)$id, $method);
        echo json_encode(['success' => $sent, 'message' => $msg, 'mode' => $mode ?? 'email']);
    } elseif (in_array($action, ['bulk_delete_active', 'bulk_delete_disabled', 'bulk_disable_student'], true)) {
        // One emailed code covers this exact list of students (see adminOtpBatchTarget).
        $ids = adminOtpParseIds($_POST['ids'] ?? '');
        if (!$ids) {
            echo json_encode(['success' => false, 'message' => t('msg.no_students_selected_or_too_many_at_once_d66c', 'No students selected (or too many at once, max 200).')]);
            exit;
        }
        [$otpOk, $otpMsg] = adminOtpVerify($action, adminOtpBatchTarget($ids), (int)($_SESSION['user_id'] ?? 0), $_POST['admin_otp'] ?? '');
        if (!$otpOk) {
            echo json_encode(['success' => false, 'message' => $otpMsg]);
            exit;
        }
        $okCount = 0;
        foreach ($ids as $sid) {
            if ($action === 'bulk_disable_student') {
                $st = $pdo->prepare("UPDATE students SET is_active=0, disabled_at=NOW() WHERE id=? AND is_active=1");
                $st->execute([$sid]);
                if ($st->rowCount() > 0) $okCount++;
                continue;
            }
            $cond = $action === 'bulk_delete_active' ? "is_active=1" : "is_active=0 AND disabled_at IS NOT NULL";
            $q = $pdo->prepare("SELECT * FROM students WHERE id=? AND $cond");
            $q->execute([$sid]);
            $s = $q->fetch();
            $del = $pdo->prepare("DELETE FROM students WHERE id=? AND $cond");
            $del->execute([$sid]);
            if ($del->rowCount() > 0) {
                $okCount++;
                if ($s && !empty($s['email'])) sendAccountDeletedEmail($s['email'], $s['first_name']);
            }
        }
        $total = count($ids);
        $verb = $action === 'bulk_disable_student' ? 'disabled' : 'deleted';
        echo json_encode(['success' => true, 'ok' => $okCount, 'failed' => $total - $okCount,
            'message' => "$okCount of $total student account(s) $verb."]);
    } elseif ($action === 'disable_student') {
        [$otpOk, $otpMsg] = adminOtpVerify($action, (int)$id, (int)($_SESSION['user_id'] ?? 0), $_POST['admin_otp'] ?? '');
        if (!$otpOk) {
            echo json_encode(['success' => false, 'message' => $otpMsg]);
            exit;
        }
        $__before = auditRow($pdo, 'students', (int)$id);
        $stmt = $pdo->prepare("UPDATE students SET is_active=0, disabled_at=NOW() WHERE id=? AND is_active=1");
        $stmt->execute([(int)$id]);
        if ($stmt->rowCount() > 0) auditLog($pdo, 'disable', 'student', (int)$id, $__before, ['is_active' => 0]);
        echo json_encode($stmt->rowCount() > 0
            ? ['success' => true, 'message' => t('msg.student_account_disabled_fc1d', 'Student account disabled.')]
            : ['success' => false, 'message' => t('msg.student_not_found_or_already_disabled_4edc', 'Student not found or already disabled.')]);
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
                'message' => t('msg.student_account_enabled_fd9c', 'Student account enabled!'),
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
            echo json_encode(['success' => false, 'message' => t('msg.student_not_found_or_not_currently_disab_d5cf', 'Student not found or not currently disabled.')]);
        }
    } elseif ($action === 'delete_disabled') {
        [$otpOk, $otpMsg] = adminOtpVerify($action, (int)$id, (int)($_SESSION['user_id'] ?? 0), $_POST['admin_otp'] ?? '');
        if (!$otpOk) {
            echo json_encode(['success' => false, 'message' => $otpMsg]);
            exit;
        }

        $student = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=0 AND disabled_at IS NOT NULL");
        $student->execute([$id]);
        $s = $student->fetch();

        $stmt = $pdo->prepare("DELETE FROM students WHERE id=? AND is_active=0 AND disabled_at IS NOT NULL");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            if ($s && !empty($s['email'])) sendAccountDeletedEmail($s['email'], $s['first_name']);
            auditLog($pdo, 'delete', 'student', (int)$id, $s ?: []);
            echo json_encode(['success' => true, 'message' => t('msg.disabled_account_deleted_permanently_c57d', 'Disabled account deleted permanently.')]);
        } else {
            echo json_encode(['success' => false, 'message' => t('msg.student_not_found_or_not_currently_disab_d5cf', 'Student not found or not currently disabled.')]);
        }
    } elseif ($action === 'delete_active') {
        [$otpOk, $otpMsg] = adminOtpVerify($action, (int)$id, (int)($_SESSION['user_id'] ?? 0), $_POST['admin_otp'] ?? '');
        if (!$otpOk) {
            echo json_encode(['success' => false, 'message' => $otpMsg]);
            exit;
        }

        $student = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=1");
        $student->execute([$id]);
        $s = $student->fetch();

        $stmt = $pdo->prepare("DELETE FROM students WHERE id=? AND is_active=1");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            if ($s && !empty($s['email'])) sendAccountDeletedEmail($s['email'], $s['first_name']);
            auditLog($pdo, 'delete', 'student', (int)$id, $s ?: []);
            echo json_encode(['success' => true, 'message' => t('msg.student_account_deleted_permanently_7896', 'Student account deleted permanently.')]);
        } else {
            echo json_encode(['success' => false, 'message' => t('msg.student_not_found_or_no_longer_active_d8af', 'Student not found or no longer active.')]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => t('msg.unknown_action_9258', 'Unknown action.')]);
    }
    exit;
}

requireStaff();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$success = $error = '';



if (isset($_POST['toggle'])) {
    requireCsrf();
    $val = (int)$_POST['val'];
    if ($val !== 1) {
        // Disabling must be confirmed with an OTP on this page (ajax disable_student)
        http_response_code(403);
        die('Disabling requires the OTP confirmation. Please use the Disable button on the Students page.');
    }
    $pdo->prepare("UPDATE students SET is_active=1, disabled_at=NULL, approved_at=COALESCE(approved_at, NOW()) WHERE id=?")->execute([(int)$_POST['id']]);
    auditLog($pdo, 'enable', 'student', (int)$_POST['id'], [], ['is_active' => 1]);
    header('Location: students.php'); exit;
}

// Approve a pending self-registration
if (isset($_POST['approve'])) {
    requireCsrf();
    $approveId = (int)$_POST['approve'];
    $pdo->prepare("UPDATE students SET is_active=1, approved_at=NOW() WHERE id=? AND is_active=0 AND disabled_at IS NULL")->execute([$approveId]);
    auditLog($pdo, 'approve', 'student', $approveId, [], ['is_active' => 1]);
    $s = $pdo->prepare("SELECT * FROM students WHERE id=?");
    $s->execute([$approveId]);
    $s = $s->fetch();
    if ($s) {
        addNotification($pdo, $s['id'], 'Your account has been approved! You can now log in.', '../index.php');
        if (!empty($s['email'])) sendApprovalEmail($s['email'], $s['first_name']);
    }
    $success = t('msg.student_account_approved_08fa', 'Student account approved!');
}

// Reject (delete) a pending self-registration
if (isset($_POST['reject'])) {
    requireCsrf();
    $rejectId = (int)$_POST['reject'];
    $s = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=0 AND disabled_at IS NULL");
    $s->execute([$rejectId]);
    $s = $s->fetch();
    $pdo->prepare("DELETE FROM students WHERE id=? AND is_active=0 AND disabled_at IS NULL")->execute([$rejectId]);
    auditLog($pdo, 'reject', 'student', $rejectId, $s ?: []);
    if ($s && !empty($s['email'])) sendRejectionEmail($s['email'], $s['first_name']);
    $success = t('msg.pending_registration_removed_7ecd', 'Pending registration removed.');
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

// Header-menu data for the Pending and Disabled tables (same menus as Active Students, applied in the page)
function stuMenuData(array $rows, string $dateField): array {
    $courses = []; $months = [];
    foreach ($rows as $r) {
        $c = trim((string)($r['course'] ?? ''));
        if ($c !== '') $courses[$c] = true;
        if (!empty($r[$dateField])) { $ts = strtotime($r[$dateField]); $months[date('Y', $ts)][(int)date('n', $ts)] = true; }
    }
    $courses = array_keys($courses); sort($courses, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($months);
    $m = [];
    foreach ($months as $y => $ms) { ksort($ms); $m[(string)$y] = array_keys($ms); }
    return [$courses, $m];
}
[$pendingCourses, $pendingMonths]   = stuMenuData($pending, 'created_at');
[$disabledCourses, $disabledMonths] = stuMenuData($disabled, 'disabled_at');

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
    <title><?= t('title.students', 'Students') ?> — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
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
    <a class="sitem" href="complaints.php"><i class="ti ti-message-report"></i> <?= t('sidebar.all_complaints', 'All Complaints') ?></a><a class="sitem" href="../osa/messages.php"><i class="ti ti-messages"></i> <?= t('sidebar.messages', 'Messages') ?></a>
    <div class="sidebar-section"><?= t('sidebar.election', 'Election') ?></div>
    <a class="sitem" href="elections.php"><i class="ti ti-trophy"></i> <?= t('sidebar.elections', 'Elections') ?></a>
    <a class="sitem" href="candidates.php"><i class="ti ti-users"></i> <?= t('sidebar.candidates', 'Candidates') ?></a>
    <a class="sitem" href="party-lists.php"><i class="ti ti-flag"></i> <?= t('sidebar.party_lists', 'Party Lists') ?></a>
    <a class="sitem" href="vouchers.php"><i class="ti ti-ticket"></i> <?= t('sidebar.vouchers', 'Vouchers') ?></a>
    <a class="sitem" href="results.php"><i class="ti ti-chart-pie"></i> <?= t('sidebar.live_results', 'Live Results') ?></a>
    <div class="sidebar-section"><?= t('sidebar.events', 'Events') ?></div>
    <a class="sitem" href="events.php"><i class="ti ti-calendar-event"></i> <?= t('sidebar.events', 'Events') ?></a>
    <div class="sidebar-section"><?= t('sidebar.system', 'System') ?></div>
    <a class="sitem active" href="students.php"><i class="ti ti-user-plus"></i> <?= t('sidebar.students', 'Students') ?></a>
    <?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem" href="staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a><?php endif; ?><?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem" href="audit-log.php"><i class="ti ti-history"></i> <?= t('sidebar.audit_log', 'Audit Log') ?></a><?php endif; ?>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> <?= t('sidebar.reports', 'Reports') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?>
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
                <button onclick="closeModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
                <button id="modal-confirm-btn" class="btn btn-sm"><?= t('ui.confirm_70d9', 'Confirm') ?></button>
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
                    <div style="font-size:15px;font-weight:600;color:#1F2937;"><?= t('ui.reject_registration_a994', 'Reject Registration') ?></div>
                    <div id="reject-modal-name" style="font-size:13px;color:#6B7280;margin-top:2px;"></div>
                </div>
            </div>
            <p style="font-size:12px;color:#991B1B;background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:9px 12px;margin-bottom:12px;">
                <?= t('ui.this_permanently_deletes_the_regis_e05f', 'This permanently deletes the registration and cannot be undone.') ?>
            </p>
            <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:6px;"><?= t('ui.reason_for_rejection_b905', 'Reason for rejection') ?> <span style="color:#DC2626;">*</span></label>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
                <button type="button" class="btn btn-outline btn-sm reject-chip" data-reason="COR is unclear or unreadable."><?= t('ui.unclear_cor_b090', 'Unclear COR') ?></button>
                <button type="button" class="btn btn-outline btn-sm reject-chip" data-reason="Student ID does not match our records."><?= t('ui.id_mismatch_1c2c', 'ID mismatch') ?></button>
                <button type="button" class="btn btn-outline btn-sm reject-chip" data-reason="Not currently enrolled."><?= t('ui.not_enrolled_6e55', 'Not enrolled') ?></button>
                <button type="button" class="btn btn-outline btn-sm reject-chip" data-reason="Duplicate registration."><?= t('ui.duplicate_ed75', 'Duplicate') ?></button>
            </div>
            <textarea id="reject-reason" class="form-control" rows="3" maxlength="500" placeholder="<?= htmlspecialchars(t('auto.type_the_reason_this_will_be_emailed_to__ff05', 'Type the reason (this will be emailed to the student)...'), ENT_QUOTES) ?>" style="resize:vertical;margin-bottom:4px;" oninput="document.getElementById('reject-modal-error').style.display='none'"></textarea>
            <div id="reject-modal-warn" style="display:none;color:#92400E;font-size:12px;margin-bottom:4px;"><i class="ti ti-mail-off"></i> <?= t('ui.this_student_has_no_email_on_file__c4e7', 'This student has no email on file, so the reason cannot be sent.') ?></div>
            <div id="reject-modal-error" style="display:none;color:#991B1B;font-size:12px;margin-bottom:4px;"><?= t('ui.please_enter_a_reason_2706', 'Please enter a reason.') ?></div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">
                <button type="button" onclick="closeRejectModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
                <button type="button" onclick="submitReject()" class="btn btn-sm btn-danger"><i class="ti ti-x"></i> <?= t('ui.reject_registration_a994', 'Reject Registration') ?></button>
            </div>
        </div>
    </div>

    <div class="stu-tabs">
        <button type="button" class="stu-tab <?= $startTab === 'pending' ? 'active' : '' ?>" id="stu-tab-btn-pending" onclick="switchStuTab('pending')">
            <i class="ti ti-clock"></i> <?= t('ui.pending_approvals_79d8', 'Pending Approvals') ?>
            <span id="pending-tab-badge" class="stu-tab-badge" style="background:#D97706;<?= count($pending) === 0 ? 'display:none;' : '' ?>"><?= count($pending) ?></span>
        </button>
        <button type="button" class="stu-tab <?= $startTab === 'active' ? 'active' : '' ?>" id="stu-tab-btn-active" onclick="switchStuTab('active')">
            <i class="ti ti-users"></i> <?= t('ui.active_students_9fd1', 'Active Students') ?>
        </button>
        <button type="button" class="stu-tab" id="stu-tab-btn-archived" onclick="switchStuTab('archived')">
            <i class="ti ti-user-off"></i> <?= t('ui.disabled_b9f5', 'Disabled') ?>
            <?php if (count($disabled) > 0): ?><span id="archived-tab-badge" class="stu-tab-badge"><?= count($disabled) ?></span><?php endif; ?>
        </button>
    </div>

    <div id="tabpanel-pending" class="stu-tabpanel" style="<?= $startTab === 'pending' ? '' : 'display:none;' ?>">
    <div class="card" id="pending-card" style="border:2px solid #FCD34D;background:#FFFBEB;margin-bottom:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <div class="card-title" style="color:#92400E;margin-bottom:0;">
                <i class="ti ti-clock" style="color:#D97706;"></i>
                <?= t('ui.pending_approvals_79d8', 'Pending Approvals') ?>
                <span id="pending-count" style="background:#D97706;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;margin-left:6px;"><?= count($pending) ?></span>
            </div>
            <div id="pending-search-wrap" style="position:relative;min-width:280px;<?= count($pending) === 0 ? 'display:none;' : '' ?>">
                <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:15px;"></i>
                <input type="text" id="pending-search" placeholder="<?= t('ui.search_by_name_id_course_email_a152', 'Search by name, ID, course, email...') ?>"
                       class="form-control" style="padding-left:32px;margin-bottom:0;background:#fff;" autocomplete="off">
            </div>
        </div>
        <div class="table-wrap" id="pending-table-wrap" style="<?= count($pending) === 0 ? 'display:none;' : '' ?>"><table id="pending-table" data-tt data-pager="10" data-pager-sizes="10,25,50,100" data-pager-always data-pager-unit="student">
            <thead><tr>
                <th data-type="text">Student ID</th>
                <th data-type="text"><?= t('ui.name_49ee', 'Name') ?></th>
                <th data-tt-cfilter="data-course" data-tt-all="All Courses" data-tt-icon="ti-school" data-tt-options="<?= e(json_encode($pendingCourses)) ?>"><?= t('ui.course_3730', 'Course') ?></th>
                <th data-type="text">Email</th>
                <th data-nosort>COR</th>
                <th data-type="date" data-tt-date data-tt-year="" data-tt-month="" data-tt-month-to="" data-tt-months="<?= e(json_encode((object)$pendingMonths)) ?>"><?= t('ui.date_registered_948f', 'Date Registered') ?></th>
                <th data-nosort><?= t('ui.action_004b', 'Action') ?></th>
            </tr></thead>
            <tbody id="pending-tbody">
            <?php foreach ($pending as $p): $pTs = strtotime($p['created_at']); ?>
            <tr id="pending-row-<?= $p['id'] ?>" data-bulk-id="<?= (int)$p['id'] ?>" data-course="<?= e($p['course']) ?>" data-ym="<?= date('Y-m', $pTs) ?>" data-search="<?= strtolower(e($p['student_id'].' '.$p['first_name'].' '.$p['last_name'].' '.$p['last_name'].', '.$p['first_name'].' '.$p['course'].' '.($p['email'] ?? ''))) ?>">
                <td><?= e($p['student_id']) ?></td>
                <td><?= e($p['last_name'].', '.$p['first_name']) ?></td>
                <td><?= e($p['course'] ?: '—') ?></td>
                <td><?= e(maskEmail($p['email']) ?: '—') ?></td>
                <td style="white-space:nowrap;">
                    <?php if (!empty($p['cor_file'])): ?>
                        <a href="../uploads/<?= e($p['cor_file']) ?>" target="_blank" class="btn btn-sm btn-outline">
                            <i class="ti ti-file-text"></i> <?= t('ui.view_cor_927f', 'View COR') ?>
                        </a>
                    <?php else: ?>
                        <span style="color:#9CA3AF;font-size:12px;"><?= t('ui.no_file_f12b', 'No file') ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;" data-sort="<?= $pTs ?>"><?= date('M d, Y', $pTs) ?></td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-sm btn-gold" onclick="handleApproval(<?= $p['id'] ?>, 'approve')">
                        <i class="ti ti-check"></i> <?= t('ui.approve_6f73', 'Approve') ?>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="handleApproval(<?= $p['id'] ?>, 'reject')" style="margin-left:4px;">
                        <i class="ti ti-x"></i> <?= t('ui.reject_d98a', 'Reject') ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div id="pending-empty" style="<?= count($pending) > 0 ? 'display:none;' : '' ?>text-align:center;padding:24px;color:#92400E;font-size:13px;">
            <i class="ti ti-circle-check" style="font-size:28px;display:block;margin-bottom:6px;"></i>
            <?= t('ui.no_pending_approvals_51dc', 'No pending approvals.') ?>
        </div>
    </div>
    </div>

    <div id="tabpanel-archived" class="stu-tabpanel" style="display:none;">
    <div class="card" id="disabled-card" style="border:2px solid #FCA5A5;background:#FEF2F2;margin-bottom:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <div class="card-title" style="color:#991B1B;margin-bottom:0;">
                <i class="ti ti-user-off" style="color:#DC2626;"></i>
                <?= t('ui.disabled_accounts_530a', 'Disabled Accounts') ?>
                <span id="disabled-count" style="background:#DC2626;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;margin-left:6px;"><?= count($disabled) ?></span>
            </div>
            <div id="disabled-search-wrap" style="position:relative;min-width:220px;<?= count($disabled) === 0 ? 'display:none;' : '' ?>">
                <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:15px;"></i>
                <input type="text" id="disabled-search" placeholder="<?= t('ui.search_by_name_id_course_c6c9', 'Search by name, ID, course...') ?>"
                       class="form-control" style="padding-left:32px;margin-bottom:0;background:#fff;" autocomplete="off">
            </div>
        </div>
        <p style="font-size:12px;color:#991B1B;margin:-4px 0 12px;"><?= t('ui.disabled_accounts_land_here_and_ar_8dae', 'Disabled accounts land here and are automatically and permanently deleted 30 days after being disabled, unless re-enabled before then.') ?></p>
        <div class="table-wrap" id="disabled-table-wrap" style="<?= count($disabled) === 0 ? 'display:none;' : '' ?>"><table id="disabled-table" data-tt data-pager="10" data-pager-sizes="10,25,50,100" data-pager-always data-pager-unit="student">
            <thead><tr>
                <th data-type="text">Student ID</th>
                <th data-type="text"><?= t('ui.name_49ee', 'Name') ?></th>
                <th data-tt-cfilter="data-course" data-tt-all="All Courses" data-tt-icon="ti-school" data-tt-options="<?= e(json_encode($disabledCourses)) ?>"><?= t('ui.course_3730', 'Course') ?></th>
                <th data-type="date" data-tt-date data-tt-year="" data-tt-month="" data-tt-month-to="" data-tt-months="<?= e(json_encode((object)$disabledMonths)) ?>"><?= t('ui.disabled_on_03af', 'Disabled On') ?></th>
                <th data-type="num" data-tt-labels="Soonest first|Latest first"><?= t('ui.auto_delete_in_5fd5', 'Auto-Delete In') ?></th>
                <th data-nosort><?= t('ui.action_004b', 'Action') ?></th>
            </tr></thead>
            <tbody id="disabled-tbody">
            <?php foreach ($disabled as $d):
                $disabledTs   = strtotime($d['disabled_at']);
                $deleteTs     = strtotime('+30 days', $disabledTs);
                $daysLeft     = max(0, (int)ceil(($deleteTs - time()) / 86400));
            ?>
            <tr id="disabled-row-<?= $d['id'] ?>" data-bulk-id="<?= (int)$d['id'] ?>" data-course="<?= e($d['course']) ?>" data-ym="<?= date('Y-m', $disabledTs) ?>" data-search="<?= strtolower(e($d['student_id'].' '.$d['first_name'].' '.$d['last_name'].' '.$d['last_name'].', '.$d['first_name'].' '.$d['course'])) ?>">
                <td><?= e($d['student_id']) ?></td>
                <td><?= e($d['last_name'].', '.$d['first_name']) ?></td>
                <td><?= e($d['course'] ?: '—') ?></td>
                <td style="white-space:nowrap;" data-sort="<?= $disabledTs ?>"><?= date('M d, Y', $disabledTs) ?></td>
                <td style="white-space:nowrap;" data-sort="<?= $deleteTs ?>">
                    <span class="badge <?= $daysLeft <= 5 ? 'badge-closed' : 'badge-active' ?>" style="<?= $daysLeft <= 5 ? 'background:#FEE2E2;color:#991B1B;' : '' ?>">
                        <?= $daysLeft ?> <?= t('count.day', 'day' . ($daysLeft === 1 ? '' : 's')) ?>
                    </span>
                </td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-sm btn-gold" onclick="handleDisabledAction(<?= $d['id'] ?>, 'enable_disabled')">
                        <i class="ti ti-user-check"></i> <?= t('ui.enable_2fae', 'Enable') ?>
                    </button>
                    <button class="btn btn-sm btn-danger admin-only" onclick="openDeleteModal(<?= $d['id'] ?>, 'delete_disabled', '<?= addslashes(e($d['last_name'].', '.$d['first_name'])) ?>')" style="margin-left:4px;">
                        <i class="ti ti-trash"></i> <?= t('ui.delete_f2a6', 'Delete') ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div id="disabled-empty" style="<?= count($disabled) > 0 ? 'display:none;' : '' ?>text-align:center;padding:24px;color:#991B1B;font-size:13px;">
            <i class="ti ti-mood-smile" style="font-size:28px;display:block;margin-bottom:6px;"></i>
            <?= t('ui.no_disabled_accounts_a351', 'No disabled accounts.') ?>
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
                showToast(__t('Session expired. Please log in again.'), 'error');
                setTimeout(function() { window.location.href = '../index.php'; }, 1500);
                return null;
            }
            // Check content type before parsing JSON
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return r.text().then(function(txt) {
                    console.error('Non-JSON response:', txt);
                    throw new Error(__t('Server returned non-JSON. Check PHP errors.'));
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
                        var pSearch = document.getElementById('pending-search-wrap');
                        if (pSearch) pSearch.style.display = 'none';
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
                            '<td><button type="button" class="btn btn-sm btn-outline admin-only" onclick="confirmStudentDisable(' + s.id + ', \'' + (s.last_name + ', ' + s.first_name).replace(/'/g,"\\'") + '\')">Disable</button></td>';
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
                showToast(data.message || __t('Something went wrong. Please try again.'), 'error');
                row.querySelectorAll('button').forEach(function(b) { b.disabled = false; b.style.opacity = '1'; });
            }
        })
        .catch(function(err) {
            console.error(__t('Approval error:'), err);
            showToast('Error: ' + (err.message || __t('Network error. Please try again.')), 'error');
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

function filterDisabled() {
    var t = document.getElementById('disabled-table');
    if (t && t._ttRun) t._ttRun();
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
                showToast(__t('Session expired. Please log in again.'), 'error');
                setTimeout(function() { window.location.href = '../index.php'; }, 1500);
                return null;
            }
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') === -1) {
                return r.text().then(function(txt) {
                    console.error('Non-JSON response:', txt);
                    throw new Error(__t('Server returned non-JSON. Check PHP errors.'));
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
                            '<td><button type="button" class="btn btn-sm btn-outline admin-only" onclick="confirmStudentDisable(' + s.id + ', \'' + (s.last_name + ', ' + s.first_name).replace(/'/g,"\\'") + '\')">Disable</button> ' +
                            '<button class="btn btn-sm btn-danger admin-only" onclick="openDeleteModal(' + s.id + ', \'delete_active\', \'' + (s.last_name + ', ' + s.first_name).replace(/'/g,"\\'") + '\')" style="margin-left:4px;"><i class="ti ti-trash"></i> Delete</button></td>';
                        newRow.style.opacity = '0';
                        newRow.style.transition = 'opacity 0.4s';
                        tbody.insertBefore(newRow, tbody.firstChild);
                        setTimeout(function() { newRow.style.opacity = '1'; }, 50);
                        var countEl = document.getElementById('student-count');
                        if (countEl) countEl.textContent = parseInt(countEl.textContent || '0') + 1;
                    }
                }
            } else {
                showToast(data.message || __t('Something went wrong. Please try again.'), 'error');
                row.querySelectorAll('button').forEach(function(b) { b.disabled = false; b.style.opacity = '1'; });
            }
        })
        .catch(function(err) {
            console.error(__t('Disabled account action error:'), err);
            showToast('Error: ' + (err.message || __t('Network error. Please try again.')), 'error');
            row.querySelectorAll('button').forEach(function(b) { b.disabled = false; b.style.opacity = '1'; });
        });
}

function handleDisabledAction(id, action) {
    openModal(
        __t('Enable Student'),
        'This will re-activate the account and cancel its scheduled deletion.',
        __t('Enable'), 'btn-gold',
        function() { doDisabledAction(id, 'enable_disabled'); }
    );
}

function handleApproval(id, action) {
    if (action === 'approve') {
        openModal(
            __t('Approve Student'),
            __t('Are you sure you want to approve this student account?'),
            __t('Approve'), 'btn-gold',
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
}
</script>
    <div id="tabpanel-active" class="stu-tabpanel" style="<?= $startTab === 'active' ? '' : 'display:none;' ?>">
    <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <div class="card-title" style="margin-bottom:0;"><i class="ti ti-users"></i> <?= t('ui.active_students_443b', 'Active Students (') ?><span id="student-count"><?= $totalRows ?></span>)</div>
                    <div class="io-group">
                    <button onclick="openStudentImportModal()" class="btn btn-outline btn-sm" style="color:#7C3AED;border-color:#7C3AED;gap:5px;white-space:nowrap;">
                        <i class="ti ti-file-spreadsheet"></i> <?= t('ui.import_excel_508e', 'Import Excel') ?>
                    </button>
                    <?php if ($anyActive): ?>
                    <?= exportMenu('export.php?type=students', 'btn btn-outline btn-sm', 'color:#059669;border-color:#059669;gap:5px;white-space:nowrap;', 'Export', null, [
                        exportCourseFilter($pdo, false, $courseSel !== '' ? [$courseSel] : []),
                        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
                    ]) ?>
                    <?php endif; ?>
                    </div>
                </div>
                <form id="student-filter-form" method="GET" action="students.php" onsubmit="return false;" style="position:relative;min-width:220px;margin:0;">
                    <?php if ($courseSel !== ''): ?><input type="hidden" name="course" value="<?= e($courseSel) ?>"><?php endif; ?>
                    <?php if ($sort): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>"><?php endif; ?>
                    <?php if ($dYear): ?><input type="hidden" name="dyear" value="<?= (int)$dYear ?>"><?php endif; ?>
                    <?php if ($dMonth): ?><input type="hidden" name="dmonth" value="<?= (int)$dMonth ?>"><?php endif; ?>
                    <?php if ($dMonthTo): ?><input type="hidden" name="dmonth_to" value="<?= (int)$dMonthTo ?>"><?php endif; ?>
                    <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:15px;"></i>
                    <input type="text" id="student-search" name="search" value="<?= e($search) ?>" placeholder="<?= t('ui.search_by_name_id_course_c6c9', 'Search by name, ID, course...') ?>"
                           class="form-control" style="padding-left:32px;margin-bottom:0;" autocomplete="off">
                </form>
            </div>
            <div id="students-live"><div class="table-wrap"><table data-tt data-tt-server data-tt-form="student-filter-form" data-tt-live="students-live" data-sort-current="<?= e($sort) ?>" data-sort-dir="<?= e($dir) ?>">
                <thead><tr>
                    <th data-sort-key="sid" data-type="num">Student ID</th>
                    <th data-sort-key="name" data-type="text"><?= t('ui.name_49ee', 'Name') ?></th>
                    <th data-tt-filter="course" data-tt-all="All Courses" data-tt-icon="ti-school" data-tt-value="<?= e($courseSel) ?>" data-tt-options="<?= e(json_encode(array_values($courseOptions))) ?>"><?= t('ui.course_3730', 'Course') ?></th>
                    <th data-sort-key="approved" data-type="date" data-tt-date data-tt-year="<?= $dYear ?: '' ?>" data-tt-month="<?= $dMonth ?: '' ?>" data-tt-month-to="<?= $dMonthTo ?: '' ?>" data-tt-months="<?= e(json_encode((object)$dateMonths)) ?>"><?= t('ui.date_approved_b08c', 'Date Approved') ?></th>
                    <th data-nosort><?= t('ui.status_ec53', 'Status') ?></th>
                    <th data-sort-key="voting" data-type="voting"><?= t('ui.voting_status_0360', 'Voting Status') ?></th>
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
                <tr id="student-row-<?= $s['id'] ?>" data-bulk-id="<?= (int)$s['id'] ?>" data-sid="<?= e($s['student_id']) ?>" data-name="<?= strtolower(e($s['last_name'].' '.$s['first_name'])) ?>" data-course="<?= strtolower(e($s['course'])) ?>" data-approved="<?= !empty($s['approved_at']) ? strtotime($s['approved_at']) : 0 ?>" data-approved-year="<?= !empty($s['approved_at']) ? date('Y', strtotime($s['approved_at'])) : '' ?>" data-approved-month="<?= !empty($s['approved_at']) ? (int)date('n', strtotime($s['approved_at'])) : '' ?>" data-status="<?= $s['is_active'] ? 1 : 0 ?>" data-voting="<?= !$activeElection ? -1 : (!empty($s['voted_now']) ? 1 : 0) ?>" data-search="<?= strtolower(e($s['student_id'].' '.$s['first_name'].' '.$s['last_name'].' '.$s['course'].' '.$votedLabel)) ?>">
                    <td><?= e($s['student_id']) ?></td>
                    <td><?= e($s['last_name'].', '.$s['first_name']) ?></td>
                    <td><?= e($s['course']) ?></td>
                    <td style="white-space:nowrap;">
                        <?php if (!empty($s['approved_at'])): ?>
                            <?= date('M d, Y g:ia', strtotime($s['approved_at'])) ?>
                            <?php if ($isRecent): ?>
                                <span class="badge badge-active" style="margin-left:4px;"><?= t('ui.new_24d4', 'NEW') ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#9CA3AF;">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $s['is_active'] ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-closed">Inactive</span>' ?></td>
                    <td>
                        <?php if (!$activeElection): ?>
                            <span class="badge badge-closed" title="<?= t('ui.no_election_is_currently_active_9fb4', 'No election is currently active') ?>"><?= t('ui.no_active_election_ddd7', 'No active election') ?></span>
                        <?php elseif (!empty($s['voted_now'])): ?>
                            <span class="badge badge-active"><i class="ti ti-check"></i> <?= t('ui.voted_ca78', 'Voted') ?></span>
                        <?php else: ?>
                            <span class="badge badge-closed"><?= t('ui.not_voted_ce75', 'Not Voted') ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm btn-primary"
                            onclick="openEditModal(<?= $s['id'] ?>,'<?= addslashes(e($s['student_id'])) ?>','<?= addslashes(e($s['first_name'])) ?>','<?= addslashes(e($s['last_name'])) ?>','<?= addslashes(e($s['course'])) ?>','<?= addslashes(e($s['email'] ?? '')) ?>')">
                            <i class="ti ti-pencil"></i> <?= t('ui.edit_7dce', 'Edit') ?>
                        </button>
                        <?php if ($s['is_active']): ?><button type="button" class="btn btn-sm btn-outline admin-only" onclick="confirmStudentDisable(<?= $s['id'] ?>, '<?= addslashes(e($s['last_name'].', '.$s['first_name'])) ?>')"><?= t('ui.disable_bcfa', 'Disable') ?></button><?php else: ?><a href="?toggle=1&id=<?= $s['id'] ?>&val=1&<?= csrfParam() ?>" class="btn btn-sm btn-outline"><?= t('ui.enable_2fae', 'Enable') ?></a><?php endif; ?>
                        <button class="btn btn-sm btn-danger admin-only" onclick="openDeleteModal(<?= $s['id'] ?>, 'delete_active', '<?= addslashes(e($s['last_name'].', '.$s['first_name'])) ?>')" style="margin-left:4px;">
                            <i class="ti ti-trash"></i> <?= t('ui.delete_f2a6', 'Delete') ?>
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

<script src="../assets/js/table-tools.js?v=9"></script>
<script src="../assets/js/client-pager.js?v=5"></script>
<script>
// Search for the Pending and Disabled tabs: same box as Active Students, works together with the
// header menus (Course filter, Date range, sorting) and the pager.
(function () {
    function bind(inputId, tableId) {
        var input = document.getElementById(inputId), table = document.getElementById(tableId);
        if (!input || !table) return;
        var timer = null;
        table._ttRun = function () {
            table._ttTerms = (input.value || '').toLowerCase().split(/\s+/).filter(Boolean);
            if (window.TableTools) TableTools.refilter(table);
        };
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(table._ttRun, 150); });
    }
    bind('pending-search', 'pending-table');
    bind('disabled-search', 'disabled-table');
})();
</script>
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
                    <div style="font-size:16px;font-weight:700;color:#1F2937;"><?= t('ui.edit_student_3640', 'Edit Student') ?></div>
                    <div style="font-size:12px;color:#6B7280;"><?= t('ui.update_student_information_bb87', 'Update student information') ?></div>
                </div>
            </div>
            <button onclick="closeEditModal()" style="background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:22px;line-height:1;padding:4px;"><i class="ti ti-x"></i></button>
        </div>

        <input type="hidden" id="edit-id">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Student ID <span style="color:#EF4444;">*</span></label>
                <input type="text" id="edit-student-id" class="form-control" placeholder="<?= t('ui.e_g_20193372_1d70', 'e.g. 20193372') ?>" inputmode="numeric" pattern="[0-9]*" oninput="this.value=this.value.replace(/[^0-9]/g,'')" style="margin-bottom:0;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.first_name_bc91', 'First Name') ?> <span style="color:#EF4444;">*</span></label>
                <input type="text" id="edit-first-name" class="form-control" placeholder="<?= t('ui.first_name_20db', 'First name') ?>" style="margin-bottom:0;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.last_name_7758', 'Last Name') ?> <span style="color:#EF4444;">*</span></label>
                <input type="text" id="edit-last-name" class="form-control" placeholder="<?= t('ui.last_name_8d3f', 'Last name') ?>" style="margin-bottom:0;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.course_3730', 'Course') ?></label>
                <input type="text" id="edit-course" class="form-control" placeholder="<?= t('ui.e_g_bsit_a8b2', 'e.g. BSIT') ?>" style="margin-bottom:0;">
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Email</label>
                <input type="email" id="edit-email" class="form-control" placeholder="student@gnc.edu.ph" style="margin-bottom:0;">
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.change_password_0b39', 'Change password') ?> <span style="color:#6B7280;font-weight:400;"><?= t('ui.optional_f53d', '(optional)') ?></span></label>
                <input type="password" id="edit-new-password" class="form-control" placeholder="<?= t('ui.leave_blank_to_keep_the_current_pa_e9e2', 'Leave blank to keep the current password. At least 8 characters.') ?>" autocomplete="new-password" style="margin-bottom:0;">
                <div style="font-size:11px;color:#6B7280;margin-top:4px;"><?= t('ui.leave_blank_to_keep_the_current_pa_b8ef', 'Leave blank to keep the current password.') ?></div>
            </div>
        </div>

        <div id="edit-error" style="display:none;margin-top:14px;padding:10px 14px;background:#FEE2E2;color:#991B1B;border-radius:8px;font-size:13px;"></div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;">
            <button onclick="closeEditModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <button onclick="submitEdit()" id="edit-save-btn" class="btn btn-sm btn-primary"><i class="ti ti-device-floppy"></i> <?= t('ui.save_changes_f5d6', 'Save Changes') ?></button>
        </div>
    </div>
</div>


<script src="../assets/js/admin-otp.js?v=2"></script>
<script>
var CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;

var _deleteTarget = null; // unused, kept for compatibility

// Delete and disable are confirmed with an emailed OTP (assets/js/admin-otp.js)
function openDeleteModal(id, action, name) {
    var isActive = action === 'delete_active';
    var row = isActive
        ? document.getElementById('student-row-' + id)
        : document.getElementById('disabled-row-' + id);
    AdminOtp.open({
        op: action,
        targetId: id,
        title: isActive ? 'Delete Student Account' : __t('Delete Disabled Account'),
        subtitle: name || '',
        body: __t('This will permanently delete this student account. This cannot be undone.'),
        confirmLabel: __t('Delete Permanently'),
        danger: true,
        action: { ajax_action: action, id: id },
        onDone: function (data) {
            showToast(data.message, 'success');
            if (row) {
                row.style.transition = 'opacity 0.4s';
                row.style.opacity = '0';
                setTimeout(function () {
                    row.remove();
                    if (isActive) {
                        var countEl = document.getElementById('student-count');
                        if (countEl) countEl.textContent = Math.max(0, parseInt(countEl.textContent || '0') - 1);
                    } else {
                        decrementDisabledCount();
                    }
                }, 400);
            }
        }
    });
}

function confirmStudentDisable(id, name) {
    AdminOtp.open({
        op: 'disable_student',
        targetId: id,
        title: __t('Disable Account'),
        subtitle: name || '',
        body: (name || __t('This student')) + ' will no longer be able to log in. Their data is kept and the account is auto-deleted after 30 days.',
        confirmLabel: __t('Disable'),
        danger: false,
        action: { ajax_action: 'disable_student', id: id },
        onDone: function (data) {
            showToast(data.message, 'success');
            setTimeout(function () { location.reload(); }, 800);
        }
    });
}

function openEditModal(id, studentId, firstName, lastName, course, email) {
    document.getElementById('edit-id').value          = id;
    document.getElementById('edit-student-id').value  = studentId;
    document.getElementById('edit-first-name').value  = firstName;
    document.getElementById('edit-last-name').value   = lastName;
    document.getElementById('edit-course').value      = course;
    document.getElementById('edit-email').value       = email;
    document.getElementById('edit-new-password').value = '';
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
    var course     = document.getElementById('edit-course').value.trim();
    var email      = document.getElementById('edit-email').value.trim();
    var newPw      = document.getElementById('edit-new-password').value;
    var errEl      = document.getElementById('edit-error');

    if (!studentId || !firstName || !lastName) {
        errEl.textContent = 'Student ID, first name, and last name are required.';
        errEl.style.display = 'block';
        return;
    }

    if (!/^[0-9]+$/.test(studentId)) {
        errEl.textContent = t('msg.student_id_must_contain_numbers_only_no__984a', 'Student ID must contain numbers only (no letters or symbols).');
        errEl.style.display = 'block';
        return;
    }
    if (newPw !== '' && newPw.length < 8) {
        errEl.textContent = t('msg.new_password_must_be_at_least_8_characte_88cc', 'New password must be at least 8 characters.');
        errEl.style.display = 'block';
        return;
    }
    errEl.style.display = 'none';

    // Edits are confirmed with the emailed code first (same as delete/disable)
    AdminOtp.open({
        op: 'edit_student',
        targetId: id,
        title: __t('Save Student Changes'),
        subtitle: lastName + ', ' + firstName,
        body: __t('The details of this student account will be changed after you confirm.'),
        confirmLabel: __t('Save Changes'),
        danger: false,
        action: {
            ajax_action: 'edit',
            id: id,
            student_id: studentId,
            first_name: firstName,
            last_name:  lastName,
            course:     course,
            email:      email,
            new_password: newPw
        },
        onDone: function (data) {
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
        }
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
            <div style="font-size:15px;font-weight:600;color:#1F2937;"><i class="ti ti-file-spreadsheet" style="margin-right:6px;color:#7C3AED;"></i> <?= t('ui.import_students_via_excel_d2c7', 'Import Students via Excel') ?></div>
            <button onclick="closeStudentImportModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#9CA3AF;">&times;</button>
        </div>

        <div style="background:#EDE9FE;border:1px solid #C4B5FD;border-radius:10px;padding:14px 16px;margin-bottom:18px;">
            <div style="font-size:13px;font-weight:600;color:#5B21B6;margin-bottom:6px;"><i class="ti ti-download" style="margin-right:4px;"></i> <?= t('ui.step_1_get_a_file_to_edit_dbbd', 'Step 1 — Get a file to edit') ?></div>
            <div style="font-size:12px;color:#6D28D9;margin-bottom:10px;line-height:1.5;">
                <?= t('ui.use_the_blank_template_below_2d3c', 'Use the blank template below,') ?> <strong><?= t('ui.or_e81c', 'or') ?></strong> <?= t('ui.just_edit_an_exported_student_mast_89cb', 'just edit an exported Student Masterlist directly (Students → Export) and re-upload it — both work.') ?><br>
                <?= t('ui.fill_in_61f2', 'Fill in:') ?> <strong>student_id, first_name, last_name</strong> <?= t('ui.required_course_email_optional_5f99', '(required) + course, email (optional).') ?><br>
                <strong><?= t('ui.default_password_student_id_2966', 'Default password = Student ID.') ?></strong> <?= t('ui.students_must_change_it_on_first_l_cc36', 'Students must change it on first login.') ?><br>
                <strong>Tip:</strong> <?= t('ui.use_an_ae00', 'use an') ?> <strong><?= t('ui.existing_f4e0', 'existing') ?></strong> <?= t('ui.student_id_to_edit_that_student_s__b4df', 'Student ID to edit that student\'s info instead of adding a new one — it will update their name/course/email, not duplicate the record.') ?>
            </div>
            <a href="import-students.php?template=1" class="btn btn-sm" style="background:#7C3AED;color:#fff;">
                <i class="ti ti-table-export"></i> <?= t('ui.download_template_xlsx_68a5', 'Download Template (.xlsx)') ?>
            </a>
        </div>

        <div style="font-size:13px;font-weight:600;color:#1F2937;margin-bottom:10px;">
            <i class="ti ti-upload" style="margin-right:4px;color:#6B1A2A;"></i> <?= t('ui.step_2_upload_filled_excel_file_th_3421', 'Step 2 — Upload filled Excel file, then review the changes before importing') ?>
        </div>

        <div id="stu-drop-zone" style="border:2px dashed #D1D5DB;border-radius:10px;padding:24px;text-align:center;cursor:pointer;transition:all 0.2s;margin-bottom:14px;"
             onclick="document.getElementById('stu-excel-input').click()"
             ondragover="event.preventDefault();this.style.borderColor='#7C3AED';this.style.background='#F5F3FF';"
             ondragleave="this.style.borderColor='#D1D5DB';this.style.background='';"
             ondrop="handleStuDrop(event)">
            <i class="ti ti-file-spreadsheet" style="font-size:32px;color:#9CA3AF;display:block;margin-bottom:8px;"></i>
            <div id="stu-drop-label" style="font-size:13px;color:#6B7280;"><?= t('ui.click_to_browse_or_drag_drop_your__9481', 'Click to browse or drag & drop your Excel file here') ?></div>
            <div style="font-size:11px;color:#9CA3AF;margin-top:4px;"><?= t('ui.xlsx_or_xls_max_10mb_8a23', '.xlsx or .xls · max 10MB') ?></div>
        </div>
        <input type="file" id="stu-excel-input" accept=".xlsx,.xls" style="display:none;" onchange="handleStuFile(this.files[0])">

        <div id="stu-import-result" style="display:none;"></div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
            <button onclick="closeStudentImportModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <button id="stu-import-btn" onclick="submitStudentImport()" class="btn btn-sm" style="background:#7C3AED;color:#fff;" disabled>
                <i class="ti ti-eye"></i> <?= t('ui.preview_changes_b2e8', 'Preview Changes') ?>
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
    document.getElementById('stu-drop-label').textContent = __t('Click to browse or drag & drop your Excel file here');
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

// One button, two steps: __t("Preview Changes") first, then __t("Confirm Import").
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
        .then(function(r){
            return r.text().then(function(t){
                try { return JSON.parse(t); }
                catch (e) {
                    // Not JSON (usually a PHP error page): show what the server said
                    var msg = t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
                    throw new Error(msg || ('Empty response from server (HTTP ' + r.status + ')'));
                }
            });
        });
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
    }).catch(function(err) {
        stuResetPreview();
        result.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:8px;padding:12px;color:#991B1B;">Could not import: ' + stuEsc(err && err.message ? err.message : 'unknown error') + '</div>';
        result.style.display = 'block';
    });
}

function confirmStudentImport() {
    if (!window._stuImportGateOK) {
        AdminOtp.gate({
            op: 'import_students', title: __t('Confirm student import'), subtitle: __t('Import students from Excel'),
            body: __t('The students in this file will be added or updated after you confirm.'), confirmLabel: __t('Import'),
            proceed: function () { window._stuImportGateOK = true; try { confirmStudentImport(); } finally { window._stuImportGateOK = false; } }
        });
        return;
    }
    var p = _stuPreview;
    var parts = [];
    if (p.new)     parts.push(p.new + ' new student' + (p.new === 1 ? '' : 's') + ' will be added');
    if (p.updated) parts.push(p.updated + ' existing student' + (p.updated === 1 ? '' : 's') + ' will be changed');
    appConfirm({
        title:   __t('Import these changes?'),
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
        // Itemized list of what changed (add = new student, update = old -> new per field)
        if (data.details && data.details.length) {
            html += '<div style="font-size:12px;font-weight:600;color:#374151;margin:12px 0 6px;">What changed</div>';
            html += '<div style="max-height:320px;overflow-y:auto;border:1px solid #E5E7EB;border-radius:8px;">';
            data.details.forEach(function(d) {
                var badge = d.action === 'add'
                    ? '<span style="background:#ECFDF5;color:#065F46;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:600;">NEW</span>'
                    : '<span style="background:#EFF6FF;color:#1D4ED8;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:600;">UPDATED</span>';
                html += '<div style="padding:10px 12px;border-bottom:1px solid #F3F4F6;font-size:13px;">' +
                    badge + ' <strong>' + stuEsc(d.name) + '</strong> <span style="color:#6B7280;">(' + stuEsc(d.student_id) + ')</span>';
                if (d.action === 'update' && d.changes && d.changes.length) {
                    html += '<div style="margin-top:4px;">' + d.changes.map(stuChangeChip).join('') + '</div>';
                }
                html += '</div>';
            });
            html += '</div>';
        }
        result.innerHTML = html; result.style.display = 'block';
        if (data.added > 0 || data.updated > 0) {
            // Keep the list on screen. Reload only when the admin clicks Done.
            btn.disabled = false;
            btn.innerHTML = '<i class="ti ti-check"></i> Done';
            btn.onclick = function () { location.reload(); };
        } else {
            _stuPreview = null;
            btn.disabled = false; btn.style.background = '#7C3AED'; btn.innerHTML = _STU_BTN_PREVIEW;
        }
    }).catch(function(err) {
        btn.disabled = false; btn.innerHTML = '<i class="ti ti-check"></i> Confirm Import';
        result.innerHTML = '<div style="background:#FEE2E2;border:1px solid #FECACA;border-radius:8px;padding:12px;color:#991B1B;">Could not import: ' + stuEsc(err && err.message ? err.message : 'unknown error') + '</div>';
        result.style.display = 'block';
    });
}
</script>
<script src="../assets/js/alerts.js"></script>
<script src="../assets/js/sidebar.js?v=5"></script>
<?php if (($_SESSION['user_type'] ?? '') !== 'admin'): ?><style>.admin-only{display:none!important}</style><?php endif; ?>
<script src="../assets/js/bulk-select.js?v=6"></script>
<script>
(function () {
  // Bulk actions reuse the same per-student server handlers (same permission + CSRF checks).
  function stuPost(action, id, reason) {
    var fd = new FormData();
    fd.append('ajax_action', action);
    fd.append('id', id);
    if (reason) fd.append('reason', reason);
    fd.append('csrf_token', CSRF_TOKEN);
    return fetch(window.location.pathname.replace(/\?.*$/, ''), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { return !!(d && d.success); });
  }
  var pw = document.getElementById('pending-table-wrap');
  if (pw) BulkSelect.init({
    rows: '#pending-tbody tr[data-bulk-id]', before: pw, noun: 'student',
    actions: [
      { label: __t('Approve'), icon: 'ti-check', confirm: function (n) { return 'Approve ' + n + __t(' registration(s)? Each student will be notified.'); },
        run: function (id) { return stuPost('approve', id); } },
      { label: __t('Reject'), icon: 'ti-x', danger: true, reason: true, confirm: function (n) { return 'Reject ' + n + __t(' registration(s)? The same reason will be emailed to each student.'); },
        run: function (id, reason) { return stuPost('reject', id, reason); } }
    ]
  });
  var IS_ADMIN = <?= json_encode(($_SESSION['user_type'] ?? '') === 'admin') ?>;
  // Delete / Disable need the emailed code: ONE code covers the whole selection, tied to that exact list.
  function otpBatch(op, ids, done, t) {
    var target = BulkSelect.batchTarget(ids);
    AdminOtp.open({
      op: op, targetId: target, title: t.title + ' (' + ids.length + ')', subtitle: ids.length + ' selected',
      body: t.body, confirmLabel: t.label, danger: t.danger,
      action: { ajax_action: op, id: target, ids: ids.join(',') },
      onDone: function (d) { done(d.ok || 0, d.failed || 0, d.message); }
    });
  }
  var dw = document.getElementById('disabled-table-wrap');
  if (dw) {
    var dActs = [
      { label: __t('Enable'), icon: 'ti-user-check', confirm: function (n) { return 'Re-enable ' + n + ' account(s)?'; },
        run: function (id) { return stuPost('enable_disabled', id); } }
    ];
    if (IS_ADMIN) dActs.push({ label: __t('Delete'), icon: 'ti-trash', danger: true,
      batch: function (ids, done) { otpBatch('bulk_delete_disabled', ids, done, { title: __t('Delete Disabled Accounts'), label: __t('Delete Permanently'), danger: true, body: 'These disabled accounts will be permanently deleted. This cannot be undone.' }); } });
    BulkSelect.init({ rows: '#disabled-tbody tr[data-bulk-id]', before: dw, noun: 'student', actions: dActs });
  }
  var live = document.getElementById('students-live');
  if (live && IS_ADMIN) BulkSelect.init({
    rows: '#student-tbody tr[data-bulk-id]', before: live, noun: 'student', exportType: 'students',
    actions: [
      { label: __t('Disable'), icon: 'ti-user-off',
        batch: function (ids, done) { otpBatch('bulk_disable_student', ids, done, { title: __t('Disable Accounts'), label: __t('Disable'), danger: false, body: __t('These students will no longer be able to log in. Data is kept; accounts are auto-deleted after 30 days.') }); } },
      { label: __t('Delete'), icon: 'ti-trash', danger: true,
        batch: function (ids, done) { otpBatch('bulk_delete_active', ids, done, { title: __t('Delete Student Accounts'), label: __t('Delete Permanently'), danger: true, body: 'These accounts will be permanently deleted and the students notified by email. This cannot be undone.' }); } }
    ]
  });
})();
</script>
</body></html>