====================================================
  GNC-OSA PORTAL — SETUP INSTRUCTIONS
  Guagua National College
====================================================

REQUIREMENTS:
  - XAMPP (download at https://www.apachefriends.org)
  - PHP 7.4 or higher
  - MySQL 5.7 or higher

SETUP STEPS:

1. Install XAMPP
   - Download and install XAMPP
   - Start Apache and MySQL from the XAMPP Control Panel

2. Copy the project folder
   - Paste the "gnc-osa-portal" folder into:
     C:/xampp/htdocs/gnc-osa-portal

3. Set up the database
   - Open your browser: http://localhost/phpmyadmin
   - Click "Import"
   - Select the file: gnc_osa_portal.sql
   - Click "Go"

4. Open the portal
   - http://localhost/gnc-osa-portal/

====================================================
DEFAULT ACCOUNTS (CHANGE PASSWORDS IMMEDIATELY!)
====================================================

ADMIN:
  Username: osa_admin
  Password: admin123

STUDENT (sample):
  Student ID: 2024-00001
  Password:   student123

====================================================
UPDATING AN EXISTING INSTALL (Disabled Accounts feature)
====================================================

If you already imported the database before this update, run this once
in phpMyAdmin > SQL tab (a fresh import of database.sql already has it):

  ALTER TABLE students ADD COLUMN disabled_at DATETIME DEFAULT NULL;

What changed:
  - Disabling a student account (admin/students.php) now stamps
    disabled_at, so it appears under a separate "Disabled Accounts"
    section instead of the "Pending Approvals" section.
  - From "Disabled Accounts", admin can Enable (restores the account)
    or Delete (removes it immediately).
  - Any disabled account left untouched for 30 days is deleted
    automatically the next time admin/students.php is opened.

====================================================
UPDATING AN EXISTING INSTALL (Completed elections)
====================================================

Elections now have Active / Closed / Completed sections. If you already
imported the database before this update, run once in phpMyAdmin > SQL:

  ALTER TABLE elections ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_hold;

(A fresh import of gnc_osa_portal.sql already has it.)

====================================================
CONFIG & SECRETS (NEW)
====================================================
All passwords/keys now live in ONE file: includes/config.local.php
(this file is NOT committed to git and is blocked from web access).

First-time setup (local or hosting):
  1. Copy includes/config.example.php -> includes/config.local.php
  2. Fill in DB, Gmail App Password, Facebook token, OneSignal keys
  3. Import gnc_osa_portal.sql, then open setup.php once to change the
     default admin/student passwords, then DELETE setup.php.

Hosting checklist:
  - Use a NEW DB user with a strong password (not root)
  - Use HTTPS
  - Delete setup.php after use
  - Make sure includes/.htaccess, vendor/.htaccess, lib/.htaccess and
    uploads/.htaccess were uploaded (hidden files!)
  - Never upload/commit config.local.php to GitHub

====================================================
FOLDER STRUCTURE
====================================================

gnc-osa-portal/
├── index.php          ← Login page
├── logout.php
├── gnc_osa_portal.sql ← Import this in phpMyAdmin
├── student/           ← Student pages
├── osa/               ← OSA Staff pages
├── admin/             ← Admin pages
├── includes/          ← DB connection, auth, functions
├── assets/css/        ← Stylesheet
└── uploads/           ← Complaint attachments

====================================================
NOTES
====================================================
- Change the admin password IMMEDIATELY after setup
- The uploads/ folder must be writable (chmod 755)
- For production, update DB credentials in
  includes/db.php

====================================================
