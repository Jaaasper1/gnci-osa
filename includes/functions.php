<?php

require_once __DIR__ . '/mailer.php';

// Sanitize output to prevent XSS
function e($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// The official list of GNC courses — used everywhere a course dropdown is
// shown (registration, election creation, etc.) so they never go out of sync.
function getCourseOptions() {
    return ['BSA','BSHM','BSBA','BSIT','BSTM','BEED','BSED','BSN','BSPharm','BSMedTech','BSCE'];
}

// Get active election for a specific course.
// An election with course = '' (blank) means "All Courses" (school-wide,
// e.g. Supreme Student Council) — visible to every student regardless of
// their own course. If a course-specific election AND an all-courses
// election are both active at the same time, the course-specific one wins.
function getActiveElection($pdo, $course = null) {
    if ($course) {
        $stmt = $pdo->prepare("SELECT * FROM elections WHERE is_active = 1 AND (course = ? OR course = '' OR course IS NULL) AND start_date <= NOW() AND end_date >= NOW() ORDER BY (course = ?) DESC LIMIT 1");
        $stmt->execute([$course, $course]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM elections WHERE is_active = 1 AND start_date <= NOW() AND end_date >= NOW() LIMIT 1");
        $stmt->execute();
    }
    return $stmt->fetch();
}

// Get an election that is active OR recently ended (for showing results/banner) — course-aware.
// Same "All Courses" (course = '') behavior as getActiveElection() above.
function getAnyActiveOrRecentElection($pdo, $course = null) {
    if ($course) {
        $stmt = $pdo->prepare("SELECT * FROM elections WHERE is_active = 1 AND (course = ? OR course = '' OR course IS NULL) ORDER BY (course = ?) DESC LIMIT 1");
        $stmt->execute([$course, $course]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM elections WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
    }
    return $stmt->fetch();
}

// Get all distinct courses from students table
function getAllCourses($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course != '' ORDER BY course");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Check if student already voted in a position
function hasVoted($pdo, $student_id, $election_id, $position_id) {
    $stmt = $pdo->prepare(
        "SELECT id FROM votes WHERE student_id = ? AND election_id = ? AND position_id = ?"
    );
    $stmt->execute([$student_id, $election_id, $position_id]);
    return $stmt->fetch() ? true : false;
}

// Check if student has voted in ALL positions that have approved candidates
// Returns TRUE only when every eligible position has a vote or abstain recorded
function hasVotedAll($pdo, $student_id, $election_id) {
    // Count positions that have at least 1 approved candidate
    $posStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT p.id) as total
         FROM positions p
         WHERE p.election_id = ?
           AND EXISTS (
               SELECT 1 FROM candidates c
               WHERE c.position_id = p.id AND c.is_approved = 1
           )"
    );
    $posStmt->execute([$election_id]);
    $totalPositions = (int) $posStmt->fetch()['total'];

    if ($totalPositions === 0) return false; // No positions yet, can't have voted

    // Count how many of those positions this student has voted/abstained in
    $voteStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT v.position_id) as voted
         FROM votes v
         JOIN positions p ON v.position_id = p.id
         WHERE v.student_id = ? AND v.election_id = ?
           AND EXISTS (
               SELECT 1 FROM candidates c
               WHERE c.position_id = p.id AND c.is_approved = 1
           )"
    );
    $voteStmt->execute([$student_id, $election_id]);
    $votedPositions = (int) $voteStmt->fetch()['voted'];

    // Locked out only when ALL eligible positions are covered
    return $votedPositions >= $totalPositions;
}

// Get unread notifications count
function getUnreadNotifCount($pdo, $student_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE student_id = ? AND is_read = 0");
    $stmt->execute([$student_id]);
    return $stmt->fetch()['cnt'];
}

// Add notification for student
function addNotification($pdo, $student_id, $message, $link = null) {
    $stmt = $pdo->prepare("INSERT INTO notifications (student_id, message, link) VALUES (?, ?, ?)");
    $stmt->execute([$student_id, $message, $link]);

    // In addition to the in-app notification, also email the student's Gmail if available
    $stmt2 = $pdo->prepare("SELECT first_name, last_name, email FROM students WHERE id = ?");
    $stmt2->execute([$student_id]);
    $student = $stmt2->fetch();
    if ($student && !empty($student['email'])) {
        sendNotificationEmail($student['email'], $student['first_name'] . ' ' . $student['last_name'], $message);
    }
}

// Get unread notifications count (OSA staff)
function getUnreadStaffNotifCount($pdo, $staff_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM staff_notifications WHERE staff_id = ? AND is_read = 0");
    $stmt->execute([$staff_id]);
    return $stmt->fetch()['cnt'];
}

// Add notification for OSA staff
function addStaffNotification($pdo, $staff_id, $message, $link = null) {
    if (!$staff_id) return;
    $stmt = $pdo->prepare("INSERT INTO staff_notifications (staff_id, message, link) VALUES (?, ?, ?)");
    $stmt->execute([$staff_id, $message, $link]);

    // In addition to the in-app notification, also email the staff member's Gmail if available
    $stmt2 = $pdo->prepare("SELECT first_name, last_name, email FROM osa_staff WHERE id = ?");
    $stmt2->execute([$staff_id]);
    $staff = $stmt2->fetch();
    if ($staff && !empty($staff['email'])) {
        sendNotificationEmail($staff['email'], $staff['first_name'] . ' ' . $staff['last_name'], $message, portalUrl('staff-login.php'));
    }
}

// Upload attachment for complaints
function uploadAttachment($file) {
    $allowed = ['jpg','jpeg','png','pdf','docx','doc'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return ['error' => 'File type not allowed.'];
    if ($file['size'] > $maxSize) return ['error' => 'File too large. Max 5MB.'];

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = uniqid('attach_') . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => true, 'filename' => $filename];
    }
    return ['error' => 'Upload failed. Make sure the uploads/ folder is writable.'];
}

// Upload candidate photo (images only)
function uploadPhoto($file) {
    $allowed = ['jpg','jpeg','png'];
    $maxSize = 2 * 1024 * 1024; // 2MB

    if ($file['error'] !== UPLOAD_ERR_OK) return ['error' => 'Photo upload failed. Please try again.'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return ['error' => 'Only JPG and PNG images are allowed.'];
    if ($file['size'] > $maxSize) return ['error' => 'Photo is too large. Maximum size is 2MB.'];

    $imgInfo = @getimagesize($file['tmp_name']);
    if (!$imgInfo) return ['error' => 'Invalid image file.'];

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = 'photo_' . uniqid() . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => true, 'filename' => $filename];
    }
    return ['error' => 'Upload failed. Make sure the uploads/ folder is writable.'];
}

// Upload Certificate of Registration (COR) for student self-registration
function uploadCorFile($file) {
    $allowed = ['jpg','jpeg','png','pdf'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Please attach a copy of your Certificate of Registration (COR).'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return ['error' => 'COR must be a JPG, PNG, or PDF file.'];
    if ($file['size'] > $maxSize) return ['error' => 'COR file is too large. Max 5MB.'];

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = 'cor_' . uniqid() . '.' . $ext;
    $dest     = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => true, 'filename' => $filename];
    }
    return ['error' => 'Upload failed. Make sure the uploads/ folder is writable.'];
}

// Format date to readable Filipino format
function formatDate($date) {
    return date('F j, Y · g:i A', strtotime($date));
}

// Status badge HTML
function statusBadge($status) {
    $map = [
        'New'       => 'badge-new',
        'In Review' => 'badge-review',
        'Resolved'  => 'badge-resolved',
        'Rejected'  => 'badge-rejected',
        'Closed'    => 'badge-closed',
    ];
    $class = $map[$status] ?? 'badge-new';
    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

// Compute a display status for an event based on its dates / cancelled flag
function eventStatus($event) {
    if (!empty($event['is_cancelled'])) return 'Cancelled';
    $today = date('Y-m-d');
    $start = $event['event_date'];
    $end   = !empty($event['end_date']) ? $event['end_date'] : $event['event_date'];
    if ($today < $start) return 'Upcoming';
    if ($today > $end)   return 'Completed';
    return 'Ongoing';
}

// Event status badge HTML
function eventStatusBadge($status) {
    $map = [
        'Upcoming'  => 'badge-new',
        'Ongoing'   => 'badge-review',
        'Completed' => 'badge-resolved',
        'Cancelled' => 'badge-rejected',
    ];
    $class = $map[$status] ?? 'badge-new';
    return '<span class="badge ' . $class . '">' . e($status) . '</span>';
}

// Auto-archive complaints resolved/rejected for 30+ days
function autoArchiveComplaints($pdo) {
    $pdo->exec(
        "UPDATE complaints
         SET is_archived = 1
         WHERE is_archived = 0
           AND status IN ('Resolved','Rejected','Closed')
           AND updated_at <= NOW() - INTERVAL 30 DAY"
    );
    $pdo->exec(
        "DELETE FROM complaints
         WHERE is_archived = 1
           AND updated_at <= NOW() - INTERVAL 30 DAY"
    );
}

require_once __DIR__ . '/export-helpers.php';
