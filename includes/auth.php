<?php

// ============================================================
// LOGIN OTP THROTTLE (stops someone from spamming a student's
// inbox by repeatedly submitting their Student ID).
// Stored in the database, so clearing cookies / opening a new
// browser does NOT reset it.
//   - per Student ID : 1 code / 30 seconds, max 5 codes / hour
//   - per IP address : max 60 codes / hour (high on purpose, since a
//                      whole campus may share one Wi-Fi/public IP)
// If the database check itself fails, login still works (fails open).
// ============================================================
define('OTP_COOLDOWN_SECONDS', 30);
define('OTP_MAX_PER_STUDENT_HOUR', 5);
define('OTP_MAX_PER_IP_HOUR', 60);

function otpThrottleEnsureTable($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `login_otp_requests` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `student_id` varchar(20) NOT NULL,
        `ip` varchar(45) NOT NULL,
        `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_student_time` (`student_id`,`requested_at`),
        KEY `idx_ip_time` (`ip`,`requested_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Returns 0 if a code may be sent now, otherwise the number of seconds to wait.
function otpThrottleWait($pdo, $studentId) {
    try {
        otpThrottleEnsureTable($pdo);
        $ip  = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $now = (int)$pdo->query("SELECT UNIX_TIMESTAMP()")->fetchColumn();

        $st = $pdo->prepare("SELECT UNIX_TIMESTAMP(requested_at) FROM login_otp_requests
                             WHERE student_id = ? AND requested_at > (NOW() - INTERVAL 1 HOUR)
                             ORDER BY requested_at DESC");
        $st->execute([$studentId]);
        $times = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $wait = 0;
        if ($times) {                                   // 30-second cooldown
            $wait = max($wait, OTP_COOLDOWN_SECONDS - ($now - $times[0]));
        }
        if (count($times) >= OTP_MAX_PER_STUDENT_HOUR) { // hourly cap: wait until the oldest counted one expires
            $oldestCounted = $times[OTP_MAX_PER_STUDENT_HOUR - 1];
            $wait = max($wait, $oldestCounted + 3600 - $now);
        }

        if ($ip !== '') {
            $ic = $pdo->prepare("SELECT COUNT(*) FROM login_otp_requests
                                 WHERE ip = ? AND requested_at > (NOW() - INTERVAL 1 HOUR)");
            $ic->execute([$ip]);
            if ((int)$ic->fetchColumn() >= OTP_MAX_PER_IP_HOUR) $wait = max($wait, 600);
        }
        return max(0, (int)$wait);
    } catch (Exception $e) {
        return 0;
    }
}

// Call right before actually sending a code.
function otpThrottleRecord($pdo, $studentId) {
    try {
        otpThrottleEnsureTable($pdo);
        $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $pdo->prepare("INSERT INTO login_otp_requests (student_id, ip) VALUES (?, ?)")->execute([$studentId, $ip]);
        if (random_int(1, 50) === 1) { // occasional cleanup of old rows
            $pdo->exec("DELETE FROM login_otp_requests WHERE requested_at < (NOW() - INTERVAL 1 DAY)");
        }
    } catch (Exception $e) { /* never block login because of logging */ }
}

// Friendly "wait X" text.
function otpWaitMessage($seconds) {
    if ($seconds >= 90) return 'Too many code requests. Please try again in about ' . ceil($seconds / 60) . ' minutes.';
    return 'Please wait ' . max(1, (int)$seconds) . ' second' . ($seconds == 1 ? '' : 's') . ' before requesting another code.';
}


// Hide most of an email address (e.g. ja***@g***.com) so the login OTP screen
// doesn't reveal a student's full email to whoever typed their Student ID.
// Uses a fixed number of asterisks so the real length isn't leaked either.
if (!function_exists('maskEmail')) {
    function maskEmail($email) {
        $email = trim((string)$email);
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) return '***';
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $keep   = strlen($local) >= 4 ? 2 : 1;
        $lm     = substr($local, 0, $keep) . '***';
        $dot    = strrpos($domain, '.');
        if ($dot === false || $dot < 1) {
            $dm = substr($domain, 0, 1) . '***';
        } else {
            $dm = substr($domain, 0, 1) . '***' . substr($domain, $dot);
        }
        return $lm . '@' . $dm;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Automatically detect the app's base URL path, so redirects work whether
// the app is deployed at the domain root (InfinityFree) or in a subfolder
// like /gnc-osa-portal/ (typical XAMPP/localhost setup).
if (!defined('APP_BASE')) {
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $appRoot = str_replace('\\', '/', rtrim(dirname(__DIR__), '/'));
    $base = '';
    if ($docRoot && strpos($appRoot, $docRoot) === 0) {
        $base = substr($appRoot, strlen($docRoot));
    }
    define('APP_BASE', $base);
}

function _loginRedirect() {
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

function loginStudent($pdo, $student_id, $password) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? AND is_active = 1");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    if ($student && password_verify($password, $student['password'])) {
        $_SESSION['user_id']              = $student['id'];
        $_SESSION['user_type']            = 'student';
        $_SESSION['name']                 = $student['first_name'] . ' ' . $student['last_name'];
        $_SESSION['student_id']           = $student['student_id'];
        $_SESSION['must_change_password'] = (bool)($student['must_change_password'] ?? 0);
        return true;
    }
    return false;
}

// Checks a Student ID exists (and is active) WITHOUT starting the session —
// used by the passwordless login-OTP flow, which only finalizes the session
// after the code is verified.
function findActiveStudentById($pdo, $student_id) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ? AND is_active = 1");
    $stmt->execute([$student_id]);
    return $stmt->fetch() ?: false;
}

// Starts the logged-in session for an already-verified student row.
function finalizeStudentSession($student) {
    $_SESSION['user_id']              = $student['id'];
    $_SESSION['user_type']            = 'student';
    $_SESSION['name']                 = $student['first_name'] . ' ' . $student['last_name'];
    $_SESSION['student_id']           = $student['student_id'];
    $_SESSION['must_change_password'] = (bool)($student['must_change_password'] ?? 0);
}

function loginStaff($pdo, $username, $password) {
    $stmt = $pdo->prepare("SELECT * FROM osa_staff WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $staff = $stmt->fetch();
    if ($staff && password_verify($password, $staff['password'])) {
        $_SESSION['user_id']   = $staff['id'];
        $_SESSION['user_type'] = $staff['role'];
        $_SESSION['name']      = $staff['first_name'] . ' ' . $staff['last_name'];
        $_SESSION['username']  = $staff['username'];
        return true;
    }
    return false;
}

function requireStudent() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
        _loginRedirect();
    }
}

function requireStaff() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['staff', 'admin'])) {
        _loginRedirect();
    }
}

function requireAdmin() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        _loginRedirect();
    }
}

// Re-checks the currently logged-in admin's password (osa_staff.password).
// Used to gate destructive actions like permanently deleting a student account.
function verifyAdminPassword($pdo, $adminId, $password) {
    if ($password === '' || $password === null || !$adminId) return false;
    $stmt = $pdo->prepare("SELECT password FROM osa_staff WHERE id = ? AND role = 'admin'");
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();
    return $row && password_verify($password, $row['password']);
}

function logout() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_destroy();
    _loginRedirect();
}

function isLoggedIn() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['user_id']);
}