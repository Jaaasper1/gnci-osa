<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/lang.php';
require_once '../includes/csrf.php';

// AJAX handler — checked before requireAdmin() so a session redirect doesn't break fetch()
if (isset($_POST['ajax_action'])) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in again.']);
        exit;
    }

    $sentToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired request. Please refresh the page and try again.']);
        exit;
    }

    $ajaxAction = trim($_POST['ajax_action'] ?? '');
    $targetId   = (int)($_POST['id'] ?? 0);

    if ($ajaxAction === 'delete_staff') {
        $adminPassword = $_POST['admin_password'] ?? '';
        if (!verifyAdminPassword($pdo, $_SESSION['user_id'] ?? 0, $adminPassword)) {
            echo json_encode(['success' => false, 'message' => 'Incorrect admin password.']);
            exit;
        }
        // Only allow deleting staff accounts, never admin
        $check = $pdo->prepare("SELECT role FROM osa_staff WHERE id=?");
        $check->execute([$targetId]);
        $target = $check->fetch();
        if (!$target || $target['role'] !== 'staff') {
            echo json_encode(['success' => false, 'message' => 'Admin accounts cannot be deleted here.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM osa_staff WHERE id=? AND role='staff'");
        $stmt->execute([$targetId]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Staff account deleted.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Staff account not found.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

requireAdmin();
$notifCount = getUnreadStaffNotifCount($pdo, $_SESSION['user_id']);

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
        $reset_id   = (int)($_POST['reset_id'] ?? 0);
        $reset_pass = trim($_POST['reset_password'] ?? '');
        if ($reset_id && strlen($reset_pass) >= 6) {
            $pdo->prepare("UPDATE osa_staff SET password=? WHERE id=?")->execute([password_hash($reset_pass, PASSWORD_DEFAULT), $reset_id]);
            $success = 'Password reset successfully!';
        } else {
            $error = 'Password must be at least 6 characters.';
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        // Only allow toggling staff accounts, never admin
        $check = $pdo->prepare("SELECT role FROM osa_staff WHERE id=?");
        $check->execute([$target_id]);
        $target = $check->fetch();
        if ($target && $target['role'] === 'staff') {
            $new_status = (int)($_POST['new_status'] ?? 0);
            $pdo->prepare("UPDATE osa_staff SET is_active=? WHERE id=? AND role='staff'")->execute([$new_status, $target_id]);
            $success = $new_status ? 'Account enabled.' : 'Account disabled. Staff can no longer log in.';
        } else {
            $error = 'Admin accounts cannot be disabled here.';
        }
    } else {
        $uname = trim($_POST['username'] ?? '');
        $fname = trim($_POST['first_name'] ?? '');
        $lname = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? 'staff';
        $pass  = $_POST['password'] ?? '';
        if (!$uname || !$fname || !$lname || !$pass) { $error = 'Please fill in all required fields.'; }
        else {
            try {
                $pdo->prepare("INSERT INTO osa_staff (username,first_name,last_name,email,role,password) VALUES (?,?,?,?,?,?)")->execute([$uname,$fname,$lname,$email,$role,password_hash($pass,PASSWORD_DEFAULT)]);
                $success = 'Staff member added!';
            } catch (Exception $e) { $error = 'Duplicate username.'; }
        }
    }
}

$staffList = $pdo->query("SELECT * FROM osa_staff ORDER BY role DESC, last_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OSA Staff — GNC-OSA Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=25">
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
    <a class="sitem" href="students.php"><i class="ti ti-user-plus"></i> Students</a>
    <a class="sitem active" href="staff.php"><i class="ti ti-shield-check"></i> OSA Staff</a>
    <a class="sitem" href="reports.php"><i class="ti ti-report-analytics"></i> Reports</a>
    <div class="sidebar-section">Account</div>
    <a class="sitem" href="notifications.php"><i class="ti ti-bell"></i> Notifications
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
                <div class="card-title" style="margin-bottom:0;"><i class="ti ti-shield-check"></i> Staff List <span style="background:#7C3AED;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;margin-left:6px;"><?= count($staffList) ?></span></div>
                <button onclick="openAddModal()" class="btn btn-primary btn-sm" style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
                    <i class="ti ti-circle-plus"></i> Add Staff
                </button>
            </div>
            <div style="position:relative;min-width:220px;">
                <i class="ti ti-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:15px;"></i>
                <input type="text" id="staff-search" placeholder="Search by name or username..." 
                       class="form-control" style="padding-left:32px;margin-bottom:0;"
                       oninput="filterStaff(this.value)">
            </div>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th></th></tr></thead>
            <tbody id="staff-tbody"><?php foreach ($staffList as $st): ?>
            <tr id="staff-row-<?= $st['id'] ?>" data-search="<?= strtolower(e($st['username'].' '.$st['first_name'].' '.$st['last_name'])) ?>">
                <td><?= e($st['username']) ?></td>
                <td><?= e($st['first_name'].' '.$st['last_name']) ?></td>
                <td><span class="badge <?= $st['role']==='admin'?'badge-review':'badge-active' ?>"><?= $st['role'] ?></span></td>
                <td><?= $st['is_active'] ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-closed">Inactive</span>' ?></td>
                <td style="white-space:nowrap;">
                    <button class="btn btn-outline btn-sm" onclick="openResetModal(<?= $st['id'] ?>, '<?= e($st['first_name'].' '.$st['last_name']) ?>')" style="margin-right:4px;"><i class="ti ti-lock-open"></i> Reset Password</button>
                    <?php if ($st['role'] === 'staff'): ?>
                        <?php if ($st['is_active']): ?>
                            <button class="btn btn-sm btn-outline" onclick="confirmToggle(<?= $st['id'] ?>, '<?= e($st['first_name'].' '.$st['last_name']) ?>', 0)"
                                style="margin-right:4px;color:#D97706;border-color:#D97706;">
                                <i class="ti ti-user-off"></i> Disable
                            </button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline" onclick="confirmToggle(<?= $st['id'] ?>, '<?= e($st['first_name'].' '.$st['last_name']) ?>', 1)"
                                style="margin-right:4px;color:#059669;border-color:#059669;">
                                <i class="ti ti-user-check"></i> Enable
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline" onclick="openDeleteModal(<?= $st['id'] ?>, '<?= e($st['first_name'].' '.$st['last_name']) ?>')"
                            style="color:#991B1B;border-color:#991B1B;">
                            <i class="ti ti-trash"></i> Delete
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <div id="no-results" style="display:none;text-align:center;padding:24px;color:#9CA3AF;font-size:13px;">
            <i class="ti ti-mood-sad" style="font-size:28px;display:block;margin-bottom:6px;"></i>
            No staff found.
        </div>
    </div>
</div></div>

<!-- ===== ADD STAFF MODAL ===== -->
<div id="add-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:500px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;max-height:90vh;overflow-y:auto;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:38px;height:38px;border-radius:50%;background:#EDE9FE;display:flex;align-items:center;justify-content:center;">
                    <i class="ti ti-circle-plus" style="color:#7C3AED;font-size:18px;"></i>
                </div>
                <div>
                    <div style="font-size:16px;font-weight:700;color:#1F2937;">Add Staff Member</div>
                    <div style="font-size:12px;color:#6B7280;">Create a new OSA staff or admin account</div>
                </div>
            </div>
            <button onclick="closeAddModal()" style="background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:22px;line-height:1;padding:4px;"><i class="ti ti-x"></i></button>
        </div>

        <form method="POST" id="add-staff-form" onsubmit="return validateForm()">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div>
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">First Name <span style="color:#EF4444;">*</span></label>
                    <input type="text" name="first_name" id="modal-first-name" class="form-control" placeholder="First name" style="margin-bottom:0;" required>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Last Name <span style="color:#EF4444;">*</span></label>
                    <input type="text" name="last_name" id="modal-last-name" class="form-control" placeholder="Last name" style="margin-bottom:0;" required>
                </div>
                <div style="grid-column:1/-1;">
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Username <span style="color:#EF4444;">*</span></label>
                    <input type="text" name="username" id="modal-username" class="form-control" placeholder="e.g. osa_staff01" style="margin-bottom:0;" required autocomplete="off">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="staff@gnc.edu.ph" style="margin-bottom:0;">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Role <span style="color:#EF4444;">*</span></label>
                    <select name="role" class="form-control" style="margin-bottom:0;">
                        <option value="staff">Staff</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Password <span style="color:#EF4444;">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="password" id="modal-password" class="form-control" placeholder="Password" style="margin-bottom:0;padding-right:38px;" required autocomplete="new-password">
                        <button type="button" onclick="togglePass()" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:16px;" tabindex="-1">
                            <i class="ti ti-eye" id="pass-eye"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div id="modal-error" style="display:none;margin-top:14px;padding:10px 14px;background:#FEE2E2;color:#991B1B;border-radius:8px;font-size:13px;"></div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;">
                <button type="button" onclick="closeAddModal()" class="btn btn-outline btn-sm">Cancel</button>
                <button type="submit" class="btn btn-sm" style="background:#7C3AED;color:#fff;border-color:#7C3AED;">
                    <i class="ti ti-circle-plus"></i> Add Staff
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
    var pass  = document.getElementById('modal-password').value;
    if (!fname || !lname || !uname || !pass) {
        errEl.textContent = 'Please fill in all required fields.';
        errEl.style.display = 'block';
        return false;
    }
    if (pass.length < 6) {
        errEl.textContent = 'Password must be at least 6 characters.';
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

<!-- Reset Password Modal -->
<div id="reset-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:420px;box-shadow:0 8px 32px rgba(0,0,0,0.18);margin:16px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
            <div style="width:38px;height:38px;border-radius:50%;background:#FEF3C7;display:flex;align-items:center;justify-content:center;">
                <i class="ti ti-lock-open" style="color:#D97706;font-size:18px;"></i>
            </div>
            <div>
                <div style="font-size:15px;font-weight:700;color:#1F2937;">Reset Password</div>
                <div id="reset-staff-name" style="font-size:12px;color:#6B7280;"></div>
            </div>
        </div>
        <form method="POST" onsubmit="return validateResetForm()">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="reset_id" id="reset-id-input">
            <div class="form-group">
                <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">New Password <span style="color:#EF4444;">*</span></label>
                <div style="position:relative;">
                    <input type="password" name="reset_password" id="reset-pass-input" class="form-control" placeholder="Min. 6 characters" style="margin-bottom:0;padding-right:38px;" autocomplete="new-password">
                    <button type="button" onclick="toggleResetPass()" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9CA3AF;font-size:16px;" tabindex="-1">
                        <i class="ti ti-eye" id="reset-pass-eye"></i>
                    </button>
                </div>
            </div>
            <div id="reset-error" style="display:none;margin-top:10px;padding:10px 14px;background:#FEE2E2;color:#991B1B;border-radius:8px;font-size:13px;"></div>
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
                <button type="button" onclick="closeResetModal()" class="btn btn-outline btn-sm">Cancel</button>
                <button type="submit" class="btn btn-sm" style="background:#D97706;color:#fff;border-color:#D97706;">
                    <i class="ti ti-lock-open"></i> Reset Password
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(id, name) {
    document.getElementById('reset-id-input').value = id;
    document.getElementById('reset-staff-name').textContent = name;
    document.getElementById('reset-pass-input').value = '';
    document.getElementById('reset-error').style.display = 'none';
    document.getElementById('reset-modal-overlay').style.display = 'flex';
    setTimeout(function(){ document.getElementById('reset-pass-input').focus(); }, 80);
}
function closeResetModal() {
    document.getElementById('reset-modal-overlay').style.display = 'none';
}
function toggleResetPass() {
    var inp = document.getElementById('reset-pass-input');
    var eye = document.getElementById('reset-pass-eye');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    eye.className = inp.type === 'password' ? 'ti ti-eye' : 'ti ti-eye-off';
}
function validateResetForm() {
    var pass = document.getElementById('reset-pass-input').value.trim();
    var errEl = document.getElementById('reset-error');
    if (pass.length < 6) {
        errEl.textContent = 'Password must be at least 6 characters.';
        errEl.style.display = 'block';
        return false;
    }
    errEl.style.display = 'none';
    return true;
}
document.getElementById('reset-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeResetModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeResetModal();
});
</script>
<!-- Hidden form: toggle active -->
<form id="toggle-form" method="POST" style="display:none;">
    <input type="hidden" name="action" value="toggle_active">
    <input type="hidden" name="target_id" id="toggle-target-id">
    <input type="hidden" name="new_status" id="toggle-new-status">
</form>

<!-- Delete Staff Confirmation Modal (requires admin password) -->
<div id="delete-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9600;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:420px;box-shadow:0 8px 32px rgba(0,0,0,0.18);">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <div style="width:42px;height:42px;border-radius:50%;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="ti ti-trash" style="color:#991B1B;font-size:20px;"></i>
            </div>
            <div>
                <div style="font-size:15px;font-weight:600;color:#1F2937;">Delete Staff Account</div>
                <div id="delete-modal-name" style="font-size:13px;color:#6B7280;margin-top:2px;"></div>
            </div>
        </div>
        <div style="font-size:13px;color:#374151;background:#F9FAFB;border-radius:8px;padding:12px 14px;margin-bottom:16px;">
            This will permanently delete this staff account. This cannot be undone.
        </div>
        <label style="font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:5px;">Enter your admin password to confirm</label>
        <input type="password" id="delete-modal-password" class="form-control" placeholder="Admin password" style="margin-bottom:6px;" autocomplete="current-password">
        <div id="delete-modal-error" style="display:none;color:#991B1B;font-size:12px;margin-bottom:4px;"></div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
            <button onclick="closeDeleteModal()" class="btn btn-outline btn-sm">Cancel</button>
            <button id="delete-modal-confirm-btn" onclick="submitDeleteModal()" class="btn btn-sm btn-danger"><i class="ti ti-trash"></i> Delete Permanently</button>
        </div>
    </div>
</div>

<script>
var CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
var _deleteTarget = null; // { id, row }

function openDeleteModal(id, name) {
    _deleteTarget = { id: id, row: document.getElementById('staff-row-' + id) };
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
    fd.append('ajax_action', 'delete_staff');
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
                    setTimeout(function() { target.row.remove(); }, 400);
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
            <button onclick="closeConfirmModal()" class="btn btn-outline btn-sm">Cancel</button>
            <button id="confirm-btn" class="btn btn-sm" onclick="submitConfirm()"></button>
        </div>
    </div>
</div>

<script>
var _confirmCallback = null;

function confirmToggle(id, name, newStatus) {
    var enabling = newStatus === 1;
    document.getElementById('confirm-icon-wrap').style.background = enabling ? '#D1FAE5' : '#FEF3C7';
    document.getElementById('confirm-icon').className = enabling ? 'ti ti-user-check' : 'ti ti-user-off';
    document.getElementById('confirm-icon').style.color = enabling ? '#059669' : '#D97706';
    document.getElementById('confirm-title').textContent = enabling ? 'Enable Account' : 'Disable Account';
    document.getElementById('confirm-subtitle').textContent = name;
    document.getElementById('confirm-body').textContent = enabling
        ? name + ' will be able to log in again.'
        : name + ' will no longer be able to log in. Their data is kept.';
    var btn = document.getElementById('confirm-btn');
    btn.textContent = enabling ? 'Enable' : 'Disable';
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
<?php include __DIR__.'/../includes/push-widget.php'; ?>
</body></html>
