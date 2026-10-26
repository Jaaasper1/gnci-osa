<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/facebook.php';
requireStaff();
session_write_close();   // read-only endpoint: don't keep other pages waiting on the session lock

// forceRefresh is left false — fbGetPagePosts already only hits the real
// Facebook API when its own 10-minute cache has expired, so polling this
// endpoint often is cheap and safe.
$fbFeed = fbGetPagePosts($pdo, 6, false);
$fbUploadsPrefix = '../uploads/';
include __DIR__ . '/../includes/fb-feed-partial.php';
