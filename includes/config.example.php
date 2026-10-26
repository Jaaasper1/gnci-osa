<?php
// ============================================================
// config.example.php  —  TEMPLATE (safe to commit to GitHub)
// ------------------------------------------------------------
// 1. Copy this file to  includes/config.local.php
// 2. Fill in the REAL values in config.local.php
// 3. NEVER commit config.local.php (it is listed in .gitignore)
// ============================================================

// ── Database ────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');            // on hosting: usually 'localhost' or the host shown in cPanel
define('DB_USER', 'root');                 // on hosting: your DB username
define('DB_PASS', '');                     // on hosting: your DB password (use a strong one!)
define('DB_NAME', 'gnc_osa_portal');       // on hosting: your DB name

// ── Gmail SMTP (OTP / approval emails) ──────────────────────
define('MAILER_FROM_EMAIL',   'your-osa-gmail@gmail.com');
define('MAILER_FROM_NAME',    'GNC-OSA-PORTAL');
define('MAILER_APP_PASSWORD', 'xxxx xxxx xxxx xxxx');   // Google App Password (16 chars)

// ── Portal public URL (used for links inside emails) ────────
// Local: leave '' (auto-detect). Online: e.g. 'https://osa.yourschool.edu.ph'
define('PORTAL_URL', '');

// ── Facebook Page feed ──────────────────────────────────────
define('FB_PAGE_ID',           'PASTE_YOUR_PAGE_ID_HERE');
define('FB_PAGE_ACCESS_TOKEN', 'PASTE_YOUR_LONG_LIVED_PAGE_ACCESS_TOKEN_HERE');
define('FB_API_VERSION',       'v26.0');
define('FB_PAGE_URL',          '');   // optional: e.g. 'https://www.facebook.com/YourOSAPage' (used in rejection email; blank = built from FB_PAGE_ID)
define('FB_FEED_CACHE_MINUTES', 10);

// ── OneSignal push notifications (leave '' to disable push) ─
define('ONESIGNAL_APP_ID',       '');
define('ONESIGNAL_REST_API_KEY', '');

// ── AI FAQ assistant for OSA messages (Google Gemini) ───────
// Leave GEMINI_API_KEY '' to disable the AI (messages then go straight to OSA staff).
define('GEMINI_API_KEY', '');
define('GEMINI_MODEL',   'gemini-3.5-flash-lite');
