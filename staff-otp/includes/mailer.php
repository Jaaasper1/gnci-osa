<?php
/**
 * mailer.php — Gmail SMTP configuration for GNC-OSA Portal
 *
 * SETUP INSTRUCTIONS:
 * 1. Go to your Google Account → Security → 2-Step Verification (enable it first)
 * 2. Then go to: https://myaccount.google.com/apppasswords
 * 3. Create an App Password for "Mail" → copy the 16-character password
 * 4. Fill in MAILER_FROM_EMAIL and MAILER_APP_PASSWORD below
 */

// ── Gmail credentials ──────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';   // MAILER_* values live in config.local.php
// ──────────────────────────────────────────────────────────────────────────

// Manual load — Composer is not required
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Creates and returns a configured PHPMailer instance ready for Gmail SMTP.
 */
function createMailer(): PHPMailer {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = MAILER_FROM_EMAIL;
    $mail->Password   = MAILER_APP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->Timeout    = 10;   // fail fast if SMTP is unreachable (default is 300s and freezes the page)

    // ── Fix SSL certificate error sa XAMPP ──
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ];

    $mail->setFrom(MAILER_FROM_EMAIL, MAILER_FROM_NAME);
    $mail->CharSet = 'UTF-8';

    return $mail;
}
/**
 * Generates a 6-digit numeric OTP code.
 */
function generateOtpCode(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Sends the registration/login OTP code to the given email via Gmail SMTP.
 * Works for any recipient email address (no domain restriction, unlike
 * MailerSend's sandbox mode).
 *
 * $purpose = 'register' (default) -> "Email Verification" email
 * $purpose = 'login'              -> "Login Verification" email that also shows
 *                                    when and from which IP the login was requested
 */
function sendRegistrationOtp(string $toEmail, string $toName, string $otp, string $purpose = 'register'): bool {
    $isLogin = ($purpose === 'login');

    if ($isLogin) {
        $subject     = "Your GNC-OSA Portal login code: {$otp}";
        $headerLabel = 'Login Verification';
        $introText   = "Hi {$toName}, use this code to log in to your GNC-OSA Portal account:";
        $reqTime     = date('F j, Y \\a\\t g:i A');
        $reqIp       = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
        $detailsHtml = '<p style="color:#374151; font-size:13px; margin:0 0 16px;">Login requested on <strong>' . $reqTime . '</strong> from IP <strong>' . $reqIp . '</strong>.</p>';
        $footerText  = "This code expires in 10 minutes. If you didn't try to log in, you can ignore this email and do not share this code with anyone.";
        $altText     = "Hi {$toName}, your GNC-OSA Portal login code is: {$otp}\nLogin requested on {$reqTime} from IP " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . ".\nThis code expires in 10 minutes. If you didn't try to log in, ignore this email.";
    } else {
        $subject     = "Your GNC-OSA Portal verification code: {$otp}";
        $headerLabel = 'Email Verification';
        $introText   = "Hi {$toName}, use this code to verify your email:";
        $detailsHtml = '';
        $footerText  = "This code expires in 10 minutes. If you didn't request this, you can ignore this email.";
        $altText     = "Hi {$toName}, your GNC-OSA Portal verification code is: {$otp}\nThis code expires in 10 minutes.";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background:#f4f6f8; margin:0; padding:0;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding: 30px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:#6B1A2A; padding:24px 32px; text-align:center;">
            <h1 style="color:#ffffff; margin:0; font-size:20px;">GNC-OSA Portal</h1>
            <p style="color:#F3D9C0; margin:6px 0 0; font-size:13px;">{$headerLabel}</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px; text-align:center;">
            <p style="color:#374151; font-size:14px; margin:0 0 20px;">{$introText}</p>
            <div style="font-size:36px; font-weight:700; letter-spacing:8px; color:#6B1A2A; background:#F9FAFB; border:1px solid #E5E7EB; border-radius:8px; padding:16px; margin-bottom:20px;">{$otp}</div>
            {$detailsHtml}
            <p style="color:#6B7280; font-size:12px; margin:0;">{$footerText}</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    try {
        $mail = createMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $altText;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("GNC-OSA OTP mailer error for {$toEmail}: " . $e->getMessage());
        return false;
    }
}

/**
 * Security notice emailed to a staff/admin after a successful login, so the
 * owner can spot a login that wasn't them.
 */
function sendStaffLoginNotice(string $toEmail, string $toName): bool {
    $time = date('F j, Y \\a\\t g:i A');
    $ip   = htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
    $name = htmlspecialchars($toName);
    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;border:1px solid #E5E7EB;border-radius:10px;">'
          . '<h2 style="color:#6B1A2A;margin:0 0 12px;font-size:18px;">GNC-OSA Portal: new login</h2>'
          . '<p style="color:#374151;font-size:14px;">Hi ' . $name . ', your staff/admin account was just used to log in on <strong>' . $time . '</strong> from IP <strong>' . $ip . '</strong>.</p>'
          . '<p style="color:#991B1B;font-size:13px;">If this was not you, tell the OSA office / system admin right away.</p></div>';
    try {
        $mail = createMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'GNC-OSA Portal: new staff login on your account';
        $mail->Body    = $html;
        $mail->AltBody = "Hi {$toName}, your GNC-OSA staff/admin account was used to log in on {$time} from IP " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . ". If this was not you, tell the OSA office / system admin right away.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("GNC-OSA login notice error for {$toEmail}: " . $e->getMessage());
        return false;
    }
}

/**
 * Builds a full portal URL for use inside emails.
 * Set PORTAL_URL in includes/config.local.php (e.g. 'https://osa.yourschool.edu.ph')
 * when hosting online. If not set, it is auto-detected from the current request
 * (works on localhost, but links will only open on the same computer).
 */
function portalUrl(string $path = ''): string {
    if (defined('PORTAL_URL') && PORTAL_URL !== '') {
        $base = rtrim(PORTAL_URL, '/');
    } else {
        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $root    = realpath(__DIR__ . '/..');
        $sub     = '';
        if ($docRoot && $root && strpos($root, $docRoot) === 0) {
            $sub = str_replace('\\', '/', substr($root, strlen($docRoot)));
        }
        $base = $scheme . '://' . $host . rtrim($sub, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * Bulletproof (table-based) email button + plain-link fallback.
 */
function emailButtonHtml(string $url, string $label, string $color = '#6B1A2A'): string {
    $u = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    return '<table cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 14px;"><tr>'
        . '<td style="background:' . $color . '; border-radius:8px;">'
        . '<a href="' . $u . '" style="display:inline-block; padding:12px 28px; color:#ffffff; font-size:14px; font-weight:bold; text-decoration:none; font-family:Arial,sans-serif;">' . $l . '</a>'
        . '</td></tr></table>'
        . '<p style="color:#6B7280; font-size:12px; margin:0 0 16px; text-align:center; word-break:break-all;">Or copy this link: '
        . '<a href="' . $u . '" style="color:#6B1A2A;">' . $u . '</a></p>';
}

/**
 * Public URL of the OSA Facebook page (for "contact us" links in emails).
 * Uses FB_PAGE_URL if set, otherwise builds it from FB_PAGE_ID. Returns '' if neither is set.
 */
function osaFacebookUrl(): string {
    if (defined('FB_PAGE_URL') && FB_PAGE_URL !== '') return FB_PAGE_URL;
    if (defined('FB_PAGE_ID') && FB_PAGE_ID !== '' && FB_PAGE_ID !== 'PASTE_YOUR_PAGE_ID_HERE') {
        return 'https://www.facebook.com/' . FB_PAGE_ID;
    }
    return '';
}

/**
 * Sends an "account approved" email to a newly-approved student.
 */
function sendApprovalEmail(string $toEmail, string $toName): bool {
    $subject = "Your GNC-OSA Portal account has been approved!";
    $loginUrl = portalUrl('index.php');
    $loginBtn = emailButtonHtml($loginUrl, 'Log in to the Portal', '#059669');

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background:#f4f6f8; margin:0; padding:0;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding: 30px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:#059669; padding:24px 32px; text-align:center;">
            <h1 style="color:#ffffff; margin:0; font-size:20px;">GNC-OSA Portal</h1>
            <p style="color:#D1FAE5; margin:6px 0 0; font-size:13px;">Account Approved</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px; text-align:center;">
            <div style="width:56px;height:56px;background:#D1FAE5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;line-height:56px;">
              <span style="font-size:28px;">&#10003;</span>
            </div>
            <p style="color:#374151; font-size:15px; margin:0 0 8px;">Hi {$toName},</p>
            <p style="color:#374151; font-size:14px; line-height:1.6; margin:0 0 20px;">
              Good news! Your GNC-OSA Portal account has been <strong>approved</strong> by the OSA Admin. You can now log in anytime using your Student ID — we'll email you a login code each time.
            </p>
            {$loginBtn}
            <p style="color:#6B7280; font-size:12px; margin:0;">If you weren't expecting this, please contact the OSA office.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    try {
        $mail = createMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = "Hi {$toName}, your GNC-OSA Portal account has been approved. You can now log in using your Student ID: {$loginUrl}";
        $mail->send();
        return true;
    } catch (Exception $e) {
        $GLOBALS['gnc_last_mail_error'] = $e->getMessage();
        error_log("GNC-OSA approval mailer error for {$toEmail}: " . $e->getMessage());
        return false;
    }
}

/**
 * Sends an "account rejected / registration not approved" email.
 */
function sendRejectionEmail(string $toEmail, string $toName, string $reason = ''): bool {
    $subject = "Update on your GNC-OSA Portal registration";
    $registerUrl = portalUrl('register.php');
    $fbUrl       = osaFacebookUrl();   // exactly the FB_PAGE_URL set in config.local.php
    if ($fbUrl !== '') {
        // Main action: open the OSA Facebook page. Small secondary link to register again.
        $registerBtn = emailButtonHtml($fbUrl, 'Visit OSA Facebook Page', '#1877F2')
            . '<p style="color:#6B7280; font-size:12px; margin:0 0 14px;">Want to know more about why your registration was not approved? Visit our Facebook page and send us a message.</p>'
            . '<p style="color:#6B7280; font-size:12px; margin:0 0 16px;">Want to try again? '
            . '<a href="' . htmlspecialchars($registerUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#991B1B;">Submit a new registration</a>.</p>';
    } else {
        $registerBtn = emailButtonHtml($registerUrl, 'Register Again', '#991B1B');
    }

    // Optional reason entered by the admin when rejecting
    $reasonHtml = '';
    if (trim($reason) !== '') {
        $safeReason = nl2br(htmlspecialchars(trim($reason), ENT_QUOTES, 'UTF-8'));
        $reasonHtml = '<div style="background:#FEF2F2; border-left:4px solid #991B1B; border-radius:6px; padding:12px 14px; margin:0 0 20px; text-align:left;">'
            . '<p style="margin:0 0 4px; font-size:12px; font-weight:bold; color:#991B1B;">Reason</p>'
            . '<p style="margin:0; font-size:14px; color:#374151; line-height:1.5;">' . $safeReason . '</p></div>';
    }

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background:#f4f6f8; margin:0; padding:0;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding: 30px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:#991B1B; padding:24px 32px; text-align:center;">
            <h1 style="color:#ffffff; margin:0; font-size:20px;">GNC-OSA Portal</h1>
            <p style="color:#FEE2E2; margin:6px 0 0; font-size:13px;">Registration Update</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px; text-align:center;">
            <p style="color:#374151; font-size:15px; margin:0 0 8px;">Hi {$toName},</p>
            <p style="color:#374151; font-size:14px; line-height:1.6; margin:0 0 20px;">
              Unfortunately, your GNC-OSA Portal registration request was <strong>not approved</strong> at this time.
              If you believe this is a mistake or would like more details, please visit the OSA office.
            </p>
            {$reasonHtml}
            {$registerBtn}
            <p style="color:#6B7280; font-size:12px; margin:0;">You're welcome to submit a new registration once any concerns are resolved.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    try {
        $mail = createMailer();
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = "Hi {$toName}, your GNC-OSA Portal registration was not approved."
            . (trim($reason) !== '' ? " Reason: " . trim($reason) . "." : '')
            . " Please visit the OSA office for details."
            . ($fbUrl !== '' ? " Visit the OSA Facebook page to ask why: {$fbUrl}." : '')
            . " Register again: {$registerUrl}";
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        $GLOBALS['gnc_last_mail_error'] = $e->getMessage();
        error_log("GNC-OSA rejection mailer error for {$toEmail}: " . $e->getMessage());
        return false;
    }
}

/**
 * Generic notification email — used by addNotification() (student)
 * and addStaffNotification() (OSA staff) in functions.php, so every time
 * a new in-app notification is created, an email is also sent to Gmail.
 * Fails silently (returns false + error_log) because the calling
 * page should not crash just for calling addNotification().
 */
function sendNotificationEmail(string $toEmail, string $toName, string $message, string $loginUrl = ''): bool {
    if ($loginUrl === '') $loginUrl = portalUrl('index.php');   // default: student login
    $loginBtn = emailButtonHtml($loginUrl, 'Log in to the Portal');
    $subject = "GNC-OSA Portal: You have a new notification";
    $safeMsg = htmlspecialchars($message);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background:#f4f6f8; margin:0; padding:0;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding: 30px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:#6B1A2A; padding:24px 32px; text-align:center;">
            <h1 style="color:#ffffff; margin:0; font-size:20px;">GNC-OSA Portal</h1>
            <p style="color:#F3D9C0; margin:6px 0 0; font-size:13px;">New Notification</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <p style="color:#374151; font-size:15px; margin:0 0 8px;">Hi {$toName},</p>
            <p style="color:#374151; font-size:14px; line-height:1.6; margin:0 0 20px;">{$safeMsg}</p>
            {$loginBtn}
            <p style="color:#6B7280; font-size:12px; margin:0;">Log in to the GNC-OSA Portal to see the details.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    // If SMTP failed in the last 2 minutes (no internet / Gmail blocked), skip instead of making
    // every approve/assign/notify click wait for the timeout again. (OTP emails are NOT affected.)
    $failFlag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gnc_smtp_fail.flag';
    if (is_file($failFlag) && (time() - filemtime($failFlag)) < 120) {
        return false;
    }

    try {
        $mail = createMailer();
        $mail->Timeout = 6;
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = "Hi {$toName},\n\n{$message}\n\nLog in to the GNC-OSA Portal to see the details: {$loginUrl}";
        $mail->send();
        @unlink($failFlag);
        return true;
    } catch (Exception $e) {
        @touch($failFlag);
        $GLOBALS['gnc_last_mail_error'] = $e->getMessage();
        error_log("GNC-OSA notification mailer error for {$toEmail}: " . $e->getMessage());
        return false;
    }
}

/**
 * Send a new complaint notification to all admins and OSA staff with an email.
 */
function sendComplaintNotification(PDO $pdo, array $complaint, int $complaintId): bool {
    $stmt = $pdo->query(
        "SELECT first_name, last_name, email, role
         FROM osa_staff
         WHERE is_active = 1 AND email IS NOT NULL AND email != ''"
    );
    $recipients = $stmt->fetchAll();

    if (empty($recipients)) {
        return false;
    }

    $title     = htmlspecialchars($complaint['title']);
    $category  = htmlspecialchars($complaint['category']);
    $submitter = $complaint['is_anonymous']
        ? '<em>Anonymous</em>'
        : htmlspecialchars($complaint['student_name']);

    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script  = $_SERVER['SCRIPT_NAME'] ?? '/gnc-osa-portal/student/new-complaint.php';
    $base    = '/' . explode('/', ltrim($script, '/'))[0];
    $viewUrl = $scheme . '://' . $host . $base . '/admin/complaints.php';
    $dateNow = date('F j, Y · g:i A');

    $body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; background:#f4f6f8; margin:0; padding:0;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8; padding: 30px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <tr>
          <td style="background:#1a56db; padding:28px 32px; text-align:center;">
            <h1 style="color:#ffffff; margin:0; font-size:22px;">🔔 New Complaint Submitted</h1>
            <p style="color:#bfdbfe; margin:6px 0 0; font-size:14px;">GNC-OSA Portal Notification</p>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <p style="color:#374151; font-size:15px; margin:0 0 20px;">A new complaint has been submitted on the portal. Please review it as soon as possible.</p>
            <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:24px;">
              <tr><td style="padding:20px 24px;">
                <table width="100%" cellpadding="6" cellspacing="0">
                  <tr>
                    <td style="color:#6b7280; font-size:13px; width:130px; font-weight:600;">Complaint #</td>
                    <td style="color:#111827; font-size:13px;">{$complaintId}</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7280; font-size:13px; font-weight:600;">Title</td>
                    <td style="color:#111827; font-size:14px; font-weight:700;">{$title}</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7280; font-size:13px; font-weight:600;">Category</td>
                    <td style="color:#111827; font-size:13px;">{$category}</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7280; font-size:13px; font-weight:600;">Submitted by</td>
                    <td style="color:#111827; font-size:13px;">{$submitter}</td>
                  </tr>
                  <tr>
                    <td style="color:#6b7280; font-size:13px; font-weight:600;">Date & Time</td>
                    <td style="color:#111827; font-size:13px;">{$dateNow}</td>
                  </tr>
                </table>
              </td></tr>
            </table>
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr><td align="center">
                <a href="{$viewUrl}" style="display:inline-block; background:#1a56db; color:#ffffff; text-decoration:none; padding:13px 32px; border-radius:8px; font-size:15px; font-weight:600;">
                  View Complaint in Portal →
                </a>
              </td></tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="background:#f9fafb; border-top:1px solid #e5e7eb; padding:16px 32px; text-align:center;">
            <p style="color:#9ca3af; font-size:12px; margin:0;">
              This is an automated notification from the GNC-OSA Portal.<br>
              Please do not reply to this email.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $atLeastOne = false;
    foreach ($recipients as $staff) {
        try {
            $mail = createMailer();
            $mail->addAddress($staff['email'], $staff['first_name'] . ' ' . $staff['last_name']);
            $mail->isHTML(true);
            $mail->Subject = "🔔 New Complaint: {$complaint['title']} — GNC-OSA Portal";
            $mail->Body    = $body;
            $mail->AltBody = "New complaint submitted.\n\nTitle: {$complaint['title']}\nCategory: {$complaint['category']}\nSubmitted by: " . ($complaint['is_anonymous'] ? 'Anonymous' : $complaint['student_name']) . "\n\nView at: {$viewUrl}";
            $mail->send();
            $atLeastOne = true;
        } catch (Exception $e) {
            error_log("GNC-OSA Mailer error for {$staff['email']}: " . $e->getMessage());
        }
    }

    return $atLeastOne;
}