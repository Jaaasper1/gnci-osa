<?php
// TEMPORARY mail diagnostic — DELETE THIS FILE after use.
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireStaff();
if (($_SESSION['user_type'] ?? '') !== 'admin') { http_response_code(403); exit('Admin only'); }
require_once '../includes/mailer.php';
header('Content-Type: text/plain; charset=utf-8');
echo "mailer.php version supports MAILER_HOST: ", (strpos(file_get_contents('../includes/mailer.php'), 'MAILER_HOST') !== false ? 'YES' : 'NO (old file uploaded!)'), "\n";
echo "MAILER_HOST: ", defined('MAILER_HOST') ? MAILER_HOST : '(not set -> gmail)', "\n";
echo "MAILER_PORT: ", defined('MAILER_PORT') ? MAILER_PORT : '(not set)', "\n";
echo "MAILER_ENCRYPTION: ", defined('MAILER_ENCRYPTION') ? MAILER_ENCRYPTION : '(not set)', "\n";
echo "Username: ", MAILER_FROM_EMAIL, "\n";
echo "Password length: ", strlen(MAILER_APP_PASSWORD), "\n\n";
try {
    $m = createMailer();
    $m->SMTPDebug = 2; $m->Debugoutput = function($s){ echo $s; };
    echo "Host used: {$m->Host}:{$m->Port} ({$m->SMTPSecure})\n\n";
    echo $m->smtpConnect() ? "\nLOGIN OK\n" : "\nLOGIN FAILED\n";
} catch (Throwable $e) { echo "ERROR: ", $e->getMessage(), "\n"; }
