<?php
// ============================================
// SETUP PAGE — Fix default passwords
// DELETE THIS FILE AFTER SETUP!
// ============================================
require_once 'includes/db.php';

// ── One-time lock ────────────────────────────────────────────────────────
// Once this page is successfully used, it writes a lock file next to it
// and refuses to run again — even if someone forgets to delete setup.php
// from the server. Delete includes/../setup.lock manually if you really
// need to re-run this (not recommended on a live server).
$lockFile = __DIR__ . '/setup.lock';
$locked = file_exists($lockFile);

$done = [];
$errors = [];

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_pass   = $_POST['admin_pass'] ?? 'admin123';
    $student_pass = $_POST['student_pass'] ?? 'student123';

    // Update admin password
    $hash_admin = password_hash($admin_pass, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE osa_staff SET password = ? WHERE username = 'osa_admin'");
    if ($stmt->execute([$hash_admin])) {
        $done[] = "Admin password updated to: <strong>" . htmlspecialchars($admin_pass) . "</strong>";
    } else {
        $errors[] = "Could not update the admin password.";
    }

    // Update student password
    $hash_student = password_hash($student_pass, PASSWORD_DEFAULT);
    $stmt2 = $pdo->prepare("UPDATE students SET password = ? WHERE student_id = '2024-00001'");
    if ($stmt2->execute([$hash_student])) {
        $done[] = "Student password updated to: <strong>" . htmlspecialchars($student_pass) . "</strong>";
    } else {
        $errors[] = "Could not update the student password.";
    }

    if (empty($errors)) {
        // Write the lock so this page can never run its logic again.
        file_put_contents($lockFile, 'Setup completed at ' . date('c') . "\n");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GNC-OSA Setup</title>
    <link rel="stylesheet" href="assets/css/style.css?v=27">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
    <style>
        body { background: #4A0F1B; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .box { background: #fff; border-radius: 14px; padding: 32px; max-width: 460px; width: 100%; box-shadow: 0 8px 32px rgba(0,0,0,0.3); }
        h2 { color: #4A0F1B; margin-bottom: 6px; }
        .warning { background: #FEF3C7; border: 1px solid #F59E0B; border-radius: 8px; padding: 12px; font-size: 13px; color: #92400E; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="box">
    <h2><i class="ti ti-settings" style="vertical-align:-3px;margin-right:6px;color:#6B1A2A;"></i>GNC-OSA Setup</h2>
    <p style="font-size:13px;color:#9CA3AF;margin-bottom:16px;">Set the default passwords for the test accounts.</p>

    <?php if ($locked): ?>
        <div class="alert alert-success" style="margin-bottom:14px;">
            <i class="ti ti-lock"></i>
            <div>Setup was already completed before. This page is now locked and will not run again — for security, please delete <code>setup.php</code> and <code>setup.lock</code> from the server.</div>
        </div>
        <div style="text-align:center;margin-top:16px;">
            <a href="index.php" class="btn btn-primary"><i class="ti ti-login"></i> Pumunta sa Login</a>
        </div>
    <?php elseif (!empty($done)): ?>
        <div class="alert alert-success" style="margin-bottom:14px;">
            <i class="ti ti-check"></i>
            <div><?php foreach ($done as $d) echo "<div>✓ $d</div>"; ?></div>
        </div>
        <div style="text-align:center;margin-top:16px;">
            <a href="index.php" class="btn btn-primary"><i class="ti ti-login"></i> Pumunta sa Login</a>
        </div>
        <div style="margin-top:16px;padding:10px;background:#FEE2E2;border-radius:8px;font-size:12px;color:#991B1B;text-align:center;">
            ⚠️ <strong>Delete setup.php</strong> after logging in, for security!
        </div>
    <?php else: ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><i class="ti ti-alert-circle"></i><?= implode('<br>', $errors) ?></div>
        <?php endif; ?>

        <div class="warning">
            <i class="ti ti-alert-triangle" style="margin-right:6px;"></i>
            <strong>This is for testing only.</strong> Change the passwords afterwards.
        </div>

        <form method="POST">
            <div class="form-group">
                <label>Admin Password <small style="color:#9CA3AF;">(username: osa_admin)</small></label>
                <input type="text" name="admin_pass" class="form-control" value="admin123">
            </div>
            <div class="form-group">
                <label>Student Password <small style="color:#9CA3AF;">(ID: 2024-00001)</small></label>
                <input type="text" name="student_pass" class="form-control" value="student123">
            </div>
            <button type="submit" class="btn btn-primary btn-block">
                <i class="ti ti-device-floppy"></i> Apply Passwords
            </button>
        </form>
    <?php endif; ?>
</div>
<script src="assets/js/sidebar.js?v=5"></script>
</body>
</html>
