<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/facebook.php';
requireStaff();
session_write_close();   // read-only endpoint: don't keep other pages waiting on the session lock

$fbFeed = fbGetPagePosts($pdo, 6, false);
$fbUploadsPrefix = '../uploads/';
include __DIR__ . '/../includes/fb-feed-partial.php';
