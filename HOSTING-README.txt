HOSTING UPDATE
1. Admin confirmation (edit / delete / disable / import): the dialog now has "Use my password instead"
   next to the emailed code. Wrong password: 5 tries, then the password option locks for 5 minutes
   (the emailed code still works).
   Files: includes/admin-otp.php, assets/js/admin-otp.js, admin/candidates.php, admin/elections.php,
          admin/events.php, admin/party-lists.php, admin/staff.php, admin/students.php
2. Voting: Vote buttons aligned + whole candidate card is clickable
   Files: assets/css/style.css, student/voting.php
3. Student ID placeholder now shows ex. 2019**** (login, register, admin students, admin candidates)
   Files: index.php, register.php, admin/students.php, admin/candidates.php
4. sw.js: cache version v17 so installed PWAs refresh

Upload every file here over the live site (same paths). Backup first. No database changes.
Do NOT touch includes/config.local.php or uploads/.
After upload: hard refresh (Ctrl+F5). Installed PWA users: reopen the app once.
