<?php
// Loads secrets from config.local.php (kept out of git). Safe to require many times.
if (!defined('DB_HOST')) {
    $__cfg = __DIR__ . '/config.local.php';
    if (!is_file($__cfg)) {
        http_response_code(500);
        die('Server is not configured yet. Copy includes/config.example.php to includes/config.local.php and fill it in.');
    }
    require_once $__cfg;
    unset($__cfg);
}
