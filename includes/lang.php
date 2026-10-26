<?php
/**
 * Simple language/translation system.
 *
 * Usage on any page (after auth.php has started the session):
 *   require_once __DIR__ . '/../includes/lang.php';
 *   <h1><?= t('dashboard.title', 'Dashboard') ?></h1>
 *
 * How it works:
 *   - t($key, $englishDefault) is called with the ENGLISH text as the
 *     second argument, so the page always works even if a key is
 *     missing from the Tagalog file.
 *   - If the current language is English, t() just returns $englishDefault
 *     immediately (fast, no lookup needed, no need to maintain an en.php).
 *   - If the current language is Tagalog, t() looks up $key in
 *     lang/tl.php. If found, returns the Tagalog translation.
 *     If not found, falls back to $englishDefault so nothing ever
 *     shows a blank or a raw key.
 *
 * The chosen language is remembered in a COOKIE ('gnc_lang') for 1 year,
 * so it persists across logins/logouts/browser restarts. It is also
 * mirrored into $_SESSION for convenience within a single session.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('GNC_LANG_COOKIE', 'gnc_lang');
define('GNC_SUPPORTED_LANGS', ['en', 'tl']);

function getCurrentLang() {
    if (isset($_COOKIE[GNC_LANG_COOKIE]) && in_array($_COOKIE[GNC_LANG_COOKIE], GNC_SUPPORTED_LANGS, true)) {
        $_SESSION['lang'] = $_COOKIE[GNC_LANG_COOKIE];
        return $_COOKIE[GNC_LANG_COOKIE];
    }
    if (isset($_SESSION['lang']) && in_array($_SESSION['lang'], GNC_SUPPORTED_LANGS, true)) {
        return $_SESSION['lang'];
    }
    return 'en';
}

$GLOBALS['__CURRENT_LANG'] = getCurrentLang();

$GLOBALS['__TL_STRINGS'] = [];
if ($GLOBALS['__CURRENT_LANG'] === 'tl') {
    $tlFile = __DIR__ . '/../lang/tl.php';
    if (file_exists($tlFile)) {
        $GLOBALS['__TL_STRINGS'] = require $tlFile;
    }
}

/**
 * Translate a string.
 *
 * @param string $key             Unique key for this string, e.g. 'nav.dashboard'
 * @param string $englishDefault  The English text (also used as fallback)
 * @return string
 */
function t($key, $englishDefault = '') {
    if ($GLOBALS['__CURRENT_LANG'] === 'tl' && isset($GLOBALS['__TL_STRINGS'][$key])) {
        return $GLOBALS['__TL_STRINGS'][$key];
    }
    return $englishDefault;
}

/**
 * tx('English text') / tx('Ends %d days ago', 3)
 * Looks the ENGLISH sentence itself up in lang/tl-text.php (English => Tagalog).
 * Falls back to the English text, so nothing breaks if a translation is missing.
 * Extra arguments are applied with sprintf() AFTER translating.
 */
function tx($english, ...$args) {
    static $map = null;
    $out = $english;
    if ($GLOBALS['__CURRENT_LANG'] === 'tl') {
        if ($map === null) {
            $f = __DIR__ . '/../lang/tl-text.php';
            $map = is_file($f) ? require $f : [];
        }
        if (isset($map[$english])) $out = $map[$english];
    }
    return $args ? vsprintf($out, $args) : $out;
}

/** Date in the current language (Tagalog month names when TL). Same format letters as date(). */
function tlDate($format, $ts = null) {
    $ts = $ts ?? time();
    if ($GLOBALS['__CURRENT_LANG'] !== 'tl') return date($format, $ts);
    $months = ['Enero','Pebrero','Marso','Abril','Mayo','Hunyo','Hulyo','Agosto','Setyembre','Oktubre','Nobyembre','Disyembre'];
    $short  = ['Ene','Peb','Mar','Abr','May','Hun','Hul','Ago','Set','Okt','Nob','Dis'];
    $m = (int)date('n', $ts) - 1;
    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $c = $format[$i];
        if ($c === '\\' && $i + 1 < $len) { $out .= $format[++$i]; continue; }
        if     ($c === 'F') $out .= $months[$m];
        elseif ($c === 'M') $out .= $short[$m];
        elseif ($c === 'a' || $c === 'A') $out .= date($c, $ts);
        else   $out .= date($c, $ts);
    }
    return $out;
}

/**
 * Translate a system-generated notification message AT DISPLAY TIME.
 * The stored text stays English (notification filters use LIKE '%complaint%' etc.).
 */
function tlNotifMsg($msg) {
    if ($GLOBALS['__CURRENT_LANG'] !== 'tl') return $msg;
    $m = (string)$msg;
    $rules = [
        '/^Your account has been approved! You can now log in\.$/' => 'Naaprubahan na ang iyong account! Maaari ka nang mag-log in.',
        '/^Your account has been re-enabled\. You can now log in\.$/' => 'Muling na-enable ang iyong account. Maaari ka nang mag-log in.',
        '/^Your complaint #(\d+) has been closed by the OSA\. You may now leave a review\.$/' => 'Isinara na ng OSA ang iyong reklamo #$1. Maaari ka nang mag-iwan ng review.',
        '/^Update on your complaint #(\d+): status is now (.+)\.$/' => null, // handled below (status word)
        '/^The OSA office replied to your message: (.*)$/s' => 'Sumagot ang OSA office sa iyong mensahe: $1',
        '/^Your vote has been recorded in (.*)\. Thank you for participating!$/s' => 'Naitala na ang iyong boto sa $1. Salamat sa pakikilahok!',
        '/^New event: (.*) — (\w{3}) (\d{1,2}), (\d{4})$/su' => null, // handled below (date)
    ];
    foreach ($rules as $re => $rep) {
        if (!preg_match($re, $m, $mm)) continue;
        if ($rep !== null) return preg_replace($re, $rep, $m);
        if (strpos($re, 'Update on') !== false) {
            $status = tx($mm[2]);
            return 'May update sa iyong reklamo #' . $mm[1] . ': ang status ay ' . $status . ' na.';
        }
        // New event
        $ts = strtotime($mm[2] . ' ' . $mm[3] . ' ' . $mm[4]);
        return 'Bagong event: ' . $mm[1] . ' — ' . ($ts ? tlDate('F j, Y', $ts) : ($mm[2] . ' ' . $mm[3] . ', ' . $mm[4]));
    }
    return tx($m);
}

/**
 * Renders the language switcher dropdown used in every navbar.
 * Include this inside the .nav-right div.
 */
function renderLangSwitcher() {
    $current = $GLOBALS['__CURRENT_LANG'];
    $base = defined('APP_BASE') ? APP_BASE : '';
    $returnTo = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/');
    ?>
    <div class="lang-switcher">
        <button class="lang-btn" id="langBtn" type="button">
            <i class="ti ti-language"></i>
            <span><?= $current === 'tl' ? 'TL' : 'EN' ?></span>
            <i class="ti ti-chevron-down" style="font-size:12px;"></i>
        </button>
        <div class="lang-menu" id="langMenu">
            <a href="<?= $base ?>/switch-lang.php?lang=en&amp;return=<?= urlencode($returnTo) ?>" class="lang-option<?= $current === 'en' ? ' active' : '' ?>">
                <span class="lang-flag">🇺🇸</span> English
            </a>
            <a href="<?= $base ?>/switch-lang.php?lang=tl&amp;return=<?= urlencode($returnTo) ?>" class="lang-option<?= $current === 'tl' ? ' active' : '' ?>">
                <span class="lang-flag">🇵🇭</span> Tagalog
            </a>
        </div>
    </div>
    <script>
    (function() {
        var btn = document.getElementById('langBtn');
        var menu = document.getElementById('langMenu');
        if (!btn || !menu) return;
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            menu.classList.toggle('open');
        });
        document.addEventListener('click', function() {
            menu.classList.remove('open');
        });
    })();
    </script>
    <?php
}
