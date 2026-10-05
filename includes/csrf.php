<?php
// ============================================
// CSRF PROTECTION HELPERS
// ============================================
// Include this AFTER includes/auth.php (auth.php already starts the session).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get (or create) the CSRF token for this session.
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Print a hidden <input> for use inside <form> tags.
// Usage inside a form: echo csrfField()
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

// Append the token as a query string param for plain <a href> GET links.
// Usage: build the link as candidates.php?delete=5& followed by echo csrfParam()
function csrfParam() {
    return 'csrf_token=' . urlencode(csrfToken());
}

// Call this at the top of any handler that changes data (POST or GET-action).
// Dies with a 403 if the token is missing or wrong.
function requireCsrf() {
    $sent = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    $real = $_SESSION['csrf_token'] ?? '';
    if (!$real || !$sent || !hash_equals($real, $sent)) {
        http_response_code(403);
        die('Invalid or expired request (CSRF check failed). Please go back and try again.');
    }
}
