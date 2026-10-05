<?php
// ============================================================
// Facebook Page API Configuration
// ============================================================
// 1. Create a Meta App at https://developers.facebook.com and add the
//    "Manage everything on your Page" use case.
// 2. In the Graph API Explorer (Tools > Graph API Explorer), select your
//    app, request the pages_show_list, pages_read_engagement and
//    pages_manage_posts permissions, then Generate Access Token.
// 3. Switch the "User or Page" dropdown to your Page to get a Page
//    Access Token. Extend it first via the Access Token Debugger
//    (Tools > Access Token Tool > Extend Access Token) so the Page
//    token you copy afterwards does not expire.
// 4. Paste your Page ID and the long-lived Page Access Token below.
// ============================================================

// Credentials are now stored in includes/config.local.php (not committed to git).
require_once __DIR__ . '/config.php';
