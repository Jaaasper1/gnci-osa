<?php
// Serves the JavaScript translation table for the current language (cookie gnc_lang).
// Included from includes/pwa-head.php as: i18n-js.php?l=<lang>  (the ?l= busts caches when the language changes)
require_once __DIR__ . '/includes/lang.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: private, no-cache');
header('Vary: Cookie');
$d = ($GLOBALS['__CURRENT_LANG'] === 'tl' && is_file(__DIR__ . '/lang/js-tl.php')) ? require __DIR__ . '/lang/js-tl.php' : [];
echo 'window.GNC_I18N=' . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
echo 'window.__t=function(s){var d=window.GNC_I18N;return(d&&Object.prototype.hasOwnProperty.call(d,s))?d[s]:s;};';
