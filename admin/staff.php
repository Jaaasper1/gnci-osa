<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/admin-otp.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/csrf.php';

// AJAX handler — checked before requireAdmin() so a session redirect doesn't break fetch()
if (isset($_POST['ajax_action'])) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => t('msg.unauthorized_please_log_in_again_f919', 'Unauthorized. Please log in again.')]);
        exit;
    }

    $sentToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
        echo json_encode(['success' => false, 'message' => t('msg.invalid_or_expired_request_please_refres_4159', 'Invalid or expired request. Please refresh the page and try again.')]);
        exit;
    }

    $ajaxAction = trim($_POST['ajax_action'] ?? '');
    $targetId   = (int)($_POST['id'] ?? 0);

    if ($ajaxAction === 'request_admin_otp') {
        $op = trim($_POST['op'] ?? '');
        if (!in_array($op, ['delete_staff', 'disable_staff', 'edit_staff', 'bulk_delete_staff', 'bulk_disable_staff'], true)) {
            echo json_encode(['success' => false, 'message' => t('msg.unknown_action_9258', 'Unknown action.')]);
            exit;
        }
        $method = in_array($_POST['method'] ?? '', ['password', 'email'], true) ? $_POST['method'] : 'auto';
        [$sent, $msg, $mode] = adminOtpSend($pdo, (int)($_SESSION['user_id'] ?? 0), $op, $targetId, $method);
        echo json_encode(['success' => $sent, 'message' => $msg, 'mode' => $mode ?? 'email']);
        exit;
    }

    if ($ajaxAction === 'bulk_delete_staff' || $ajaxAction === 'bulk_disable_staff') {
        // One emailed code covers this exact list of staff accounts (see adminOtpBatchTarget).
        $ids = adminOtpParseIds($_POST['ids'] ?? '');
        $me  = (int)($_SESSION['user_id'] ?? 0);
        if (!$ids) {
            echo json_encode(['success' => false, 'message' => t('msg.no_accounts_selected_or_too_many_at_once_f101', 'No accounts selected (or too many at once, max 200).')]);
            exit;
        }
        [$otpOk, $otpMsg] = adminOtpVerify($ajaxAction, adminOtpBatchTarget($ids), $me, $_POST['admin_otp'] ?? '');
        if (!$otpOk) {
            echo json_encode(['success' => false, 'message' => $otpMsg]);
            exit;
        }
        $okCount = 0;
        foreach ($ids as $sid) {
            if ($sid === $me) continue; // never touch your own account; role='staff' below also excludes admins
            $st = $ajaxAction === 'bulk_delete_staff'
                ? $pdo->prepare("DELETE FROM osa_staff WHERE id=? AND role='staff'")
                : $pdo->prepare("UPDATE osa_staff SET is_active=0 WHERE id=? AND role='staff'");
            $st->execute([$sid]);
            if ($st->rowCount() > 0) $okCount++;
        }
        $total = count($ids);
        $verb = $ajaxAction === 'bulk_delete_staff' ? 'deleted' : 'disabled';
        echo json_encode(['success' => true, 'ok' => $okCount, 'failed' => $total - $okCount,
            'message' => "$okCount of $total staff account(s) $verb."]);
        exit;
    }

    if ($ajaxAction === 'disable_staff') {
        [$otpOk, $otpMsg] = adminOtpVerify($ajaxAction, $targetId, (int)($_SESSION['user_id'] ?? 0), $_POST['admin_otp'] ?? '');
        if (!$otpOk) {
            echo json_encode(['success' => false, 'message' => $otpMsg]);
            exit;
        }
        $check = $pdo->prepare("SELECT role FROM osa_staff WHERE id=?");
        $check->execute([$targetId]);
        $target = $check->fetch();
        if (!$target || $target['role'] !== 'staff') {
            echo json_encode(['success' => false, 'message' => t('msg.admin_accounts_cannot_be_disabled_here_d3a1', 'Admin accounts cannot be disabled here.')]);
            exit;
        }
        $__before = auditRow($pdo, 'osa_staff', $targetId);
        $pdo->prepare("UPDATE osa_staff SET is_active=0 WHERE id=? AND role='staff'")->execute([$targetId]);
        auditLog($pdo, 'disable', 'staff', $targetId, $__before, ['is_active' => 0]);
        echo json_encode(['success' => true, 'message' => t('msg.account_disabled_staff_can_no_longer_log_a4ce', 'Account disabled. Staff can no longer log in.')]);
        exit;
    }

    if ($ajaxAction === 'update_staff') {
        [$otpOk, $otpMsg] = adminOtpVerify('edit_staff', $targetId, (int)($_SESSION['user_id'] ?? 0), $_POST['admin_otp'] ?? '');
        if (!$otpOk) {
            echo json_encode(['success' => false, 'message' => $otpMsg]);
            exit;
        }
        $uname    = trim($_POST['username'] ?? '');
        $fname    = trim($_POST['first_name'] ?? '');
        $lname    = trim($_POST['last_name'] ?? '');
        $newEmail = trim($_POST['new_email'] ?? '');
        $newPw    = (string)($_POST['new_password'] ?? '');   // optional: blank = keep current password
        if ($newPw !== '' && mb_strlen($newPw) < 9) {
            echo json_encode(['success' => false, 'message' => t('msg.new_password_must_be_at_least_9_characte_d1ff', 'New password must be at least 9 characters.')]);
            exit;
        }
        if ($targetId <= 0 || $uname === '' || $fname === '' || $lname === '' || $newEmail === '') {
            echo json_encode(['success' => false, 'message' => t('msg.please_fill_in_all_fields_18f5', 'Please fill in all fields.')]);
            exit;
        }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => t('msg.please_enter_a_valid_email_address_b5bc', 'Please enter a valid email address.')]);
            exit;
        }
        $__before = auditRow($pdo, 'osa_staff', $targetId);
        try {
            $pdo->prepare("UPDATE osa_staff SET username=?, first_name=?, last_name=?, email=? WHERE id=?")
                ->execute([$uname, $fname, $lname, $newEmail, $targetId]);
            if ($newPw !== '') {
                $pdo->prepare("UPDATE osa_staff SET password=? WHERE id=?")
                    ->execute([password_hash($newPw, PASSWORD_DEFAULT), $targetId]);
                adminMarkPasswordSet($pdo, $targetId);   // this account now confirms with its password
            }
            auditLog($pdo, 'update', 'staff', $targetId, $__before, ['username' => $uname, 'first_name' => $fname, 'last_name' => $lname, 'email' => $newEmail, 'password_reset' => $newPw !== '']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => t('msg.that_username_is_already_taken_3f77', 'That username is already taken.')]);
            exit;
        }
        // Keep the session in sync if the admin edited their own account
        if ($targetId === (int)($_SESSION['user_id'] ?? 0)) {
            $_SESSION['username'] = $uname;
            $_SESSION['name']     = $fname . ' ' . $lname;
        }
        echo json_encode(['success' => true, 'message' => t('msg.account_updated_2a38', 'Account updated.')]);
        exit;
    }

    if ($ajaxAction === 'delete_staff') {
        [$otpOk, $otpMsg] = adminOtpVerify($ajaxAction, $targetId, (int)($_SESSION['user_id'] ?? 0), $_POST['admin_otp'] ?? '');
        if (!$otpOk) {
            echo json_encode(['success' => false, 'message' => $otpMsg]);
            exit;
        }
        // Only allow deleting staff accounts, never admin
        $check = $pdo->prepare("SELECT role FROM osa_staff WHERE id=?");
        $check->execute([$targetId]);
        $target = $check->fetch();
        if (!$target || $target['role'] !== 'staff') {
            echo json_encode(['success' => false, 'message' => t('msg.admin_accounts_cannot_be_deleted_here_737a', 'Admin accounts cannot be deleted here.')]);
            exit;
        }
        $__before = auditRow($pdo, 'osa_staff', $targetId);
        $stmt = $pdo->prepare("DELETE FROM osa_staff WHERE id=? AND role='staff'");
        $stmt->execute([$targetId]);
        if ($stmt->rowCount() > 0) {
            auditLog($pdo, 'delete', 'staff', $targetId, $__before);
            echo json_encode(['success' => true, 'message' => t('msg.staff_account_deleted_f95a', 'Staff account deleted.')]);
        } else {
            echo json_encode(['success' => false, 'message' => t('msg.staff_account_not_found_c220', 'Staff account not found.')]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => t('msg.unknown_action_9258', 'Unknown action.')]);
    exit;
}

requireAdmin();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        // Only allow toggling staff accounts, never admin
        $check = $pdo->prepare("SELECT role FROM osa_staff WHERE id=?");
        $check->execute([$target_id]);
        $target = $check->fetch();
        $new_status = (int)($_POST['new_status'] ?? 0);
        if ($new_status === 0) {
            $error = t('msg.disabling_a_staff_account_requires_an_ot_8c96', 'Disabling a staff account requires an OTP confirmation.');
        } elseif ($target && $target['role'] === 'staff') {
            $__before = auditRow($pdo, 'osa_staff', $target_id);
            $pdo->prepare("UPDATE osa_staff SET is_active=1 WHERE id=? AND role='staff'")->execute([$target_id]);
            auditLog($pdo, 'enable', 'staff', $target_id, $__before, ['is_active' => 1]);
            $success = t('msg.account_enabled_610d', 'Account enabled.');
        } else {
            $error = t('msg.admin_accounts_cannot_be_disabled_here_d3a1', 'Admin accounts cannot be disabled here.');
        }
    } else {
        $uname = trim($_POST['username'] ?? '');
        $fname = trim($_POST['first_name'] ?? '');
        $lname = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? 'staff';
        if (!$uname || !$fname || !$lname || !$email) { $error = t('msg.please_fill_in_all_required_fields_d5a0', 'Please fill in all required fields.'); }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = t('msg.please_enter_a_valid_email_address_b5bc', 'Please enter a valid email address.'); }
        else {
            try {
                // Staff log in with an emailed code only, so the password column gets a random,
                // unusable hash (the column is NOT NULL in the schema).
                $unusable = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO osa_staff (username,first_name,last_name,email,role,password) VALUES (?,?,?,?,?,?)")->execute([$uname,$fname,$lname,$email,$role,$unusable]);
                $success = t('msg.staff_member_added_74d0', 'Staff member added!');
                auditLog($pdo, 'create', 'staff', (int)$pdo->lastInsertId(), [], ['username' => $uname, 'role' => $role]);
            } catch (Exception $e) { $error = t('msg.duplicate_username_ccc3', 'Duplicate username.'); }
        }
    }
}

$staffList = $pdo->query("SELECT * FROM osa_staff ORDER BY role DESC, last_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('title.osa_staff', 'OSA Staff') ?> — GNC-OSA Admin</title>
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
    <a class="sitem" href="students.php"><i class="ti ti-user-plus"></i> <?= t('sidebar.students', 'Students') ?></a>
    <?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem active" href="staff.php"><i class="ti ti-shield-check"></i> <?= t('sidebar.osa_staff', 'OSA Staff') ?></a><?php endif; ?><?php if ($_SESSION['user_type'] === 'admin'): ?><a class="sitem" href="audit-log.php"><i class="ti ti-history"></i> <?= t('sidebar.audit_log', 'Audit Log') ?></a><?php endif; ?>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> <?= t('sidebar.reports', 'Reports') ?></a>
    <div class="sidebar-section"><?= t('sidebar.account', 'Account') ?></div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> <?= t('sidebar.notifications', 'Notifications') ?>
        <?php if ($notifCount > 0): ?><span class="badge-count"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a class="sitem" href="../logout.php"><i class="ti ti-logout"></i> <?= t('nav.logout', 'Log out') ?></a>
</aside>
<div class="main"><div class="content">
    <div class="page-header">
        <div><h1><?= t('admin.staff.title', 'OSA Staff Accounts') ?></h1><p><?= t('admin.staff.subtitle', 'Manage OSA staff and admin accounts') ?></p></div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="ti ti-check"></i><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= e($error) ?></div><?php endif; ?>

    <!-- Toast -->
    <div id="toast" style="position:fixed;top:20px;right:24px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 4px 16px rgba(0,0,0,0.15);display:none;align-items:center;gap:10px;"></div>

    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="card-title" style="margin-bottom:0;"><i class="ti ti-shield-check"></i> <?= t('ui.staff_list_6b91', 'Staff List') ?> <span style="background:#7C3AED;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;margin-left:6px;"><?= count($staffList) ?></span></div>
                <button onclick="openAddModal()" class="btn btn-primary btn-sm" style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
                    <i class="ti ti-circle-plus"></i> <?= t('ui.add_staff_cce4', 'Add Staff') ?>
                </button>
            </div>
            <div style="position:relative;min-width:220px;">
                <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:15px;"></i>
                <input type="text" id="staff-search" placeholder="<?= t('ui.search_by_name_or_username_98ea', 'Search by name or username...') ?>" 
                       class="form-control" style="padding-left:32px;margin-bottom:0;"
                       oninput="filterStaff(this.value)">
            </div>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Username</th><th><?= t('ui.name_49ee', 'Name') ?></th><th><?= t('ui.role_bbba', 'Role') ?></th><th><?= t('ui.status_ec53', 'Status') ?></th><th></th></tr></thead>
            <tbody id="staff-tbody"><?php foreach ($staffList as $st): ?>
            <tr id="staff-row-<?= $st['id'] ?>"<?= $st['role'] === 'staff' ? ' data-bulk-id="' . (int)$st['id'] . '"' : '' ?> data-search="<?= strtolower(e($st['username'].' '.$st['first_name'].' '.$st['last_name'])) ?>">
                <td><?= e($st['username']) ?></td>
                <td><?= e($st['first_name'].' '.$st['last_name']) ?></td>
                <td><span class="badge <?= $st['role']==='admin'?'badge-review':'badge-active' ?>"><?= $st['role'] ?></span></td>
                <td><?= $st['is_active'] ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-closed">Inactive</span>' ?></td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-outline btn-sm" onclick="openEditStaffModal(this)"
                        data-id="<?= (int)$st['id'] ?>" data-username="<?= e($st['username']) ?>"
                        data-first="<?= e($st['first_name']) ?>" data-last="<?= e($st['last_name']) ?>" data-email="<?= e($st['email'] ?? '') ?>"
                        style="margin-right:4px;"><i class="ti ti-pencil"></i> <?= t('ui.edit_7dce', 'Edit') ?></button>
                    <?php if ($st['role'] === 'staff'): ?>
                        <?php if ($st['is_active']): ?>
                            <button class="btn btn-sm btn-outline" onclick="confirmToggle(<?= $st['id'] ?>, '<?= e($st['first_name'].' '.$st['last_name']) ?>', 0)"
                                style="margin-right:4px;color:#D97706;border-color:#D97706;">
                                <i class="ti ti-user-off"></i> <?= t('ui.disable_bcfa', 'Disable') ?>
                            </button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline" onclick="confirmToggle(<?= $st['id'] ?>, '<?= e($st['first_name'].' '.$st['last_name']) ?>', 1)"
                                style="margin-right:4px;color:#059669;border-color:#059669;">
                                <i class="ti ti-user-check"></i> <?= t('ui.enable_2fae', 'Enable') ?>
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline" onclick="openDeleteModal(<?= $st['id'] ?>, '<?= e($st['first_name'].' '.$st['last_name']) ?>')"
                            style="color:#991B1B;border-color:#991B1B;">
                            <i class="ti ti-trash"></i> <?= t('ui.delete_f2a6', 'Delete') ?>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <div id="no-results" style="display:none;text-align:center;padding:24px;color:#9CA3AF;font-size:13px;">
            <i class="ti ti-mood-sad" style="font-size:28px;display:block;margin-bottom:6px;"></i>
            <?= t('ui.no_staff_found_44f1', 'No staff found.') ?>
        </div>
    </div>
</div></div>

<!-- ===== ADD STAFF MODAL ===== -->
<!-- ===== EDIT STAFF EMAIL MODAL ===== -->
<div id="edit-staff-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
  <form id="edit-staff-form" onsubmit="event.preventDefault(); submitStaffEdit(); return false;" style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:480px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;max-height:90vh;overflow-y:auto;">
    <input type="hidden" id="edit-staff-id">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:38px;height:38px;border-radius:50%;background:#EDE9FE;display:flex;align-items:center;justify-content:center;">
          <i class="ti ti-pencil" style="color:#7C3AED;font-size:18px;"></i>
        </div>
        <div>
          <div style="font-size:16px;font-weight:700;color:#1F2937;"><?= t('ui.edit_staff_b70b', 'Edit Staff') ?></div>
          <div style="font-size:12px;color:#6B7280;"><?= t('ui.confirm_with_your_admin_password_o_9a47', 'Confirm with your admin password or an emailed code') ?></div>
        </div>
      </div>
      <button type="button" onclick="closeEditStaffModal()" style="background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:22px;line-height:1;padding:4px;"><i class="ti ti-x"></i></button>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
      <div style="grid-column:1/-1;">
        <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Username <span style="color:#EF4444;">*</span></label>
        <input type="text" name="username" id="edit-staff-username" class="form-control" required style="margin-bottom:0;">
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.first_name_bc91', 'First Name') ?> <span style="color:#EF4444;">*</span></label>
        <input type="text" name="first_name" id="edit-staff-first" class="form-control" required style="margin-bottom:0;">
      </div>
      <div>
        <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.last_name_7758', 'Last Name') ?> <span style="color:#EF4444;">*</span></label>
        <input type="text" name="last_name" id="edit-staff-last" class="form-control" required style="margin-bottom:0;">
      </div>
      <div style="grid-column:1/-1;">
        <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Email <span style="color:#EF4444;">*</span></label>
        <input type="email" name="new_email" id="edit-staff-email" class="form-control" placeholder="name@gmail.com" required style="margin-bottom:0;">
      </div>
      <div style="grid-column:1/-1;">
        <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.change_password_0b39', 'Change password') ?> <span style="color:#6B7280;font-weight:400;"><?= t('ui.optional_f53d', '(optional)') ?></span></label>
        <input type="password" id="edit-staff-newpw" class="form-control" placeholder="<?= t('ui.leave_blank_to_keep_the_current_pa_6c04', 'Leave blank to keep the current password. At least 9 characters.') ?>" autocomplete="new-password" style="margin-bottom:0;">
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;">
      <button type="button" onclick="closeEditStaffModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
      <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-mail-check"></i> <?= t('ui.continue_a0bf', 'Continue') ?></button>
    </div>
  </form>
</div>

<div id="add-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;max-height:90vh;overflow-y:auto;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:38px;height:38px;border-radius:50%;background:#EDE9FE;display:flex;align-items:center;justify-content:center;">
                    <i class="ti ti-circle-plus" style="color:#7C3AED;font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-size:16px;font-weight:700;color:#1F2937;"><?= t('ui.add_staff_member_4b1e', 'Add Staff Member') ?></div>
                    <div style="font-size:12px;color:#6B7280;"><?= t('ui.create_a_new_osa_staff_or_admin_ac_ce5f', 'Create a new OSA staff or admin account') ?></div>
                </div>
            </div>
            <button onclick="closeAddModal()" style="background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:22px;line-height:1;padding:4px;"><i class="ti ti-x"></i></button>
        </div>

        <form method="POST" id="add-staff-form" onsubmit="return validateForm()">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div>
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.first_name_bc91', 'First Name') ?> <span style="color:#EF4444;">*</span></label>
                    <input type="text" name="first_name" id="modal-first-name" class="form-control" placeholder="<?= t('ui.first_name_20db', 'First name') ?>" style="margin-bottom:0;" required>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.last_name_7758', 'Last Name') ?> <span style="color:#EF4444;">*</span></label>
                    <input type="text" name="last_name" id="modal-last-name" class="form-control" placeholder="<?= t('ui.last_name_8d3f', 'Last name') ?>" style="margin-bottom:0;" required>
                </div>
                <div style="grid-column:1/-1;">
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Username <span style="color:#EF4444;">*</span></label>
                    <input type="text" name="username" id="modal-username" class="form-control" placeholder="<?= t('ui.e_g_osa_staff01_fe89', 'e.g. osa_staff01') ?>" style="margin-bottom:0;" required autocomplete="off">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Gmail <span style="color:#EF4444;">*</span></label>
                    <input type="email" name="email" id="modal-email" class="form-control" placeholder="name@gmail.com" style="margin-bottom:0;" required>
                    <div style="font-size:11px;color:#6B7280;margin-top:4px;"><?= t('ui.login_codes_are_sent_to_this_email_6a95', 'Login codes are sent to this email. No password needed.') ?></div>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;"><?= t('ui.role_bbba', 'Role') ?> <span style="color:#EF4444;">*</span></label>
                    <select name="role" class="form-control" style="margin-bottom:0;">
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>

            <div id="modal-error" style="display:none;margin-top:14px;padding:10px 14px;background:#FEE2E2;color:#991B1B;border-radius:8px;font-size:13px;"></div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;">
                <button type="button" onclick="closeAddModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
                <button type="submit" class="btn btn-sm" style="background:#7C3AED;color:#fff;border-color:#7C3AED;">
                    <i class="ti ti-circle-plus"></i> <?= t('ui.add_staff_cce4', 'Add Staff') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
<?php if ($error): ?>
// Re-open modal if there was a server-side error
window.addEventListener('DOMContentLoaded', function() { openAddModal(); });
<?php endif; ?>

// Simple toast for this page (staff.php does not load the students toast).
if (typeof window.showToast !== 'function') {
    window.showToast = function (message, type) {
        var t = document.createElement('div');
        t.textContent = message;
        t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:9999;padding:12px 18px;border-radius:10px;font-size:13px;box-shadow:0 6px 18px rgba(0,0,0,.2);color:#1F2937;background:' + (type === 'error' ? '#FEE2E2' : '#D1FAE5');
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 2600);
    };
}

function submitStaffEdit() {
    var form = document.getElementById('edit-staff-form');
    if (!form.reportValidity()) return;
    var id    = document.getElementById('edit-staff-id').value;
    var uname = document.getElementById('edit-staff-username').value.trim();
    var newPw = document.getElementById('edit-staff-newpw').value;
    if (newPw !== '' && newPw.length < 9) {
        alert(t('msg.new_password_must_be_at_least_9_characte_d1ff', __t('New password must be at least 9 characters.')));
        return;
    }
    AdminOtp.open({
        op: 'edit_staff',
        targetId: id,
        title: __t('Save Staff Changes'),
        subtitle: uname,
        body: __t('The username, name, or login email of this account will be changed after you confirm.'),
        confirmLabel: __t('Save Changes'),
        danger: false,
        action: {
            ajax_action: 'update_staff',
            id: id,
            username:   uname,
            first_name: document.getElementById('edit-staff-first').value.trim(),
            last_name:  document.getElementById('edit-staff-last').value.trim(),
            new_email:  document.getElementById('edit-staff-email').value.trim(),
            new_password: newPw
        },
        onDone: function (data) {
            closeEditStaffModal();
            showToast(data.message, 'success');
            setTimeout(function () { location.reload(); }, 800);
        }
    });
}

function openEditStaffModal(btn) {
    document.getElementById('edit-staff-id').value       = btn.dataset.id;
    document.getElementById('edit-staff-username').value = btn.dataset.username;
    document.getElementById('edit-staff-first').value    = btn.dataset.first;
    document.getElementById('edit-staff-last').value     = btn.dataset.last;
    document.getElementById('edit-staff-newpw').value    = '';
    document.getElementById('edit-staff-email').value    = btn.dataset.email;
    document.getElementById('edit-staff-overlay').style.display = 'flex';
    setTimeout(function(){ document.getElementById('edit-staff-email').focus(); }, 80);
}

function closeEditStaffModal() {
    document.getElementById('edit-staff-overlay').style.display = 'none';
}

document.getElementById('edit-staff-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeEditStaffModal();
});

function openAddModal() {
    document.getElementById('add-modal-overlay').style.display = 'flex';
    setTimeout(function(){ document.getElementById('modal-first-name').focus(); }, 80);
}

function closeAddModal() {
    document.getElementById('add-modal-overlay').style.display = 'none';
}

document.getElementById('add-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeAddModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAddModal();
});

function togglePass() {
    var inp = document.getElementById('modal-password');
    var eye = document.getElementById('pass-eye');
    if (inp.type === 'password') {
        inp.type = 'text';
        eye.className = 'ti ti-eye-off';
    } else {
        inp.type = 'password';
        eye.className = 'ti ti-eye';
    }
}

function validateForm() {
    var errEl = document.getElementById('modal-error');
    var fname = document.getElementById('modal-first-name').value.trim();
    var lname = document.getElementById('modal-last-name').value.trim();
    var uname = document.getElementById('modal-username').value.trim();
    var email = document.getElementById('modal-email').value.trim();
    if (!fname || !lname || !uname || !email) {
        errEl.textContent = t('msg.please_fill_in_all_required_fields_d5a0', __t('Please fill in all required fields.'));
        errEl.style.display = 'block';
        return false;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errEl.textContent = t('msg.please_enter_a_valid_email_address_b5bc', __t('Please enter a valid email address.'));
        errEl.style.display = 'block';
        return false;
    }
    errEl.style.display = 'none';
    return true;
}

function filterStaff(query) {
    var q = query.toLowerCase().trim();
    var rows = document.querySelectorAll('#staff-tbody tr');
    var visible = 0;
    rows.forEach(function(row) {
        var text = row.getAttribute('data-search') || '';
        var match = !q || text.indexOf(q) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('no-results').style.display = (visible === 0) ? 'block' : 'none';
}
</script>

</script>
<!-- Hidden form: toggle active -->
<form id="toggle-form" method="POST" style="display:none;">
    <input type="hidden" name="action" value="toggle_active">
    <input type="hidden" name="target_id" id="toggle-target-id">
    <input type="hidden" name="new_status" id="toggle-new-status">
</form>

<!-- Delete and disable are confirmed with an emailed OTP (assets/js/admin-otp.js) -->
<script src="../assets/js/admin-otp.js?v=2"></script>
<script>
var CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;

function openDeleteModal(id, name) {
    var row = document.getElementById('staff-row-' + id);
    AdminOtp.open({
        op: 'delete_staff',
        targetId: id,
        title: __t('Delete Staff Account'),
        subtitle: name || '',
        body: __t('This will permanently delete this staff account. This cannot be undone.'),
        confirmLabel: __t('Delete Permanently'),
        danger: true,
        action: { ajax_action: 'delete_staff', id: id },
        onDone: function (data) {
            showToast(data.message, 'success');
            if (row) {
                row.style.transition = 'opacity 0.4s';
                row.style.opacity = '0';
                setTimeout(function () { row.remove(); }, 400);
            }
        }
    });
}

function adminDisableStaff(id, name) {
    AdminOtp.open({
        op: 'disable_staff',
        targetId: id,
        title: __t('Disable Account'),
        subtitle: name || '',
        body: (name || __t('This account')) + __t(' will no longer be able to log in. Their data is kept.'),
        confirmLabel: __t('Disable'),
        danger: false,
        action: { ajax_action: 'disable_staff', id: id },
        onDone: function (data) {
            showToast(data.message, 'success');
            setTimeout(function () { location.reload(); }, 800);
        }
    });
}
</script>

<!-- Confirm modal (shared, still used for enable/disable) -->
<div id="confirm-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9500;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:400px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div id="confirm-icon-wrap" style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i id="confirm-icon" style="font-size:20px;"></i>
            </div>
            <div>
                <div id="confirm-title" style="font-size:15px;font-weight:700;color:#1F2937;"></div>
                <div id="confirm-subtitle" style="font-size:12px;color:#6B7280;margin-top:2px;"></div>
            </div>
        </div>
        <div id="confirm-body" style="font-size:13px;color:#374151;background:#F9FAFB;border-radius:8px;padding:12px 14px;margin-bottom:20px;"></div>
        <div style="display:flex;justify-content:flex-end;gap:10px;">
            <button onclick="closeConfirmModal()" class="btn btn-outline btn-sm"><?= t('ui.cancel_ea47', 'Cancel') ?></button>
            <button id="confirm-btn" class="btn btn-sm" onclick="submitConfirm()"></button>
        </div>
    </div>
</div>

<script>
var _confirmCallback = null;

function confirmToggle(id, name, newStatus) {
    if (newStatus === 0) { adminDisableStaff(id, name); return; }
    var enabling = newStatus === 1;
    document.getElementById('confirm-icon-wrap').style.background = enabling ? '#D1FAE5' : '#FEF3C7';
    document.getElementById('confirm-icon').className = enabling ? 'ti ti-user-check' : 'ti ti-user-off';
    document.getElementById('confirm-icon').style.color = enabling ? '#059669' : '#D97706';
    document.getElementById('confirm-title').textContent = enabling ? 'Enable Account' : __t('Disable Account');
    document.getElementById('confirm-subtitle').textContent = name;
    document.getElementById('confirm-body').textContent = enabling
        ? name + ' will be able to log in again.'
        : name + __t(' will no longer be able to log in. Their data is kept.');
    var btn = document.getElementById('confirm-btn');
    btn.textContent = enabling ? 'Enable' : __t('Disable');
    btn.style.background = enabling ? '#059669' : '#D97706';
    btn.style.color = '#fff';
    btn.style.borderColor = enabling ? '#059669' : '#D97706';
    _confirmCallback = function() {
        document.getElementById('toggle-target-id').value = id;
        document.getElementById('toggle-new-status').value = newStatus;
        document.getElementById('toggle-form').submit();
    };
    document.getElementById('confirm-modal-overlay').style.display = 'flex';
}

function submitConfirm() {
    if (_confirmCallback) _confirmCallback();
}

function closeConfirmModal() {
    document.getElementById('confirm-modal-overlay').style.display = 'none';
    _confirmCallback = null;
}

document.getElementById('confirm-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeConfirmModal();
});
</script>

<script src="../assets/js/alerts.js"></script>
<script src="../assets/js/sidebar.js?v=5"></script>
<script src="../assets/js/bulk-select.js?v=4"></script>
<script>
(function () {
  var tb = document.getElementById('staff-tbody'); if (!tb) return;
  function otpBatch(op, ids, done, t) {
    var target = BulkSelect.batchTarget(ids);
    AdminOtp.open({
      op: op, targetId: target, title: t.title + ' (' + ids.length + ')', subtitle: ids.length + ' selected',
      body: t.body, confirmLabel: t.label, danger: t.danger,
      action: { ajax_action: op, id: target, ids: ids.join(',') },
      onDone: function (d) { done(d.ok || 0, d.failed || 0, d.message); }
    });
  }
  function enableOne(id) {
    var fd = new FormData();
    fd.append('action', 'toggle_active'); fd.append('target_id', id); fd.append('new_status', 1);
    fd.append('csrf_token', CSRF_TOKEN);
    return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.ok; });
  }
  BulkSelect.init({
    rows: '#staff-tbody tr[data-bulk-id]', before: tb.closest('.table-wrap'), noun: 'staff account',
    actions: [
      { label: __t('Enable'), icon: 'ti-user-check', confirm: function (n) { return 'Enable ' + n + __t(' staff account(s)?'); }, run: enableOne },
      { label: __t('Disable'), icon: 'ti-user-off',
        batch: function (ids, done) { otpBatch('bulk_disable_staff', ids, done, { title: __t('Disable Staff Accounts'), label: __t('Disable'), danger: false, body: __t('These staff will no longer be able to log in. Their data is kept.') }); } },
      { label: __t('Delete'), icon: 'ti-trash', danger: true,
        batch: function (ids, done) { otpBatch('bulk_delete_staff', ids, done, { title: __t('Delete Staff Accounts'), label: __t('Delete Permanently'), danger: true, body: __t('These staff accounts will be permanently deleted. This cannot be undone.') }); } }
    ]
  });
})();
</script>
</body></html>
