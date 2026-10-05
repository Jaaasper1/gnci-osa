<?php
// ============================================================
// OneSignal Push Notification Configuration
// ============================================================
// 1. Go to https://onesignal.com and create a FREE account.
// 2. Create a new app -> choose "Web Push".
// 3. During setup, enter your site URL (your InfinityFree domain,
//    e.g. https://gncosaportal.rf.gd) — it must be HTTPS.
// 4. After the app is created, go to:
//    Settings -> Keys & IDs
//    Copy the "OneSignal App ID" and the "REST API Key" below.
// 5. Also see includes/push-widget.php and the two
//    OneSignalSDKWorker*.js files that must sit at your SITE ROOT
//    (same folder as index.php) — not inside /includes/.
// ============================================================

// Keys are now stored in includes/config.local.php (not committed to git).
require_once __DIR__ . '/config.php';
