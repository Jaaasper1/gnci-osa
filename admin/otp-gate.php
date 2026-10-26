<?php
// Answers the confirmation requests for exports and imports (assets/js/admin-otp.js, AdminOtp.gate).
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/admin-otp.php';
if (!function_exists('t')) { require_once __DIR__ . '/../includes/lang.php'; }
requireAdmin();
header('Content-Type: application/json; charset=utf-8');
if (!adminOtpHandleAjax($pdo)) {
    echo json_encode(['success' => false, 'message' => t('msg.unknown_request_97eb', 'Unknown request.')]);
}
