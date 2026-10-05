<?php
require_once __DIR__ . '/includes/lang.php';

$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, GNC_SUPPORTED_LANGS, true)) {
    $lang = 'en';
}

// Cookie lasts 1 year, available site-wide.
setcookie(GNC_LANG_COOKIE, $lang, [
    'expires'  => time() + (365 * 24 * 60 * 60),
    'path'     => '/',
    'samesite' => 'Lax',
]);
$_SESSION['lang'] = $lang;

$return = $_GET['return'] ?? '';
if ($return === '' || strpos($return, '//') === 0 || preg_match('#^https?://#i', $return)) {
    // Prevent open-redirect; fall back to home if the return URL looks external.
    $return = '/';
}

header('Location: ' . $return);
exit;
