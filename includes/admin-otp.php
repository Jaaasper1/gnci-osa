<?php
// ============================================================
// admin-otp.php: one-time code for admin confirmation.
// Used before DISABLING or DELETING a student/staff account.
// The code is emailed to the logged-in admin (osa_staff.email),
// stored hashed in the session, tied to ONE action on ONE record,
// expires in 10 minutes, allows 5 tries, and is single-use.
// ============================================================
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/password-login.php';
if (!function_exists('t')) { require_once __DIR__ . '/lang.php'; }

define('ADMIN_OTP_TTL', 600);
define('ADMIN_OTP_MAX_TRIES', 5);
define('ADMIN_OTP_COOLDOWN', 30);

function adminOtpLabel(string $op): string {
    $labels = [
        'delete_staff'    => 'Delete a staff account',
        'disable_staff'   => 'Disable a staff account',
        'delete_active'   => 'Delete a student account',
        'delete_disabled' => 'Permanently delete a disabled student account',
        'disable_student' => 'Disable a student account',
        'edit_staff'      => 'Edit a staff account',
        'edit_candidate'  => 'Edit a candidate',
        'edit_party'      => 'Edit a party list',
        'edit_election'   => 'Edit an election',
        'edit_event'      => 'Edit an event',
        'edit_student'    => 'Edit a student account',
        'bulk_delete_staff'      => 'Delete several staff accounts',
        'bulk_disable_staff'     => 'Disable several staff accounts',
        'bulk_delete_active'     => 'Delete several student accounts',
        'bulk_delete_disabled'   => 'Permanently delete several disabled student accounts',
        'bulk_disable_student'   => 'Disable several student accounts',
        'export_data'            => 'Export data',
        'export_reports'         => 'Export a report',
        'export_vouchers'        => 'Export vouchers',
        'import_students'        => 'Import students from Excel',
        'import_candidates'      => 'Import candidates from Excel',
        'import_party_lists'     => 'Import party lists from Excel',
    ];
    return $labels[$op] ?? '';
}

// ── Bulk actions: ONE code covers ONE exact list of records ─────────────────
// The code is tied to a fingerprint of the sorted id list, so a code requested for
// one selection cannot be reused for a different (or larger) selection.
// JS twin: BulkSelect.batchTarget() in assets/js/bulk-select.js (same CRC32 + 1).
function adminOtpParseIds(string $csv, int $max = 200): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)), fn($v) => $v > 0)));
    sort($ids);
    return count($ids) > $max ? [] : $ids;
}
function adminOtpBatchTarget(array $ids): int {
    return (int) (crc32(implode(',', $ids)) + 1);
}

// Creates and emails a code. Returns [bool ok, string message].
// $method: 'password' = use the admin password, 'email' = emailed code, 'auto' = password if set, else email.
function adminOtpSend(PDO $pdo, int $adminId, string $op, int $targetId, string $method = 'auto'): array {
    if (adminOtpLabel($op) === '') return [false, t('msg.unknown_action_9258', 'Unknown action.'), 'email'];

    $hasPw = adminHasOwnPassword($pdo, $adminId);
    if ($method === 'password' && !$hasPw) {
        return [false, t('msg.you_have_not_set_an_admin_password_yet_s_b2b8', 'You have not set an admin password yet. Set one in Settings, or use the email code.'), 'email'];
    }
    $usePassword = ($method === 'password') || ($method === 'auto' && $hasPw);

    if ($usePassword) {
        $_SESSION['admin_otp'] = [
            'mode'      => 'password',
            'op'        => $op,
            'target'    => $targetId,
            'admin_id'  => $adminId,
            'expires'   => time() + ADMIN_OTP_TTL,
            'tries'     => 0,
            'last_sent' => time(),
        ];
        return [true, t('msg.enter_your_admin_password_to_confirm_4993', 'Enter your admin password to confirm.'), 'password'];
    }

    $st = $pdo->prepare("SELECT first_name, email FROM osa_staff WHERE id = ? AND is_active = 1");
    $st->execute([$adminId]);
    $admin = $st->fetch();
    if (!$admin || empty($admin['email'])) {
        return [false, t('msg.this_admin_account_has_no_email_set_add__a385', 'This admin account has no email set. Add one first, then try again.'), 'email'];
    }

    $prev = $_SESSION['admin_otp'] ?? null;
    if ($prev && $prev['op'] === $op && $prev['target'] === $targetId
        && time() - $prev['last_sent'] < ADMIN_OTP_COOLDOWN) {
        return [false, t('msg.please_wait_a_few_seconds_before_request_9635', 'Please wait a few seconds before requesting another code.'), 'email'];
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    if (!sendAdminActionOtp($admin['email'], $admin['first_name'], $otp, adminOtpLabel($op))) {
        return [false, t('msg.could_not_send_the_code_please_try_again_1401', 'Could not send the code. Please try again.'), 'email'];
    }

    $_SESSION['admin_otp'] = [
        'op'        => $op,
        'target'    => $targetId,
        'admin_id'  => $adminId,
        'hash'      => hash('sha256', $otp),   // never keep the plain code
        'expires'   => time() + ADMIN_OTP_TTL,
        'tries'     => 0,
        'last_sent' => time(),
    ];
    return [true, 'A 6-digit code was sent to ' . maskEmail($admin['email']) . '.', 'email'];
}

// Checks the code for one action on one record. Single-use on success.
function adminOtpVerify(string $op, int $targetId, int $adminId, string $code): array {
    $p = $_SESSION['admin_otp'] ?? null;
    if (!$p || $p['op'] !== $op || $p['target'] !== $targetId || $p['admin_id'] !== $adminId) {
        // No code was requested first. If this admin has a password, the typed value can
        // confirm the action directly (same rate limit as password mode).
        global $pdo;
        if (!adminHasOwnPassword($pdo, $adminId)) {
            return [false, t('msg.please_request_a_confirmation_code_first_bde2', 'Please request a confirmation code first.')];
        }
        $key = 'ADM' . $adminId;
        if (($wait = pwThrottleWait($pdo, $key)) > 0) {
            return [false, sprintf(t('msg.too_many_wrong_pw', 'Too many wrong passwords. Try again in %d minute(s).'), max(1, (int)ceil($wait / 60)))];
        }
        $st = $pdo->prepare("SELECT password FROM osa_staff WHERE id = ? AND is_active = 1");
        $st->execute([$adminId]);
        $row = $st->fetch();
        if (!$row || (string)$code === '' || !password_verify((string)$code, $row['password'])) {
            pwThrottleFail($pdo, $key);
            return [false, t('msg.incorrect_admin_password_48e1', 'Incorrect admin password.')];
        }
        pwThrottleClear($pdo, $key);
        unset($_SESSION['admin_otp']);
        return [true, ''];
    }
    if (time() > $p['expires']) {
        unset($_SESSION['admin_otp']);
        return [false, t('msg.this_code_has_expired_request_a_new_one_8c07', 'This code has expired. Request a new one.')];
    }

    // Password mode: the admin's own password confirms the action (rate-limited).
    if (($p['mode'] ?? 'email') === 'password') {
        global $pdo;
        $key = 'ADM' . $adminId;
        if (($wait = pwThrottleWait($pdo, $key)) > 0) {
            return [false, sprintf(t('msg.too_many_wrong_pw', 'Too many wrong passwords. Try again in %d minute(s).'), max(1, (int)ceil($wait / 60)))];
        }
        $st = $pdo->prepare("SELECT password FROM osa_staff WHERE id = ? AND is_active = 1");
        $st->execute([$adminId]);
        $row = $st->fetch();
        if (!$row || (string)$code === '' || !password_verify((string)$code, $row['password'])) {
            pwThrottleFail($pdo, $key);
            return [false, t('msg.incorrect_admin_password_48e1', 'Incorrect admin password.')];
        }
        pwThrottleClear($pdo, $key);
        unset($_SESSION['admin_otp']);
        return [true, ''];
    }

    if ($p['tries'] >= ADMIN_OTP_MAX_TRIES) {
        unset($_SESSION['admin_otp']);
        return [false, t('msg.too_many_incorrect_attempts_request_a_ne_4e15', 'Too many incorrect attempts. Request a new code.')];
    }
    $code = trim($code);
    if ($code === '' || !hash_equals($p['hash'], hash('sha256', $code))) {
        $_SESSION['admin_otp']['tries'] = $p['tries'] + 1;
        return [false, t('msg.incorrect_code_3d33', 'Incorrect code.')];
    }
    unset($_SESSION['admin_otp']);
    return [true, ''];
}

function sendAdminActionOtp(string $toEmail, string $toName, string $otp, string $label): bool {
    $name = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $what = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $ip   = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;border:1px solid #E5E7EB;border-radius:10px;">'
          . '<h2 style="color:#6B1A2A;margin:0 0 12px;font-size:18px;">GNC-OSA Portal: Admin confirmation</h2>'
          . '<p style="color:#374151;font-size:14px;">Hi ' . $name . ', use this code to confirm the following action:</p>'
          . '<p style="color:#374151;font-size:14px;"><strong>' . $what . '</strong></p>'
          . '<div style="font-size:32px;font-weight:700;letter-spacing:8px;color:#6B1A2A;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:14px;text-align:center;margin:16px 0;">' . $otp . '</div>'
          . '<p style="color:#6B7280;font-size:12px;">Requested from IP ' . $ip . '. This code expires in 10 minutes. If you did not request this, do not share the code and check your admin account.</p>'
          . '</div>';
    try {
        $mail = createMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'GNC-OSA Portal admin code: ' . $otp;
        $mail->Body    = $html;
        $mail->AltBody = "Hi {$toName}, your code to confirm \"{$label}\" is: {$otp}. It expires in 10 minutes.";
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('GNC-OSA admin OTP mailer error for ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

// ── Approval for edits that save through a normal form or fetch ─────────────
// After the emailed code is confirmed, the page's save is allowed ONCE, within
// ADMIN_OTP_APPROVAL_TTL seconds, for that one record.
define('ADMIN_OTP_APPROVAL_TTL', 180);

function adminOtpApprove(string $op, int $targetId): void {
    $_SESSION['admin_otp_approved'][$op . ':' . $targetId] = time() + ADMIN_OTP_APPROVAL_TTL;
}

// True once (then cleared) if this op + record was just confirmed with the code.
function adminOtpConsumeApproval(string $op, int $targetId): bool {
    $key = $op . ':' . $targetId;
    $exp = $_SESSION['admin_otp_approved'][$key] ?? 0;
    unset($_SESSION['admin_otp_approved'][$key]);
    return $exp >= time();
}

// Answers the two AJAX calls from assets/js/admin-otp.js on pages that save normally:
//   request_admin_otp -> emails the code
//   otp_verify_only   -> checks the code, then approves the page's next save
// Returns false for other requests so the page can carry on.
function adminOtpHandleAjax(PDO $pdo): bool {
    $action = $_POST['ajax_action'] ?? '';
    if ($action !== 'request_admin_otp' && $action !== 'otp_verify_only') return false;

    header('Content-Type: application/json; charset=utf-8');
    if (($_SESSION['user_type'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'message' => t('msg.confirmation_codes_are_only_for_the_admi_38af', 'Confirmation codes are only for the admin account.')]);
        exit;
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => t('msg.invalid_or_expired_request_please_refres_4159', 'Invalid or expired request. Please refresh the page and try again.')]);
        exit;
    }

    $op       = trim($_POST['op'] ?? '');
    $targetId = (int)($_POST['id'] ?? 0);
    $adminId  = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'request_admin_otp') {
        $method = in_array($_POST['method'] ?? '', ['password', 'email'], true) ? $_POST['method'] : 'auto';
        [$sent, $msg, $mode] = adminOtpSend($pdo, $adminId, $op, $targetId, $method);
        echo json_encode(['success' => $sent, 'message' => $msg, 'mode' => $mode ?? 'email']);
        exit;
    }

    [$ok, $msg] = adminOtpVerify($op, $targetId, $adminId, $_POST['admin_otp'] ?? '');
    if ($ok) adminOtpApprove($op, $targetId);
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Confirmed.' : $msg]);
    exit;
}

// ── Gate for exports and imports ───────────────────────────────────────────
// The page confirms first (admin password or emailed code) and that approves ONE
// request for this op. The export or import then consumes the approval.
function adminOtpRequireGate(string $op): void {
    if (adminOtpConsumeApproval($op, 0)) return;
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars(t('auto.confirm_first_784c', 'Confirm first'), ENT_QUOTES) . '</title></head>'
       . '<body style="font-family:sans-serif;padding:24px;color:#374151">'
       . '<p>' . htmlspecialchars(t('admin_otp.gate_msg', 'This download needs confirmation first. Go back to the page, click Export again, and confirm with your admin password or an emailed code.'), ENT_QUOTES) . '</p>'
       . '<p><a href="javascript:history.back()">' . htmlspecialchars(t('auto.go_back_22b6', 'Go back'), ENT_QUOTES) . '</a></p></body></html>';
    exit;
}

function adminOtpRequireGateJson(string $op): void {
    if (adminOtpConsumeApproval($op, 0)) return;
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('msg.confirm_this_import_first_with_your_admi_c9ad', 'Confirm this import first with your admin password or an emailed code, then try again.')]);
    exit;
}
