-- Run once in phpMyAdmin (SQL tab) on your portal database.
-- Speeds up the Complaints list (tabs, sorting, date/status/category filters) when there are many rows.
-- XAMPP's MariaDB supports IF NOT EXISTS, so it is safe to run twice.
CREATE INDEX IF NOT EXISTS idx_complaints_archived_created ON complaints (is_archived, created_at);
CREATE INDEX IF NOT EXISTS idx_complaints_status           ON complaints (status);
CREATE INDEX IF NOT EXISTS idx_complaints_category         ON complaints (category);
CREATE INDEX IF NOT EXISTS idx_complaints_autoarchive      ON complaints (is_archived, status, updated_at);

-- Students page (Active Students list: paging, name/date sort, course filter)
CREATE INDEX IF NOT EXISTS idx_students_active_name     ON students (is_active, last_name, first_name);
CREATE INDEX IF NOT EXISTS idx_students_active_approved ON students (is_active, approved_at);
CREATE INDEX IF NOT EXISTS idx_students_active_course   ON students (is_active, course);
-- "Has this student voted?" lookup used by the Voting Status column
CREATE INDEX IF NOT EXISTS idx_votes_election_student   ON votes (election_id, student_id);

-- Notifications pages (tabs, pagination, unread badge) - student + OSA/admin
CREATE INDEX IF NOT EXISTS idx_notif_student_read_created ON notifications (student_id, is_read, created_at);
CREATE INDEX IF NOT EXISTS idx_notif_student_created      ON notifications (student_id, created_at);
CREATE INDEX IF NOT EXISTS idx_snotif_staff_read_created  ON staff_notifications (staff_id, is_read, created_at);
CREATE INDEX IF NOT EXISTS idx_snotif_staff_created       ON staff_notifications (staff_id, created_at);
