<?php
// Shared password rules (students, OSA staff, registration).
// Returns NULL when the password is OK, otherwise a short error message to show the user.
//
//   $ctx    = values the password must NOT contain: [$studentId, $email]
//   $minLen = minimum length (default 8)
//   $names  = first/last name: ALLOWED in the password, but the rest of the password
//             (after removing the name) must still be strong: 5+ chars with a number and a symbol

function _pp_t(string $key, string $fallback): string {
    return function_exists('t') ? t($key, $fallback) : $fallback;
}

function passwordPolicyWeakWords(): array {
    return ['password','passw','qwerty','qwertyuiop','asdfgh','asdfghjkl','zxcvbn','iloveyou','letmein','welcome',
             'admin','administrator','student','login','abc','monkey','dragon','football','basketball','superman',
             'guagua','gnc','gncosa','osa','portal','changeme','default','secret','sunshine','princess','master'];
}

// Settings for assets/js/pw-policy.js (same rules + same messages as the server check)
function passwordPolicyJsConfig(int $minLen = 8): array {
    return [
        'minLen' => $minLen,
        'weak'   => passwordPolicyWeakWords(),
        'msg'    => [
            'len'      => sprintf(_pp_t('pw.err_min', 'Password must be at least %d characters.'), $minLen),
            'max'      => _pp_t('pw.err_max', 'Password is too long (maximum 72 characters).'),
            'up'       => _pp_t('pw.err_upper', 'Password must have at least one uppercase letter (A-Z).'),
            'low'      => _pp_t('pw.err_lower', 'Password must have at least one lowercase letter (a-z).'),
            'num'      => _pp_t('pw.err_number', 'Password must have at least one number (0-9).'),
            'sym'      => _pp_t('pw.err_symbol', 'Password must have at least one special character (e.g. ! @ # $ % &).'),
            'repeat'   => _pp_t('pw.err_repeat', 'Password must not repeat the same character 4 or more times in a row.'),
            'common'   => _pp_t('pw.err_common', 'That password is too common or easy to guess. Choose a more unique one.'),
            'personal' => _pp_t('pw.err_personal', 'Password must not contain your Student ID or email.'),
            'nameOnly' => _pp_t('pw.err_name_only', 'If your password includes your name, add at least 5 more characters with a number and a special character (e.g. Jasper#2024x).'),
            'ok'       => _pp_t('pw.ok', 'Strong enough.'),
        ],
    ];
}

function passwordPolicyError(string $pw, array $ctx = [], int $minLen = 8, array $names = []): ?string {
    if (mb_strlen($pw) < $minLen) {
        return sprintf(_pp_t('pw.err_min', 'Password must be at least %d characters.'), $minLen);
    }
    if (strlen($pw) > 72) { // bcrypt only uses the first 72 bytes
        return _pp_t('pw.err_max', 'Password is too long (maximum 72 characters).');
    }
    if (!preg_match('/[A-Z]/', $pw)) {
        return _pp_t('pw.err_upper', 'Password must have at least one uppercase letter (A-Z).');
    }
    if (!preg_match('/[a-z]/', $pw)) {
        return _pp_t('pw.err_lower', 'Password must have at least one lowercase letter (a-z).');
    }
    if (!preg_match('/\d/', $pw)) {
        return _pp_t('pw.err_number', 'Password must have at least one number (0-9).');
    }
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) {
        return _pp_t('pw.err_symbol', 'Password must have at least one special character (e.g. ! @ # $ % &).');
    }
    if (preg_match('/(.)\1{3,}/u', $pw)) {
        return _pp_t('pw.err_repeat', 'Password must not repeat the same character 4 or more times in a row.');
    }

    // Common / easy-to-guess passwords (also catches P@ssw0rd1!-style variations)
    $leet    = strtr(strtolower($pw), ['@' => 'a', '$' => 's', '0' => 'o', '3' => 'e', '4' => 'a', '5' => 's']);
    $letters = preg_replace('/[^a-z]/', '', $leet);
    $weak = passwordPolicyWeakWords();
    $isWeak = false;
    foreach ($weak as $w) {
        // the word itself, or the word plus up to 2 extra letters (e.g. "Password1!", "Welcomeab1!")
        if ($letters === $w || (strpos($letters, $w) === 0 && strlen($letters) - strlen($w) <= 2)) { $isWeak = true; break; }
    }
    // Pure number sequences like 12345678 / 1234567890 (with at most a couple of letters)
    $digitsOnly = preg_replace('/\D/', '', $pw);
    if (!$isWeak && preg_match('/^(0123456789|1234567890|123456789|12345678|987654321)$/', $digitsOnly) && strlen($letters) < 3) $isWeak = true;
    if ($isWeak) {
        return _pp_t('pw.err_common', 'That password is too common or easy to guess. Choose a more unique one.');
    }

    // Student ID / email must not be used (email: the part before @ as a whole)
    $lowerPw = mb_strtolower($pw);
    foreach ($ctx as $v) {
        $v = mb_strtolower(trim((string)$v));
        if ($v === '') continue;
        if (strpos($v, '@') !== false) $v = substr($v, 0, strpos($v, '@'));
        if (mb_strlen($v) >= 4 && mb_strpos($lowerPw, $v) !== false) {
            return _pp_t('pw.err_personal', 'Password must not contain your Student ID or email.');
        }
    }

    // Name is allowed, but the password can not be mostly the name
    $rest = $lowerPw; $usedName = false;
    foreach ($names as $v) {
        foreach (preg_split('/[\s._\-]+/u', mb_strtolower(trim((string)$v))) as $part) {
            if (mb_strlen($part) >= 3 && mb_strpos($rest, $part) !== false) {
                $rest = str_replace($part, '', $rest); $usedName = true;
            }
        }
    }
    if ($usedName && (mb_strlen($rest) < 5 || !preg_match('/\d/', $rest) || !preg_match('/[^a-z0-9]/', $rest))) {
        return _pp_t('pw.err_name_only', 'If your password includes your name, add at least 5 more characters with a number and a special character (e.g. Jasper#2024x).');
    }
    return null;
}
